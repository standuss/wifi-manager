<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'WiFi Manager',
        'environment' => 'production',
        'debug' => false,
        'timezone' => 'Europe/Prague',
        'base_path' => '',
        'https' => false,
        'key' => '',
    ],
    'database' => [
        'path' => dirname(__DIR__) . '/storage/wifimanager.sqlite',
        'busy_timeout_ms' => 5000,
    ],
    'session' => [
        'name' => 'wifimanager_session',
        'lifetime' => 28800,
    ],
    'sync' => [
        'fast_interval_seconds' => 3,
        'full_interval_seconds' => 60,
        'offline_after_seconds' => 15,
    ],
    'logging' => [
        'syslog_dir' => '/var/lib/wifimanager/syslog',
        'netflow_dir' => '/var/lib/wifimanager/netflow',
        'nfdump_binary' => '/usr/bin/nfdump',
        'query_limit' => 500,
        'max_query_days' => 31,
        'query_timeout_seconds' => 20,
        'max_output_bytes' => 8388608,
    ],
    'update' => [
        'repository' => 'standuss/wifi-manager',
        'channel' => 'stable',
        'request_dir' => '/var/lib/wifimanager/update-requests',
        'status_file' => '/var/lib/wifimanager/update-status.json',
        'github_api' => 'https://api.github.com',
        'timeout_seconds' => 12,
    ],
    'system' => [
        'request_dir' => '/var/lib/wifimanager/service-requests',
    ],
];
