CREATE TABLE IF NOT EXISTS schema_meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO schema_meta (key, value) VALUES ('version', '5')
ON CONFLICT(key) DO UPDATE SET value = excluded.value;

CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    username_normalized TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    email TEXT,
    notify_new_device INTEGER NOT NULL DEFAULT 0,
    notify_backup_result INTEGER NOT NULL DEFAULT 0,
    notify_monitoring_problem INTEGER NOT NULL DEFAULT 0,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'viewer')),
    active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username_normalized TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_recent ON login_attempts(attempted_at, username_normalized, ip_address);

CREATE TABLE IF NOT EXISTS sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    note TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO sites (id, name, note) VALUES (1, 'Výchozí lokalita', 'Název lze upravit pro konkrétní instalaci') ON CONFLICT(id) DO NOTHING;

CREATE TABLE IF NOT EXISTS routers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    host TEXT NOT NULL,
    port INTEGER NOT NULL DEFAULT 8728,
    username TEXT NOT NULL,
    password_cipher TEXT NOT NULL DEFAULT '',
    tls_mode TEXT NOT NULL DEFAULT 'fingerprint' CHECK (tls_mode IN ('fingerprint', 'system_ca')),
    tls_fingerprint TEXT,
    tls_peer_name TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    identity TEXT,
    model TEXT,
    routeros_version TEXT,
    status TEXT NOT NULL DEFAULT 'unconfigured' CHECK (status IN ('unconfigured', 'online', 'offline', 'error')),
    last_sync_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_routers_site_host ON routers(site_id, host, port);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO app_settings (key, value) VALUES
    ('approved_vlan_id', '10'),
    ('registration_vlan_id', '20'),
    ('approved_dhcp_server', 'dhcp_wifi'),
    ('registration_dhcp_server', 'dhcp_wifi_registration'),
    ('static_ip_start', '192.168.10.2'),
    ('static_ip_end', '192.168.10.99'),
    ('default_rate_down', '2M'),
    ('default_rate_up', '2M'),
    ('max_devices_per_person', '1'),
    ('monitor_listen_address', '0.0.0.0'),
    ('monitor_syslog_tcp_port', '5514'),
    ('monitor_syslog_udp_port', '514'),
    ('monitor_netflow_port', '2055'),
    ('monitor_retention_days', '1825'),
    ('monitor_syslog_max_gib', '60'),
    ('monitor_netflow_max_gib', '280'),
    ('monitor_router_target_address', ''),
    ('monitor_router_syslog_port', '5514'),
    ('monitor_router_syslog_transport', 'tcp'),
    ('monitor_router_netflow_port', '2055'),
    ('smtp_enabled', '0'),
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_encryption', 'starttls'),
    ('smtp_auth_enabled', '1'),
    ('smtp_username', ''),
    ('smtp_password_cipher', ''),
    ('smtp_from_email', ''),
    ('smtp_from_name', 'WiFi Manager'),
    ('smtp_timeout_seconds', '10'),
    ('backup_enabled', '0'),
    ('backup_interval_days', '7'),
    ('backup_retention_count', '12'),
    ('backup_password_cipher', ''),
    ('update_github_repository', 'standuss/wifi-manager'),
    ('update_channel', 'stable'),
    ('update_auto_check', '1')
ON CONFLICT(key) DO NOTHING;

CREATE TABLE IF NOT EXISTS people (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    note TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_people_name ON people(name);

CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER REFERENCES people(id) ON DELETE SET NULL,
    name TEXT NOT NULL,
    mac_address TEXT NOT NULL UNIQUE,
    current_ip TEXT,
    rate_down TEXT NOT NULL DEFAULT '',
    rate_up TEXT NOT NULL DEFAULT '',
    registration_state TEXT NOT NULL DEFAULT 'pending' CHECK (registration_state IN ('pending', 'registering', 'registered', 'incomplete', 'blocked', 'archived')),
    mikrotik_access_id TEXT,
    mikrotik_lease_id TEXT,
    mikrotik_queue_id TEXT,
    registered_at TEXT,
    archived_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_devices_person_state ON devices(person_id, registration_state);
CREATE INDEX IF NOT EXISTS idx_devices_ip ON devices(current_ip);

CREATE TABLE IF NOT EXISTS ip_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    ip_address TEXT NOT NULL,
    valid_from TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_to TEXT,
    source TEXT NOT NULL DEFAULT 'wifimanager',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ip_assignments_lookup ON ip_assignments(ip_address, valid_from, valid_to);

CREATE TABLE IF NOT EXISTS wifi_networks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    mikrotik_id TEXT NOT NULL,
    config_name TEXT NOT NULL,
    ssid TEXT NOT NULL,
    band TEXT,
    vlan_id INTEGER,
    registration_enabled INTEGER NOT NULL DEFAULT 0,
    registration_vlan_id INTEGER,
    password_cipher TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1,
    managed INTEGER NOT NULL DEFAULT 0,
    source_hash TEXT,
    sync_state TEXT NOT NULL DEFAULT 'synced' CHECK (sync_state IN ('synced', 'changed', 'error')),
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(router_id, mikrotik_id)
);
CREATE INDEX IF NOT EXISTS idx_wifi_networks_ssid ON wifi_networks(ssid);

