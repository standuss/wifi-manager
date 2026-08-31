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
        'CREATE TABLE routers (id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, host TEXT NOT NULL, port INTEGER NOT NULL DEFAULT 8728);
         INSERT INTO routers (id,site_id,host,port) VALUES (1,1,"192.0.2.1",8728);
         CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, username_normalized TEXT, display_name TEXT, password_hash TEXT, role TEXT, active INTEGER, last_login_at TEXT, created_at TEXT, updated_at TEXT);
         CREATE TABLE devices (
            id INTEGER PRIMARY KEY, person_id INTEGER, name TEXT NOT NULL, mac_address TEXT NOT NULL UNIQUE, current_ip TEXT,
            registration_state TEXT NOT NULL DEFAULT "pending", mikrotik_access_id TEXT, mikrotik_lease_id TEXT,
            mikrotik_queue_id TEXT, registered_at TEXT, archived_at TEXT, created_at TEXT, updated_at TEXT
         );
         CREATE TABLE wifi_networks (
            id INTEGER PRIMARY KEY, router_id INTEGER NOT NULL, mikrotik_id TEXT NOT NULL, config_name TEXT NOT NULL,
            ssid TEXT NOT NULL, band TEXT, vlan_id INTEGER, registration_enabled INTEGER NOT NULL DEFAULT 0,
            registration_vlan_id INTEGER, enabled INTEGER NOT NULL DEFAULT 1, managed INTEGER NOT NULL DEFAULT 0,
            source_hash TEXT, sync_state TEXT NOT NULL DEFAULT "synced", last_seen_at TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(router_id,mikrotik_id)
         );
         INSERT INTO wifi_networks (id,router_id,mikrotik_id,config_name,ssid) VALUES (1,1,"*1","cfg","ssid");
         CREATE TABLE access_points (id INTEGER PRIMARY KEY, router_id INTEGER NOT NULL, mikrotik_id TEXT NOT NULL, name TEXT NOT NULL, UNIQUE(router_id,mikrotik_id));
         INSERT INTO access_points (id,router_id,mikrotik_id,name) VALUES (1,1,"AA:BB:CC:DD:EE:FF","cap-1");
         CREATE TABLE wifi_radios_cache (id INTEGER PRIMARY KEY, router_id INTEGER NOT NULL, mikrotik_id TEXT NOT NULL, interface_name TEXT, radio_mac TEXT, UNIQUE(router_id,mikrotik_id));
         INSERT INTO wifi_radios_cache (id,router_id,mikrotik_id,radio_mac) VALUES (1,1,"11:22:33:44:55:66","11:22:33:44:55:66");
         CREATE TABLE wifi_sessions (
            id INTEGER PRIMARY KEY, device_id INTEGER, mac_address TEXT NOT NULL, ip_address TEXT, ssid TEXT,
            access_point_name TEXT, connected_at TEXT NOT NULL, disconnected_at TEXT, signal_min INTEGER,
            signal_max INTEGER, created_at TEXT
         )'
    );
    $database->migrate(dirname(__DIR__) . '/database/schema.sql');
    $columns = $database->pdo()->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['email', 'notify_new_device', 'notify_backup_result', 'notify_monitoring_problem'] as $column) {
        if (!in_array($column, $columns, true)) throw new RuntimeException('Migrace nepřidala sloupec ' . $column . '.');
    }
    $deviceColumns = $database->pdo()->query('PRAGMA table_info(devices)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['rate_down', 'rate_up', 'capsman_type'] as $column) {
        if (!in_array($column, $deviceColumns, true)) throw new RuntimeException('Migrace nepřidala sloupec zařízení ' . $column . '.');
    }
    $networkColumns = $database->pdo()->query('PRAGMA table_info(wifi_networks)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['mikrotik_raw_id', 'capsman_type', 'hidden', 'desired_json', 'remote_json', 'conflict_summary'] as $column) {
        if (!in_array($column, $networkColumns, true)) throw new RuntimeException('Migrace nepřidala sloupec Wi-Fi sítě ' . $column . '.');
    }
    $migratedNetwork = $database->pdo()->query("SELECT mikrotik_id,mikrotik_raw_id,capsman_type FROM wifi_networks WHERE id=1")->fetch();
    if (($migratedNetwork['mikrotik_id'] ?? null) !== 'wifi:*1' || ($migratedNetwork['mikrotik_raw_id'] ?? null) !== '*1' || ($migratedNetwork['capsman_type'] ?? null) !== 'wifi') {
        throw new RuntimeException('Migrace nezachovala a neoddělila ID existující Wi-Fi sítě.');
    }
    $migratedAp = $database->pdo()->query("SELECT mikrotik_id,mikrotik_raw_id FROM access_points WHERE id=1")->fetch();
    if (($migratedAp['mikrotik_id'] ?? null) !== 'wifi:AA:BB:CC:DD:EE:FF' || ($migratedAp['mikrotik_raw_id'] ?? null) !== null) {
        throw new RuntimeException('Migrace existujícího přístupového bodu není bezpečná.');
    }
    $migratedRadio = $database->pdo()->query("SELECT mikrotik_id FROM wifi_radios_cache WHERE id=1")->fetchColumn();
    if ($migratedRadio !== 'wifi:11:22:33:44:55:66') {
        throw new RuntimeException('Migrace neoddělila ID existujícího rádia.');
    }
    foreach (['notification_queue', 'discovered_devices', 'router_backups', 'wifi_connection_events', 'syslog_ingest_state'] as $table) {
        $statement = $database->pdo()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name");
        $statement->execute(['name' => $table]);
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Migrace nevytvořila tabulku ' . $table . '.');
    }
    $version = (string) $database->pdo()->query("SELECT value FROM schema_meta WHERE key='version'")->fetchColumn();
    if ($version !== '6') throw new RuntimeException('Migrace nenastavila verzi schématu 6.');
    echo "Schema migration test OK\n";
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
