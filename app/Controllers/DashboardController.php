<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Database;
use WifiManager\View;

final class DashboardController
{
    public function __construct(private readonly Database $database, private readonly Auth $auth, private readonly View $view)
    {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $pdo = $this->database->pdo();
        $router = $pdo->query('SELECT * FROM routers ORDER BY id LIMIT 1')->fetch() ?: null;
        $stats = [
            'clients' => (int) $pdo->query('SELECT COUNT(*) FROM connected_clients')->fetchColumn(),
            'pending' => (int) $pdo->query("SELECT COUNT(*) FROM connected_clients WHERE registration_status = 'pending'")->fetchColumn(),
            'registered' => (int) $pdo->query("SELECT COUNT(*) FROM devices WHERE registration_state = 'registered'")->fetchColumn(),
            'aps_online' => (int) $pdo->query("SELECT COUNT(*) FROM access_points WHERE status = 'online'")->fetchColumn(),
            'networks_enabled' => (int) $pdo->query('SELECT COUNT(DISTINCT ssid) FROM wifi_networks WHERE enabled = 1')->fetchColumn(),
        ];
        $clients = $pdo->query(
            'SELECT c.*, d.name AS device_name, p.name AS person_name
             FROM connected_clients c
             LEFT JOIN devices d ON d.id = c.device_id
             LEFT JOIN people p ON p.id = d.person_id
             ORDER BY CASE c.registration_status WHEN \'pending\' THEN 0 WHEN \'incomplete\' THEN 1 ELSE 2 END, c.signal_dbm DESC
             LIMIT 12'
        )->fetchAll();
        $jobs = $pdo->query(
            "SELECT id, type, status, progress, last_error, created_at FROM sync_jobs WHERE status IN ('pending','running','failed') ORDER BY id DESC LIMIT 6"
        )->fetchAll();

        $this->view->render('dashboard', compact('router', 'stats', 'clients', 'jobs') + [
            'title' => 'Přehled', 'activeNav' => 'dashboard',
        ]);
    }

    public function live(): void
    {
        $this->auth->requireLogin();
        header('Content-Type: application/json; charset=utf-8');
        $pdo = $this->database->pdo();
        $router = $pdo->query('SELECT status, last_sync_at, last_error FROM routers ORDER BY id LIMIT 1')->fetch() ?: null;
        $clients = $pdo->query(
            'SELECT c.*, d.name AS device_name, p.name AS person_name FROM connected_clients c
             LEFT JOIN devices d ON d.id = c.device_id LEFT JOIN people p ON p.id = d.person_id
             ORDER BY CASE c.registration_status WHEN \'pending\' THEN 0 WHEN \'incomplete\' THEN 1 ELSE 2 END, c.signal_dbm DESC'
        )->fetchAll();
        echo json_encode([
            'router' => $router,
            'clients' => $clients,
            'counts' => [
                'clients' => count($clients),
                'pending' => count(array_filter($clients, static fn(array $row): bool => $row['registration_status'] === 'pending')),
            ],
            'generated_at' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

