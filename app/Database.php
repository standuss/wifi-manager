<?php

declare(strict_types=1);

namespace WifiManager;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $path = (string) $config->get('database.path');
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nelze vytvořit adresář databáze.');
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = ' . (int) $config->get('database.busy_timeout_ms', 5000));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function migrate(string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new \RuntimeException('Nelze načíst databázové schéma.');
        }
        $this->pdo->exec($schema);

        // SQLite has no portable "ADD COLUMN IF NOT EXISTS". Keep this small
        // compatibility migration here so upgrades preserve the existing DB.
        $columns = $this->pdo->query('PRAGMA table_info(wifi_networks)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('password_cipher', $columns, true)) {
            $this->pdo->exec("ALTER TABLE wifi_networks ADD COLUMN password_cipher TEXT NOT NULL DEFAULT ''");
        }
        $this->pdo->exec("INSERT INTO schema_meta (key, value) VALUES ('version', '3') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    }
}
