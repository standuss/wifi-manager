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
        $this->addColumn('devices', 'rate_down', "TEXT NOT NULL DEFAULT ''");
        $this->addColumn('devices', 'rate_up', "TEXT NOT NULL DEFAULT ''");
        $this->addColumn('routers', 'capsman_types', "TEXT NOT NULL DEFAULT ''");
        $this->addColumn('devices', 'capsman_type', "TEXT NOT NULL DEFAULT 'wifi'");
        $this->addColumn('wifi_networks', 'mikrotik_raw_id', 'TEXT');
        $this->addColumn('wifi_networks', 'capsman_type', "TEXT NOT NULL DEFAULT 'wifi'");
        $this->addColumn('wifi_networks', 'hidden', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumn('wifi_networks', 'desired_json', 'TEXT');
        $this->addColumn('wifi_networks', 'remote_json', 'TEXT');
        $this->addColumn('wifi_networks', 'conflict_summary', 'TEXT');
        $this->addColumn('access_points', 'mikrotik_raw_id', 'TEXT');
        $this->addColumn('access_points', 'capsman_type', "TEXT NOT NULL DEFAULT 'wifi'");
        $this->addColumn('wifi_radios_cache', 'mikrotik_raw_id', 'TEXT');
        $this->addColumn('wifi_radios_cache', 'capsman_type', "TEXT NOT NULL DEFAULT 'wifi'");
        $this->addColumn('connected_clients', 'capsman_type', "TEXT NOT NULL DEFAULT 'wifi'");
        $this->addColumn('wifi_sessions', 'router_id', 'INTEGER REFERENCES routers(id) ON DELETE SET NULL');
        $this->addColumn('wifi_sessions', 'capsman_type', "TEXT NOT NULL DEFAULT 'wifi'");
        $this->addColumn('wifi_sessions', 'disconnect_reason', 'TEXT');
        $this->addColumn('wifi_sessions', 'source', "TEXT NOT NULL DEFAULT 'api'");

        // Before schema v6 only the new WiFi CAPsMAN was supported and cache
        // identifiers had no namespace. Keep those rows (including desired
        // state and encrypted passwords) while making room for legacy records
        // that may use the same internal RouterOS identifiers.
        $this->pdo->exec(
            "UPDATE wifi_networks
             SET mikrotik_raw_id = COALESCE(mikrotik_raw_id, mikrotik_id),
                 mikrotik_id = 'wifi:' || mikrotik_id,
                 capsman_type = 'wifi'
             WHERE mikrotik_id NOT LIKE 'wifi:%' AND mikrotik_id NOT LIKE 'legacy:%'"
        );
        $this->pdo->exec(
            "UPDATE access_points
             SET mikrotik_id = 'wifi:' || mikrotik_id, capsman_type = 'wifi'
             WHERE mikrotik_id NOT LIKE 'wifi:%' AND mikrotik_id NOT LIKE 'legacy:%'"
        );
        $this->pdo->exec(
            "UPDATE wifi_radios_cache
             SET mikrotik_id = 'wifi:' || mikrotik_id, capsman_type = 'wifi'
             WHERE mikrotik_id NOT LIKE 'wifi:%' AND mikrotik_id NOT LIKE 'legacy:%'"
        );

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
        $this->pdo->exec("INSERT INTO schema_meta (key, value) VALUES ('version', '6') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $columns, true)) {
            $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
        }
    }
}
