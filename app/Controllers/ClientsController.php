<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\RouterFactory;
use WifiManager\View;

final class ClientsController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly RouterFactory $routerFactory,
        private readonly AuditService $audit,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $clients = $this->database->pdo()->query(
            'SELECT c.*, d.name AS device_name, d.registration_state AS device_state, p.name AS person_name
             FROM connected_clients c
             LEFT JOIN devices d ON d.id = c.device_id
             LEFT JOIN people p ON p.id = d.person_id
             ORDER BY c.last_seen_at DESC, c.signal_dbm DESC'
        )->fetchAll();
        $this->view->render('clients', [
            'title' => 'Připojená zařízení', 'activeNav' => 'clients', 'clients' => $clients,
        ]);
    }

    public function disconnect(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $mac = normalize_mac((string) ($_POST['mac_address'] ?? ''));

        $statement = $this->database->pdo()->prepare(
            'SELECT router_id, mac_address FROM connected_clients WHERE mac_address = :mac LIMIT 1'
        );
        $statement->execute(['mac' => $mac]);
        $client = $statement->fetch();
        if (!is_array($client)) throw new \RuntimeException('Zařízení už není připojené.');

        $repository = $this->routerFactory->repository((int) $client['router_id']);
        $found = false;
        foreach ($repository->rows('/interface/wifi/registration-table/print', ['.id', 'mac-address']) as $row) {
            if (!isset($row['.id'], $row['mac-address'])) continue;
            try {
                $candidate = normalize_mac((string) $row['mac-address']);
            } catch (\Throwable) {
                continue;
            }
            if ($candidate !== $mac) continue;
            $repository->remove('/interface/wifi/registration-table', (string) $row['.id']);
            $found = true;
        }
        if (!$found) throw new \RuntimeException('Aktuální Wi‑Fi spojení už na MikroTiku nebylo nalezeno.');

        $this->database->pdo()->prepare('DELETE FROM connected_clients WHERE router_id = :router_id AND mac_address = :mac')
            ->execute(['router_id' => (int) $client['router_id'], 'mac' => $mac]);

        $user = $this->auth->user();
        $this->audit->log((int) $user['id'], 'client.disconnect', 'Aktuální Wi‑Fi spojení bylo odpojeno', 'client', $mac, [], request_ip());
        flash('success', 'Zařízení bylo odpojeno. Klient se může automaticky připojit znovu.');
        redirect('/clients');
    }
}
