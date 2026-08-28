<?php

declare(strict_types=1);

namespace WifiManager;

use PDO;

final class Auth
{
    private ?array $user = null;

    public function __construct(private readonly Database $database)
    {
    }

    public function attempt(string $username, string $password, string $ip): bool
    {
        $username = mb_strtolower(trim($username));
        if ($username === '' || $password === '' || $this->isRateLimited($username, $ip)) {
            $this->recordAttempt($username, $ip, false);
            return false;
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM admin_users WHERE username_normalized = :username AND active = 1 LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        $valid = is_array($user) && password_verify($password, (string) $user['password_hash']);
        $this->recordAttempt($username, $ip, $valid);
        if (!$valid) {
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], password_algorithm())) {
            $rehash = $this->database->pdo()->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
            $rehash->execute(['hash' => password_hash($password, password_algorithm()), 'id' => $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $this->user = $user;
        $update = $this->database->pdo()->prepare('UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['id' => $user['id']]);
        return true;
    }

    public function logout(): void
    {
        $this->user = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT id, username, display_name, role, active, last_login_at FROM admin_users WHERE id = :id AND active = 1'
        );
        $statement->execute(['id' => (int) $id]);
        $user = $statement->fetch();
        $this->user = is_array($user) ? $user : null;
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? null) === 'admin';
    }

    public function requireLogin(): void
    {
        if (!$this->check()) {
            redirect('/login');
        }
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            http_response_code(403);
            throw new \RuntimeException('K této operaci nemáte oprávnění.');
        }
    }

    private function isRateLimited(string $username, string $ip): bool
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND attempted_at >= datetime('now', '-15 minutes')
             AND (username_normalized = :username OR ip_address = :ip)"
        );
        $statement->execute(['username' => $username, 'ip' => $ip]);
        return (int) $statement->fetchColumn() >= 8;
    }

    private function recordAttempt(string $username, string $ip, bool $success): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO login_attempts (username_normalized, ip_address, success) VALUES (:username, :ip, :success)'
        );
        $statement->execute(['username' => $username, 'ip' => $ip, 'success' => $success ? 1 : 0]);
        $this->database->pdo()->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-2 days')");
    }
}
