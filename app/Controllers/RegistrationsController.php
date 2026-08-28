<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\JobService;
use WifiManager\Services\SettingsService;
use WifiManager\View;

final class RegistrationsController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly SettingsService $settings,
        private readonly JobService $jobs,
        private readonly AuditService $audit,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $pending = $this->database->pdo()->query(
            "SELECT * FROM connected_clients WHERE registration_status IN ('pending','incomplete') ORDER BY first_seen_at"
        )->fetchAll();
        $devices = $this->database->pdo()->query(
            'SELECT d.*, p.name AS person_name, p.note AS person_note FROM devices d
             LEFT JOIN people p ON p.id = d.person_id ORDER BY d.updated_at DESC'
        )->fetchAll();
        $settings = $this->settings->all();
        $this->view->render('registrations', [
            'title' => 'Registrace zařízení', 'activeNav' => 'registrations', 'pending' => $pending,
            'devices' => $devices, 'settings' => $settings, 'suggestedIp' => $this->nextFreeIp($settings),
        ]);
    }

    public function store(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $name = trim((string) ($_POST['person_name'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $deviceName = trim((string) ($_POST['device_name'] ?? $name));
        $mac = normalize_mac((string) ($_POST['mac_address'] ?? ''));
        $settings = $this->settings->all();
        $ip = trim((string) ($_POST['ip_address'] ?? ''));
        $rateDown = strtoupper(trim((string) ($_POST['rate_down'] ?? $settings['default_rate_down'])));
        $rateUp = strtoupper(trim((string) ($_POST['rate_up'] ?? $settings['default_rate_up'])));

        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Zadejte jméno držitele zařízení.');
        if ($deviceName === '' || mb_strlen($deviceName) > 120) throw new \InvalidArgumentException('Zadejte název zařízení.');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !$this->ipInRange($ip, $settings['static_ip_start'], $settings['static_ip_end'])) {
            throw new \InvalidArgumentException('IP adresa není v povoleném statickém rozsahu.');
        }
        if (!preg_match('/^\d+(K|M|G)$/', $rateDown) || !preg_match('/^\d+(K|M|G)$/', $rateUp)) {
            throw new \InvalidArgumentException('Rychlost zadejte například jako 2M.');
        }

        $routerId = (int) $this->database->pdo()->query('SELECT id FROM routers WHERE enabled = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($routerId <= 0) throw new \RuntimeException('Nejprve nastavte připojení k MikroTiku.');

        $existing = $this->database->pdo()->prepare("SELECT id FROM devices WHERE mac_address = :mac AND registration_state != 'archived'");
        $existing->execute(['mac' => $mac]);
        if ($existing->fetchColumn()) throw new \RuntimeException('Tato MAC adresa už je v evidenci.');

        $existingPersonId = null;
        foreach ($this->database->pdo()->query('SELECT id, name FROM people WHERE active = 1')->fetchAll() as $personRow) {
            if (mb_strtolower(trim((string) $personRow['name'])) === mb_strtolower($name)) {
                $existingPersonId = (int) $personRow['id'];
                break;
            }
        }
        if ($existingPersonId !== null) {
            $check = $this->database->pdo()->prepare("SELECT COUNT(*) FROM devices WHERE person_id = :person_id AND registration_state != 'archived'");
            $check->execute(['person_id' => $existingPersonId]);
            if ((int) $check->fetchColumn() >= (int) ($settings['max_devices_per_person'] ?? 1)) {
                throw new \RuntimeException('Tento držitel už má maximální povolený počet aktivních zařízení.');
            }
        }

        [$personId, $deviceId] = $this->database->transaction(function () use ($existingPersonId, $name, $note, $deviceName, $mac): array {
            if ($existingPersonId === null) {
                $person = $this->database->pdo()->prepare('INSERT INTO people (name, note) VALUES (:name, :note)');
                $person->execute(['name' => $name, 'note' => $note !== '' ? $note : null]);
                $personId = (int) $this->database->pdo()->lastInsertId();
            } else {
                $personId = $existingPersonId;
                if ($note !== '') $this->database->pdo()->prepare('UPDATE people SET note = :note, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['note' => $note, 'id' => $personId]);
            }
            $device = $this->database->pdo()->prepare(
                "INSERT INTO devices (person_id, name, mac_address, registration_state) VALUES (:person_id, :name, :mac, 'registering')"
            );
            $device->execute(['person_id' => $personId, 'name' => $deviceName, 'mac' => $mac]);
            return [$personId, (int) $this->database->pdo()->lastInsertId()];
        });

        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue($routerId, 'register_device', [
            'person_id' => $personId, 'device_id' => $deviceId, 'device_name' => $deviceName,
            'mac_address' => $mac, 'ip_address' => $ip, 'rate_down' => $rateDown, 'rate_up' => $rateUp,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'device.register.requested', 'Registrace zařízení byla zařazena', 'device', $deviceId, [
            'mac_address' => $mac, 'ip_address' => $ip, 'job_id' => $jobId,
        ], request_ip());
        flash('success', 'Registrace byla zařazena. Stav se bude průběžně aktualizovat.');
        redirect('/registrations');
    }

    public function update(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        $statement = $this->database->pdo()->prepare(
            'SELECT d.*, p.name AS person_name, p.note AS person_note FROM devices d
             LEFT JOIN people p ON p.id = d.person_id WHERE d.id = :id'
        );
        $statement->execute(['id' => $deviceId]);
        $device = $statement->fetch();
        if (!is_array($device) || ($device['registration_state'] ?? '') === 'archived') {
            throw new \RuntimeException('Zařízení nebylo nalezeno nebo je archivované.');
        }

        $settings = $this->settings->all();
        $personName = trim((string) ($_POST['person_name'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $deviceName = trim((string) ($_POST['device_name'] ?? ''));
        $ip = trim((string) ($_POST['ip_address'] ?? ''));
        $rateDown = strtoupper(trim((string) ($_POST['rate_down'] ?? $settings['default_rate_down'])));
        $rateUp = strtoupper(trim((string) ($_POST['rate_up'] ?? $settings['default_rate_up'])));

        if ($personName === '' || mb_strlen($personName) > 120) throw new \InvalidArgumentException('Zadejte jméno držitele zařízení.');
        if (mb_strlen($note) > 250) throw new \InvalidArgumentException('Poznámka může mít nejvýše 250 znaků.');
        if ($deviceName === '' || mb_strlen($deviceName) > 120) throw new \InvalidArgumentException('Zadejte název zařízení.');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !$this->ipInRange($ip, $settings['static_ip_start'], $settings['static_ip_end'])) {
            throw new \InvalidArgumentException('IP adresa není v povoleném statickém rozsahu.');
        }
        if (!preg_match('/^\d+(K|M|G)$/', $rateDown) || !preg_match('/^\d+(K|M|G)$/', $rateUp)) {
            throw new \InvalidArgumentException('Rychlost zadejte například jako 2M.');
        }

        $ipCheck = $this->database->pdo()->prepare(
            "SELECT COUNT(*) FROM devices WHERE current_ip = :ip AND id != :id AND registration_state != 'archived'"
        );
        $ipCheck->execute(['ip' => $ip, 'id' => $deviceId]);
        if ((int) $ipCheck->fetchColumn() > 0) throw new \RuntimeException('Tuto statickou IP už používá jiné evidované zařízení.');

        foreach ($this->database->pdo()->query('SELECT id, name FROM people WHERE active = 1')->fetchAll() as $person) {
            if (mb_strtolower(trim((string) $person['name'])) !== mb_strtolower($personName)) continue;
            if ((int) $person['id'] !== (int) ($device['person_id'] ?? 0)) {
                $count = $this->database->pdo()->prepare(
                    "SELECT COUNT(*) FROM devices WHERE person_id = :person_id AND id != :device_id AND registration_state != 'archived'"
                );
                $count->execute(['person_id' => $person['id'], 'device_id' => $deviceId]);
                if ((int) $count->fetchColumn() >= (int) ($settings['max_devices_per_person'] ?? 1)) {
                    throw new \RuntimeException('Vybraný držitel už má maximální povolený počet zařízení.');
                }
            }
            break;
        }

        $routerId = (int) $this->database->pdo()->query('SELECT id FROM routers WHERE enabled = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($routerId <= 0) throw new \RuntimeException('Nejprve nastavte připojení k MikroTiku.');
        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue($routerId, 'update_device', [
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'person_name' => $personName,
            'note' => $note,
            'mac_address' => (string) $device['mac_address'],
            'ip_address' => $ip,
            'rate_down' => $rateDown,
            'rate_up' => $rateUp,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'device.update.requested', 'Úprava zařízení byla zařazena', 'device', $deviceId, [
            'device_name' => $deviceName, 'ip_address' => $ip, 'rate_down' => $rateDown, 'rate_up' => $rateUp, 'job_id' => $jobId,
        ], request_ip());
        flash('success', 'Úprava zařízení byla předána workeru. Po dokončení se změní MikroTik i evidence.');
        redirect('/registrations');
    }

    /** @param array<string,string> $settings */
    private function nextFreeIp(array $settings): string
    {
        $used = [];
        foreach ($this->database->pdo()->query("SELECT current_ip AS ip FROM devices WHERE current_ip IS NOT NULL UNION SELECT ip_address AS ip FROM connected_clients WHERE ip_address IS NOT NULL UNION SELECT address AS ip FROM dhcp_leases_cache WHERE address IS NOT NULL")->fetchAll() as $row) {
            $used[(string) $row['ip']] = true;
        }
        $start = ip2long($settings['static_ip_start']);
        $end = ip2long($settings['static_ip_end']);
        if ($start === false || $end === false) return '';
        for ($candidate = $start; $candidate <= $end; $candidate++) {
            $ip = long2ip($candidate);
            if (!isset($used[$ip])) return $ip;
        }
        return '';
    }

    private function ipInRange(string $ip, string $start, string $end): bool
    {
        $value = ip2long($ip); $min = ip2long($start); $max = ip2long($end);
        return $value !== false && $min !== false && $max !== false && $value >= $min && $value <= $max;
    }
}
