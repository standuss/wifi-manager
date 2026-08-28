<?php

declare(strict_types=1);

namespace WifiManager\Services;

use PDO;
use WifiManager\Crypto;
use WifiManager\Database;

final class SyncService
{
    public function __construct(
        private readonly Database $database,
        private readonly RouterFactory $routerFactory,
        private readonly SettingsService $settingsService,
        private readonly Crypto $crypto,
        private readonly NotificationService $notifications,
    ) {
    }

    public function sync(int $routerId, bool $full = false): void
    {
        $statusStatement = $this->database->pdo()->prepare('SELECT status FROM routers WHERE id=:id');
        $statusStatement->execute(['id' => $routerId]);
        $previousStatus = (string) ($statusStatement->fetchColumn() ?: 'unconfigured');
        $repository = $this->routerFactory->repository($routerId);
        try {
            $snapshot = $full ? $repository->fullSnapshot() : $repository->fastSnapshot();
            $now = gmdate('Y-m-d H:i:s');
            $settings = $this->settingsService->all();

            $this->database->transaction(function (PDO $pdo) use ($routerId, $snapshot, $settings, $now, $full): void {
                $warnings = $snapshot['warnings'] ?? [];
                if ($full) {
                    $this->syncRouter($pdo, $routerId, $snapshot, $now);
                    if (!self::sectionFailed($warnings, 'interface/wifi/configuration/print')) {
                        $this->syncNetworks($pdo, $routerId, $snapshot['networks'] ?? [], $settings, $now);
                    }
                    if (!self::sectionFailed($warnings, 'interface/wifi/capsman/remote-cap/print')) {
                        $this->syncAccessPoints($pdo, $routerId, $snapshot['caps'] ?? [], $now);
                    }
                    if (!self::sectionFailed($warnings, 'interface/wifi/radio/print')) {
                        $this->syncRadios($pdo, $routerId, $snapshot['radios'] ?? [], $now);
                    }
                }
                if (!self::sectionFailed($warnings, 'ip/dhcp-server/lease/print')) {
                    $this->syncLeases($pdo, $routerId, $snapshot['leases'] ?? [], $now);
                }
                $criticalClientReadFailed = self::sectionFailed($warnings, 'interface/wifi/registration-table/print')
                    || self::sectionFailed($warnings, 'ip/dhcp-server/lease/print')
                    || self::sectionFailed($warnings, 'interface/wifi/access-list/print')
                    || self::sectionFailed($warnings, 'queue/simple/print');
                if (!$criticalClientReadFailed) {
                    $this->syncClients($pdo, $routerId, $snapshot, $settings, $now);
                }

                $warning = implode(' | ', $warnings);
                $statement = $pdo->prepare(
                    "UPDATE routers SET status = 'online', last_sync_at = :now, last_error = :warning, updated_at = :now WHERE id = :id"
                );
                $statement->execute(['now' => $now, 'warning' => $warning !== '' ? mb_substr($warning, 0, 1000) : null, 'id' => $routerId]);
            });
        } catch (\Throwable $exception) {
            $statement = $this->database->pdo()->prepare(
                "UPDATE routers SET status = 'offline', last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $statement->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $routerId]);
            if ($previousStatus === 'online') $this->notifications->queueMonitoringProblem($routerId, $exception->getMessage());
            throw $exception;
        }
    }

    /** @param list<array<string,string>> $leases */
    private function syncLeases(PDO $pdo, int $routerId, array $leases, string $now): void
    {
        $seen = [];
        $statement = $pdo->prepare(
            'INSERT INTO dhcp_leases_cache
             (router_id, mikrotik_id, address, mac_address, server_name, hostname, comment, dynamic, status, last_seen, synced_at)
             VALUES (:router_id, :mikrotik_id, :address, :mac, :server, :hostname, :comment, :dynamic, :status, :last_seen, :synced_at)
             ON CONFLICT(router_id, mikrotik_id) DO UPDATE SET address = excluded.address, mac_address = excluded.mac_address,
             server_name = excluded.server_name, hostname = excluded.hostname, comment = excluded.comment, dynamic = excluded.dynamic,
             status = excluded.status, last_seen = excluded.last_seen, synced_at = excluded.synced_at'
        );
        foreach ($leases as $lease) {
            $id = (string) ($lease['.id'] ?? '');
            if ($id === '') continue;
            $seen[] = $id;
            $statement->execute([
                'router_id' => $routerId, 'mikrotik_id' => $id, 'address' => $lease['address'] ?? null,
                'mac' => self::mac($lease['mac-address'] ?? ''), 'server' => $lease['server'] ?? null,
                'hostname' => $lease['host-name'] ?? null, 'comment' => $lease['comment'] ?? null,
                'dynamic' => self::yes($lease['dynamic'] ?? 'yes') ? 1 : 0, 'status' => $lease['status'] ?? null,
                'last_seen' => $lease['last-seen'] ?? null, 'synced_at' => $now,
            ]);
        }
        if ($seen === []) {
            $pdo->prepare('DELETE FROM dhcp_leases_cache WHERE router_id = :router_id')->execute(['router_id' => $routerId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $delete = $pdo->prepare("DELETE FROM dhcp_leases_cache WHERE router_id = ? AND mikrotik_id NOT IN ($placeholders)");
            $delete->execute([$routerId, ...$seen]);
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function syncRouter(PDO $pdo, int $routerId, array $snapshot, string $now): void
    {
        $identity = $snapshot['identity'] ?? [];
        $resource = $snapshot['resource'] ?? [];
        $statement = $pdo->prepare(
            'UPDATE routers SET identity = :identity, model = :model, routeros_version = :version, updated_at = :now WHERE id = :id'
        );
        $statement->execute([
            'identity' => $identity['name'] ?? null,
            'model' => $resource['board-name'] ?? null,
            'version' => $resource['version'] ?? null,
            'now' => $now,
            'id' => $routerId,
        ]);
    }

    /** @param list<array<string,string>> $networks @param array<string,string> $settings */
    private function syncNetworks(PDO $pdo, int $routerId, array $networks, array $settings, string $now): void
    {
        $seen = [];
        $statement = $pdo->prepare(
            'INSERT INTO wifi_networks
             (router_id, mikrotik_id, config_name, ssid, band, vlan_id, registration_enabled, registration_vlan_id, password_cipher, enabled, source_hash, last_seen_at, updated_at)
             VALUES (:router_id, :mikrotik_id, :config_name, :ssid, :band, :vlan_id, :registration_enabled, :registration_vlan_id, :password_cipher, :enabled, :source_hash, :last_seen_at, :updated_at)
             ON CONFLICT(router_id, mikrotik_id) DO UPDATE SET
             config_name = excluded.config_name, ssid = excluded.ssid, band = excluded.band, vlan_id = excluded.vlan_id,
             registration_enabled = excluded.registration_enabled, registration_vlan_id = excluded.registration_vlan_id,
             password_cipher = CASE WHEN excluded.password_cipher <> \'\' THEN excluded.password_cipher ELSE wifi_networks.password_cipher END,
             enabled = excluded.enabled, source_hash = excluded.source_hash, last_seen_at = excluded.last_seen_at, updated_at = excluded.updated_at'
        );

        foreach ($networks as $network) {
            $id = (string) ($network['.id'] ?? '');
            if ($id === '') continue;
            $seen[] = $id;
            $vlan = isset($network['datapath.vlan-id']) && $network['datapath.vlan-id'] !== '' ? (int) $network['datapath.vlan-id'] : null;
            $registrationEnabled = $vlan !== null && $vlan === (int) ($settings['registration_vlan_id'] ?? 0);
            $passphrase = (string) ($network['security.passphrase'] ?? '');
            $hash = hash('sha256', json_encode($network, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $statement->execute([
                'router_id' => $routerId,
                'mikrotik_id' => $id,
                'config_name' => $network['name'] ?? $id,
                'ssid' => $network['ssid'] ?? $network['name'] ?? 'Bez názvu',
                'band' => $network['channel.band'] ?? null,
                'vlan_id' => $vlan,
                'registration_enabled' => $registrationEnabled ? 1 : 0,
                'registration_vlan_id' => $registrationEnabled ? $vlan : null,
                'password_cipher' => $passphrase !== '' ? $this->crypto->encrypt($passphrase) : '',
                'enabled' => self::yes($network['disabled'] ?? 'no') ? 0 : 1,
                'source_hash' => $hash,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($seen !== []) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $delete = $pdo->prepare("DELETE FROM wifi_networks WHERE router_id = ? AND mikrotik_id NOT IN ($placeholders)");
            $delete->execute([$routerId, ...$seen]);
        }
    }

    /** @param list<array<string,string>> $caps */
    private function syncAccessPoints(PDO $pdo, int $routerId, array $caps, string $now): void
    {
        $seen = [];
        $statement = $pdo->prepare(
            'INSERT INTO access_points
             (router_id, mikrotik_id, name, address, board_name, serial, routeros_version, base_mac, status, connected_time, uptime, last_seen_at, updated_at)
             VALUES (:router_id, :mikrotik_id, :name, :address, :board_name, :serial, :version, :base_mac, :status, :connected_time, :uptime, :last_seen_at, :updated_at)
             ON CONFLICT(router_id, mikrotik_id) DO UPDATE SET
             name = excluded.name, address = excluded.address, board_name = excluded.board_name, serial = excluded.serial,
             routeros_version = excluded.routeros_version, base_mac = excluded.base_mac, status = excluded.status, connected_time = excluded.connected_time,
             uptime = excluded.uptime, last_seen_at = excluded.last_seen_at, updated_at = excluded.updated_at'
        );
        foreach ($caps as $cap) {
            $id = (string) ($cap['base-mac'] ?? $cap['identity'] ?? $cap['.id'] ?? '');
            if ($id === '') continue;
            $seen[] = $id;
            $statement->execute([
                'router_id' => $routerId,
                'mikrotik_id' => $id,
                'name' => $cap['identity'] ?? $cap['common-name'] ?? $id,
                'address' => $cap['address'] ?? null,
                'board_name' => $cap['board-name'] ?? null,
                'serial' => $cap['serial'] ?? null,
                'version' => $cap['version'] ?? null,
                'base_mac' => $cap['base-mac'] ?? null,
                'status' => strtolower((string) ($cap['state'] ?? 'ok')) === 'ok' ? 'online' : 'offline',
                'connected_time' => $cap['connected-time'] ?? null,
                'uptime' => $cap['uptime'] ?? null,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
        }
        if ($seen === []) {
            $pdo->prepare("UPDATE access_points SET status = 'offline' WHERE router_id = :router_id")
                ->execute(['router_id' => $routerId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $offline = $pdo->prepare("UPDATE access_points SET status = 'offline' WHERE router_id = ? AND mikrotik_id NOT IN ($placeholders)");
            $offline->execute([$routerId, ...$seen]);
        }

        // Remove records created by the old filtered query, which contained only *1, *2… IDs.
        $pdo->prepare(
            "DELETE FROM access_points
             WHERE router_id = :router_id AND base_mac IS NULL AND address IS NULL AND board_name IS NULL AND name = mikrotik_id"
        )->execute(['router_id' => $routerId]);
    }

    /** @param list<array<string,string>> $radios */
    private function syncRadios(PDO $pdo, int $routerId, array $radios, string $now): void
    {
        $seen = [];
        $statement = $pdo->prepare(
            'INSERT INTO wifi_radios_cache
             (router_id, mikrotik_id, cap_identity, cap_base_mac, interface_name, radio_mac, bands, hw_type, max_peers, last_seen_at)
             VALUES (:router_id, :mikrotik_id, :cap_identity, :cap_base_mac, :interface_name, :radio_mac, :bands, :hw_type, :max_peers, :last_seen_at)
             ON CONFLICT(router_id, mikrotik_id) DO UPDATE SET
             cap_identity = excluded.cap_identity, cap_base_mac = excluded.cap_base_mac, interface_name = excluded.interface_name,
             radio_mac = excluded.radio_mac, bands = excluded.bands, hw_type = excluded.hw_type,
             max_peers = excluded.max_peers, last_seen_at = excluded.last_seen_at'
        );

        foreach ($radios as $radio) {
            $id = (string) ($radio['radio-mac'] ?? $radio['.id'] ?? '');
            if ($id === '') continue;
            $seen[] = $id;
            [$capIdentity, $capBaseMac] = self::parseCapReference((string) ($radio['cap'] ?? ''));
            $statement->execute([
                'router_id' => $routerId,
                'mikrotik_id' => $id,
                'cap_identity' => $capIdentity,
                'cap_base_mac' => $capBaseMac,
                'interface_name' => $radio['interface'] ?? null,
                'radio_mac' => $radio['radio-mac'] ?? null,
                'bands' => $radio['bands'] ?? null,
                'hw_type' => $radio['hw-type'] ?? null,
                'max_peers' => isset($radio['max-peers']) ? (int) $radio['max-peers'] : null,
                'last_seen_at' => $now,
            ]);
        }

        if ($seen === []) {
            $pdo->prepare('DELETE FROM wifi_radios_cache WHERE router_id = :router_id')->execute(['router_id' => $routerId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $delete = $pdo->prepare("DELETE FROM wifi_radios_cache WHERE router_id = ? AND mikrotik_id NOT IN ($placeholders)");
            $delete->execute([$routerId, ...$seen]);
        }
    }

    /** @param array<string,mixed> $snapshot @param array<string,string> $settings */
    private function syncClients(PDO $pdo, int $routerId, array $snapshot, array $settings, string $now): void
    {
        $clients = $snapshot['clients'] ?? [];
        $leasesByMac = [];
        foreach ($snapshot['leases'] ?? [] as $lease) {
            $mac = self::mac($lease['mac-address'] ?? '');
            if ($mac !== '') $leasesByMac[$mac][] = $lease;
        }
        $accessByMac = [];
        foreach ($snapshot['access_list'] ?? [] as $access) {
            $mac = self::mac($access['mac-address'] ?? '');
            if ($mac !== '') $accessByMac[$mac][] = $access;
        }
        $queueByTarget = [];
        foreach ($snapshot['queues'] ?? [] as $queue) {
            $targets = explode(',', (string) ($queue['target'] ?? ''));
            foreach ($targets as $target) {
                $queueByTarget[trim($target)] = $queue;
            }
        }
        $devices = [];
        foreach ($pdo->query("SELECT * FROM devices WHERE registration_state != 'archived'")->fetchAll() as $device) {
            $devices[self::mac((string) $device['mac_address'])] = $device;
        }
        $networks = [];
        $networkStatement = $pdo->prepare('SELECT ssid, vlan_id FROM wifi_networks WHERE router_id = :router_id');
        $networkStatement->execute(['router_id' => $routerId]);
        foreach ($networkStatement->fetchAll() as $network) {
            $networks[(string) $network['ssid']] = $network;
        }
        $radiosByInterface = [];
        $radioStatement = $pdo->prepare('SELECT interface_name, cap_identity FROM wifi_radios_cache WHERE router_id = :router_id AND interface_name IS NOT NULL');
        $radioStatement->execute(['router_id' => $routerId]);
        foreach ($radioStatement->fetchAll() as $radio) {
            $radiosByInterface[(string) $radio['interface_name']] = (string) ($radio['cap_identity'] ?: $radio['interface_name']);
        }
        $previous = [];
        $previousStatement = $pdo->prepare('SELECT * FROM connected_clients WHERE router_id = :router_id');
        $previousStatement->execute(['router_id' => $routerId]);
        foreach ($previousStatement->fetchAll() as $row) {
            $previous[(string) $row['mac_address']] = $row;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO connected_clients
             (router_id, device_id, mac_address, ip_address, hostname, ssid, interface_name, access_point_name, band, vlan_id,
              signal_dbm, tx_rate, rx_rate, tx_bps, rx_bps, uptime, last_activity, authorized, registration_status, first_seen_at, last_seen_at)
             VALUES (:router_id, :device_id, :mac, :ip, :hostname, :ssid, :interface, :ap, :band, :vlan,
              :signal, :tx_rate, :rx_rate, :tx_bps, :rx_bps, :uptime, :last_activity, :authorized, :status, :first_seen, :last_seen)
             ON CONFLICT(router_id, mac_address) DO UPDATE SET
              device_id = excluded.device_id, ip_address = excluded.ip_address, hostname = excluded.hostname, ssid = excluded.ssid,
              interface_name = excluded.interface_name, access_point_name = excluded.access_point_name, band = excluded.band,
              vlan_id = excluded.vlan_id, signal_dbm = excluded.signal_dbm, tx_rate = excluded.tx_rate, rx_rate = excluded.rx_rate,
              tx_bps = excluded.tx_bps, rx_bps = excluded.rx_bps, uptime = excluded.uptime, last_activity = excluded.last_activity,
              authorized = excluded.authorized, registration_status = excluded.registration_status, last_seen_at = excluded.last_seen_at'
        );

        $currentMacs = [];
        foreach ($clients as $client) {
            $mac = self::mac($client['mac-address'] ?? '');
            if ($mac === '') continue;
            $currentMacs[] = $mac;
            $device = $devices[$mac] ?? null;
            $accessRows = $accessByMac[$mac] ?? [];
            $access = self::approvedAccess($accessRows, (int) ($settings['approved_vlan_id'] ?? 0));
            $lease = self::preferredLease($leasesByMac[$mac] ?? [], (string) ($settings['approved_dhcp_server'] ?? ''));
            $ip = $lease['address'] ?? null;
            $queue = $ip ? ($queueByTarget[$ip . '/32'] ?? null) : null;
            $isStaticApprovedLease = $lease
                && (string) ($lease['server'] ?? '') === (string) ($settings['approved_dhcp_server'] ?? '')
                && !self::yes($lease['dynamic'] ?? 'yes');

            if (($device['registration_state'] ?? '') === 'blocked') {
                $status = 'blocked';
            } elseif ($device && $access && $isStaticApprovedLease && $queue) {
                $status = 'registered';
            } elseif ($device || $access) {
                $status = 'incomplete';
            } else {
                $status = 'pending';
            }

            $ssid = (string) ($client['ssid'] ?? '');
            $vlan = isset($client['vlan-id']) && $client['vlan-id'] !== ''
                ? (int) $client['vlan-id']
                : ($access ? (int) ($settings['approved_vlan_id'] ?? 0) : ($networks[$ssid]['vlan_id'] ?? null));
            $interface = (string) ($client['interface'] ?? '');
            $accessPoint = $radiosByInterface[$interface] ?? ($interface !== '' ? $interface : null);
            $firstSeen = $previous[$mac]['first_seen_at'] ?? $now;
            $this->notifications->observeDevice(
                $pdo,
                $routerId,
                $mac,
                isset($lease['host-name']) ? (string) $lease['host-name'] : null,
                $ip !== null ? (string) $ip : null,
                $ssid !== '' ? $ssid : null,
                $now,
            );
            $upsert->execute([
                'router_id' => $routerId,
                'device_id' => $device['id'] ?? null,
                'mac' => $mac,
                'ip' => $ip,
                'hostname' => $lease['host-name'] ?? null,
                'ssid' => $ssid,
                'interface' => $interface !== '' ? $interface : null,
                'ap' => $accessPoint,
                'band' => $client['band'] ?? null,
                'vlan' => $vlan,
                'signal' => isset($client['signal']) ? (int) $client['signal'] : null,
                'tx_rate' => $client['tx-rate'] ?? null,
                'rx_rate' => $client['rx-rate'] ?? null,
                'tx_bps' => isset($client['tx-bits-per-second']) ? (int) $client['tx-bits-per-second'] : null,
                'rx_bps' => isset($client['rx-bits-per-second']) ? (int) $client['rx-bits-per-second'] : null,
                'uptime' => $client['uptime'] ?? null,
                'last_activity' => $client['last-activity'] ?? null,
                'authorized' => self::yes($client['authorized'] ?? 'no') ? 1 : 0,
                'status' => $status,
                'first_seen' => $firstSeen,
                'last_seen' => $now,
            ]);

            if (!isset($previous[$mac])) {
                $session = $pdo->prepare(
                    'INSERT INTO wifi_sessions (device_id, mac_address, ip_address, ssid, access_point_name, signal_min, signal_max) VALUES (:device_id, :mac, :ip, :ssid, :ap, :signal, :signal)'
                );
                $session->execute([
                    'device_id' => $device['id'] ?? null,
                    'mac' => $mac,
                    'ip' => $ip,
                    'ssid' => $ssid,
                    'ap' => $accessPoint,
                    'signal' => isset($client['signal']) ? (int) $client['signal'] : null,
                ]);
            } else {
                $session = $pdo->prepare(
                    'UPDATE wifi_sessions SET signal_min = MIN(COALESCE(signal_min, :signal), :signal), signal_max = MAX(COALESCE(signal_max, :signal), :signal)
                     WHERE mac_address = :mac AND disconnected_at IS NULL'
                );
                $session->execute(['signal' => isset($client['signal']) ? (int) $client['signal'] : 0, 'mac' => $mac]);
            }
        }

        foreach ($previous as $mac => $row) {
            if (!in_array($mac, $currentMacs, true)) {
                $close = $pdo->prepare('UPDATE wifi_sessions SET disconnected_at = :now WHERE mac_address = :mac AND disconnected_at IS NULL');
                $close->execute(['now' => $now, 'mac' => $mac]);
            }
        }

        if ($currentMacs === []) {
            $pdo->prepare('DELETE FROM connected_clients WHERE router_id = :router_id')->execute(['router_id' => $routerId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($currentMacs), '?'));
            $delete = $pdo->prepare("DELETE FROM connected_clients WHERE router_id = ? AND mac_address NOT IN ($placeholders)");
            $delete->execute([$routerId, ...$currentMacs]);
        }
    }

    /** @param list<array<string,string>> $rows @return array<string,string>|null */
    private static function approvedAccess(array $rows, int $approvedVlan): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['vlan-id'] ?? 0) === $approvedVlan && !self::yes($row['disabled'] ?? 'no') && ($row['action'] ?? 'accept') === 'accept') {
                return $row;
            }
        }
        return null;
    }

    /** @param list<array<string,string>> $rows @return array<string,string>|null */
    private static function preferredLease(array $rows, string $server): ?array
    {
        foreach ($rows as $row) {
            if (($row['server'] ?? '') === $server) return $row;
        }
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'bound') return $row;
        }
        return $rows[0] ?? null;
    }

    private static function yes(string|int|bool|null $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'yes', 'true', 'on'], true);
    }

    private static function mac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9a-f]/i', '', $mac) ?? '');
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
    }

    /** @return array{0:?string,1:?string} */
    private static function parseCapReference(string $reference): array
    {
        if ($reference === '') return [null, null];
        $identity = strstr($reference, '@', true);
        $identity = $identity !== false && $identity !== '' ? $identity : $reference;
        $baseMac = null;
        if (preg_match('/@([0-9A-Fa-f:]{17})(?:%|$)/', $reference, $match) === 1) {
            $baseMac = self::mac($match[1]);
        }
        return [$identity, $baseMac];
    }

    /** @param list<string> $warnings */
    private static function sectionFailed(array $warnings, string $section): bool
    {
        foreach ($warnings as $warning) {
            if (str_starts_with($warning, $section . ':')) return true;
        }
        return false;
    }
}
