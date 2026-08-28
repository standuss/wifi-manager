<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Database;
use WifiManager\View;

final class AccessPointsController
{
    public function __construct(private readonly Database $database, private readonly Auth $auth, private readonly View $view)
    {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $aps = $this->database->pdo()->query(
            'SELECT a.*, (SELECT COUNT(*) FROM connected_clients c WHERE c.access_point_name = a.name) AS client_count FROM access_points a ORDER BY a.status DESC, COALESCE(a.custom_name, a.name)'
        )->fetchAll();
        $this->view->render('access_points', [
            'title' => 'Přístupové body', 'activeNav' => 'access-points', 'aps' => $aps,
        ]);
    }
}

