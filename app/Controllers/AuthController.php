<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Services\AuditService;
use WifiManager\View;

final class AuthController
{
    public function __construct(private readonly Auth $auth, private readonly View $view, private readonly AuditService $audit)
    {
    }

    public function showLogin(): void
    {
        if ($this->auth->check()) redirect('/');
        $this->view->render('login', ['title' => 'Přihlášení'], false);
    }

    public function login(): void
    {
        Csrf::enforce();
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($this->auth->attempt($username, (string) ($_POST['password'] ?? ''), request_ip())) {
            $user = $this->auth->user();
            $this->audit->log((int) $user['id'], 'auth.login', 'Přihlášení do administrace', 'admin_user', $user['id'], [], request_ip());
            redirect('/');
        }
        flash('error', 'Přihlášení se nezdařilo. Zkontrolujte jméno a heslo.');
        redirect('/login');
    }

    public function logout(): void
    {
        Csrf::enforce();
        $user = $this->auth->user();
        if ($user) $this->audit->log((int) $user['id'], 'auth.logout', 'Odhlášení z administrace', 'admin_user', $user['id'], [], request_ip());
        $this->auth->logout();
        redirect('/login');
    }
}

