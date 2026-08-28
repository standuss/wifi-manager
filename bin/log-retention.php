#!/usr/bin/env php
<?php

declare(strict_types=1);

const ENV_FILE = '/etc/default/wifimanager-monitoring';

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Retenci musí spouštět root.\n");
    exit(1);
}

$env = is_file(ENV_FILE) ? parse_ini_file(ENV_FILE, false, INI_SCANNER_RAW) : false;
if (!is_array($env)) {
    fwrite(STDERR, "Nelze načíst " . ENV_FILE . ".\n");
    exit(1);
}

$syslogDir = safeDirectory((string) ($env['WFM_SYSLOG_DIR'] ?? ''));
$netflowDir = safeDirectory((string) ($env['WFM_NETFLOW_DIR'] ?? ''));
$retentionDays = boundedInt($env['WFM_RETENTION_DAYS'] ?? 1825, 30, 3650, 'retence');
$compressAfter = boundedInt($env['WFM_COMPRESS_AFTER_DAYS'] ?? 2, 1, 30, 'komprese');
$syslogBudget = boundedInt($env['WFM_SYSLOG_MAX_BYTES'] ?? 64424509440, 1073741824, PHP_INT_MAX, 'limit syslogu');
$netflowBudget = boundedInt($env['WFM_NETFLOW_MAX_BYTES'] ?? 300647710720, 1073741824, PHP_INT_MAX, 'limit IPFIX');

$now = time();
$compressed = 0;
$deleted = 0;

foreach (files($syslogDir, static fn (string $path): bool => str_ends_with($path, '/events.jsonl')) as $file) {
    if (filemtime($file) >= $now - ($compressAfter * 86400)) continue;
    $target = $file . '.gz';
    if (is_file($target)) continue;
    $temporary = $target . '.tmp-' . getmypid();
    $input = fopen($file, 'rb');
    $output = gzopen($temporary, 'wb6');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) gzclose($output);
        @unlink($temporary);
        continue;
    }
    $success = true;
    while (!feof($input)) {
        $chunk = fread($input, 1048576);
        if ($chunk === false || ($chunk !== '' && gzwrite($output, $chunk) === false)) {
            $success = false;
            break;
        }
    }
    fclose($input);
    $success = gzclose($output) && $success;
    if ($success && is_file($temporary) && filesize($temporary) > 0 && rename($temporary, $target)) {
        chmod($target, 0640);
        unlink($file);
        $compressed++;
    } else {
        @unlink($temporary);
    }
}

$cutoff = $now - ($retentionDays * 86400);
foreach (files($syslogDir, static fn (string $path): bool => preg_match('~/events\.jsonl(?:\.gz)?$~', $path) === 1) as $file) {
    if (filemtime($file) < $cutoff && unlink($file)) $deleted++;
}
foreach (files($netflowDir, static fn (string $path): bool => str_starts_with(basename($path), 'nfcapd.')) as $file) {
    if (filemtime($file) < $cutoff && unlink($file)) $deleted++;
}

$deleted += enforceBudget($syslogDir, $syslogBudget, static fn (string $path): bool => preg_match('~/events\.jsonl(?:\.gz)?$~', $path) === 1);
$deleted += enforceBudget($netflowDir, $netflowBudget, static fn (string $path): bool => str_starts_with(basename($path), 'nfcapd.'));
removeEmptyDirectories($syslogDir);
removeEmptyDirectories($netflowDir);

$syslogFiles = files($syslogDir, static fn (string $path): bool => preg_match('~/events\.jsonl(?:\.gz)?$~', $path) === 1);
$netflowFiles = files($netflowDir, static fn (string $path): bool => str_starts_with(basename($path), 'nfcapd.'));
$status = [
    'generated_at' => date(DATE_ATOM),
    'retention_days' => $retentionDays,
    'syslog_bytes' => totalSize($syslogFiles),
    'netflow_bytes' => totalSize($netflowFiles),
    'syslog_newest_at' => newestAt($syslogFiles),
    'netflow_newest_at' => newestAt($netflowFiles),
    'compressed_files' => $compressed,
    'deleted_files' => $deleted,
];
$statusPath = dirname($syslogDir) . '/archive-status.json';
$temporary = $statusPath . '.tmp-' . getmypid();
file_put_contents($temporary, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
chmod($temporary, 0640);
rename($temporary, $statusPath);
printf("Retence dokončena: komprimováno %d, odstraněno %d, syslog %d B, IPFIX %d B.\n", $compressed, $deleted, $status['syslog_bytes'], $status['netflow_bytes']);

function safeDirectory(string $path): string
{
    $path = rtrim($path, '/');
    $blocked = ['', '/', '/var', '/var/lib', '/home', '/root'];
    if (in_array($path, $blocked, true) || strlen($path) < 18 || !str_starts_with($path, '/var/lib/wifimanager/')) {
        throw new RuntimeException('Nebezpečná cesta archivu: ' . $path);
    }
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Nelze vytvořit adresář ' . $path);
    }
    return $path;
}

function boundedInt(mixed $value, int $minimum, int $maximum, string $label): int
{
    $integer = filter_var($value, FILTER_VALIDATE_INT);
    if ($integer === false || $integer < $minimum || $integer > $maximum) {
        throw new RuntimeException('Neplatná hodnota: ' . $label);
    }
    return $integer;
}

/** @return list<string> */
function files(string $base, callable $accept): array
{
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink() && $accept($file->getPathname())) $result[] = $file->getPathname();
    }
    return $result;
}

function enforceBudget(string $base, int $budget, callable $accept): int
{
    $items = files($base, $accept);
    usort($items, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
    $size = totalSize($items);
    $deleted = 0;
    foreach ($items as $file) {
        if ($size <= $budget) break;
        $bytes = filesize($file) ?: 0;
        if (unlink($file)) {
            $size -= $bytes;
            $deleted++;
        }
    }
    return $deleted;
}

/** @param list<string> $items */
function totalSize(array $items): int
{
    return array_sum(array_map(static fn (string $path): int => (int) (filesize($path) ?: 0), $items));
}

/** @param list<string> $items */
function newestAt(array $items): ?string
{
    if ($items === []) return null;
    return date(DATE_ATOM, max(array_map('filemtime', $items)));
}

function removeEmptyDirectories(string $base): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
    }
}
