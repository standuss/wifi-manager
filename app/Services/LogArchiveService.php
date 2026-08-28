<?php

declare(strict_types=1);

namespace WifiManager\Services;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use WifiManager\Config;
use WifiManager\Database;

final class LogArchiveService
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
    ) {
        $this->timezone = new DateTimeZone((string) $config->get('app.timezone', 'Europe/Prague'));
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $syslogDir = (string) $this->config->get('logging.syslog_dir');
        $netflowDir = (string) $this->config->get('logging.netflow_dir');
        $nfdump = (string) $this->config->get('logging.nfdump_binary');
        $statusPath = dirname($syslogDir) . '/archive-status.json';
        $archive = [];
        if (is_readable($statusPath)) {
            $decoded = json_decode((string) file_get_contents($statusPath), true);
            $archive = is_array($decoded) ? $decoded : [];
        }

        return [
            'syslog' => [
                'directory' => $syslogDir,
                'readable' => is_dir($syslogDir) && is_readable($syslogDir),
                'bytes' => isset($archive['syslog_bytes']) ? (int) $archive['syslog_bytes'] : null,
                'newest_at' => $archive['syslog_newest_at'] ?? $this->newestSyslogTimestamp($syslogDir),
                'service' => $this->serviceState('rsyslog.service'),
            ],
            'netflow' => [
                'directory' => $netflowDir,
                'readable' => is_dir($netflowDir) && is_readable($netflowDir),
                'bytes' => isset($archive['netflow_bytes']) ? (int) $archive['netflow_bytes'] : null,
                'newest_at' => $archive['netflow_newest_at'] ?? $this->newestFlowTimestamp($netflowDir),
                'service' => $this->serviceState('wifimanager-nfcapd.service'),
                'nfdump' => is_executable($nfdump),
            ],
            'retention' => [
                'last_run_at' => $archive['generated_at'] ?? null,
                'service' => $this->serviceState('wifimanager-retention.timer'),
            ],
            'disk' => [
                'total' => is_dir(dirname($syslogDir)) ? @disk_total_space(dirname($syslogDir)) ?: null : null,
                'free' => is_dir(dirname($syslogDir)) ? @disk_free_space(dirname($syslogDir)) ?: null : null,
            ],
        ];
    }

    /**
     * @param array{from?:string,to?:string,text?:string,host?:string,severity?:string,limit?:int} $filters
     * @return array{rows:list<array<string,mixed>>,from:DateTimeImmutable,to:DateTimeImmutable,truncated:bool}
     */
    public function searchSyslog(array $filters): array
    {
        [$from, $to] = $this->dateRange($filters);
        $limit = $this->limit($filters['limit'] ?? null);
        $text = mb_strtolower(trim((string) ($filters['text'] ?? '')));
        $host = mb_strtolower(trim((string) ($filters['host'] ?? '')));
        $severity = mb_strtolower(trim((string) ($filters['severity'] ?? '')));
        if ($severity !== '' && !in_array($severity, ['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'], true)) {
            throw new \InvalidArgumentException('Neplatná závažnost syslogu.');
        }

        $rows = [];
        $base = rtrim((string) $this->config->get('logging.syslog_dir'), '/');
        foreach (array_reverse($this->days($from, $to)) as $day) {
            foreach ([$base . '/' . $day->format('Y/m/d') . '/events.jsonl', $base . '/' . $day->format('Y/m/d') . '/events.jsonl.gz'] as $path) {
                if (!is_readable($path)) continue;
                foreach ($this->jsonLines($path) as $item) {
                    $row = $this->normalizeSyslog($item);
                    $timestamp = $this->parseTimestamp($row['timestamp'] ?? null);
                    if (!$timestamp || $timestamp < $from || $timestamp > $to) continue;
                    $haystack = mb_strtolower(implode(' ', [
                        (string) ($row['message'] ?? ''), (string) ($row['raw'] ?? ''),
                        (string) ($row['program'] ?? ''), (string) ($row['hostname'] ?? ''),
                        (string) ($row['source_ip'] ?? ''), (string) ($row['facility'] ?? ''),
                    ]));
                    if ($text !== '' && !str_contains($haystack, $text)) continue;
                    if ($host !== '' && !str_contains(mb_strtolower((string) ($row['hostname'] . ' ' . $row['source_ip'])), $host)) continue;
                    if ($severity !== '' && mb_strtolower((string) $row['severity']) !== $severity) continue;
                    $row['timestamp'] = $timestamp->format(DATE_ATOM);
                    $rows[] = $row;
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));
        $truncated = count($rows) > $limit;
        if ($truncated) $rows = array_slice($rows, 0, $limit);
        return compact('rows', 'from', 'to', 'truncated');
    }

    /**
     * @param array{from?:string,to?:string,ip?:string,port?:string|int,protocol?:string,limit?:int} $filters
     * @return array{rows:list<array<string,mixed>>,from:DateTimeImmutable,to:DateTimeImmutable,truncated:bool,summary:array<string,int>}
     */
    public function searchFlows(array $filters): array
    {
        [$from, $to] = $this->dateRange($filters);
        $limit = $this->limit($filters['limit'] ?? null);
        $ip = trim((string) ($filters['ip'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('IP adresa ve filtru není platná.');
        }
        $port = trim((string) ($filters['port'] ?? ''));
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new \InvalidArgumentException('Port ve filtru není platný.');
        }
        $protocol = mb_strtolower(trim((string) ($filters['protocol'] ?? '')));
        if ($protocol !== '' && !in_array($protocol, ['tcp', 'udp', 'icmp', 'icmp6', 'gre', 'esp'], true)) {
            throw new \InvalidArgumentException('Protokol ve filtru není podporovaný.');
        }

        $expression = [];
        if ($ip !== '') $expression[] = 'ip ' . $ip;
        if ($port !== '') $expression[] = 'port ' . (int) $port;
        if ($protocol !== '') $expression[] = 'proto ' . $protocol;
        $filterExpression = $expression === [] ? 'any' : implode(' and ', $expression);

        $rows = [];
        $base = rtrim((string) $this->config->get('logging.netflow_dir'), '/');
        $binary = (string) $this->config->get('logging.nfdump_binary');
        if (!is_executable($binary)) {
            throw new \RuntimeException('Nástroj nfdump není nainstalovaný nebo spustitelný.');
        }

        foreach ($this->days($from, $to) as $day) {
            $dayDir = $base . '/' . $day->format('Y/m/d');
            if (!is_dir($dayDir) || !is_readable($dayDir)) continue;
            $remaining = max(1, $limit + 1 - count($rows));
            $command = [$binary, '-R', $dayDir, '-q', '-O', 'tstart', '-o', 'ndjson', '-c', (string) $remaining, $filterExpression];
            $output = $this->run($command, (int) $this->config->get('logging.query_timeout_seconds', 20));
            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                if ($line === '') continue;
                $item = json_decode($line, true);
                if (!is_array($item)) continue;
                $row = $this->normalizeFlow($item);
                $timestamp = $this->parseTimestamp($row['first_at'] ?? null);
                if (!$timestamp || $timestamp < $from || $timestamp > $to) continue;
                $row['first_at'] = $timestamp->format(DATE_ATOM);
                $last = $this->parseTimestamp($row['last_at'] ?? null);
                $row['last_at'] = ($last ?: $timestamp)->format(DATE_ATOM);
                $row['source_identity'] = $this->identityForIp((string) $row['source_ip'], $timestamp);
                $row['destination_identity'] = $this->identityForIp((string) $row['destination_ip'], $timestamp);
                $rows[] = $row;
                if (count($rows) > $limit) break 2;
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['first_at'], (string) $a['first_at']));
        $truncated = count($rows) > $limit;
        if ($truncated) $rows = array_slice($rows, 0, $limit);
        $endpoints = [];
        $bytes = 0;
        $packets = 0;
        foreach ($rows as $row) {
            $bytes += (int) $row['bytes'];
            $packets += (int) $row['packets'];
            if ($row['source_ip'] !== '') $endpoints[$row['source_ip']] = true;
            if ($row['destination_ip'] !== '') $endpoints[$row['destination_ip']] = true;
        }
        $summary = ['flows' => count($rows), 'bytes' => $bytes, 'packets' => $packets, 'endpoints' => count($endpoints)];
        return compact('rows', 'from', 'to', 'truncated', 'summary');
    }

    /** @param array<string,mixed> $filters @return array{DateTimeImmutable,DateTimeImmutable} */
    private function dateRange(array $filters): array
    {
        $to = $this->localDate((string) ($filters['to'] ?? '')) ?? new DateTimeImmutable('now', $this->timezone);
        $from = $this->localDate((string) ($filters['from'] ?? '')) ?? $to->sub(new DateInterval('PT24H'));
        if ($from > $to) throw new \InvalidArgumentException('Začátek období musí být před jeho koncem.');
        $maxDays = max(1, (int) $this->config->get('logging.max_query_days', 31));
        if ($from < $to->sub(new DateInterval('P' . $maxDays . 'D'))) {
            throw new \InvalidArgumentException('Jeden dotaz může pokrýt nejvýše ' . $maxDays . ' dní.');
        }
        return [$from, $to];
    }

    private function localDate(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') return null;
        try {
            return new DateTimeImmutable($value, $this->timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Datum a čas filtru nejsou platné.');
        }
    }

    /** @return list<DateTimeImmutable> */
    private function days(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0)->add(new DateInterval('P1D'));
        return iterator_to_array(new DatePeriod($start, new DateInterval('P1D'), $end), false);
    }

    private function limit(mixed $requested): int
    {
        $maximum = max(1, (int) $this->config->get('logging.query_limit', 500));
        $value = (int) ($requested ?: min(200, $maximum));
        return max(1, min($maximum, $value));
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function jsonLines(string $path): \Generator
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($handle === false) return;
        try {
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                $item = json_decode($line, true);
                if (is_array($item)) yield $item;
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalizeSyslog(array $item): array
    {
        return [
            'timestamp' => $item['timegenerated'] ?? $item['timereported'] ?? $item['timestamp'] ?? null,
            'hostname' => (string) ($item['hostname'] ?? ''),
            'source_ip' => (string) ($item['fromhost-ip'] ?? $item['fromhost'] ?? ''),
            'severity' => (string) ($item['syslogseverity-text'] ?? $item['severity'] ?? 'info'),
            'facility' => (string) ($item['syslogfacility-text'] ?? $item['facility'] ?? ''),
            'program' => (string) ($item['programname'] ?? $item['app-name'] ?? ''),
            'message' => trim((string) ($item['msg'] ?? $item['message'] ?? '')),
            'raw' => trim((string) ($item['rawmsg'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalizeFlow(array $item): array
    {
        $sourceIp = (string) ($item['src4_addr'] ?? $item['src6_addr'] ?? $item['src_addr'] ?? '');
        $destinationIp = (string) ($item['dst4_addr'] ?? $item['dst6_addr'] ?? $item['dst_addr'] ?? '');
        $protocolValue = (string) ($item['proto'] ?? $item['protocol'] ?? $item['protocolIdentifier'] ?? '');
        $protocol = [
            '1' => 'ICMP', '6' => 'TCP', '17' => 'UDP', '47' => 'GRE', '50' => 'ESP', '58' => 'ICMP6',
        ][$protocolValue] ?? strtoupper($protocolValue);
        return [
            'first_at' => $item['t_first'] ?? $item['first'] ?? $item['first_at'] ?? null,
            'last_at' => $item['t_last'] ?? $item['last'] ?? $item['last_at'] ?? null,
            'protocol' => $protocol,
            'source_ip' => $sourceIp,
            'source_port' => (int) ($item['src_port'] ?? 0),
            'source_mac' => self::firstValue($item, ['in_src_mac', 'out_src_mac', 'src_mac', 'src_mac_addr', 'srcMacAddress']),
            'destination_ip' => $destinationIp,
            'destination_port' => (int) ($item['dst_port'] ?? 0),
            'destination_mac' => self::firstValue($item, ['out_dst_mac', 'in_dst_mac', 'dst_mac', 'dst_mac_addr', 'dstMacAddress']),
            'packets' => (int) ($item['in_packets'] ?? $item['packets'] ?? 0),
            'bytes' => (int) ($item['in_bytes'] ?? $item['bytes'] ?? 0),
            'input_interface' => $item['input_snmp'] ?? $item['in_if'] ?? null,
            'output_interface' => $item['output_snmp'] ?? $item['out_if'] ?? null,
            'nat_source_ip' => self::firstValue($item, ['src4_xlt_ip', 'src6_xlt_ip', 'xlate_src_ip', 'postNATSourceIPv4Address']),
            'nat_destination_ip' => self::firstValue($item, ['dst4_xlt_ip', 'dst6_xlt_ip', 'xlate_dst_ip', 'postNATDestinationIPv4Address']),
            'nat_source_port' => (int) self::firstValue($item, ['src_xlt_port', 'xlate_src_port', 'postNAPTSourceTransportPort']),
            'nat_destination_port' => (int) self::firstValue($item, ['dst_xlt_port', 'xlate_dst_port', 'postNAPTDestinationTransportPort']),
        ];
    }

    /** @param array<string,mixed> $item @param list<string> $keys */
    private static function firstValue(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && (string) $item[$key] !== '') return (string) $item[$key];
        }
        return '';
    }

    /** @return array{name:string,person:string}|null */
    private function identityForIp(string $ip, DateTimeImmutable $at): ?array
    {
        if ($ip === '') return null;
        $statement = $this->database->pdo()->prepare(
            "SELECT d.name, COALESCE(p.name, '') AS person
             FROM ip_assignments a JOIN devices d ON d.id = a.device_id
             LEFT JOIN people p ON p.id = d.person_id
             WHERE a.ip_address = :ip AND datetime(a.valid_from) <= datetime(:at)
               AND (a.valid_to IS NULL OR datetime(a.valid_to) >= datetime(:at))
             ORDER BY a.valid_from DESC LIMIT 1"
        );
        $statement->execute(['ip' => $ip, 'at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            $fallback = $this->database->pdo()->prepare(
                "SELECT d.name, COALESCE(p.name, '') AS person FROM devices d LEFT JOIN people p ON p.id = d.person_id WHERE d.current_ip = :ip LIMIT 1"
            );
            $fallback->execute(['ip' => $ip]);
            $row = $fallback->fetch();
        }
        return is_array($row) ? ['name' => (string) $row['name'], 'person' => (string) $row['person']] : null;
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        try {
            if (is_numeric($value)) {
                $numeric = (float) $value;
                if ($numeric > 1000000000000) $numeric /= 1000;
                return (new DateTimeImmutable('@' . (int) $numeric))->setTimezone($this->timezone);
            }
            return (new DateTimeImmutable((string) $value))->setTimezone($this->timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $command */
    private function run(array $command, int $timeoutSeconds): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new \RuntimeException('Nelze spustit dotaz nad archivem.');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $maxOutput = (int) $this->config->get('logging.max_output_bytes', 8388608);
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $started > $timeoutSeconds || strlen($stdout) > $maxOutput) {
                proc_terminate($process, 15);
                usleep(100000);
                proc_terminate($process, 9);
                throw new \RuntimeException('Dotaz nad archivem překročil bezpečný limit. Zkraťte období nebo doplňte filtr.');
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 && $stderr !== '') throw new \RuntimeException('nfdump: ' . trim($stderr));
        return $stdout;
    }

    private function serviceState(string $unit): string
    {
        try {
            $result = trim($this->run(['/usr/bin/systemctl', 'is-active', $unit], 2));
            return $result !== '' ? $result : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function newestSyslogTimestamp(string $base): ?string
    {
        for ($i = 0; $i < 8; $i++) {
            $day = (new DateTimeImmutable('now', $this->timezone))->sub(new DateInterval('P' . $i . 'D'));
            foreach (['events.jsonl', 'events.jsonl.gz'] as $file) {
                $path = rtrim($base, '/') . '/' . $day->format('Y/m/d') . '/' . $file;
                if (is_file($path)) return date(DATE_ATOM, (int) filemtime($path));
            }
        }
        return null;
    }

    private function newestFlowTimestamp(string $base): ?string
    {
        for ($i = 0; $i < 8; $i++) {
            $day = (new DateTimeImmutable('now', $this->timezone))->sub(new DateInterval('P' . $i . 'D'));
            $dir = rtrim($base, '/') . '/' . $day->format('Y/m/d');
            $files = [];
            if (is_dir($dir)) {
                $files = array_merge(glob($dir . '/nfcapd.*') ?: [], glob($dir . '/*/nfcapd.*') ?: []);
            }
            if ($files !== []) {
                $newest = max(array_map('filemtime', $files));
                return date(DATE_ATOM, (int) $newest);
            }
        }
        return null;
    }
}
