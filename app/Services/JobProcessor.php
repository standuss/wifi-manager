<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Database;
use WifiManager\RouterOS\RouterRepository;

final class JobProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly RouterFactory $routerFactory,
        private readonly SettingsService $settingsService,
        private readonly JobService $jobs,
        private readonly AuditService $audit,
        private readonly BackupService $backups,
    ) {
    }

    public function processOne(): bool
    {
        $job = $this->jobs->next();
        if ($job === null) return false;
        $jobId = (int) $job['id'];
        try {
            $repository = $this->routerFactory->repository((int) $job['router_id']);
            match ((string) $job['type']) {
                'register_device' => $this->registerDevice($jobId, $repository, $job['payload']),
                'toggle_network' => $this->toggleNetwork($jobId, $repository, $job['payload']),
                'create_network' => $this->createNetwork($jobId, $repository, $job['payload']),
                'configure_monitoring' => $this->configureMonitoring($jobId, $repository, $job['payload']),
                'backup_router' => $this->backups->run($jobId, $repository, $job['payload']),
                default => throw new \RuntimeException('Neznámý typ synchronizační úlohy.'),
            };
            $this->jobs->done($jobId);
            $this->audit->log(
                isset($job['created_by']) ? (int) $job['created_by'] : null,
                'job.done',
                'Síťová změna byla dokončena',
                'sync_job',
                $jobId,
                ['type' => $job['type']],
            );
        } catch (\Throwable $exception) {
            $this->jobs->failed($jobId, $exception->getMessage());
            $this->audit->log(
                isset($job['created_by']) ? (int) $job['created_by'] : null,
                'job.failed',
                'Síťová změna selhala: ' . $exception->getMessage(),
                'sync_job',
                $jobId,
                ['type' => $job['type']],
            );
        }
        return true;
    }

    /** @param array<string,mixed> $payload */
    private function configureMonitoring(int $jobId, RouterRepository $repository, array $payload): void
    {
        $target = (string) $payload['target_address'];
        $syslogPort = (int) $payload['syslog_port'];
        $flowPort = (int) $payload['netflow_port'];
        $transport = (string) $payload['syslog_transport'];
        $routerId = (int) ($payload['router_id'] ?? 0);
        $remoteEndpoint = str_contains($target, ':') ? '[' . $target . ']:' . $syslogPort : $target . ':' . $syslogPort;

        $this->jobs->progress($jobId, 'Nastavuji vzdálený syslog na MikroTiku');
        $actions = $repository->rows('/system/logging/action/print');
        $action = null;
        foreach ($actions as $row) if (($row['name'] ?? '') === 'wifimanager-remote') $action = $row;
        $attributes = [
            'target' => 'remote',
            'remote-port' => $remoteEndpoint,
            'remote-protocol' => $transport,
            'remote-log-format' => $transport === 'tcp' ? 'cef' : 'syslog',
            'syslog-time-format' => 'iso8601',
        ];
        if ($action === null) {
            $repository->add('/system/logging/action', ['name' => 'wifimanager-remote'] + $attributes);
        } else {
            $repository->set('/system/logging/action', (string) $action['.id'], $attributes);
        }

        $topics = ['info,!firewall', 'warning', 'error', 'critical'];
        $rules = $repository->rows('/system/logging/print');
        foreach ($topics as $index => $topic) {
            $prefix = 'WFM' . ($index + 1) . '|';
            $rule = null;
            foreach ($rules as $candidate) if (($candidate['prefix'] ?? '') === $prefix) $rule = $candidate;
            $values = ['topics' => $topic, 'action' => 'wifimanager-remote', 'prefix' => $prefix, 'disabled' => 'no'];
            if ($rule === null) $repository->add('/system/logging', $values);
            else $repository->set('/system/logging', (string) $rule['.id'], $values);
        }

        $this->jobs->progress($jobId, 'Nastavuji export IPFIX na MikroTiku');
        $repository->setSingleton('/ip/traffic-flow', [
            'enabled' => 'yes', 'interfaces' => 'all', 'active-flow-timeout' => '5m', 'inactive-flow-timeout' => '15s',
        ]);
        $repository->setSingleton('/ip/traffic-flow/ipfix', [
            'src-mac-address' => 'yes',
            'dst-mac-address' => 'yes',
            'nat-src-address' => 'yes',
            'nat-dst-address' => 'yes',
            'nat-src-port' => 'yes',
            'nat-dst-port' => 'yes',
            'in-interface' => 'yes',
            'out-interface' => 'yes',
        ]);
        $targets = $repository->rows('/ip/traffic-flow/target/print');
        $flowTarget = null;
        $storedTargetId = (string) ($this->settingsService->all()['monitor_router_flow_target_id_' . $routerId] ?? '');
        foreach ($targets as $candidate) {
            if (($storedTargetId !== '' && ($candidate['.id'] ?? '') === $storedTargetId)
                || (($candidate['dst-address'] ?? '') === $target && (int) ($candidate['port'] ?? 0) === $flowPort)) {
                $flowTarget = $candidate;
                break;
            }
        }
        $flowValues = ['dst-address' => $target, 'port' => $flowPort, 'version' => 'IPFIX'];
        if ($flowTarget === null) {
            $done = $repository->add('/ip/traffic-flow/target', $flowValues);
            if (($done['ret'] ?? '') !== '') $storedTargetId = (string) $done['ret'];
        } else {
            $storedTargetId = (string) $flowTarget['.id'];
            $repository->set('/ip/traffic-flow/target', $storedTargetId, $flowValues);
        }
        if ($routerId > 0 && $storedTargetId !== '') {
            $this->settingsService->save(['monitor_router_flow_target_id_' . $routerId => $storedTargetId]);
        }

        $verifyAction = false;
        foreach ($repository->rows('/system/logging/action/print') as $row) {
            if (($row['name'] ?? '') === 'wifimanager-remote' && ($row['remote-port'] ?? '') === $remoteEndpoint) $verifyAction = true;
        }
        $verifyFlow = false;
        foreach ($repository->rows('/ip/traffic-flow/target/print') as $row) {
            if (($row['dst-address'] ?? '') === $target && (int) ($row['port'] ?? 0) === $flowPort) $verifyFlow = true;
        }
        if (!$verifyAction || !$verifyFlow) throw new \RuntimeException('MikroTik nepotvrdil nastavení syslogu nebo IPFIX.');
    }

    /** @param array<string,mixed> $payload */
    private function registerDevice(int $jobId, RouterRepository $repository, array $payload): void
    {
        $settings = $this->settingsService->all();
        $mac = normalize_mac((string) $payload['mac_address']);
        $ip = (string) $payload['ip_address'];
        $name = trim((string) $payload['device_name']);
        $deviceId = (int) $payload['device_id'];
        $approvedVlan = (int) $settings['approved_vlan_id'];
        $dhcpServer = (string) $settings['approved_dhcp_server'];
        $rateDown = (string) ($payload['rate_down'] ?? $settings['default_rate_down']);
        $rateUp = (string) ($payload['rate_up'] ?? $settings['default_rate_up']);

        $snapshot = $repository->fastSnapshot();
        foreach ($snapshot['leases'] as $lease) {
            if (($lease['address'] ?? '') === $ip && self::safeMac((string) ($lease['mac-address'] ?? '')) !== $mac) {
                throw new \RuntimeException('Vybraná IP adresa je už obsazená jiným zařízením.');
            }
        }

        $this->jobs->progress($jobId, 'Nastavuji Wi‑Fi Access List');
        $access = self::findByMac($snapshot['access_list'], $mac);
        if ($access === null) {
            $repository->add('/interface/wifi/access-list', [
                'action' => 'accept', 'comment' => $name, 'disabled' => 'no', 'mac-address' => $mac, 'vlan-id' => $approvedVlan,
            ]);
        } else {
            $repository->set('/interface/wifi/access-list', (string) $access['.id'], [
                'action' => 'accept', 'comment' => $name, 'disabled' => 'no', 'interface' => '', 'vlan-id' => $approvedVlan,
            ]);
        }

        $this->jobs->progress($jobId, 'Vytvářím statickou IP adresu');
        $approvedLease = null;
        $registrationLease = null;
        foreach ($snapshot['leases'] as $lease) {
            if (self::safeMac((string) ($lease['mac-address'] ?? '')) === $mac) {
                if (($lease['server'] ?? '') === $dhcpServer) $approvedLease = $lease;
                if (($lease['server'] ?? '') === ($settings['registration_dhcp_server'] ?? '')) $registrationLease = $lease;
            }
        }
        $leaseToUse = $approvedLease ?? $registrationLease;
        if ($leaseToUse === null) {
            $repository->add('/ip/dhcp-server/lease', [
                'address' => $ip, 'mac-address' => $mac, 'server' => $dhcpServer, 'comment' => $name,
            ]);
        } else {
            $leaseId = (string) $leaseToUse['.id'];
            if (self::truthy($leaseToUse['dynamic'] ?? 'no')) {
                $repository->action('/ip/dhcp-server/lease', 'make-static', ['numbers' => $leaseId]);
            }
            $repository->set('/ip/dhcp-server/lease', $leaseId, ['address' => $ip, 'server' => $dhcpServer, 'comment' => $name]);
        }

        $this->jobs->progress($jobId, 'Nastavuji omezení rychlosti');
        $queue = self::findQueue($snapshot['queues'], $ip);
        if ($queue === null) {
            $repository->add('/queue/simple', [
                'name' => $name, 'target' => $ip . '/32', 'max-limit' => $rateUp . '/' . $rateDown, 'comment' => $mac,
            ]);
        } else {
            $repository->set('/queue/simple', (string) $queue['.id'], [
                'name' => $name, 'target' => $ip . '/32', 'max-limit' => $rateUp . '/' . $rateDown, 'comment' => $mac, 'disabled' => 'no',
            ]);
        }

        $this->jobs->progress($jobId, 'Ověřuji vytvořené záznamy');
        $verify = $repository->fastSnapshot();
        $verifiedAccess = self::findByMac($verify['access_list'], $mac);
        $verifiedLease = null;
        foreach ($verify['leases'] as $lease) {
            if (self::safeMac((string) ($lease['mac-address'] ?? '')) === $mac && ($lease['server'] ?? '') === $dhcpServer && ($lease['address'] ?? '') === $ip) {
                $verifiedLease = $lease;
                break;
            }
        }
        $verifiedQueue = self::findQueue($verify['queues'], $ip);
        if (!$verifiedAccess || !$verifiedLease || !$verifiedQueue) {
            throw new \RuntimeException('MikroTik nepotvrdil všechny části registrace.');
        }

        $this->database->transaction(function () use ($deviceId, $ip, $verifiedAccess, $verifiedLease, $verifiedQueue): void {
            $statement = $this->database->pdo()->prepare(
                "UPDATE devices SET current_ip = :ip, registration_state = 'registered', mikrotik_access_id = :access_id,
                 mikrotik_lease_id = :lease_id, mikrotik_queue_id = :queue_id, registered_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $statement->execute([
                'ip' => $ip,
                'access_id' => $verifiedAccess['.id'] ?? null,
                'lease_id' => $verifiedLease['.id'] ?? null,
                'queue_id' => $verifiedQueue['.id'] ?? null,
                'id' => $deviceId,
            ]);
            $this->database->pdo()->prepare('UPDATE ip_assignments SET valid_to = CURRENT_TIMESTAMP WHERE device_id = :id AND valid_to IS NULL')
                ->execute(['id' => $deviceId]);
            $this->database->pdo()->prepare('INSERT INTO ip_assignments (device_id, ip_address) VALUES (:id, :ip)')
                ->execute(['id' => $deviceId, 'ip' => $ip]);
        });
    }

    /** @param array<string,mixed> $payload */
    private function toggleNetwork(int $jobId, RouterRepository $repository, array $payload): void
    {
        $id = (string) $payload['mikrotik_id'];
        $enable = (bool) $payload['enable'];
        $this->jobs->progress($jobId, $enable ? 'Zapínám Wi‑Fi síť' : 'Vypínám Wi‑Fi síť');
        $repository->set('/interface/wifi/configuration', $id, ['disabled' => $enable ? 'no' : 'yes']);
        // Avoid .proplist here; RouterOS 7.22 may otherwise omit disabled.
        $rows = $repository->rows('/interface/wifi/configuration/print');
        foreach ($rows as $row) {
            if (($row['.id'] ?? '') === $id) {
                $disabled = ($row['disabled'] ?? 'no') === 'yes';
                if ($disabled === $enable) {
                    throw new \RuntimeException('MikroTik nepotvrdil změnu stavu Wi‑Fi sítě.');
                }
                return;
            }
        }
        throw new \RuntimeException('Wi‑Fi konfigurace po změně nebyla nalezena.');
    }

    /** @param array<string,mixed> $payload */
    private function createNetwork(int $jobId, RouterRepository $repository, array $payload): void
    {
        $settings = $this->settingsService->all();
        $snapshot = $repository->fullSnapshot();
        $bands = $payload['band'] === 'both' ? ['2ghz-ax', '5ghz-ax'] : [(string) $payload['band']];
        $provisioningByBand = [];
        foreach ($bands as $band) {
            foreach ($snapshot['provisioning'] as $rule) {
                if (($rule['disabled'] ?? 'no') !== 'yes' && str_contains((string) ($rule['supported-bands'] ?? ''), $band)) {
                    $provisioningByBand[$band] = $rule;
                    break;
                }
            }
            if (!isset($provisioningByBand[$band])) throw new \RuntimeException('Pro pásmo ' . $band . ' nebylo nalezeno provisioning pravidlo.');
        }

        $baseName = self::slug((string) $payload['name']);
        $existingNames = array_column($snapshot['networks'], 'name');
        $created = [];
        $changedRules = [];
        try {
            foreach ($bands as $band) {
                $suffix = str_starts_with($band, '2') ? '_2g' : '_5g';
                $configName = $baseName . $suffix;
                $number = 2;
                while (in_array($configName, $existingNames, true)) $configName = $baseName . $suffix . '_' . $number++;
                $effectiveVlan = (int) $payload['vlan_id'];
                $this->jobs->progress($jobId, 'Vytvářím konfiguraci ' . $band);
                $done = $repository->add('/interface/wifi/configuration', [
                    'name' => $configName, 'ssid' => (string) $payload['ssid'], 'country' => 'Czech', 'mode' => 'ap', 'disabled' => 'no',
                    'channel.band' => $band, 'datapath.vlan-id' => $effectiveVlan,
                    'security.authentication-types' => 'wpa2-psk,wpa3-psk', 'security.passphrase' => (string) $payload['password'],
                ]);
                $created[] = ['id' => $done['ret'] ?? null, 'name' => $configName];
                $existingNames[] = $configName;
                $rule = $provisioningByBand[$band];
                $ruleId = (string) $rule['.id'];
                if (!isset($changedRules[$ruleId])) $changedRules[$ruleId] = (string) ($rule['slave-configurations'] ?? '');
                $slaves = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['slave-configurations'] ?? '')))));
                if (!in_array($configName, $slaves, true)) $slaves[] = $configName;
                $repository->set('/interface/wifi/provisioning', $ruleId, ['slave-configurations' => implode(',', $slaves)]);
                $provisioningByBand[$band]['slave-configurations'] = implode(',', $slaves);
            }

            $this->jobs->progress($jobId, 'Ověřuji vysílání Wi‑Fi sítě');
            $verify = $repository->fullSnapshot();
            foreach ($created as $config) {
                $found = false;
                foreach ($verify['networks'] as $network) {
                    if (($network['name'] ?? '') === $config['name'] && ($network['ssid'] ?? '') === $payload['ssid']) $found = true;
                }
                if (!$found) throw new \RuntimeException('Nová konfigurace ' . $config['name'] . ' nebyla po zápisu nalezena.');
            }
        } catch (\Throwable $exception) {
            foreach ($changedRules as $ruleId => $originalSlaves) {
                try { $repository->set('/interface/wifi/provisioning', $ruleId, ['slave-configurations' => $originalSlaves]); } catch (\Throwable) {}
            }
            foreach (array_reverse($created) as $config) {
                if ($config['id']) { try { $repository->remove('/interface/wifi/configuration', (string) $config['id']); } catch (\Throwable) {} }
            }
            throw $exception;
        }
    }

    /** @param list<array<string,string>> $rows @return array<string,string>|null */
    private static function findByMac(array $rows, string $mac): ?array
    {
        foreach ($rows as $row) {
            $candidate = self::safeMac((string) ($row['mac-address'] ?? ''));
            if ($candidate !== null && $candidate === $mac) return $row;
        }
        return null;
    }

    /** @param list<array<string,string>> $rows @return array<string,string>|null */
    private static function findQueue(array $rows, string $ip): ?array
    {
        foreach ($rows as $row) {
            $targets = array_map('trim', explode(',', (string) ($row['target'] ?? '')));
            if (in_array($ip . '/32', $targets, true)) return $row;
        }
        return null;
    }

    private static function safeMac(string $mac): ?string
    {
        try { return normalize_mac($mac); } catch (\Throwable) { return null; }
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'yes', 'true', 'on'], true);
    }

    private static function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $ascii) ?? '', '_'));
        return mb_substr($slug !== '' ? $slug : 'wifi', 0, 40);
    }
}