CREATE TABLE IF NOT EXISTS access_points (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    mikrotik_id TEXT NOT NULL,
    name TEXT NOT NULL,
    custom_name TEXT,
    address TEXT,
    board_name TEXT,
    serial TEXT,
    routeros_version TEXT,
    base_mac TEXT,
    status TEXT NOT NULL DEFAULT 'online',
    connected_time TEXT,
    uptime TEXT,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(router_id, mikrotik_id)
);

CREATE TABLE IF NOT EXISTS wifi_radios_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    mikrotik_id TEXT NOT NULL,
    cap_identity TEXT,
    cap_base_mac TEXT,
    interface_name TEXT,
    radio_mac TEXT,
    bands TEXT,
    hw_type TEXT,
    max_peers INTEGER,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(router_id, mikrotik_id)
);
CREATE INDEX IF NOT EXISTS idx_wifi_radios_interface ON wifi_radios_cache(router_id, interface_name);

CREATE TABLE IF NOT EXISTS connected_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    device_id INTEGER REFERENCES devices(id) ON DELETE SET NULL,
    mac_address TEXT NOT NULL,
    ip_address TEXT,
    hostname TEXT,
    ssid TEXT,
    interface_name TEXT,
    access_point_name TEXT,
    band TEXT,
    vlan_id INTEGER,
    signal_dbm INTEGER,
    tx_rate TEXT,
    rx_rate TEXT,
    tx_bps INTEGER,
    rx_bps INTEGER,
    uptime TEXT,
    last_activity TEXT,
    authorized INTEGER NOT NULL DEFAULT 0,
    registration_status TEXT NOT NULL DEFAULT 'pending',
    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(router_id, mac_address)
);
CREATE INDEX IF NOT EXISTS idx_connected_clients_status ON connected_clients(registration_status, ssid);
CREATE INDEX IF NOT EXISTS idx_connected_clients_ip ON connected_clients(ip_address);

CREATE TABLE IF NOT EXISTS discovered_devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    mac_address TEXT NOT NULL,
    hostname TEXT,
    ip_address TEXT,
    ssid TEXT,
    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(router_id, mac_address)
);
CREATE INDEX IF NOT EXISTS idx_discovered_devices_seen ON discovered_devices(last_seen_at DESC);

CREATE TABLE IF NOT EXISTS notification_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    event_key TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sending', 'sent', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT,
    UNIQUE(user_id, type, event_key)
);
CREATE INDEX IF NOT EXISTS idx_notification_queue_pending ON notification_queue(status, next_attempt_at, created_at);

CREATE TABLE IF NOT EXISTS router_backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    local_path TEXT,
    size_bytes INTEGER,
    routeros_version TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed')),
    error TEXT,
    created_by INTEGER REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_router_backups_router_created ON router_backups(router_id, created_at DESC);

CREATE TABLE IF NOT EXISTS dhcp_leases_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    mikrotik_id TEXT NOT NULL,
    address TEXT,
    mac_address TEXT,
    server_name TEXT,
    hostname TEXT,
    comment TEXT,
    dynamic INTEGER NOT NULL DEFAULT 1,
    status TEXT,
    last_seen TEXT,
    synced_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(router_id, mikrotik_id)
);
CREATE INDEX IF NOT EXISTS idx_dhcp_leases_address ON dhcp_leases_cache(address);
CREATE INDEX IF NOT EXISTS idx_dhcp_leases_mac ON dhcp_leases_cache(mac_address);

CREATE TABLE IF NOT EXISTS wifi_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER REFERENCES devices(id) ON DELETE SET NULL,
    mac_address TEXT NOT NULL,
    ip_address TEXT,
    ssid TEXT,
    access_point_name TEXT,
    connected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    disconnected_at TEXT,
    signal_min INTEGER,
    signal_max INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wifi_sessions_mac_time ON wifi_sessions(mac_address, connected_at, disconnected_at);

CREATE TABLE IF NOT EXISTS sync_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id INTEGER NOT NULL REFERENCES routers(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    payload_cipher TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed')),
    progress TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_by INTEGER REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT,
    finished_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_sync_jobs_pending ON sync_jobs(status, created_at);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES admin_users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id TEXT,
    summary TEXT NOT NULL,
    details_json TEXT,
    ip_address TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log(entity_type, entity_id);
