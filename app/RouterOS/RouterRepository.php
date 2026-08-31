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
        $snapshot['networks'] = [];
        $snapshot['caps'] = [];
        $snapshot['radios'] = [];
        $snapshot['provisioning'] = [];
        foreach ($snapshot['capsman_types'] as $type) {
            foreach (['networks' => 'configuration', 'caps' => 'remote-cap', 'radios' => 'radio', 'provisioning' => 'provisioning'] as $key => $subject) {
                try {
                    $snapshot[$key] = array_merge($snapshot[$key], $this->tagRows($this->rows($this->menu($type, $subject) . '/print'), $type));
                } catch (RouterOsException $exception) {
                    $warnings[] = $type . '/' . $subject . ': ' . $exception->getMessage();
                }
            }
        }
        $snapshot['warnings'] = $warnings;
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function fastSnapshot(): array
    {
        $warnings = [];
        $types = [];
        $clients = [];
        $accessList = [];
        foreach (['wifi', 'legacy'] as $type) {
            $clientSupported = false;
            $accessSupported = false;
            $clientRows = $this->optionalRows($this->menu($type, 'registration-table') . '/print', [
                '.id', 'mac-address', 'interface', 'ssid', 'signal', 'rx-signal', 'band', 'vlan-id', 'tx-bits-per-second', 'rx-bits-per-second', 'tx-rate', 'rx-rate', 'uptime', 'last-activity', 'authorized',
            ], $clientSupported);
            $accessRows = $this->optionalRows($this->menu($type, 'access-list') . '/print', [
                '.id', 'mac-address', 'comment', 'action', 'disabled', 'interface', 'vlan-id', 'vlan-mode', 'ssid-regexp',
            ], $accessSupported);
            if ($clientSupported || $accessSupported) {
                $types[] = $type;
                $clients = array_merge($clients, $this->tagRows($clientRows, $type));
                $accessList = array_merge($accessList, $this->tagRows($accessRows, $type));
                if (!$clientSupported) $warnings[] = 'capsman/registration-table/print: ' . $type . ' CAPsMAN nelze načíst.';
                if (!$accessSupported) $warnings[] = 'capsman/access-list/print: ' . $type . ' CAPsMAN nelze načíst.';
            }
        }
        if ($types === []) {
            $warnings[] = 'capsman/registration-table/print: Router neobsahuje nový ani starý CAPsMAN.';
            $warnings[] = 'capsman/access-list/print: Router neobsahuje nový ani starý CAPsMAN.';
        }
        return [
            'clients' => $clients,
            'leases' => $this->safeRows('/ip/dhcp-server/lease/print', [
                '.id', 'address', 'mac-address', 'server', 'host-name', 'comment', 'dynamic', 'status', 'last-seen', 'expires-after',
            ], $warnings),
            'access_list' => $accessList,
            'queues' => $this->safeRows('/queue/simple/print', [
                '.id', 'name', 'target', 'max-limit', 'disabled', 'comment', 'rate', 'bytes',
            ], $warnings),
            'capsman_types' => $types,
            'warnings' => $warnings,
        ];
    }

    public function menu(string $type, string $subject): string
    {
        $type = self::capsmanType($type);
        $menus = $type === 'legacy'
            ? [
                'configuration' => '/caps-man/configuration',
                'registration-table' => '/caps-man/registration-table',
                'access-list' => '/caps-man/access-list',
                'remote-cap' => '/caps-man/remote-cap',
                'radio' => '/caps-man/radio',
                'provisioning' => '/caps-man/provisioning',
            ]
            : [
                'configuration' => '/interface/wifi/configuration',
                'registration-table' => '/interface/wifi/registration-table',
                'access-list' => '/interface/wifi/access-list',
                'remote-cap' => '/interface/wifi/capsman/remote-cap',
                'radio' => '/interface/wifi/radio',
                'provisioning' => '/interface/wifi/provisioning',
            ];
        if (!isset($menus[$subject])) throw new \InvalidArgumentException('Neznámá část CAPsMANu.');
        return $menus[$subject];
    }

    public static function capsmanType(string $type): string
    {
        return strtolower($type) === 'legacy' ? 'legacy' : 'wifi';
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

    /** @param array<string,scalar|null> $attributes */
    public function setSingleton(string $menu, array $attributes): void
    {
        $this->client->command(rtrim($menu, '/') . '/set', $attributes);
    }

    public function readFileChunk(string $file, int $offset, int $chunkSize): string
    {
        $response = $this->client->command('/file/read', [
            'file' => $file,
            'offset' => $offset,
            'chunk-size' => $chunkSize,
        ]);
        $row = $response['rows'][0] ?? $response['done'];
        return (string) ($row['data'] ?? '');
    }

    public function removeFile(string $file): void
    {
        foreach ($this->rows('/file/print') as $row) {
            if (($row['name'] ?? '') === $file && isset($row['.id'])) {
                $this->remove('/file', (string) $row['.id']);
                return;
            }
        }
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

    /** @return list<array<string,string>> */
    private function optionalRows(string $command, array $properties, bool &$supported): array
    {
        try {
            $rows = $this->rows($command, $properties);
            $supported = true;
            return $rows;
        } catch (RouterOsException) {
            $supported = false;
            return [];
        }
    }

    /** @param list<array<string,string>> $rows @return list<array<string,string>> */
    private function tagRows(array $rows, string $type): array
    {
        return array_map(static function (array $row) use ($type): array {
            $row['_capsman_type'] = $type;
            return $row;
        }, $rows);
    }
}
