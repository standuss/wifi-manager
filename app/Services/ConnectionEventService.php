<?php

declare(strict_types=1);

namespace WifiManager\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use WifiManager\Config;
use WifiManager\Database;

final class ConnectionEventService
{
    private readonly DateTimeZone $timezone;

    public function __construct(private readonly Database $database, private readonly Config $config)
    {
        $this->timezone = new DateTimeZone((string) $config->get('app.timezone', 'Europe/Prague'));
    }

    public function ingest(int $limit = 5000): int
    {
        $base = rtrim((string) $this->config->get('logging.syslog_dir'), '/');
        $processed = 0;
        foreach ([new DateTimeImmutable('yesterday', $this->timezone), new DateTimeImmutable('today', $this->timezone)] as $day) {
            if ($processed >= $limit) break;
            $path = $base . '/' . $day->format('Y/m/d') . '/events.jsonl';
            if (!is_file($path) || !is_readable($path)) continue;
            $processed += $this->ingestFile($path, $limit - $processed);
        }
        return $processed;
    }

    private function ingestFile(string $path, int $limit): int
    {
        $stat = @stat($path);
        if (!is_array($stat)) return 0;
        $inode = (string) ($stat['ino'] ?? '');
        $state = $this->database->pdo()->prepare('SELECT byte_offset,file_inode FROM syslog_ingest_state WHERE path=:path');
        $state->execute(['path' => $path]);
        $saved = $state->fetch();
        $offset = is_array($saved) && (string) ($saved['file_inode'] ?? '') === $inode ? (int) $saved['byte_offset'] : 0;
        if ($offset < 0 || $offset > (int) $stat['size']) $offset = 0;

        $handle = @fopen($path, 'rb');
        if ($handle === false) return 0;
        $count = 0;
        try {
            if ($offset > 0) fseek($handle, $offset);
            while ($count < $limit && ($line = fgets($handle)) !== false) {
                $offset = ftell($handle) ?: $offset;
                $item = json_decode($line, true);
                if (!is_array($item)) continue;
                $event = $this->parse($item);
                if ($event === null) continue;
                $this->save($event);
                $count++;
            }
        } finally {
            fclose($handle);
        }
        $this->database->pdo()->prepare(
            'INSERT INTO syslog_ingest_state(path,byte_offset,file_inode) VALUES(:path,:offset,:inode)
             ON CONFLICT(path) DO UPDATE SET byte_offset=excluded.byte_offset,file_inode=excluded.file_inode,updated_at=CURRENT_TIMESTAMP'
        )->execute(['path' => $path, 'offset' => $offset, 'inode' => $inode]);
        return $count;
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|null */
    private function parse(array $item): ?array
    {
        $message = trim(implode(' ', array_filter([
            (string) ($item['msg'] ?? $item['message'] ?? ''), (string) ($item['rawmsg'] ?? ''),
        ])));
        if ($message === '' || preg_match('/(?<![0-9a-f])([0-9a-f]{2}(?:[:-][0-9a-f]{2}){5})(?![0-9a-f])/i', $message, $macMatch) !== 1) return null;
        $lower = mb_strtolower($message);
        $event = match (true) {
            str_contains($lower, 'roam') || str_contains($lower, 'reassociated') => 'roamed',
            preg_match('/\b(disconnected|unregistered|deauthenticated|disassociated|left|lost connection)\b/i', $message) === 1 => 'disconnected',
            preg_match('/\b(connected|registered|associated|joined)\b/i', $message) === 1 => 'connected',
            default => null,
        };
        if ($event === null) return null;
        try { $mac = normalize_mac($macMatch[1]); } catch (\Throwable) { return null; }

        $timestamp = (string) ($item['timegenerated'] ?? $item['timereported'] ?? $item['timestamp'] ?? 'now');
        try {
            $at = new DateTimeImmutable($timestamp, $this->timezone);
        } catch (\Throwable) {
            $at = new DateTimeImmutable('now', $this->timezone);
        }
        $at = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $ssid = self::capture($message, '/\bssid\s*[=:]\s*["\']?([^,"\'\s]+)/i');
        $interface = self::capture($message, '/\b(?:interface|iface)\s*[=:]\s*([\w.:-]+)/i');
        $reason = self::capture($message, '/\breason\s*[=:]\s*(.+?)(?:,|$)/i');
        $hostname = trim((string) ($item['hostname'] ?? ''));
        $source = trim((string) ($item['fromhost-ip'] ?? $item['fromhost'] ?? ''));

        return [
            'mac' => $mac, 'event' => $event, 'occurred_at' => $at, 'ssid' => $ssid, 'interface' => $interface,
            'reason' => $reason, 'hostname' => $hostname, 'source_ip' => $source, 'raw' => mb_substr($message, 0, 2000),
            'type' => str_contains($lower, 'caps-man') || str_contains($lower, 'capsman') ? 'legacy' : 'wifi',
        ];
    }

    /** @param array<string,mixed> $event */
    private function save(array $event): void
    {
        $this->database->transaction(function (PDO $pdo) use ($event): void {
            $routerId = $this->routerId($pdo, (string) $event['hostname'], (string) $event['source_ip']);
            $deviceStatement = $pdo->prepare("SELECT id,capsman_type,current_ip FROM devices WHERE mac_address=:mac AND registration_state!='archived' LIMIT 1");
            $deviceStatement->execute(['mac' => $event['mac']]);
            $device = $deviceStatement->fetch();
            $deviceId = is_array($device) ? (int) $device['id'] : null;
            $type = is_array($device) ? (string) ($device['capsman_type'] ?: $event['type']) : (string) $event['type'];
            $hash = hash('sha256', implode('|', [$routerId, $event['mac'], $event['event'], $event['occurred_at'], $event['raw']]));

            $pdo->prepare(
                "DELETE FROM wifi_connection_events WHERE mac_address=:mac AND event_type=:event AND source='api'
                 AND ABS(strftime('%s',occurred_at)-strftime('%s',:occurred_at)) <= 30"
            )->execute(['mac' => $event['mac'], 'event' => $event['event'], 'occurred_at' => $event['occurred_at']]);
            $insert = $pdo->prepare(
                'INSERT OR IGNORE INTO wifi_connection_events
                 (router_id,device_id,capsman_type,mac_address,event_type,occurred_at,ssid,interface_name,access_point_name,reason,source,raw_message,event_hash)
                 VALUES(:router_id,:device_id,:type,:mac,:event,:occurred_at,:ssid,:interface,:ap,:reason,\'syslog\',:raw,:hash)'
            );
            $insert->execute([
                'router_id' => $routerId, 'device_id' => $deviceId, 'type' => $type, 'mac' => $event['mac'],
                'event' => $event['event'], 'occurred_at' => $event['occurred_at'], 'ssid' => $event['ssid'],
                'interface' => $event['interface'], 'ap' => $event['interface'], 'reason' => $event['reason'], 'raw' => $event['raw'], 'hash' => $hash,
            ]);
            if ($insert->rowCount() === 0) return;

            if (in_array($event['event'], ['disconnected', 'roamed'], true)) {
                $pdo->prepare(
                    "UPDATE wifi_sessions SET disconnected_at=:at,disconnect_reason=:reason,source='syslog'
                     WHERE mac_address=:mac AND disconnected_at IS NULL"
                )->execute(['at' => $event['occurred_at'], 'reason' => $event['reason'], 'mac' => $event['mac']]);
            }
            if (in_array($event['event'], ['connected', 'roamed'], true)) {
                $open = $pdo->prepare('SELECT id,connected_at FROM wifi_sessions WHERE mac_address=:mac AND disconnected_at IS NULL ORDER BY id DESC LIMIT 1');
                $open->execute(['mac' => $event['mac']]);
                $openSession = $open->fetch();
                if (is_array($openSession)) {
                    $pdo->prepare("UPDATE wifi_sessions SET source='syslog',device_id=COALESCE(device_id,:device_id),router_id=COALESCE(router_id,:router_id) WHERE id=:id")
                        ->execute(['device_id' => $deviceId, 'router_id' => $routerId, 'id' => $openSession['id']]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO wifi_sessions(router_id,device_id,capsman_type,mac_address,ip_address,ssid,access_point_name,connected_at,source)
                         VALUES(:router_id,:device_id,:type,:mac,:ip,:ssid,:ap,:at,\'syslog\')'
                    )->execute([
                        'router_id' => $routerId, 'device_id' => $deviceId, 'type' => $type, 'mac' => $event['mac'],
                        'ip' => is_array($device) ? ($device['current_ip'] ?? null) : null, 'ssid' => $event['ssid'],
                        'ap' => $event['interface'], 'at' => $event['occurred_at'],
                    ]);
                }
            }
        });
    }

    private function routerId(PDO $pdo, string $hostname, string $sourceIp): ?int
    {
        $routers = $pdo->query('SELECT id,name,identity,host FROM routers WHERE enabled=1 ORDER BY id')->fetchAll();
        foreach ($routers as $router) {
            if (($hostname !== '' && in_array(mb_strtolower($hostname), [mb_strtolower((string) $router['name']), mb_strtolower((string) ($router['identity'] ?? ''))], true))
                || ($sourceIp !== '' && $sourceIp === (string) $router['host'])) return (int) $router['id'];
        }
        return count($routers) === 1 ? (int) $routers[0]['id'] : null;
    }

    private static function capture(string $message, string $pattern): ?string
    {
        return preg_match($pattern, $message, $match) === 1 ? trim((string) $match[1], " \t\n\r\0\x0B\"'") : null;
    }
}
