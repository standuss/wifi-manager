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

        // SQLite has no portable "ADD COLUMN IF NOT EXISTS". These idempotent
        // compatibility migrations preserve customer databases during updates.
        $this->addColumn('wifi_networks', 'password_cipher', "TEXT NOT NULL DEFAULT ''");
        $this->addColumn('admin_users', 'email', 'TEXT');
        $this->addColumn('admin_users', 'notify_new_device', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumn('admin_users', 'notify_backup_result', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumn('admin_users', 'notify_monitoring_problem', 'INTEGER NOT NULL DEFAULT 0');

        // Existing clients must be considered known. Otherwise the first sync
        // after an update would send a misleading notification storm.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO discovered_devices (router_id, mac_address, hostname, ip_address, ssid, first_seen_at, last_seen_at)
             SELECT router_id, mac_address, hostname, ip_address, ssid, first_seen_at, last_seen_at FROM connected_clients"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO discovered_devices (router_id, mac_address, hostname, ip_address, first_seen_at, last_seen_at)
             SELECT r.id, d.mac_address, d.name, d.current_ip, d.created_at, d.updated_at FROM routers r CROSS JOIN devices d"
        );
        $this->pdo->exec("INSERT INTO schema_meta (key, value) VALUES ('version', '4') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $columns, true)) {
            $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
        }
    }
}
