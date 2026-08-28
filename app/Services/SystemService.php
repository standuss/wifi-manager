<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Config;

final class SystemService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /** @param array<string,mixed> $input @return array{queued:bool,values:array<string,string|int>} */
    public function saveMonitoring(array $input): array
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

        $values = [
            'monitor_listen_address' => $address,
            'monitor_syslog_tcp_port' => $tcp,
            'monitor_syslog_udp_port' => $udp,
            'monitor_netflow_port' => $flow,
            'monitor_retention_days' => $retention,
            'monitor_syslog_max_gib' => $syslogGiB,
            'monitor_netflow_max_gib' => $flowGiB,
        ];
        $this->settings->save($values);
        $queued = $this->writeRequest(
            (string) $this->config->get('system.request_dir') . '/monitoring.json',
            $values + ['requested_at' => date(DATE_ATOM)]
        );
        return compact('queued', 'values');
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
