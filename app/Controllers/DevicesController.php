<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\JobService;
use WifiManager\Services\LogArchiveService;
use WifiManager\Services\SettingsService;
use WifiManager\View;

final class DevicesController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly SettingsService $settings,
        private readonly JobService $jobs,
        private readonly AuditService $audit,
        private readonly LogArchiveService $archive,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $devices = $this->database->pdo()->query(
            "SELECT d.*, p.name AS person_name, p.note AS person_note,
                    (SELECT MAX(s.connected_at) FROM wifi_sessions s WHERE s.device_id=d.id) AS last_connected_at,
                    EXISTS(SELECT 1 FROM connected_clients c WHERE c.device_id=d.id) AS online
             FROM devices d LEFT JOIN people p ON p.id=d.person_id
             WHERE d.registration_state IN ('registered','incomplete','blocked')
             ORDER BY online DESC, d.updated_at DESC"
        )->fetchAll();
        $this->view->render('devices', [
            'title' => 'Registrovaná zařízení', 'activeNav' => 'devices',
            'devices' => $devices, 'settings' => $this->settings->all(),
        ]);
    }

    public function show(): void
    {
        $this->auth->requireLogin();
        $device = $this->device((int) ($_GET['id'] ?? 0));
        $eventsStatement = $this->database->pdo()->prepare(
            'SELECT * FROM wifi_connection_events WHERE device_id=:id OR (device_id IS NULL AND mac_address=:mac) ORDER BY occurred_at DESC LIMIT 300'
        );
        $eventsStatement->execute(['id' => $device['id'], 'mac' => $device['mac_address']]);
        $events = $eventsStatement->fetchAll();
        $sessionsStatement = $this->database->pdo()->prepare(
            'SELECT * FROM wifi_sessions WHERE device_id=:id OR (device_id IS NULL AND mac_address=:mac) ORDER BY connected_at DESC LIMIT 200'
        );
        $sessionsStatement->execute(['id' => $device['id'], 'mac' => $device['mac_address']]);
        $sessions = $sessionsStatement->fetchAll();
        $ipStatement = $this->database->pdo()->prepare('SELECT ip_address, valid_from, valid_to FROM ip_assignments WHERE device_id=:id ORDER BY valid_from DESC');
        $ipStatement->execute(['id' => $device['id']]);
        $ipAssignments = $ipStatement->fetchAll();
        if ($ipAssignments === [] && (string) ($device['current_ip'] ?? '') !== '') {
            $ipAssignments[] = ['ip_address' => $device['current_ip'], 'valid_from' => $device['registered_at'], 'valid_to' => null];
        }

        $allowedIps = array_values(array_unique(array_filter(array_column($ipAssignments, 'ip_address'))));
        $selectedIp = trim((string) ($_GET['ip'] ?? ($device['current_ip'] ?? '')));
        if (!in_array($selectedIp, $allowedIps, true)) $selectedIp = $allowedIps[0] ?? '';
        $from = trim((string) ($_GET['from'] ?? date('Y-m-d\TH:i', time() - 86400)));
        $to = trim((string) ($_GET['to'] ?? date('Y-m-d\TH:i')));
        $flowStatus = $this->archive->status();
        $flows = ['rows' => [], 'truncated' => false, 'summary' => ['flows' => 0, 'bytes' => 0, 'packets' => 0, 'endpoints' => 0]];
        $flowError = null;
        if ($selectedIp !== '' && $flowStatus['netflow']['readable'] && $flowStatus['netflow']['nfdump']) {
            try {
                $flows = $this->archive->searchFlows(['from' => $from, 'to' => $to, 'ip' => $selectedIp, 'limit' => 150]);
            } catch (\Throwable $exception) {
                $flowError = $exception->getMessage();
            }
        }

        $this->view->render('device_detail', compact(
            'device', 'events', 'sessions', 'ipAssignments', 'selectedIp', 'from', 'to', 'flows', 'flowStatus', 'flowError'
        ) + ['title' => (string) $device['name'], 'activeNav' => 'devices']);
    }

    public function repair(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $device = $this->device((int) ($_POST['device_id'] ?? 0));
        if ((string) ($device['current_ip'] ?? '') === '') throw new \RuntimeException('Zařízení nemá uloženou statickou IP adresu.');
        $routerId = (int) $this->database->pdo()->query('SELECT id FROM routers WHERE enabled=1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($routerId <= 0) throw new \RuntimeException('Není nastavený aktivní MikroTik.');
        $settings = $this->settings->all();
        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue($routerId, 'update_device', [
            'device_id' => (int) $device['id'],
            'device_name' => (string) $device['name'],
            'person_name' => (string) ($device['person_name'] ?? ''),
            'note' => (string) ($device['person_note'] ?? ''),
            'mac_address' => (string) $device['mac_address'],
            'ip_address' => (string) $device['current_ip'],
            'rate_down' => (string) ($device['rate_down'] ?: $settings['default_rate_down']),
            'rate_up' => (string) ($device['rate_up'] ?: $settings['default_rate_up']),
            'capsman_type' => (string) ($device['capsman_type'] ?? 'wifi'),
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'device.repair.requested', 'Oprava konfigurace zařízení byla zařazena', 'device', (int) $device['id'], ['job_id' => $jobId], request_ip());
        flash('success', 'WiFi Manager obnoví Access List, DHCP a Simple Queue podle uložené evidence.');
        redirect('/devices/detail?id=' . (int) $device['id']);
    }

    /** @return array<string,mixed> */
    private function device(int $id): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT d.*, p.name AS person_name, p.note AS person_note,
                    EXISTS(SELECT 1 FROM connected_clients c WHERE c.device_id=d.id) AS online
             FROM devices d LEFT JOIN people p ON p.id=d.person_id
             WHERE d.id=:id AND d.registration_state IN ('registered','incomplete','blocked') LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $device = $statement->fetch();
        if (!is_array($device)) throw new \RuntimeException('Registrované zařízení nebylo nalezeno.');
        return $device;
    }
}
