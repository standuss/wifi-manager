<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Config;
use WifiManager\Crypto;
use WifiManager\Database;

final class SystemService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Database $database,
        private readonly JobService $jobs,
        private readonly Crypto $crypto,
        private readonly SmtpMailer $mailer,
    ) {
    }

    /** @param array<string,mixed> $input @return array{queued:bool,values:array<string,string|int>} */
    public function saveMonitoring(array $input, ?int $userId = null): array
    {
        $address = trim((string) ($input['monitor_listen_address'] ?? ''));
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Naslouchací IP adresa není platná.');
        }
        $tcp = $this->port($input['monitor_syslog_tcp_port'] ?? null, 'TCP syslog');
        $udp = $this->port($input['monitor_syslog_udp_port'] ?? null, 'UDP syslog');
        $flow = $this->port($input['monitor_netflow_port'] ?? null, 'IPFIX');
        if ($udp === $flow) throw new \InvalidArgumentException('UDP syslog a IPFIX nemohou používat stejný port.');
        $retention = $this->integer($input['monitor_retention_days'] ?? null, 30, 3650, 'retence');
        $syslogGiB = $this->integer($input['monitor_syslog_max_gib'] ?? null, 1, 4096, 'limit syslogu');
        $flowGiB = $this->integer($input['monitor_netflow_max_gib'] ?? null, 1, 16384, 'limit IPFIX');
        $target = trim((string) ($input['monitor_router_target_address'] ?? ''));
        if ($target !== '' && filter_var($target, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Veřejná/NAT adresa pro MikroTik není platná.');
        }
        $routerSyslog = $this->port($input['monitor_router_syslog_port'] ?? null, 'cílový syslog');
        $routerFlow = $this->port($input['monitor_router_netflow_port'] ?? null, 'cílový IPFIX');
        $transport = strtolower(trim((string) ($input['monitor_router_syslog_transport'] ?? 'tcp')));
        if (!in_array($transport, ['tcp', 'udp'], true)) throw new \InvalidArgumentException('Neplatný přenos syslogu.');

        $values = [
            'monitor_listen_address' => $address,
            'monitor_syslog_tcp_port' => $tcp,
            'monitor_syslog_udp_port' => $udp,
            'monitor_netflow_port' => $flow,
            'monitor_retention_days' => $retention,
            'monitor_syslog_max_gib' => $syslogGiB,
            'monitor_netflow_max_gib' => $flowGiB,
            'monitor_router_target_address' => $target,
            'monitor_router_syslog_port' => $routerSyslog,
            'monitor_router_syslog_transport' => $transport,
            'monitor_router_netflow_port' => $routerFlow,
        ];
        $this->settings->save($values);
        $queued = $this->writeRequest(
            (string) $this->config->get('system.request_dir') . '/monitoring.json',
            $values + ['requested_at' => date(DATE_ATOM)]
        );
        $routerJobs = 0;
        if ($target !== '') {
            foreach ($this->database->pdo()->query('SELECT id FROM routers WHERE enabled=1')->fetchAll(\PDO::FETCH_COLUMN) as $routerId) {
                $this->jobs->enqueue((int) $routerId, 'configure_monitoring', [
                    'router_id' => (int) $routerId,
                    'target_address' => $target,
                    'syslog_port' => $routerSyslog,
                    'syslog_transport' => $transport,
                    'netflow_port' => $routerFlow,
                ], $userId);
                $routerJobs++;
            }
        }
        return compact('queued', 'values', 'routerJobs');
    }

    /** @param array<string,mixed> $input @return array<string,string|int> */
    public function saveSmtp(array $input): array
    {
        $enabled = isset($input['smtp_enabled']) ? 1 : 0;
        $auth = isset($input['smtp_auth_enabled']) ? 1 : 0;
        $host = trim((string) ($input['smtp_host'] ?? ''));
        $port = $this->port($input['smtp_port'] ?? null, 'SMTP');
        $encryption = strtolower(trim((string) ($input['smtp_encryption'] ?? 'none')));
        if (!in_array($encryption, ['none', 'starttls', 'tls'], true)) throw new \InvalidArgumentException('Neplatné SMTP šifrování.');
        $from = trim((string) ($input['smtp_from_email'] ?? ''));
        if ($enabled && ($host === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('Pro aktivní SMTP vyplňte server a platný e-mail odesílatele.');
        }
        $values = [
            'smtp_enabled' => $enabled,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_auth_enabled' => $auth,
            'smtp_username' => trim((string) ($input['smtp_username'] ?? '')),
            'smtp_from_email' => $from,
            'smtp_from_name' => mb_substr(trim((string) ($input['smtp_from_name'] ?? 'WiFi Manager')), 0, 120),
            'smtp_timeout_seconds' => $this->integer($input['smtp_timeout_seconds'] ?? 10, 3, 60, 'SMTP timeout'),
        ];
        $password = (string) ($input['smtp_password'] ?? '');
        if ($password !== '') $values['smtp_password_cipher'] = $this->crypto->encrypt($password);
        $this->settings->save($values);
        return $values;
    }

    public function testSmtp(string $recipient): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Zadejte platný testovací e-mail.');
        $settings = $this->settings->all();
        $password = ($settings['smtp_password_cipher'] ?? '') !== '' ? $this->crypto->decrypt($settings['smtp_password_cipher']) : '';
        $this->mailer->send($settings, $recipient, 'Test WiFi Manageru', "SMTP nastavení funguje.\n\nOdesláno: " . date('d. m. Y H:i:s'), $password);
    }

    /** @param array<string,mixed> $input @return array<string,string|int> */
    public function saveBackup(array $input): array
    {
        $enabled = isset($input['backup_enabled']) ? 1 : 0;
        $values = [
            'backup_enabled' => $enabled,
            'backup_interval_days' => $this->integer($input['backup_interval_days'] ?? 7, 1, 365, 'interval záloh'),
            'backup_retention_count' => $this->integer($input['backup_retention_count'] ?? 12, 1, 100, 'počet záloh'),
        ];
        $password = (string) ($input['backup_password'] ?? '');
        if ($password !== '') {
            if (mb_strlen($password) < 8) throw new \InvalidArgumentException('Heslo zálohy musí mít alespoň 8 znaků.');
            $values['backup_password_cipher'] = $this->crypto->encrypt($password);
        } elseif ($enabled && (($this->settings->all()['backup_password_cipher'] ?? '') === '')) {
            throw new \InvalidArgumentException('Pro automatické zálohy nastavte heslo šifrování.');
        }
        $this->settings->save($values);
        return $values;
    }

    /** @param array<string,mixed> $payload */
    public function queueUpdate(array $payload): bool
    {
        return $this->writeRequest(
            (string) $this->config->get('update.request_dir') . '/install.json',
            $payload + ['requested_at' => date(DATE_ATOM)]
        );
    }

    /** @return array<string,mixed> */
    public function updateStatus(): array
    {
        $path = (string) $this->config->get('update.status_file');
        if (!is_readable($path)) return [];
        $value = json_decode((string) file_get_contents($path), true);
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function applyStatus(): array
    {
        $path = '/var/lib/wifimanager/system-apply-status.json';
        if (!is_readable($path)) return [];
        $versionPath = WFM_ROOT . '/VERSION';
        if (is_file($versionPath) && filemtime($path) < filemtime($versionPath)) return [];
        $value = json_decode((string) file_get_contents($path), true);
        return is_array($value) ? $value : [];
    }

    private function port(mixed $value, string $label): int
    {
        return $this->integer($value, 1, 65535, $label);
    }

    private function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $minimum || $number > $maximum) {
            throw new \InvalidArgumentException('Neplatná hodnota: ' . $label . '.');
        }
        return $number;
    }

    /** @param array<string,mixed> $payload */
    private function writeRequest(string $path, array $payload): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) return false;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($temporary, $json . "\n", LOCK_EX) === false) return false;
        chmod($temporary, 0660);
        return rename($temporary, $path);
    }
}
