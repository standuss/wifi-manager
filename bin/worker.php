#!/usr/bin/env php
<?php

declare(strict_types=1);

use WifiManager\Services\AuditService;
use WifiManager\Services\JobProcessor;
use WifiManager\Services\JobService;
use WifiManager\Services\RouterFactory;
use WifiManager\Services\SettingsService;
use WifiManager\Services\SyncService;
use WifiManager\Services\SmtpMailer;
use WifiManager\Services\NotificationService;
use WifiManager\Services\BackupService;

$container = require dirname(__DIR__) . '/app/bootstrap.php';
extract($container);
$database->migrate(dirname(__DIR__) . '/database/schema.sql');

$settings = new SettingsService($database);
$routerFactory = new RouterFactory($database, $crypto);
$jobService = new JobService($database, $crypto);
$audit = new AuditService($database);
$notifications = new NotificationService($database, $settings, $crypto, new SmtpMailer());
$backups = new BackupService($database, $config, $settings, $jobService, $crypto, $notifications);
$processor = new JobProcessor($database, $routerFactory, $settings, $jobService, $audit, $backups);
$sync = new SyncService($database, $routerFactory, $settings, $crypto, $notifications);

$once = in_array('--once', $argv, true);
$fastInterval = max(2, (int) $config->get('sync.fast_interval_seconds', 3));
$fullInterval = max($fastInterval, (int) $config->get('sync.full_interval_seconds', 60));
$lastFull = 0;

do {
    $cycleStarted = time();
    $processed = 0;
    while ($processed < 10 && $processor->processOne()) $processed++;

    // A configuration job can change WiFi networks. Refresh the complete cache
    // in the same cycle so the UI does not keep showing the old state.
    $full = $processed > 0 || $lastFull === 0 || (time() - $lastFull) >= $fullInterval;
    $routerIds = $database->pdo()->query('SELECT id FROM routers WHERE enabled = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($routerIds as $routerId) {
        try {
            $sync->sync((int) $routerId, $full);
            if ($full) $backups->scheduleIfDue((int) $routerId);
            fwrite(STDOUT, sprintf("[%s] Router %d synchronizován%s.\n", date('c'), $routerId, $full ? ' kompletně' : ''));
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("[%s] Router %d: %s\n", date('c'), $routerId, $exception->getMessage()));
        }
    }
    $sent = 0;
    while ($sent < 5 && $notifications->processOne()) $sent++;
    if ($full) $lastFull = time();
    if ($once) break;
    $elapsed = time() - $cycleStarted;
    sleep(max(1, $fastInterval - $elapsed));
} while (true);
