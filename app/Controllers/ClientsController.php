<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Database;
use WifiManager\View;

final class ClientsController
{
    public function __construct(private readonly Database $database, private readonly Auth $auth, private readonly View $view)
    {
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
}

