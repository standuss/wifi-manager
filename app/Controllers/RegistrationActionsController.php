<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\RouterFactory;

final class RegistrationActionsController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly RouterFactory $routerFactory,
        private readonly AuditService $audit,
    ) {
    }

    public function toggle(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $device = $this->device((int) ($_POST['device_id'] ?? 0));
        $block = (string) $device['registration_state'] !== 'blocked';
        $routerId = $this->activeRouterId();
        $repository = $this->routerFactory->repository($routerId);
        $snapshot = $repository->fastSnapshot();
        $mac = normalize_mac((string) $device['mac_address']);
        $type = (string) ($device['capsman_type'] ?? 'wifi') === 'legacy' ? 'legacy' : 'wifi';
        $accessMenu = $repository->menu($type, 'access-list');
        $approvedVlan = (int) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'approved_vlan_id'")->fetchColumn();
        $comment = $this->accessComment($device);

        $access = null;
        foreach ($snapshot['access_list'] ?? [] as $row) {
            if ((string) ($row['_capsman_type'] ?? 'wifi') === $type && $this->rowMac($row) === $mac) {
                $access = $row;
                break;
            }
        }

        if ($access === null) {
            $values = [
                'mac-address' => $mac,
                'action' => $block ? 'reject' : 'accept',
                'disabled' => 'no',
                'comment' => $comment,
            ];
            if (!$block) {
                $values['vlan-id'] = $approvedVlan;
                if ($type === 'legacy') $values['vlan-mode'] = 'use-tag';
            }
            $repository->add($accessMenu, $values);
        } else {
            $values = [
                'action' => $block ? 'reject' : 'accept',
                'disabled' => 'no',
                'comment' => $comment,
            ];
            if (!$block) {
                $values['vlan-id'] = $approvedVlan;
                if ($type === 'legacy') $values['vlan-mode'] = 'use-tag';
            }
            $repository->set($accessMenu, (string) $access['.id'], $values);
        }

        if ($block) $this->disconnectMac($repository, $mac, $type);

        $this->database->pdo()->prepare(
            "UPDATE devices SET registration_state = :state, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        )->execute(['state' => $block ? 'blocked' : 'registered', 'id' => (int) $device['id']]);

        $user = $this->auth->user();
        $this->audit->log(
            (int) $user['id'],
            $block ? 'device.blocked' : 'device.unblocked',
            ($block ? 'Zařízení bylo zakázáno: ' : 'Zařízení bylo povoleno: ') . $device['name'],
            'device',
            (int) $device['id'],
            ['mac_address' => $mac, 'capsman_type' => $type],
            request_ip(),
        );
        flash('success', $block ? 'Zařízení bylo zakázáno a aktuální spojení odpojeno.' : 'Zařízení bylo znovu povoleno.');
        $returnTo = (string) ($_POST['return_to'] ?? '/devices');
        redirect(in_array($returnTo, ['/clients', '/devices'], true) ? $returnTo : '/devices');
    }

    public function delete(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $device = $this->device((int) ($_POST['device_id'] ?? 0));
        $routerId = $this->activeRouterId();
        $repository = $this->routerFactory->repository($routerId);
        $snapshot = $repository->fastSnapshot();
        $mac = normalize_mac((string) $device['mac_address']);
        $type = (string) ($device['capsman_type'] ?? 'wifi') === 'legacy' ? 'legacy' : 'wifi';
        $accessMenu = $repository->menu($type, 'access-list');
        $currentIp = trim((string) ($device['current_ip'] ?? ''));
        $storedAccess = (string) ($device['mikrotik_access_id'] ?? '');
        $storedLease = (string) ($device['mikrotik_lease_id'] ?? '');
        $storedQueue = (string) ($device['mikrotik_queue_id'] ?? '');
        $approvedServer = (string) $this->database->pdo()->query("SELECT value FROM app_settings WHERE key = 'approved_dhcp_server'")->fetchColumn();

        foreach ($snapshot['access_list'] ?? [] as $row) {
            if (!isset($row['.id'])) continue;
            if ((string) ($row['_capsman_type'] ?? 'wifi') !== $type) continue;
            if ((string) $row['.id'] === $storedAccess || $this->rowMac($row) === $mac) {
                $repository->remove($accessMenu, (string) $row['.id']);
            }
        }
        foreach ($snapshot['leases'] ?? [] as $row) {
            if (!isset($row['.id'])) continue;
            $isStored = (string) $row['.id'] === $storedLease;
            $isManagedStatic = $this->rowMac($row) === $mac
                && (string) ($row['server'] ?? '') === $approvedServer
                && !in_array(strtolower((string) ($row['dynamic'] ?? 'no')), ['1', 'yes', 'true', 'on'], true);
            if ($isStored || $isManagedStatic) {
                $repository->remove('/ip/dhcp-server/lease', (string) $row['.id']);
            }
        }
        foreach ($snapshot['queues'] ?? [] as $row) {
            if (!isset($row['.id'])) continue;
            $targets = array_map('trim', explode(',', (string) ($row['target'] ?? '')));
            $matchesTarget = $currentIp !== '' && in_array($currentIp . '/32', $targets, true);
            $matchesComment = $this->rowMac(['mac-address' => (string) ($row['comment'] ?? '')]) === $mac;
            if ((string) $row['.id'] === $storedQueue || $matchesTarget || $matchesComment) {
                $repository->remove('/queue/simple', (string) $row['.id']);
            }
        }
        $this->disconnectMac($repository, $mac, $type);

        $this->database->transaction(function () use ($device, $routerId, $mac): void {
            $this->database->pdo()->prepare('DELETE FROM connected_clients WHERE router_id = :router_id AND mac_address = :mac')
                ->execute(['router_id' => $routerId, 'mac' => $mac]);
            $this->database->pdo()->prepare('UPDATE ip_assignments SET valid_to = CURRENT_TIMESTAMP WHERE device_id = :id AND valid_to IS NULL')
                ->execute(['id' => (int) $device['id']]);
            $this->database->pdo()->prepare(
                "UPDATE devices SET registration_state = 'archived', archived_at = CURRENT_TIMESTAMP,
                 mikrotik_access_id = NULL, mikrotik_lease_id = NULL, mikrotik_queue_id = NULL,
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute(['id' => (int) $device['id']]);
        });

        $user = $this->auth->user();
        $this->audit->log(
            (int) $user['id'],
            'device.deleted',
            'Registrace zařízení byla odstraněna: ' . $device['name'],
            'device',
            (int) $device['id'],
            ['mac_address' => $mac, 'capsman_type' => $type],
            request_ip(),
        );
        flash('success', 'Zařízení bylo odstraněno z WiFi Manageru i z Access Listu, DHCP a Simple Queue.');
        redirect('/devices');
    }

    /** @return array<string,mixed> */
    private function device(int $id): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT d.*, p.name AS person_name FROM devices d LEFT JOIN people p ON p.id = d.person_id
             WHERE d.id = :id AND d.registration_state != 'archived' LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $device = $statement->fetch();
        if (!is_array($device)) throw new \RuntimeException('Zařízení nebylo nalezeno.');
        return $device;
    }

    private function activeRouterId(): int
    {
        $id = (int) $this->database->pdo()->query('SELECT id FROM routers WHERE enabled = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id <= 0) throw new \RuntimeException('Není nastavený aktivní MikroTik.');
        return $id;
    }

    /** @param array<string,mixed> $row */
    private function rowMac(array $row): ?string
    {
        $value = (string) ($row['mac-address'] ?? '');
        if ($value === '') return null;
        try {
            return normalize_mac($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function accessComment(array $device): string
    {
        $parts = array_values(array_filter([
            'WiFi Manager', trim((string) ($device['person_name'] ?? '')),
            trim((string) ($device['name'] ?? '')), trim((string) ($device['mac_address'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        return mb_substr(implode(' | ', $parts), 0, 120);
    }

    private function disconnectMac(object $repository, string $mac, string $type): void
    {
        $menu = $repository->menu($type, 'registration-table');
        foreach ($repository->rows($menu . '/print', ['.id', 'mac-address']) as $row) {
            if (!isset($row['.id']) || $this->rowMac($row) !== $mac) continue;
            try {
                $repository->remove($menu, (string) $row['.id']);
            } catch (\Throwable) {
                // Klient se mohl odpojit sám mezi načtením tabulky a remove.
            }
        }
    }
}
