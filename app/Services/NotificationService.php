<?php

declare(strict_types=1);

namespace WifiManager\Services;

use PDO;
use WifiManager\Crypto;
use WifiManager\Database;

final class NotificationService
{
    public function __construct(
        private readonly Database $database,
        private readonly SettingsService $settings,
        private readonly Crypto $crypto,
        private readonly SmtpMailer $mailer,
    ) {
    }

    public function observeDevice(PDO $pdo, int $routerId, string $mac, ?string $hostname, ?string $ip, ?string $ssid, string $now): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO discovered_devices (router_id, mac_address, hostname, ip_address, ssid, first_seen_at, last_seen_at)
             VALUES (:router, :mac, :hostname, :ip, :ssid, :now, :now)
             ON CONFLICT(router_id, mac_address) DO NOTHING'
        );
        $statement->execute(['router' => $routerId, 'mac' => $mac, 'hostname' => $hostname, 'ip' => $ip, 'ssid' => $ssid, 'now' => $now]);
        $isNew = $statement->rowCount() === 1;
        if (!$isNew) {
            $pdo->prepare('UPDATE discovered_devices SET hostname=:hostname, ip_address=:ip, ssid=:ssid, last_seen_at=:now WHERE router_id=:router AND mac_address=:mac')
                ->execute(['hostname' => $hostname, 'ip' => $ip, 'ssid' => $ssid, 'now' => $now, 'router' => $routerId, 'mac' => $mac]);
            return;
        }

        $router = $pdo->prepare('SELECT COALESCE(identity, name) FROM routers WHERE id = :id');
        $router->execute(['id' => $routerId]);
        $routerName = (string) ($router->fetchColumn() ?: 'MikroTik');
        $subject = 'Nové zařízení v síti ' . $routerName;
        $body = "WiFi Manager zaznamenal nové zařízení.\n\n"
            . "Router: $routerName\nMAC: $mac\nIP: " . ($ip ?: 'nezjištěna') . "\n"
            . 'Hostname: ' . ($hostname ?: 'nezjištěn') . "\nSSID: " . ($ssid ?: 'nezjištěno') . "\n"
            . 'Čas: ' . date('d. m. Y H:i:s') . "\n";
        $this->queueForPreference($pdo, 'notify_new_device', 'new_device', $routerId . ':' . $mac, $subject, $body);
    }

    public function queueBackupResult(int $routerId, int $backupId, bool $success, string $detail): void
    {
        $pdo = $this->database->pdo();
        $router = $pdo->prepare('SELECT COALESCE(identity, name) FROM routers WHERE id = :id');
        $router->execute(['id' => $routerId]);
        $name = (string) ($router->fetchColumn() ?: 'MikroTik');
        $subject = ($success ? 'Záloha dokončena: ' : 'Záloha selhala: ') . $name;
        $body = $subject . "\n\n" . $detail . "\nČas: " . date('d. m. Y H:i:s') . "\n";
        $this->queueForPreference($pdo, 'notify_backup_result', 'backup_result', (string) $backupId, $subject, $body);
    }

    public function queueMonitoringProblem(int $routerId, string $error): void
    {
        $pdo = $this->database->pdo();
        $router = $pdo->prepare('SELECT COALESCE(identity, name) FROM routers WHERE id = :id');
        $router->execute(['id' => $routerId]);
        $name = (string) ($router->fetchColumn() ?: 'MikroTik');
        $subject = 'Monitoring routeru selhal: ' . $name;
        $body = $subject . "\n\nChyba: " . mb_substr($error, 0, 1000) . "\nČas: " . date('d. m. Y H:i:s') . "\n";
        $event = $routerId . ':' . gmdate('Y-m-d-H') . ':' . substr(hash('sha256', $error), 0, 12);
        $this->queueForPreference($pdo, 'notify_monitoring_problem', 'monitoring_problem', $event, $subject, $body);
    }

    public function processOne(): bool
    {
        $settings = $this->settings->all();
        if (($settings['smtp_enabled'] ?? '0') !== '1') return false;
        $job = $this->database->transaction(function (PDO $pdo): ?array {
            $row = $pdo->query(
                "SELECT q.*, u.email FROM notification_queue q JOIN admin_users u ON u.id=q.user_id
                 WHERE q.status IN ('pending','failed') AND q.attempts < 5 AND datetime(q.next_attempt_at) <= CURRENT_TIMESTAMP
                   AND u.active=1 AND u.email IS NOT NULL AND u.email <> '' ORDER BY q.created_at, q.id LIMIT 1"
            )->fetch();
            if (!is_array($row)) return null;
            $update = $pdo->prepare("UPDATE notification_queue SET status='sending', attempts=attempts+1 WHERE id=:id AND status IN ('pending','failed')");
            $update->execute(['id' => $row['id']]);
            return $update->rowCount() === 1 ? $row : null;
        });
        if ($job === null) return false;

        try {
            $password = ($settings['smtp_password_cipher'] ?? '') !== '' ? $this->crypto->decrypt($settings['smtp_password_cipher']) : '';
            $this->mailer->send($settings, (string) $job['email'], (string) $job['subject'], (string) $job['body'], $password);
            $this->database->pdo()->prepare("UPDATE notification_queue SET status='sent', sent_at=CURRENT_TIMESTAMP, last_error=NULL WHERE id=:id")
                ->execute(['id' => $job['id']]);
        } catch (\Throwable $exception) {
            $delay = min(3600, 60 * (2 ** min(5, (int) $job['attempts'])));
            $this->database->pdo()->prepare(
                "UPDATE notification_queue SET status='failed', last_error=:error, next_attempt_at=datetime('now', :delay) WHERE id=:id"
            )->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'delay' => '+' . $delay . ' seconds', 'id' => $job['id']]);
        }
        return true;
    }

    private function queueForPreference(PDO $pdo, string $preference, string $type, string $event, string $subject, string $body): void
    {
        if (($this->settings->all()['smtp_enabled'] ?? '0') !== '1') return;
        $users = $pdo->query("SELECT id FROM admin_users WHERE active=1 AND email IS NOT NULL AND email<>'' AND $preference=1")->fetchAll(PDO::FETCH_COLUMN);
        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO notification_queue (user_id, type, event_key, subject, body) VALUES (:user, :type, :event, :subject, :body)'
        );
        foreach ($users as $userId) {
            $insert->execute(['user' => $userId, 'type' => $type, 'event' => $event, 'subject' => $subject, 'body' => $body]);
        }
    }
}
