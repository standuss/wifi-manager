<?php

declare(strict_types=1);

namespace WifiManager\RouterOS;

final class RouterRepository
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    /** @return array{identity:array<string,string>,resource:array<string,string>} */
    public function testConnection(): array
    {
        return [
            'identity' => $this->first('/system/identity/print', ['name']),
            'resource' => $this->first('/system/resource/print', ['board-name', 'version', 'uptime', 'cpu-load', 'free-memory', 'total-memory']),
        ];
    }

    /** @return array<string,mixed> */
    public function fullSnapshot(): array
    {
        $snapshot = $this->fastSnapshot();
        $warnings = $snapshot['warnings'] ?? [];
        $snapshot['identity'] = $this->safeFirst('/system/identity/print', ['name'], $warnings);
        $snapshot['resource'] = $this->safeFirst('/system/resource/print', ['board-name', 'version', 'uptime', 'cpu-load', 'free-memory', 'total-memory'], $warnings);
        // RouterOS 7.22 can return only internal IDs for selected WiFi tables
        // when .proplist is used. Complete rows also expose the passphrase to
        // API users with the sensitive policy, which lets us cache it encrypted.
        $snapshot['networks'] = $this->safeRows('/interface/wifi/configuration/print', [], $warnings);
        // RouterOS 7.22 can return incomplete Remote CAP records when .proplist is used.
        // This table is small, so reading complete rows is both reliable and inexpensive.
        $snapshot['caps'] = $this->safeRows('/interface/wifi/capsman/remote-cap/print', [], $warnings);
        $snapshot['radios'] = $this->safeRows('/interface/wifi/radio/print', [
            '.id', 'cap', 'radio-mac', 'interface', 'bands', 'hw-type', 'max-peers',
        ], $warnings);
        $snapshot['provisioning'] = $this->safeRows('/interface/wifi/provisioning/print', [], $warnings);
        $snapshot['warnings'] = $warnings;
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function fastSnapshot(): array
    {
        $warnings = [];
        return [
            'clients' => $this->safeRows('/interface/wifi/registration-table/print', [
                '.id', 'mac-address', 'interface', 'ssid', 'signal', 'band', 'vlan-id', 'tx-bits-per-second', 'rx-bits-per-second', 'tx-rate', 'rx-rate', 'uptime', 'last-activity', 'authorized',
            ], $warnings),
            'leases' => $this->safeRows('/ip/dhcp-server/lease/print', [
                '.id', 'address', 'mac-address', 'server', 'host-name', 'comment', 'dynamic', 'status', 'last-seen', 'expires-after',
            ], $warnings),
            'access_list' => $this->safeRows('/interface/wifi/access-list/print', [
                '.id', 'mac-address', 'comment', 'action', 'disabled', 'interface', 'vlan-id', 'ssid-regexp',
            ], $warnings),
            'queues' => $this->safeRows('/queue/simple/print', [
                '.id', 'name', 'target', 'max-limit', 'disabled', 'comment', 'rate', 'bytes',
            ], $warnings),
            'warnings' => $warnings,
        ];
    }

    /** @return list<array<string,string>> */
    public function rows(string $command, array $properties = []): array
    {
        return $this->client->command($command, [], [], $properties)['rows'];
    }

    /** @param array<string,scalar|null> $attributes @return array<string,string> */
    public function add(string $menu, array $attributes): array
    {
        return $this->client->command(rtrim($menu, '/') . '/add', $attributes)['done'];
    }

    /** @param array<string,scalar|null> $attributes */
    public function set(string $menu, string $id, array $attributes): void
    {
        $this->client->command(rtrim($menu, '/') . '/set', ['.id' => $id] + $attributes);
    }

    public function remove(string $menu, string $id): void
    {
        $this->client->command(rtrim($menu, '/') . '/remove', ['.id' => $id]);
    }

    /** @param array<string,scalar|null> $attributes @return array<string,string> */
    public function action(string $menu, string $action, array $attributes = []): array
    {
        return $this->client->command(rtrim($menu, '/') . '/' . trim($action, '/'), $attributes)['done'];
    }

    /** @return array<string,string> */
    private function first(string $command, array $properties): array
    {
        return $this->rows($command, $properties)[0] ?? [];
    }

    /** @param list<string> $warnings @return list<array<string,string>> */
    private function safeRows(string $command, array $properties, array &$warnings): array
    {
        try {
            return $this->rows($command, $properties);
        } catch (RouterOsException $exception) {
            $warnings[] = trim($command, '/') . ': ' . $exception->getMessage();
            return [];
        }
    }

    /** @param list<string> $warnings @return array<string,string> */
    private function safeFirst(string $command, array $properties, array &$warnings): array
    {
        return $this->safeRows($command, $properties, $warnings)[0] ?? [];
    }
}
