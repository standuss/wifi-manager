<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\View;

final class UsersController
{
    public function __construct(private readonly Database $database, private readonly Auth $auth, private readonly View $view, private readonly AuditService $audit)
    {
    }

    public function index(): void
    {
        $this->auth->requireAdmin();
        $users = $this->database->pdo()->query('SELECT id, username, display_name, email, role, active, notify_new_device, notify_backup_result, notify_monitoring_problem, last_login_at, created_at FROM admin_users ORDER BY active DESC, display_name')->fetchAll();
        $this->view->render('users', ['title' => 'Uživatelé administrace', 'activeNav' => 'users', 'users' => $users]);
    }

    public function store(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $username = trim((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'viewer');
        $email = $this->email($_POST['email'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) throw new \InvalidArgumentException('Přihlašovací jméno musí mít 3–50 povolených znaků.');
        if ($displayName === '') throw new \InvalidArgumentException('Zadejte zobrazované jméno.');
        if (mb_strlen($password) < 10) throw new \InvalidArgumentException('Heslo musí mít alespoň 10 znaků.');
        if (!in_array($role, ['admin', 'viewer'], true)) throw new \InvalidArgumentException('Neplatná uživatelská role.');
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO admin_users (username, username_normalized, display_name, email, password_hash, role, notify_new_device, notify_backup_result, notify_monitoring_problem)
             VALUES (:username, :normalized, :display_name, :email, :password_hash, :role, :new_device, :backup, :monitoring)'
        );
        $statement->execute([
            'username' => $username, 'normalized' => mb_strtolower($username), 'display_name' => $displayName,
            'email' => $email, 'password_hash' => password_hash($password, password_algorithm()), 'role' => $role,
            'new_device' => isset($_POST['notify_new_device']) ? 1 : 0,
            'backup' => isset($_POST['notify_backup_result']) ? 1 : 0,
            'monitoring' => isset($_POST['notify_monitoring_problem']) ? 1 : 0,
        ]);
        $id = (int) $this->database->pdo()->lastInsertId();
        $this->audit->log((int) $this->auth->user()['id'], 'admin_user.created', 'Byl vytvořen účet ' . $username, 'admin_user', $id, ['role' => $role], request_ip());
        flash('success', 'Uživatelský účet byl vytvořen.');
        redirect('/users');
    }

    public function update(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $id = (int) ($_POST['id'] ?? 0);
        $statement = $this->database->pdo()->prepare('SELECT * FROM admin_users WHERE id=:id');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        if (!is_array($user)) throw new \RuntimeException('Uživatel nebyl nalezen.');
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        if ($displayName === '') throw new \InvalidArgumentException('Zadejte zobrazované jméno.');
        $email = $this->email($_POST['email'] ?? '');
        $role = (string) ($_POST['role'] ?? 'viewer');
        if (!in_array($role, ['admin', 'viewer'], true)) throw new \InvalidArgumentException('Neplatná uživatelská role.');
        if ($id === (int) $this->auth->user()['id'] && $role !== 'admin') throw new \RuntimeException('Vlastnímu účtu nelze odebrat oprávnění administrátora.');
        if ($user['role'] === 'admin' && $role !== 'admin' && (bool) $user['active']) {
            $count = (int) $this->database->pdo()->query("SELECT COUNT(*) FROM admin_users WHERE role='admin' AND active=1")->fetchColumn();
            if ($count <= 1) throw new \RuntimeException('Poslední aktivní administrátor musí zůstat administrátorem.');
        }
        $this->database->pdo()->prepare(
            'UPDATE admin_users SET display_name=:name, email=:email, role=:role, notify_new_device=:new_device,
             notify_backup_result=:backup, notify_monitoring_problem=:monitoring, updated_at=CURRENT_TIMESTAMP WHERE id=:id'
        )->execute([
            'name' => $displayName, 'email' => $email, 'role' => $role,
            'new_device' => isset($_POST['notify_new_device']) ? 1 : 0,
            'backup' => isset($_POST['notify_backup_result']) ? 1 : 0,
            'monitoring' => isset($_POST['notify_monitoring_problem']) ? 1 : 0,
            'id' => $id,
        ]);
        $this->audit->log((int) $this->auth->user()['id'], 'admin_user.updated', 'Byla upravena nastavení účtu ' . $user['username'], 'admin_user', $id, ['email' => $email, 'role' => $role], request_ip());
        flash('success', 'Uživatel a jeho e-mailová oznámení byly upraveny.');
        redirect('/users');
    }

    public function toggle(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $this->auth->user()['id']) throw new \RuntimeException('Vlastní účet nelze deaktivovat.');
        $statement = $this->database->pdo()->prepare('SELECT * FROM admin_users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        if (!is_array($user)) throw new \RuntimeException('Uživatel nebyl nalezen.');
        if ($user['role'] === 'admin' && (bool) $user['active']) {
            $count = (int) $this->database->pdo()->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active = 1")->fetchColumn();
            if ($count <= 1) throw new \RuntimeException('Poslední aktivní administrátor nesmí být deaktivován.');
        }
        $active = (bool) $user['active'] ? 0 : 1;
        $this->database->pdo()->prepare('UPDATE admin_users SET active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['active' => $active, 'id' => $id]);
        $this->audit->log((int) $this->auth->user()['id'], 'admin_user.toggled', ($active ? 'Aktivován' : 'Deaktivován') . ' účet ' . $user['username'], 'admin_user', $id, [], request_ip());
        flash('success', $active ? 'Účet byl aktivován.' : 'Účet byl deaktivován.');
        redirect('/users');
    }

    private function email(mixed $value): ?string
    {
        $email = trim((string) $value);
        if ($email === '') return null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('E-mailová adresa není platná.');
        return mb_substr($email, 0, 254);
    }
}
