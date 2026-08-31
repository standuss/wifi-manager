<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use WifiManager\Auth;
use WifiManager\Services\LogArchiveService;
use WifiManager\View;

final class LoggingController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
        private readonly LogArchiveService $archive,
    ) {
    }

    public function syslog(): void
    {
        $this->auth->requireLogin();
        $filters = $this->baseFilters() + [
            'text' => trim((string) ($_GET['text'] ?? '')),
            'host' => trim((string) ($_GET['host'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
        ];
        $status = $this->archive->status();
        $result = ['rows' => [], 'truncated' => false];
        $error = null;
        if ($status['syslog']['readable']) {
            try {
                $result = $this->archive->searchSyslog($filters);
                if ($result['rows'] === []) {
                    $fallback = $this->searchSyslogFallback((string) $status['syslog']['directory'], $filters);
                    if ($fallback['rows'] !== []) $result = $fallback;
                }
            } catch (\Throwable $exception) {
                try {
                    $result = $this->searchSyslogFallback((string) $status['syslog']['directory'], $filters);
                    if ($result['rows'] === []) $error = $exception->getMessage();
                } catch (\Throwable) {
                    $error = $exception->getMessage();
                }
            }
        }
        $this->view->render('syslog', compact('filters', 'status', 'result', 'error') + [
            'title' => 'Syslog události', 'activeNav' => 'syslog',
        ]);
    }

    public function flows(): void
    {
        $this->auth->requireLogin();
        $filters = $this->baseFilters() + [
            'ip' => trim((string) ($_GET['ip'] ?? '')),
            'port' => trim((string) ($_GET['port'] ?? '')),
            'protocol' => trim((string) ($_GET['protocol'] ?? '')),
        ];
        $status = $this->archive->status();
        $result = ['rows' => [], 'truncated' => false, 'summary' => ['flows' => 0, 'bytes' => 0, 'packets' => 0, 'endpoints' => 0]];
        $error = null;
        if ($status['netflow']['readable'] && $status['netflow']['nfdump']) {
            try {
                $result = $this->archive->searchFlows($filters);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $this->view->render('flows', compact('filters', 'status', 'result', 'error') + [
            'title' => 'Síťové toky', 'activeNav' => 'flows',
        ]);
    }

    /** @return array{from:string,to:string,limit:int} */
    private function baseFilters(): array
    {
        return [
            'from' => trim((string) ($_GET['from'] ?? date('Y-m-d\TH:i', time() - 86400))),
            'to' => trim((string) ($_GET['to'] ?? date('Y-m-d\TH:i'))),
            'limit' => max(1, min(500, (int) ($_GET['limit'] ?? 200))),
        ];
    }

    /**
     * Fallback pro archivy vytvořené různými verzemi rsyslogu. Některé buildy
     * serializují jsonmesg jinak, takže primární normalizátor nemusí poznat čas.
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,from:DateTimeImmutable,to:DateTimeImmutable,truncated:bool}
     */
    private function searchSyslogFallback(string $base, array $filters): array
    {
        $from = new DateTimeImmutable((string) $filters['from']);
        $to = new DateTimeImmutable((string) $filters['to']);
        if ($from > $to) throw new \InvalidArgumentException('Začátek období musí být před jeho koncem.');
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
        $text = mb_strtolower(trim((string) ($filters['text'] ?? '')));
        $host = mb_strtolower(trim((string) ($filters['host'] ?? '')));
        $severity = mb_strtolower(trim((string) ($filters['severity'] ?? '')));

        $rows = [];
        $dayStart = $from->setTime(0, 0)->sub(new DateInterval('P1D'));
        $dayEnd = $to->setTime(0, 0)->add(new DateInterval('P2D'));
        foreach (new DatePeriod($dayStart, new DateInterval('P1D'), $dayEnd) as $day) {
            foreach ([$base . '/' . $day->format('Y/m/d') . '/events.jsonl', $base . '/' . $day->format('Y/m/d') . '/events.jsonl.gz'] as $path) {
                if (!is_readable($path)) continue;
                foreach ($this->readLines($path) as $line) {
                    $row = $this->parseSyslogLine($line);
                    if ($row === null) continue;
                    try {
                        $timestamp = new DateTimeImmutable((string) $row['timestamp']);
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($timestamp < $from || $timestamp > $to) continue;
                    $haystack = mb_strtolower(implode(' ', [
                        (string) $row['message'], (string) $row['raw'], (string) $row['program'],
                        (string) $row['hostname'], (string) $row['source_ip'], (string) $row['facility'],
                    ]));
                    if ($text !== '' && !str_contains($haystack, $text)) continue;
                    if ($host !== '' && !str_contains(mb_strtolower((string) $row['hostname'] . ' ' . (string) $row['source_ip']), $host)) continue;
                    if ($severity !== '' && mb_strtolower((string) $row['severity']) !== $severity) continue;
                    $row['timestamp'] = $timestamp->format(DATE_ATOM);
                    $rows[] = $row;
                    if (count($rows) > $limit) break 3;
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));
        $truncated = count($rows) > $limit;
        if ($truncated) $rows = array_slice($rows, 0, $limit);
        return compact('rows', 'from', 'to', 'truncated');
    }

    /** @return \Generator<int,string> */
    private function readLines(string $path): \Generator
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($handle === false) return;
        try {
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) yield trim($line);
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }
    }

    /** @return array<string,mixed>|null */
    private function parseSyslogLine(string $line): ?array
    {
        if ($line === '') return null;
        $decoded = json_decode($line, true);
        if (is_string($decoded)) {
            $line = $decoded;
            $decoded = null;
        }
        if (is_array($decoded)) {
            $flat = [];
            $this->flatten($decoded, $flat);
            $pick = static function (array $keys) use ($flat): string {
                foreach ($keys as $wanted) {
                    $wanted = strtolower($wanted);
                    foreach ($flat as $key => $value) {
                        $base = strtolower((string) preg_replace('/^.*[.\/]/', '', $key));
                        if (($base === $wanted || strtolower($key) === $wanted) && is_scalar($value)) return (string) $value;
                    }
                }
                return '';
            };
            $timestamp = $pick(['timegenerated', 'timereported', 'timestamp', 'time', 'eventtime']);
            if ($timestamp === '') return null;
            return [
                'timestamp' => $timestamp,
                'hostname' => $pick(['hostname', 'host']),
                'source_ip' => $pick(['fromhost-ip', 'fromhost', 'source_ip', 'source']),
                'severity' => strtolower($pick(['syslogseverity-text', 'severity', 'level']) ?: 'info'),
                'facility' => $pick(['syslogfacility-text', 'facility']),
                'program' => $pick(['programname', 'app-name', 'program', 'tag']),
                'message' => trim($pick(['msg', 'message'])),
                'raw' => trim($pick(['rawmsg', 'raw']) ?: $line),
            ];
        }

        if (preg_match('/^(?<time>\d{4}-\d{2}-\d{2}T\S+)\s+(?<host>\S+)\s+(?<msg>.*)$/', $line, $match) === 1) {
            return [
                'timestamp' => $match['time'], 'hostname' => $match['host'], 'source_ip' => '',
                'severity' => 'info', 'facility' => '', 'program' => '', 'message' => $match['msg'], 'raw' => $line,
            ];
        }
        return null;
    }

    /** @param array<string,mixed> $value @param array<string,mixed> $flat */
    private function flatten(array $value, array &$flat, string $prefix = ''): void
    {
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($item)) $this->flatten($item, $flat, $path);
            else $flat[$path] = $item;
        }
    }
}
