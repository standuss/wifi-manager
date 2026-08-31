<?php

declare(strict_types=1);

use WifiManager\Config;
use WifiManager\Csrf;

function app_config(?string $key = null, mixed $default = null): mixed
{
    /** @var Config $config */
    $config = $GLOBALS['wfm_config'];
    return $key === null ? $config : $config->get($key, $default);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    $base = rtrim((string) app_config('app.base_path', ''), '/');
    $path = '/' . ltrim($path, '/');
    return $base . ($path === '//' ? '/' : $path);
}

function asset(string $path): string
{
    return url('/assets/' . ltrim($path, '/'));
}

function redirect(string $path, int $status = 302): never
{
    header('Location: ' . url($path), true, $status);
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function consume_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($flashes) ? $flashes : [];
}

function request_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function password_algorithm(): string|int|null
{
    return defined('PASSWORD_ARGON2ID') ? constant('PASSWORD_ARGON2ID') : PASSWORD_DEFAULT;
}

function normalize_mac(string $mac): string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
    if (strlen($hex) !== 12) {
        throw new InvalidArgumentException('MAC adresa nemá platný formát.');
    }
    return implode(':', str_split($hex, 2));
}

function is_private_mac(string $mac): bool
{
    try {
        $normalized = normalize_mac($mac);
    } catch (Throwable) {
        return false;
    }
    return (hexdec(substr($normalized, 0, 2)) & 0x02) === 0x02;
}

function signal_class(?int $signal): string
{
    if ($signal === null) return 'muted';
    if ($signal >= -60) return 'good';
    if ($signal >= -70) return 'ok';
    if ($signal >= -80) return 'warn';
    return 'bad';
}

function format_datetime(?string $value): string
{
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) app_config('app.timezone', 'Europe/Prague')))
            ->format('d. m. Y H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

function format_bytes(int|float|null $bytes, int $precision = 1): string
{
    if ($bytes === null) return '—';
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return number_format($bytes, $index === 0 ? 0 : $precision, ',', ' ') . ' ' . $units[$index];
}

function icon(string $name, string $class = ''): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'devices' => '<rect x="5" y="2.5" width="14" height="19" rx="3"/><path d="M10 18h4"/>',
        'register' => '<path d="M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M19 8v6m3-3h-6"/>',
        'wifi' => '<path d="M5 12.6a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01M2 9a15 15 0 0 1 20 0"/>',
        'radio' => '<circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4M19 5a10 10 0 0 1 0 14M5 19A10 10 0 0 1 5 5"/>',
        'network' => '<rect x="3" y="3" width="18" height="6" rx="2"/><rect x="3" y="15" width="18" height="6" rx="2"/><path d="M7 9v6m10-6v6M7 6h.01M7 18h.01"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6m3-3h-6"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'refresh' => '<path d="M20 6v5h-5M4 18v-5h5"/><path d="M18 9a7 7 0 0 0-12-2L4 11m16 2-2 4a7 7 0 0 1-12 0"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'alert' => '<path d="M10.3 3.3 2.1 18a2 2 0 0 0 1.8 3h16.2a2 2 0 0 0 1.8-3L13.7 3.3a2 2 0 0 0-3.4 0z"/><path d="M12 9v4m0 4h.01"/>',
        'server' => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A10.8 10.8 0 0 1 12 4c6 0 10 8 10 8a17 17 0 0 1-2.1 3.1M6.6 6.6C3.8 8.4 2 12 2 12s4 8 10 8a9.8 9.8 0 0 0 4.1-.9"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'terminal' => '<path d="m4 17 6-6-6-6M12 19h8"/>',
        'flow' => '<circle cx="5" cy="6" r="2"/><circle cx="19" cy="6" r="2"/><circle cx="12" cy="18" r="2"/><path d="M7 6h10M6.5 7.5l4.3 8.7M17.5 7.5l-4.3 8.7"/>',
        'archive' => '<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
        'arrow-right' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M5 21h14"/>',
        'github' => '<path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3.3-.4 6.8-1.6 6.8-7A5.4 5.4 0 0 0 19.4 4 5 5 0 0 0 19.3.5S18.2.1 15 1.8a13.4 13.4 0 0 0-7 0C4.8.1 3.7.5 3.7.5A5 5 0 0 0 3.6 4a5.4 5.4 0 0 0-1.4 3.7c0 5.4 3.5 6.6 6.8 7A4.8 4.8 0 0 0 8 18v4M8 19c-3 .9-3-1.5-4-2"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6m4-6v6"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
    ];
    $body = $paths[$name] ?? $paths['alert'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
