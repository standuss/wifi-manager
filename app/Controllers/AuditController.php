<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Database;
use WifiManager\View;

final class AuditController
{
    public function __construct(private readonly Database $database, private readonly Auth $auth, private readonly View $view)
    {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $entries = $this->database->pdo()->query(
            'SELECT a.*, u.display_name AS user_name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300'
        )->fetchAll();
        $this->view->render('audit', ['title' => 'Historie změn', 'activeNav' => 'audit', 'entries' => $entries]);
    }
}
