<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Database;
use WifiManager\RouterOS\RouterOsException;
use WifiManager\RouterOS\RouterRepository;

final class JobProcessor
{
    private const LOGGING_ACTION_NAME = 'wifimanagerRemote';
    private const LEGACY_LOGGING_ACTION_NAME = 'wifimanager-remote';

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
                'update_device' => $this->registerDevice($jobId, $repository, $job['payload']),
                'toggle_network' => $this->toggleNetwork($jobId, $repository, $job['payload']),
                'set_network_hidden' => $this->setNetworkHidden($jobId, $repository, $job['payload']),
                'apply_network' => $this->applyNetwork($jobId, $repository, $job['payload']),
                'create_network' => $this->createNetwork($jobId, $repository, $job['payload']),
                'provision_ap' => $this->provisionAccessPoint($jobId, $repository, $job['payload']),
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
                ['type' => $job['type'], 'router_id' => (int) $job['router_id']],
            );
        } catch (\Throwable $exception) {
            if (isset($job['payload']['device_id'])) {
                $this->database->pdo()->prepare(
                    "UPDATE devices SET registration_state='incomplete', updated_at=CURRENT_TIMESTAMP WHERE id=:id AND registration_state!='archived'"
                )->execute(['id' => (int) $job['payload']['device_id']]);
            }
            $this->jobs->failed($jobId, $exception->getMessage());
            $this->audit->log(
                isset($job['created_by']) ? (int) $job['created_by'] : null,
                'job.failed',
                'Síťová změna selhala: ' . $exception->getMessage(),
                'sync_job',
                $jobId,
                ['type' => $job['type'], 'router_id' => (int) $job['router_id'], 'error_class' => $exception::class],
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
        $actionName = self::LOGGING_ACTION_NAME;
        foreach ($actions as $row) {
            if (($row['name'] ?? '') !== self::LOGGING_ACTION_NAME) continue;
            $action = $row;
            break;
        }
        if ($action === null) {
            foreach ($actions as $row) {
                if (($row['name'] ?? '') !== self::LEGACY_LOGGING_ACTION_NAME) continue;
                $action = $row;
                $actionName = self::LEGACY_LOGGING_ACTION_NAME;
                break;
            }
        }
        $legacyLogging = false;
        foreach ($actions as $row) {
            if (array_key_exists('remote', $row)) {
                $legacyLogging = true;
                break;
            }
        }
        $applyLoggingAction = function (bool $legacy) use ($repository, $action, $actionName, $target, $syslogPort, $remoteEndpoint, $transport): void {
            $attributes = [
                'target' => 'remote',
                'remote-protocol' => $transport,
                'remote-log-format' => $transport === 'tcp' ? 'cef' : 'syslog',
            ];
            if ($transport !== 'tcp') $attributes['syslog-time-format'] = 'iso8601';
            if ($legacy) {
                $attributes['remote'] = $target;
                $attributes['remote-port'] = (string) $syslogPort;
            } else {
                $attributes['remote-port'] = $remoteEndpoint;
            }
            if ($action === null) {
                $repository->add('/system/logging/action', ['name' => $actionName] + $attributes);
            } else {
                $repository->set('/system/logging/action', (string) $action['.id'], $attributes);
            }
        };
        if ($legacyLogging) {
            $applyLoggingAction($legacyLogging);
        } else {
            try {
                $applyLoggingAction(false);
            } catch (RouterOsException $exception) {
                if (!str_contains(strtolower($exception->getMessage()), 'remote-port')) throw $exception;
                // RouterOS 7.18+ changed the logging endpoint representation. Some
                // point releases do not expose enough metadata to detect it first.
                $applyLoggingAction(true);
            }
        }

        $topics = ['info,!firewall', 'warning', 'error', 'critical'];
        $rules = $repository->rows('/system/logging/print');
        foreach ($topics as $index => $topic) {
            $prefix = 'WFM' . ($index + 1) . '|';
            $rule = null;
            foreach ($rules as $candidate) if (($candidate['prefix'] ?? '') === $prefix) $rule = $candidate;
            $values = ['topics' => $topic, 'action' => $actionName, 'prefix' => $prefix, 'disabled' => 'no'];
            if ($rule === null) $repository->add('/system/logging', $values);
            else $repository->set('/system/logging', (string) $rule['.id'], $values);
        }

        $this->jobs->progress($jobId, 'Nastavuji export IPFIX na MikroTiku');
        $repository->setSingleton('/ip/traffic-flow', [
            'enabled' => 'yes', 'active-flow-timeout' => '5m', 'inactive-flow-timeout' => '15s',
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
        $flowValues = ['dst-address' => $target, 'port' => $flowPort, 'version' => 'ipfix'];
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
            if (self::loggingActionMatches($row, $target, $syslogPort, $remoteEndpoint)) $verifyAction = true;
        }
        $verifyFlow = false;
        foreach ($repository->rows('/ip/traffic-flow/target/print') as $row) {
            if (self::flowTargetMatches($row, $target, $flowPort)) $verifyFlow = true;
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

        $deviceStatement = $this->database->pdo()->prepare('SELECT * FROM devices WHERE id = :id');
        $deviceStatement->execute(['id' => $deviceId]);
        $device = $deviceStatement->fetch();
        if (!is_array($device)) throw new \RuntimeException('Zařízení nebylo v evidenci nalezeno.');
        $type = RouterRepository::capsmanType((string) ($payload['capsman_type'] ?? $device['capsman_type'] ?? 'wifi'));
        $accessMenu = $repository->menu($type, 'access-list');
        $oldIp = (string) ($device['current_ip'] ?? '');
        $storedQueueId = (string) ($device['mikrotik_queue_id'] ?? '');
        $personName = trim((string) ($payload['person_name'] ?? ''));
        $personNote = trim((string) ($payload['note'] ?? ''));
        $comment = self::managedComment($personName, $name, $mac);

        $snapshot = $repository->fastSnapshot();
        foreach ($snapshot['leases'] as $lease) {
            if (($lease['address'] ?? '') === $ip && self::safeMac((string) ($lease['mac-address'] ?? '')) !== $mac) {
                throw new \RuntimeException('Vybraná IP adresa je už obsazená jiným zařízením.');
            }
        }

        /** @var list<callable():void> $rollback */
        $rollback = [];
        try {
        $this->jobs->progress($jobId, 'Nastavuji Wi‑Fi Access List');
        $access = self::findByMac($snapshot['access_list'], $mac, $type);
        $accessValues = [
            'action' => 'accept', 'comment' => $comment, 'disabled' => 'no', 'vlan-id' => $approvedVlan,
        ];
        if ($type === 'legacy') $accessValues['vlan-mode'] = 'use-tag';
        if ($access === null) {
            $createdAccess = $repository->add($accessMenu, ['mac-address' => $mac] + $accessValues);
            $createdAccessId = (string) ($createdAccess['ret'] ?? '');
            $rollback[] = static function () use ($repository, $accessMenu, $createdAccessId, $mac): void {
                if ($createdAccessId !== '') {
                    $repository->remove($accessMenu, $createdAccessId);
                    return;
                }
                foreach ($repository->rows($accessMenu . '/print', ['.id', 'mac-address']) as $row) {
                    if (isset($row['.id']) && self::safeMac((string) ($row['mac-address'] ?? '')) === $mac) $repository->remove($accessMenu, (string) $row['.id']);
                }
            };
        } else {
            $accessId = (string) $access['.id'];
            $originalAccess = [
                'action' => (string) ($access['action'] ?? 'accept'), 'comment' => (string) ($access['comment'] ?? ''),
                'disabled' => (string) ($access['disabled'] ?? 'no'), 'vlan-id' => (string) ($access['vlan-id'] ?? ''),
            ];
            if ($type === 'legacy') $originalAccess['vlan-mode'] = (string) ($access['vlan-mode'] ?? 'no-tag');
            $rollback[] = static fn () => $repository->set($accessMenu, $accessId, $originalAccess);
            $repository->set($accessMenu, (string) $access['.id'], $accessValues);
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
            $createdLease = $repository->add('/ip/dhcp-server/lease', [
                'address' => $ip, 'mac-address' => $mac, 'server' => $dhcpServer, 'comment' => $comment,
            ]);
            $createdLeaseId = (string) ($createdLease['ret'] ?? '');
            $rollback[] = static function () use ($repository, $createdLeaseId, $mac, $dhcpServer): void {
                foreach ($repository->rows('/ip/dhcp-server/lease/print', ['.id', 'mac-address', 'server']) as $row) {
                    if (!isset($row['.id'])) continue;
                    if (($createdLeaseId !== '' && (string) $row['.id'] === $createdLeaseId)
                        || (self::safeMac((string) ($row['mac-address'] ?? '')) === $mac && (string) ($row['server'] ?? '') === $dhcpServer)) {
                        $repository->remove('/ip/dhcp-server/lease', (string) $row['.id']);
                    }
                }
            };
        } else {
            $leaseId = (string) $leaseToUse['.id'];
            $leaseWasDynamic = self::truthy($leaseToUse['dynamic'] ?? 'no');
            if ($leaseWasDynamic) {
                $repository->action('/ip/dhcp-server/lease', 'make-static', ['numbers' => $leaseId]);
                $rollback[] = static fn () => $repository->remove('/ip/dhcp-server/lease', $leaseId);
            } else {
                $originalLease = [
                    'address' => (string) ($leaseToUse['address'] ?? ''), 'server' => (string) ($leaseToUse['server'] ?? ''),
                    'comment' => (string) ($leaseToUse['comment'] ?? ''),
                ];
                $rollback[] = static fn () => $repository->set('/ip/dhcp-server/lease', $leaseId, $originalLease);
            }
            $repository->set('/ip/dhcp-server/lease', $leaseId, ['address' => $ip, 'server' => $dhcpServer, 'comment' => $comment]);
        }

        $this->jobs->progress($jobId, 'Nastavuji omezení rychlosti');
        $queue = self::findQueueForDevice($snapshot['queues'], $mac, $ip, $oldIp, $storedQueueId);
        if ($queue === null) {
            $createdQueue = $repository->add('/queue/simple', [
                'name' => $name, 'target' => $ip . '/32', 'max-limit' => $rateUp . '/' . $rateDown, 'comment' => $comment,
            ]);
            $createdQueueId = (string) ($createdQueue['ret'] ?? '');
            $rollback[] = static function () use ($repository, $createdQueueId, $ip): void {
                foreach ($repository->rows('/queue/simple/print', ['.id', 'target']) as $row) {
                    if (!isset($row['.id'])) continue;
                    if (($createdQueueId !== '' && (string) $row['.id'] === $createdQueueId) || in_array($ip . '/32', array_map('trim', explode(',', (string) ($row['target'] ?? ''))), true)) {
                        $repository->remove('/queue/simple', (string) $row['.id']);
                    }
                }
            };
        } else {
            $queueId = (string) $queue['.id'];
            $originalQueue = [
                'name' => (string) ($queue['name'] ?? ''), 'target' => (string) ($queue['target'] ?? ''),
                'max-limit' => (string) ($queue['max-limit'] ?? ''), 'comment' => (string) ($queue['comment'] ?? ''),
                'disabled' => (string) ($queue['disabled'] ?? 'no'),
            ];
            $rollback[] = static fn () => $repository->set('/queue/simple', $queueId, $originalQueue);
            $repository->set('/queue/simple', (string) $queue['.id'], [
                'name' => $name, 'target' => $ip . '/32', 'max-limit' => $rateUp . '/' . $rateDown, 'comment' => $comment, 'disabled' => 'no',
            ]);
        }

        $this->jobs->progress($jobId, 'Ověřuji vytvořené záznamy');
        $verify = $repository->fastSnapshot();
        $verifiedAccess = self::findByMac($verify['access_list'], $mac, $type);
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

        $this->database->transaction(function () use ($deviceId, $ip, $name, $rateDown, $rateUp, $personName, $personNote, $type, $verifiedAccess, $verifiedLease, $verifiedQueue): void {
            $current = $this->database->pdo()->prepare('SELECT person_id, current_ip FROM devices WHERE id = :id');
            $current->execute(['id' => $deviceId]);
            $currentDevice = $current->fetch();
            if (!is_array($currentDevice)) throw new \RuntimeException('Zařízení během aktualizace zmizelo z evidence.');
            $personId = isset($currentDevice['person_id']) ? (int) $currentDevice['person_id'] : null;
            if ($personName !== '') {
                $personId = $this->resolvePersonForDevice($deviceId, $personId, $personName, $personNote);
            }
            $statement = $this->database->pdo()->prepare(
                "UPDATE devices SET person_id = :person_id, name = :name, current_ip = :ip, rate_down = :rate_down, rate_up = :rate_up, capsman_type = :capsman_type,
                 registration_state = 'registered', mikrotik_access_id = :access_id, mikrotik_lease_id = :lease_id,
                 mikrotik_queue_id = :queue_id, registered_at = COALESCE(registered_at, CURRENT_TIMESTAMP),
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $statement->execute([
                'person_id' => $personId,
                'name' => $name,
                'ip' => $ip,
                'rate_down' => $rateDown,
                'rate_up' => $rateUp,
                'capsman_type' => $type,
                'access_id' => $verifiedAccess['.id'] ?? null,
                'lease_id' => $verifiedLease['.id'] ?? null,
                'queue_id' => $verifiedQueue['.id'] ?? null,
                'id' => $deviceId,
            ]);
            if ((string) ($currentDevice['current_ip'] ?? '') !== $ip) {
                $this->database->pdo()->prepare('UPDATE ip_assignments SET valid_to = CURRENT_TIMESTAMP WHERE device_id = :id AND valid_to IS NULL')
                    ->execute(['id' => $deviceId]);
                $this->database->pdo()->prepare('INSERT INTO ip_assignments (device_id, ip_address) VALUES (:id, :ip)')
                    ->execute(['id' => $deviceId, 'ip' => $ip]);
            }
        });
        } catch (\Throwable $exception) {
            $rollbackErrors = [];
            foreach (array_reverse($rollback) as $step) {
                try { $step(); } catch (\Throwable $rollbackException) { $rollbackErrors[] = $rollbackException->getMessage(); }
            }
            $message = 'Registrace nebyla dokončena; provedené změny byly vráceny. ' . $exception->getMessage();
            if ($rollbackErrors !== []) $message .= ' Některé kroky rollbacku selhaly: ' . implode(' | ', $rollbackErrors);
            throw new \RuntimeException($message, 0, $exception);
        }
    }

    /** @param array<string,mixed> $payload */
    private function toggleNetwork(int $jobId, RouterRepository $repository, array $payload): void
    {
        $id = (string) $payload['mikrotik_id'];
        $type = RouterRepository::capsmanType((string) ($payload['capsman_type'] ?? 'wifi'));
        $menu = $repository->menu($type, 'configuration');
        $enable = (bool) $payload['enable'];
        $this->jobs->progress($jobId, $enable ? 'Zapínám Wi‑Fi síť' : 'Vypínám Wi‑Fi síť');
        $repository->set($menu, $id, ['disabled' => $enable ? 'no' : 'yes']);
        // Avoid .proplist here; RouterOS 7.22 may otherwise omit disabled.
        $rows = $repository->rows($menu . '/print');
        foreach ($rows as $row) {
            if (($row['.id'] ?? '') === $id) {
                $disabled = ($row['disabled'] ?? 'no') === 'yes';
                if ($disabled === $enable) {
                    throw new \RuntimeException('MikroTik nepotvrdil změnu stavu Wi‑Fi sítě.');
                }
                $this->rememberNetworkDesired((int) ($payload['network_id'] ?? 0), ['enabled' => $enable]);
                return;
            }
        }
        throw new \RuntimeException('Wi‑Fi konfigurace po změně nebyla nalezena.');
    }

    /** @param array<string,mixed> $payload */
    private function setNetworkHidden(int $jobId, RouterRepository $repository, array $payload): void
    {
        $id = (string) $payload['mikrotik_id'];
        $type = RouterRepository::capsmanType((string) ($payload['capsman_type'] ?? 'wifi'));
        $menu = $repository->menu($type, 'configuration');
        $hidden = (bool) $payload['hidden'];
        $this->jobs->progress($jobId, $hidden ? 'Skrývám název Wi‑Fi sítě' : 'Zapínám vysílání názvu Wi‑Fi sítě');
        $repository->set($menu, $id, ['hide-ssid' => $hidden ? 'yes' : 'no']);
        $row = $this->configuration($repository, $menu, $id);
        if (self::truthy($row['hide-ssid'] ?? 'no') !== $hidden) {
            throw new \RuntimeException('MikroTik nepotvrdil změnu viditelnosti SSID.');
        }
        $this->rememberNetworkDesired((int) ($payload['network_id'] ?? 0), ['hidden' => $hidden]);
    }

    /** @param array<string,mixed> $payload */
    private function applyNetwork(int $jobId, RouterRepository $repository, array $payload): void
    {
        $id = (string) $payload['mikrotik_id'];
        $type = RouterRepository::capsmanType((string) ($payload['capsman_type'] ?? 'wifi'));
        $desired = is_array($payload['desired'] ?? null) ? $payload['desired'] : [];
        if ($desired === []) throw new \RuntimeException('Požadovaný stav Wi‑Fi sítě je prázdný.');
        $values = [
            'name' => (string) ($desired['name'] ?? ''),
            'ssid' => (string) ($desired['ssid'] ?? ''),
            'disabled' => !empty($desired['enabled']) ? 'no' : 'yes',
            'hide-ssid' => !empty($desired['hidden']) ? 'yes' : 'no',
        ];
        if (($desired['band'] ?? '') !== '') $values['channel.band'] = (string) $desired['band'];
        if (($desired['vlan_id'] ?? null) !== null) {
            $values['datapath.vlan-id'] = (int) $desired['vlan_id'];
            if ($type === 'legacy') $values['datapath.vlan-mode'] = 'use-tag';
        }
        $this->jobs->progress($jobId, 'Obnovuji konfiguraci Wi‑Fi podle WiFi Manageru');
        $menu = $repository->menu($type, 'configuration');
        $repository->set($menu, $id, array_filter($values, static fn (mixed $value): bool => $value !== ''));
        $this->configuration($repository, $menu, $id);
        $this->database->pdo()->prepare(
            "UPDATE wifi_networks SET remote_json=desired_json, sync_state='synced', conflict_summary=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=:id"
        )->execute(['id' => (int) ($payload['network_id'] ?? 0)]);
    }

    /** @param array<string,mixed> $payload */
    private function provisionAccessPoint(int $jobId, RouterRepository $repository, array $payload): void
    {
        $type = RouterRepository::capsmanType((string) ($payload['capsman_type'] ?? 'wifi'));
        $remoteCapId = trim((string) ($payload['remote_cap_id'] ?? ''));
        if ($remoteCapId === '') throw new \RuntimeException('Chybí identifikátor vzdáleného přístupového bodu.');
        $this->jobs->progress($jobId, 'Aplikuji provisioning na přístupový bod');
        $repository->action($repository->menu($type, 'remote-cap'), 'provision', ['numbers' => $remoteCapId]);
    }

    /** @param array<string,mixed> $payload */
    private function createNetwork(int $jobId, RouterRepository $repository, array $payload): void
    {
        $type = RouterRepository::capsmanType((string) ($payload['capsman_type'] ?? 'wifi'));
        $configurationMenu = $repository->menu($type, 'configuration');
        $provisioningMenu = $repository->menu($type, 'provisioning');
        $snapshot = $repository->fullSnapshot();
        $bands = $payload['band'] === 'both' ? ['2ghz', '5ghz'] : [(string) $payload['band']];
        $provisioningByBand = [];
        foreach ($bands as $band) {
            foreach ($snapshot['provisioning'] as $rule) {
                if ((string) ($rule['_capsman_type'] ?? 'wifi') !== $type) continue;
                if (($rule['disabled'] ?? 'no') !== 'yes' && self::provisioningMatchesBand($rule, $band, $type)) {
                    $provisioningByBand[$band] = $rule;
                    break;
                }
            }
            if (!isset($provisioningByBand[$band])) throw new \RuntimeException('Pro pásmo ' . $band . ' nebylo nalezeno provisioning pravidlo.');
        }

        $baseName = self::slug((string) $payload['name']);
        $existingNames = [];
        foreach ($snapshot['networks'] as $network) {
            if ((string) ($network['_capsman_type'] ?? 'wifi') === $type) $existingNames[] = (string) ($network['name'] ?? '');
        }
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
                $actualBand = self::configurationBand($provisioningByBand[$band], $band, $type);
                $values = [
                    'name' => $configName, 'ssid' => (string) $payload['ssid'], 'country' => 'Czech', 'mode' => 'ap', 'disabled' => 'no',
                    'hide-ssid' => !empty($payload['hidden']) ? 'yes' : 'no',
                    'channel.band' => $actualBand, 'datapath.vlan-id' => $effectiveVlan,
                    'security.authentication-types' => $type === 'legacy' ? 'wpa2-psk' : 'wpa2-psk,wpa3-psk',
                    'security.passphrase' => (string) $payload['password'],
                ];
                if ($type === 'legacy') {
                    $values['datapath.vlan-mode'] = 'use-tag';
                    $values['security.encryption'] = 'aes-ccm';
                }
                $done = $repository->add($configurationMenu, $values);
                $created[] = ['id' => $done['ret'] ?? null, 'name' => $configName, 'band' => $actualBand];
                $existingNames[] = $configName;
                $rule = $provisioningByBand[$band];
                $ruleId = (string) $rule['.id'];
                if (!isset($changedRules[$ruleId])) $changedRules[$ruleId] = (string) ($rule['slave-configurations'] ?? '');
                $slaves = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['slave-configurations'] ?? '')))));
                if (!in_array($configName, $slaves, true)) $slaves[] = $configName;
                $repository->set($provisioningMenu, $ruleId, ['slave-configurations' => implode(',', $slaves)]);
                $provisioningByBand[$band]['slave-configurations'] = implode(',', $slaves);
            }

            $this->jobs->progress($jobId, 'Ověřuji vysílání Wi‑Fi sítě');
            $verify = $repository->fullSnapshot();
            foreach ($created as $createdIndex => $config) {
                $found = false;
                foreach ($verify['networks'] as $network) {
                    if ((string) ($network['_capsman_type'] ?? 'wifi') !== $type) continue;
                    if (($network['name'] ?? '') === $config['name'] && ($network['ssid'] ?? '') === $payload['ssid']) {
                        $found = true;
                        $config['id'] = $network['.id'] ?? $config['id'];
                        $created[$createdIndex]['id'] = $config['id'];
                        $this->storeManagedNetwork((int) ($payload['router_id'] ?? 0), $type, $config, $payload);
                    }
                }
                if (!$found) throw new \RuntimeException('Nová konfigurace ' . $config['name'] . ' nebyla po zápisu nalezena.');
            }
        } catch (\Throwable $exception) {
            if ((int) ($payload['router_id'] ?? 0) > 0) {
                $deleteLocal = $this->database->pdo()->prepare('DELETE FROM wifi_networks WHERE router_id=:router_id AND mikrotik_id=:mikrotik_id');
                foreach ($created as $config) {
                    if (!empty($config['id'])) $deleteLocal->execute([
                        'router_id' => (int) $payload['router_id'], 'mikrotik_id' => $type . ':' . (string) $config['id'],
                    ]);
                }
            }
            foreach ($changedRules as $ruleId => $originalSlaves) {
                try { $repository->set($provisioningMenu, $ruleId, ['slave-configurations' => $originalSlaves]); } catch (\Throwable) {}
            }
            foreach (array_reverse($created) as $config) {
                if ($config['id']) { try { $repository->remove($configurationMenu, (string) $config['id']); } catch (\Throwable) {} }
            }
            throw $exception;
        }
    }

    /** @param list<array<string,string>> $rows @return array<string,string>|null */
    private static function findByMac(array $rows, string $mac, ?string $type = null): ?array
    {
        foreach ($rows as $row) {
            if ($type !== null && RouterRepository::capsmanType((string) ($row['_capsman_type'] ?? 'wifi')) !== $type) continue;
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

    /** @param list<array<string,string>> $rows @return array<string,string>|null */
    private static function findQueueForDevice(array $rows, string $mac, string $newIp, string $oldIp, string $storedId): ?array
    {
        foreach ($rows as $row) {
            if ($storedId !== '' && (string) ($row['.id'] ?? '') === $storedId) return $row;
        }
        foreach ($rows as $row) {
            if (self::safeMac((string) ($row['comment'] ?? '')) === $mac) return $row;
        }
        $queue = self::findQueue($rows, $newIp);
        if ($queue !== null || $oldIp === '' || $oldIp === $newIp) return $queue;
        return self::findQueue($rows, $oldIp);
    }

    /** @param array<string,mixed> $row */
    private static function loggingActionMatches(array $row, string $target, int $port, string $endpoint): bool
    {
        if (!in_array((string) ($row['name'] ?? ''), [self::LOGGING_ACTION_NAME, self::LEGACY_LOGGING_ACTION_NAME], true)) return false;
        $legacyTarget = trim((string) ($row['remote'] ?? ''), '[]');
        if ($legacyTarget !== '') {
            return $legacyTarget === trim($target, '[]') && (int) ($row['remote-port'] ?? 0) === $port;
        }
        return (string) ($row['remote-port'] ?? '') === $endpoint;
    }

    /** @param array<string,mixed> $row */
    private static function flowTargetMatches(array $row, string $target, int $port): bool
    {
        return (string) ($row['dst-address'] ?? '') === $target
            && (int) ($row['port'] ?? 0) === $port
            && strtolower((string) ($row['version'] ?? '')) === 'ipfix';
    }

    private function resolvePersonForDevice(int $deviceId, ?int $currentPersonId, string $name, string $note): int
    {
        $people = $this->database->pdo()->query('SELECT id, name FROM people WHERE active = 1')->fetchAll();
        foreach ($people as $person) {
            if (mb_strtolower(trim((string) $person['name'])) !== mb_strtolower($name)) continue;
            $personId = (int) $person['id'];
            $this->database->pdo()->prepare('UPDATE people SET name = :name, note = :note, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['name' => $name, 'note' => $note !== '' ? $note : null, 'id' => $personId]);
            return $personId;
        }

        if ($currentPersonId !== null) {
            $count = $this->database->pdo()->prepare("SELECT COUNT(*) FROM devices WHERE person_id = :person_id AND id != :device_id AND registration_state != 'archived'");
            $count->execute(['person_id' => $currentPersonId, 'device_id' => $deviceId]);
            if ((int) $count->fetchColumn() === 0) {
                $this->database->pdo()->prepare('UPDATE people SET name = :name, note = :note, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute(['name' => $name, 'note' => $note !== '' ? $note : null, 'id' => $currentPersonId]);
                return $currentPersonId;
            }
        }

        $statement = $this->database->pdo()->prepare('INSERT INTO people (name, note) VALUES (:name, :note)');
        $statement->execute(['name' => $name, 'note' => $note !== '' ? $note : null]);
        return (int) $this->database->pdo()->lastInsertId();
    }

    /** @return array<string,string> */
    private function configuration(RouterRepository $repository, string $menu, string $id): array
    {
        foreach ($repository->rows($menu . '/print') as $row) {
            if ((string) ($row['.id'] ?? '') === $id) return $row;
        }
        throw new \RuntimeException('Wi‑Fi konfigurace nebyla po změně na MikroTiku nalezena.');
    }

    /** @param array<string,mixed> $changes */
    private function rememberNetworkDesired(int $networkId, array $changes): void
    {
        if ($networkId <= 0) return;
        $statement = $this->database->pdo()->prepare('SELECT * FROM wifi_networks WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $networkId]);
        $network = $statement->fetch();
        if (!is_array($network)) return;
        $desired = json_decode((string) ($network['desired_json'] ?? ''), true);
        if (!is_array($desired)) {
            $desired = [
                'name' => (string) $network['config_name'], 'ssid' => (string) $network['ssid'],
                'band' => (string) ($network['band'] ?? ''), 'vlan_id' => $network['vlan_id'] !== null ? (int) $network['vlan_id'] : null,
                'enabled' => (bool) $network['enabled'], 'hidden' => (bool) $network['hidden'],
                'capsman_type' => RouterRepository::capsmanType((string) ($network['capsman_type'] ?? 'wifi')),
            ];
        }
        $desired = array_replace($desired, $changes);
        $json = json_encode($desired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->database->pdo()->prepare(
            "UPDATE wifi_networks SET managed=1, desired_json=:desired, remote_json=:desired, sync_state='synced', conflict_summary=NULL,
             enabled=:enabled, hidden=:hidden, updated_at=CURRENT_TIMESTAMP WHERE id=:id"
        )->execute([
            'desired' => $json, 'enabled' => !empty($desired['enabled']) ? 1 : 0,
            'hidden' => !empty($desired['hidden']) ? 1 : 0, 'id' => $networkId,
        ]);
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $payload */
    private function storeManagedNetwork(int $routerId, string $type, array $config, array $payload): void
    {
        if ($routerId <= 0 || empty($config['id'])) return;
        $desired = [
            'name' => (string) $config['name'], 'ssid' => (string) $payload['ssid'], 'band' => (string) $config['band'],
            'vlan_id' => (int) $payload['vlan_id'], 'enabled' => true, 'hidden' => !empty($payload['hidden']), 'capsman_type' => $type,
        ];
        $json = json_encode($desired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO wifi_networks
             (router_id,mikrotik_id,mikrotik_raw_id,capsman_type,config_name,ssid,band,vlan_id,registration_enabled,enabled,hidden,managed,source_hash,desired_json,remote_json,sync_state)
             VALUES (:router_id,:mikrotik_id,:raw_id,:type,:name,:ssid,:band,:vlan,:registration,1,:hidden,1,:hash,:desired,:remote,\'synced\')
             ON CONFLICT(router_id,mikrotik_id) DO UPDATE SET mikrotik_raw_id=excluded.mikrotik_raw_id,capsman_type=excluded.capsman_type,
             config_name=excluded.config_name,ssid=excluded.ssid,band=excluded.band,vlan_id=excluded.vlan_id,registration_enabled=excluded.registration_enabled,
             enabled=1,hidden=excluded.hidden,managed=1,source_hash=excluded.source_hash,desired_json=excluded.desired_json,remote_json=excluded.remote_json,
             sync_state=\'synced\',conflict_summary=NULL,updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'router_id' => $routerId, 'mikrotik_id' => $type . ':' . (string) $config['id'], 'raw_id' => (string) $config['id'],
            'type' => $type, 'name' => $config['name'], 'ssid' => $payload['ssid'], 'band' => $config['band'],
            'vlan' => (int) $payload['vlan_id'], 'registration' => !empty($payload['registration_enabled']) ? 1 : 0,
            'hidden' => !empty($payload['hidden']) ? 1 : 0, 'hash' => hash('sha256', $json), 'desired' => $json, 'remote' => $json,
        ]);
    }

    /** @param array<string,mixed> $rule */
    private static function provisioningMatchesBand(array $rule, string $family, string $type): bool
    {
        $supported = strtolower((string) ($rule['supported-bands'] ?? ''));
        if ($supported !== '') return str_contains($supported, $family);
        if ($type !== 'legacy') return false;
        $modes = array_filter(array_map('trim', explode(',', strtolower((string) ($rule['hw-supported-modes'] ?? '')))));
        $wanted = $family === '2ghz' ? ['b', 'g', 'gn'] : ['a', 'an', 'ac'];
        return array_intersect($modes, $wanted) !== [];
    }

    /** @param array<string,mixed> $rule */
    private static function configurationBand(array $rule, string $family, string $type): string
    {
        $supported = array_values(array_filter(array_map('trim', explode(',', (string) ($rule['supported-bands'] ?? '')))));
        foreach ($supported as $band) if (str_starts_with(strtolower($band), $family)) return $band;
        if ($type === 'legacy') return $family === '2ghz' ? '2ghz-b/g/n' : '5ghz-a/n/ac';
        return $family === '2ghz' ? '2ghz-ax' : '5ghz-ax';
    }

    private static function managedComment(string $person, string $device, string $mac): string
    {
        $parts = array_values(array_filter(['WiFi Manager', trim($person), trim($device), $mac], static fn (string $value): bool => $value !== ''));
        return mb_substr(implode(' | ', $parts), 0, 120);
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
