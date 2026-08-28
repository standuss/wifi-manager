<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Crypto;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\RouterFactory;
use WifiManager\Services\SettingsService;
use WifiManager\View;

final class SettingsController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly SettingsService $settings,
        private readonly Crypto $crypto,
        private readonly RouterFactory $routerFactory,
        private readonly AuditService $audit,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAdmin();
        $router = $this->database->pdo()->query('SELECT * FROM routers ORDER BY id LIMIT 1')->fetch() ?: [
            'id' => null, 'name' => 'Hlavní MikroTik', 'host' => '', 'port' => 8728,
            'username' => 'wifimanager',
            'status' => 'unconfigured', 'last_sync_at' => null, 'last_error' => null,
        ];
        $this->view->render('settings', [
            'title' => 'Nastavení', 'activeNav' => 'settings', 'router' => $router, 'settings' => $this->settings->all(),
        ]);
    }

    public function save(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $name = trim((string) ($_POST['name'] ?? 'MikroTik'));
        $host = trim((string) ($_POST['host'] ?? ''));
        $port = (int) ($_POST['port'] ?? 8728);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $action = (string) ($_POST['action'] ?? 'save');

        if ($name === '' || $host === '' || $username === '') throw new \InvalidArgumentException('Vyplňte název, adresu a uživatelské jméno MikroTiku.');
        if ($port < 1 || $port > 65535) throw new \InvalidArgumentException('Port API není platný.');

        $existing = $this->database->pdo()->query('SELECT * FROM routers ORDER BY id LIMIT 1')->fetch() ?: null;
        if ($password === '' && !$existing) throw new \InvalidArgumentException('Při prvním nastavení zadejte heslo API účtu.');
        $passwordCipher = $password !== '' ? $this->crypto->encrypt($password) : (string) $existing['password_cipher'];

        $this->database->transaction(function () use ($existing, $name, $host, $port, $username, $passwordCipher): void {
            if ($existing) {
                $statement = $this->database->pdo()->prepare(
                    "UPDATE routers SET name = :name, host = :host, port = :port, username = :username, password_cipher = :password,
                     status = 'unconfigured', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
                );
                $statement->execute([
                    'name' => $name, 'host' => $host, 'port' => $port, 'username' => $username, 'password' => $passwordCipher,
                    'id' => $existing['id'],
                ]);
            } else {
                $statement = $this->database->pdo()->prepare(
                    'INSERT INTO routers (site_id, name, host, port, username, password_cipher)
                     VALUES (1, :name, :host, :port, :username, :password)'
                );
                $statement->execute([
                    'name' => $name, 'host' => $host, 'port' => $port, 'username' => $username, 'password' => $passwordCipher,
                ]);
            }
        });

        $this->settings->save([
            'approved_vlan_id' => (int) ($_POST['approved_vlan_id'] ?? 10),
            'registration_vlan_id' => (int) ($_POST['registration_vlan_id'] ?? 20),
            'approved_dhcp_server' => trim((string) ($_POST['approved_dhcp_server'] ?? 'dhcp_wifi')),
            'registration_dhcp_server' => trim((string) ($_POST['registration_dhcp_server'] ?? 'dhcp_wifi_registration')),
            'static_ip_start' => trim((string) ($_POST['static_ip_start'] ?? '192.168.10.2')),
            'static_ip_end' => trim((string) ($_POST['static_ip_end'] ?? '192.168.10.99')),
            'default_rate_down' => strtoupper(trim((string) ($_POST['default_rate_down'] ?? '2M'))),
            'default_rate_up' => strtoupper(trim((string) ($_POST['default_rate_up'] ?? '2M'))),
            'max_devices_per_person' => max(1, (int) ($_POST['max_devices_per_person'] ?? 1)),
        ]);

        $routerId = (int) $this->database->pdo()->query('SELECT id FROM routers ORDER BY id LIMIT 1')->fetchColumn();
        if ($action === 'test') {
            $result = $this->routerFactory->repository($routerId)->testConnection();
            $identity = $result['identity']['name'] ?? 'MikroTik';
            $resource = $result['resource'];
            $this->database->pdo()->prepare(
                "UPDATE routers SET identity = :identity, model = :model, routeros_version = :version, status = 'online', last_error = NULL, last_sync_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([
                'identity' => $identity, 'model' => $resource['board-name'] ?? null,
                'version' => $resource['version'] ?? null, 'id' => $routerId,
            ]);
            flash('success', 'Spojení s MikroTikem je funkční: ' . $identity . '.');
        } else {
            flash('success', 'Nastavení bylo uloženo.');
        }

        $this->audit->log((int) $this->auth->user()['id'], 'settings.updated', 'Bylo změněno nastavení WiFi Manageru', 'router', $routerId, ['host' => $host, 'port' => $port], request_ip());
        redirect('/settings');
    }
}
