#!/usr/bin/env php
<?php

declare(strict_types=1);

const REQUEST_DIR = '/var/lib/wifimanager/service-requests';
const ENV_FILE = '/etc/default/wifimanager-monitoring';
const STATUS_FILE = '/var/lib/wifimanager/system-apply-status.json';

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Změny služeb musí aplikovat root.\n");
    exit(1);
}

$root = '/var/www/wifimanager';
$monitoringRequest = REQUEST_DIR . '/monitoring.json';

try {
    $changed = [];
    if (is_file($monitoringRequest)) {
        $request = readRequest($monitoringRequest);
        $address = validIp($request['monitor_listen_address'] ?? null);
        $tcp = validInt($request['monitor_syslog_tcp_port'] ?? null, 1, 65535, 'TCP syslog port');
        $udp = validInt($request['monitor_syslog_udp_port'] ?? null, 1, 65535, 'UDP syslog port');
        $flow = validInt($request['monitor_netflow_port'] ?? null, 1, 65535, 'IPFIX port');
        if ($udp === $flow) throw new RuntimeException('UDP syslog a IPFIX mají stejný port.');
        $retention = validInt($request['monitor_retention_days'] ?? null, 30, 3650, 'retence');
        $syslogGiB = validInt($request['monitor_syslog_max_gib'] ?? null, 1, 4096, 'limit syslogu');
        $netflowGiB = validInt($request['monitor_netflow_max_gib'] ?? null, 1, 16384, 'limit IPFIX');
        $current = is_file(ENV_FILE) ? parse_ini_file(ENV_FILE, false, INI_SCANNER_RAW) : [];
        if (!is_array($current)) $current = [];
        $values = [
            'WFM_LISTEN_ADDRESS' => $address,
            'WFM_SYSLOG_TCP_PORT' => $tcp,
            'WFM_SYSLOG_UDP_PORT' => $udp,
            'WFM_NETFLOW_PORT' => $flow,
            'WFM_SYSLOG_DIR' => safeArchivePath((string) ($current['WFM_SYSLOG_DIR'] ?? '/var/lib/wifimanager/syslog')),
            'WFM_NETFLOW_DIR' => safeArchivePath((string) ($current['WFM_NETFLOW_DIR'] ?? '/var/lib/wifimanager/netflow')),
            'WFM_RETENTION_DAYS' => $retention,
            'WFM_SYSLOG_MAX_BYTES' => $syslogGiB * 1073741824,
            'WFM_NETFLOW_MAX_BYTES' => $netflowGiB * 1073741824,
            'WFM_COMPRESS_AFTER_DAYS' => validInt($current['WFM_COMPRESS_AFTER_DAYS'] ?? 2, 1, 30, 'komprese'),
        ];
        atomicWrite(ENV_FILE, envContent($values), 0644);
        $template = (string) file_get_contents($root . '/deploy/logging/rsyslog-wifimanager.conf');
        $rendered = str_replace(
            ['@@LISTEN_ADDRESS@@', '@@SYSLOG_TCP_PORT@@', '@@SYSLOG_UDP_PORT@@'],
            [$address, (string) $tcp, (string) $udp],
            $template
        );
        atomicWrite('/etc/rsyslog.d/30-wifimanager.conf', $rendered, 0644);
        command(['/usr/sbin/rsyslogd', '-N1']);
        try {
            command(['/usr/bin/systemctl', 'disable', '--now', 'nfdump@default.service', 'nfdump.service']);
        } catch (\Throwable) {
            // Starší nebo ručně instalovaný nfdump nemusí mít výchozí jednotky.
        }
        command(['/usr/bin/systemctl', 'restart', 'rsyslog.service']);
        command(['/usr/bin/systemctl', 'restart', 'wifimanager-nfcapd.service']);
        command(['/usr/bin/systemctl', 'start', 'wifimanager-retention.service']);
        unlink($monitoringRequest);
        $changed[] = 'monitoring';
    }

    writeStatus('done', $changed === [] ? 'Nebyl nalezen žádný požadavek.' : 'Použito: ' . implode(', ', $changed) . '.');
} catch (Throwable $exception) {
    @unlink($monitoringRequest);
    writeStatus('failed', $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function readRequest(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) throw new RuntimeException('Požadavek nemá platný JSON formát.');
    return $decoded;
}

function validIp(mixed $value): string
{
    $value = trim((string) $value);
    if (filter_var($value, FILTER_VALIDATE_IP) === false) throw new RuntimeException('Neplatná naslouchací IP adresa.');
    return $value;
}

function validInt(mixed $value, int $minimum, int $maximum, string $label): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $minimum || $number > $maximum) throw new RuntimeException('Neplatná hodnota: ' . $label . '.');
    return $number;
}

function safeArchivePath(string $path): string
{
    $path = rtrim($path, '/');
    if (!str_starts_with($path, '/var/lib/wifimanager/') || strlen($path) < 18) throw new RuntimeException('Nebezpečná cesta archivu.');
    return $path;
}

/** @param array<string,string|int> $values */
function envContent(array $values): string
{
    $lines = [];
    foreach ($values as $key => $value) {
        if (preg_match('/^[A-Z0-9_]+$/', $key) !== 1 || preg_match('/[\r\n]/', (string) $value)) throw new RuntimeException('Neplatná systémová konfigurace.');
        $lines[] = $key . '=' . $value;
    }
    return implode("\n", $lines) . "\n";
}

function atomicWrite(string $path, string $content, int $mode): void
{
    $temporary = $path . '.tmp-' . getmypid();
    if (file_put_contents($temporary, $content, LOCK_EX) === false) throw new RuntimeException('Nelze zapsat ' . $path . '.');
    chmod($temporary, $mode);
    if (!rename($temporary, $path)) throw new RuntimeException('Nelze aktivovat ' . $path . '.');
}

/** @param list<string> $arguments */
function command(array $arguments): void
{
    $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Nelze spustit systémový příkaz.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException(trim((string) ($stderr ?: $stdout ?: 'Systémový příkaz selhal.')));
}

function writeStatus(string $state, string $message): void
{
    atomicWrite(STATUS_FILE, json_encode(['state' => $state, 'message' => $message, 'updated_at' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", 0640);
    @chown(STATUS_FILE, 'root');
    @chgrp(STATUS_FILE, 'www-data');
}
