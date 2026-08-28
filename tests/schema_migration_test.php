<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Config.php';
require dirname(__DIR__) . '/app/Database.php';

use WifiManager\Config;
use WifiManager\Database;

$path = sys_get_temp_dir() . '/wfm-schema-' . bin2hex(random_bytes(6)) . '.sqlite';
try {
    $database = new Database(new Config(['database' => ['path' => $path, 'busy_timeout_ms' => 1000]]));
    $database->pdo()->exec(
        'CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, username_normalized TEXT, display_name TEXT, password_hash TEXT, role TEXT, active INTEGER, last_login_at TEXT, created_at TEXT, updated_at TEXT);
         CREATE TABLE devices (
            id INTEGER PRIMARY KEY, person_id INTEGER, name TEXT NOT NULL, mac_address TEXT NOT NULL UNIQUE, current_ip TEXT,
            registration_state TEXT NOT NULL DEFAULT "pending", mikrotik_access_id TEXT, mikrotik_lease_id TEXT,
            mikrotik_queue_id TEXT, registered_at TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT
         )'
    );
    $database->migrate(dirname(__DIR__) . '/database/schema.sql');
    $columns = $database->pdo()->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['email', 'notify_new_device', 'notify_backup_result', 'notify_monitoring_problem'] as $column) {
        if (!in_array($column, $columns, true)) throw new RuntimeException('Migrace nepřidala sloupec ' . $column . '.');
    }
    $deviceColumns = $database->pdo()->query('PRAGMA table_info(devices)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['rate_down', 'rate_up'] as $column) {
        if (!in_array($column, $deviceColumns, true)) throw new RuntimeException('Migrace nepřidala sloupec zařízení ' . $column . '.');
    }
    foreach (['notification_queue', 'discovered_devices', 'router_backups'] as $table) {
        $statement = $database->pdo()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name");
        $statement->execute(['name' => $table]);
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Migrace nevytvořila tabulku ' . $table . '.');
    }
    echo "Schema migration test OK\n";
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
