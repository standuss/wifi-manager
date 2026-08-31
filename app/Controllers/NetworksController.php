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
            'SELECT n.*, (SELECT COUNT(*) FROM connected_clients c WHERE c.router_id=n.router_id AND c.capsman_type=n.capsman_type AND c.ssid = n.ssid) AS client_count
             FROM wifi_networks n ORDER BY n.ssid, n.band'
        )->fetchAll();
        $approvedVlan = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'approved_vlan_id'")->fetchColumn();
        $registrationVlan = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'registration_vlan_id'")->fetchColumn();
        $routerTypes = (string) $this->database->pdo()->query("SELECT capsman_types FROM routers WHERE enabled = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $capsmanTypes = array_values(array_intersect(['wifi', 'legacy'], array_filter(array_map('trim', explode(',', $routerTypes)))));
        if ($capsmanTypes === []) $capsmanTypes = ['wifi'];
        $this->view->render('networks', [
            'title' => 'Wi‑Fi sítě', 'activeNav' => 'networks', 'networks' => $networks,
            'approvedVlan' => $approvedVlan, 'registrationVlan' => $registrationVlan, 'capsmanTypes' => $capsmanTypes,
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
            'network_id' => $id, 'mikrotik_id' => $network['mikrotik_raw_id'] ?: $network['mikrotik_id'],
            'capsman_type' => $network['capsman_type'] ?: 'wifi', 'enable' => $enable,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'network.toggle.requested', ($enable ? 'Zapnutí' : 'Vypnutí') . ' Wi‑Fi ' . $network['ssid'], 'wifi_network', $id, ['job_id' => $jobId], request_ip());
        flash('success', 'Změna stavu Wi‑Fi byla zařazena.');
        redirect('/networks');
    }

    public function hidden(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $network = $this->network((int) ($_POST['id'] ?? 0));
        $hidden = !(bool) $network['hidden'];
        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue((int) $network['router_id'], 'set_network_hidden', [
            'network_id' => (int) $network['id'], 'mikrotik_id' => $network['mikrotik_raw_id'] ?: $network['mikrotik_id'],
            'capsman_type' => $network['capsman_type'] ?: 'wifi', 'hidden' => $hidden,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'network.visibility.requested', ($hidden ? 'Skrytí' : 'Zobrazení') . ' SSID ' . $network['ssid'], 'wifi_network', (int) $network['id'], ['job_id' => $jobId], request_ip());
        flash('success', 'Změna viditelnosti SSID byla zařazena do synchronizace.');
        redirect('/networks');
    }

    public function resolveConflict(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $network = $this->network((int) ($_POST['id'] ?? 0));
        $choice = (string) ($_POST['choice'] ?? '');
        $user = $this->auth->user();
        if ($choice === 'router') {
            $this->database->pdo()->prepare(
                "UPDATE wifi_networks SET managed=1, desired_json=remote_json, sync_state='synced', conflict_summary=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=:id"
            )->execute(['id' => $network['id']]);
            $this->audit->log((int) $user['id'], 'network.conflict.router_kept', 'Převzata konfigurace sítě z MikroTiku: ' . $network['ssid'], 'wifi_network', (int) $network['id'], [], request_ip());
            flash('success', 'Stav z MikroTiku byl přijat jako nový požadovaný stav.');
        } elseif ($choice === 'manager') {
            $desired = json_decode((string) ($network['desired_json'] ?? ''), true);
            if (!is_array($desired)) throw new \RuntimeException('WiFi Manager nemá uložený požadovaný stav této sítě.');
            $jobId = $this->jobs->enqueue((int) $network['router_id'], 'apply_network', [
                'network_id' => (int) $network['id'], 'mikrotik_id' => $network['mikrotik_raw_id'] ?: $network['mikrotik_id'],
                'capsman_type' => $network['capsman_type'] ?: 'wifi', 'desired' => $desired,
            ], (int) $user['id']);
            $this->audit->log((int) $user['id'], 'network.conflict.manager_requested', 'Obnovení sítě podle WiFi Manageru bylo zařazeno: ' . $network['ssid'], 'wifi_network', (int) $network['id'], ['job_id' => $jobId], request_ip());
            flash('success', 'Obnovení požadovaného stavu bylo zařazeno do synchronizace.');
        } else {
            throw new \InvalidArgumentException('Vyberte, který stav chcete zachovat.');
        }
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
        $type = (string) ($network['capsman_type'] ?? 'wifi') === 'legacy' ? 'legacy' : 'wifi';
        $configMenu = $repository->menu($type, 'configuration');
        $provisioningMenu = $repository->menu($type, 'provisioning');
        $changedRules = [];

        try {
            foreach ($snapshot['provisioning'] ?? [] as $rule) {
                if ((string) ($rule['_capsman_type'] ?? 'wifi') !== $type) continue;
                if (!isset($rule['.id'])) continue;
                $slaves = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['slave-configurations'] ?? '')))));
                if (!in_array($configName, $slaves, true)) continue;
                $changedRules[(string) $rule['.id']] = (string) ($rule['slave-configurations'] ?? '');
                $slaves = array_values(array_filter($slaves, static fn (string $name): bool => $name !== $configName));
                $repository->set($provisioningMenu, (string) $rule['.id'], ['slave-configurations' => implode(',', $slaves)]);
            }
            $repository->remove($configMenu, (string) ($network['mikrotik_raw_id'] ?: $network['mikrotik_id']));
        } catch (\Throwable $exception) {
            foreach ($changedRules as $ruleId => $original) {
                try {
                    $repository->set($provisioningMenu, $ruleId, ['slave-configurations' => $original]);
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
            ['mikrotik_id' => $network['mikrotik_raw_id'] ?: $network['mikrotik_id'], 'capsman_type' => $type],
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
        $capsmanType = (string) ($_POST['capsman_type'] ?? 'wifi') === 'legacy' ? 'legacy' : 'wifi';
        $hidden = isset($_POST['hidden']);
        $registration = isset($_POST['registration_enabled']);
        if ($name === '' || mb_strlen($name) > 80) throw new \InvalidArgumentException('Zadejte interní název Wi‑Fi sítě.');
        if ($ssid === '' || mb_strlen($ssid) > 32) throw new \InvalidArgumentException('SSID musí mít 1 až 32 znaků.');
        if (strlen($password) < 8 || strlen($password) > 63) throw new \InvalidArgumentException('Wi‑Fi heslo musí mít 8 až 63 znaků.');
        if ($vlanId < 1 || $vlanId > 4094) throw new \InvalidArgumentException('VLAN ID musí být v rozsahu 1–4094.');
        if (!in_array($band, ['2ghz', '5ghz', 'both'], true)) throw new \InvalidArgumentException('Vyberte podporované pásmo.');
        if ($registration) {
            $vlanId = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'registration_vlan_id'")->fetchColumn();
        }
        $router = $this->database->pdo()->query('SELECT id,capsman_types FROM routers WHERE enabled = 1 ORDER BY id LIMIT 1')->fetch();
        if (!is_array($router)) throw new \RuntimeException('Nejprve nastavte MikroTik.');
        $routerId = (int) $router['id'];
        $supportedTypes = array_filter(array_map('trim', explode(',', (string) ($router['capsman_types'] ?? ''))));
        if ($supportedTypes !== [] && !in_array($capsmanType, $supportedTypes, true)) {
            throw new \RuntimeException('Vybraný typ CAPsMANu není na tomto MikroTiku dostupný.');
        }
        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue($routerId, 'create_network', [
            'name' => $name, 'ssid' => $ssid, 'password' => $password, 'vlan_id' => $vlanId,
            'band' => $band, 'registration_enabled' => $registration, 'capsman_type' => $capsmanType, 'hidden' => $hidden,
            'router_id' => $routerId,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'network.create.requested', 'Vytvoření Wi‑Fi ' . $ssid . ' bylo zařazeno', 'wifi_network', null, ['job_id' => $jobId, 'vlan_id' => $vlanId, 'capsman_type' => $capsmanType, 'hidden' => $hidden], request_ip());
        flash('success', 'Vytvoření Wi‑Fi sítě bylo zařazeno do synchronizace.');
        redirect('/networks');
    }

    /** @return array<string,mixed> */
    private function network(int $id): array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM wifi_networks WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $id]);
        $network = $statement->fetch();
        if (!is_array($network)) throw new \RuntimeException('Wi‑Fi síť nebyla nalezena.');
        return $network;
    }
}
