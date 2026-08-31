<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Crypto;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\JobService;
use WifiManager\Services\RouterFactory;
use WifiManager\View;

final class NetworksController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly JobService $jobs,
        private readonly AuditService $audit,
        private readonly Crypto $crypto,
        private readonly RouterFactory $routerFactory,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $networks = $this->database->pdo()->query(
            'SELECT n.*, (SELECT COUNT(*) FROM connected_clients c WHERE c.ssid = n.ssid) AS client_count
             FROM wifi_networks n ORDER BY n.ssid, n.band'
        )->fetchAll();
        $approvedVlan = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'approved_vlan_id'")->fetchColumn();
        $registrationVlan = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'registration_vlan_id'")->fetchColumn();
        $this->view->render('networks', [
            'title' => 'Wi‑Fi sítě', 'activeNav' => 'networks', 'networks' => $networks,
            'approvedVlan' => $approvedVlan, 'registrationVlan' => $registrationVlan,
        ]);
    }

    public function toggle(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $id = (int) ($_POST['id'] ?? 0);
        $statement = $this->database->pdo()->prepare('SELECT * FROM wifi_networks WHERE id = :id');
        $statement->execute(['id' => $id]);
        $network = $statement->fetch();
        if (!is_array($network)) throw new \RuntimeException('Wi‑Fi síť nebyla nalezena.');
        $enable = !(bool) $network['enabled'];
        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue((int) $network['router_id'], 'toggle_network', [
            'mikrotik_id' => $network['mikrotik_id'], 'enable' => $enable,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'network.toggle.requested', ($enable ? 'Zapnutí' : 'Vypnutí') . ' Wi‑Fi ' . $network['ssid'], 'wifi_network', $id, ['job_id' => $jobId], request_ip());
        flash('success', 'Změna stavu Wi‑Fi byla zařazena.');
        redirect('/networks');
    }

    public function delete(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $id = (int) ($_POST['id'] ?? 0);
        $statement = $this->database->pdo()->prepare('SELECT * FROM wifi_networks WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $network = $statement->fetch();
        if (!is_array($network)) throw new \RuntimeException('Wi‑Fi profil nebyl nalezen.');

        $repository = $this->routerFactory->repository((int) $network['router_id']);
        $snapshot = $repository->fullSnapshot();
        $configName = (string) $network['config_name'];
        $changedRules = [];

        try {
            foreach ($snapshot['provisioning'] ?? [] as $rule) {
                if (!isset($rule['.id'])) continue;
                $slaves = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['slave-configurations'] ?? '')))));
                if (!in_array($configName, $slaves, true)) continue;
                $changedRules[(string) $rule['.id']] = (string) ($rule['slave-configurations'] ?? '');
                $slaves = array_values(array_filter($slaves, static fn (string $name): bool => $name !== $configName));
                $repository->set('/interface/wifi/provisioning', (string) $rule['.id'], ['slave-configurations' => implode(',', $slaves)]);
            }
            $repository->remove('/interface/wifi/configuration', (string) $network['mikrotik_id']);
        } catch (\Throwable $exception) {
            foreach ($changedRules as $ruleId => $original) {
                try {
                    $repository->set('/interface/wifi/provisioning', $ruleId, ['slave-configurations' => $original]);
                } catch (\Throwable) {
                }
            }
            throw $exception;
        }

        $this->database->pdo()->prepare('DELETE FROM wifi_networks WHERE id = :id')->execute(['id' => $id]);
        $user = $this->auth->user();
        $this->audit->log(
            (int) $user['id'],
            'network.deleted',
            'Wi‑Fi profil byl smazán: ' . $network['ssid'] . ' (' . $configName . ')',
            'wifi_network',
            $id,
            ['mikrotik_id' => $network['mikrotik_id']],
            request_ip(),
        );
        flash('success', 'Wi‑Fi profil byl odstraněn z MikroTiku i WiFi Manageru.');
        redirect('/networks');
    }

    public function password(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        try {
            $this->auth->requireAdmin();
            Csrf::enforce();
            $id = (int) ($_POST['id'] ?? 0);
            $statement = $this->database->pdo()->prepare(
                'SELECT id, ssid, password_cipher FROM wifi_networks WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $id]);
            $network = $statement->fetch();
            if (!is_array($network)) {
                throw new \RuntimeException('Wi‑Fi síť nebyla nalezena.');
            }
            if ((string) $network['password_cipher'] === '') {
                throw new \RuntimeException('Heslo této sítě zatím nebylo načteno z MikroTiku.');
            }

            $password = $this->crypto->decrypt((string) $network['password_cipher']);
            $user = $this->auth->user();
            $this->audit->log(
                (int) $user['id'],
                'network.password.viewed',
                'Zobrazení hesla Wi‑Fi ' . $network['ssid'],
                'wifi_network',
                (string) $network['id'],
                [],
                request_ip(),
            );
            echo json_encode(['ok' => true, 'password' => $password], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    public function store(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $name = trim((string) ($_POST['name'] ?? ''));
        $ssid = trim((string) ($_POST['ssid'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $vlanId = (int) ($_POST['vlan_id'] ?? 0);
        $band = (string) ($_POST['band'] ?? 'both');
        $registration = isset($_POST['registration_enabled']);
        if ($name === '' || mb_strlen($name) > 80) throw new \InvalidArgumentException('Zadejte interní název Wi‑Fi sítě.');
        if ($ssid === '' || mb_strlen($ssid) > 32) throw new \InvalidArgumentException('SSID musí mít 1 až 32 znaků.');
        if (strlen($password) < 8 || strlen($password) > 63) throw new \InvalidArgumentException('Wi‑Fi heslo musí mít 8 až 63 znaků.');
        if ($vlanId < 1 || $vlanId > 4094) throw new \InvalidArgumentException('VLAN ID musí být v rozsahu 1–4094.');
        if (!in_array($band, ['2ghz-ax', '5ghz-ax', 'both'], true)) throw new \InvalidArgumentException('Vyberte podporované pásmo.');
        if ($registration) {
            $vlanId = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'registration_vlan_id'")->fetchColumn();
        }
        $routerId = (int) $this->database->pdo()->query('SELECT id FROM routers WHERE enabled = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($routerId <= 0) throw new \RuntimeException('Nejprve nastavte MikroTik.');
        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue($routerId, 'create_network', [
            'name' => $name, 'ssid' => $ssid, 'password' => $password, 'vlan_id' => $vlanId,
            'band' => $band, 'registration_enabled' => $registration,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'network.create.requested', 'Vytvoření Wi‑Fi ' . $ssid . ' bylo zařazeno', 'wifi_network', null, ['job_id' => $jobId, 'vlan_id' => $vlanId], request_ip());
        flash('success', 'Vytvoření Wi‑Fi sítě bylo zařazeno do synchronizace.');
        redirect('/networks');
    }
}
