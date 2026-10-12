<?php
declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && !isset($_ENV[$key])) {
            $_ENV[$key] = $value; putenv("$key=$value");
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_ENV)) return $_ENV[$key];
    $v = getenv($key);
    return $v === false ? $default : $v;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = defined('APP_URL') ? APP_URL : '';
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    $target = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
        ? $path : url($path);
    header('Location: ' . $target);
    exit;
}

function client_ip(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }
function user_agent(): string { return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255); }

function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) return [];
    $all = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $all;
}

function only(array $source, array $keys): array
{
    $out = [];
    foreach ($keys as $k) if (array_key_exists($k, $source)) $out[$k] = $source[$k];
    return $out;
}

function random_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }

function make_reference(string $prefix, int $number, ?string $year = null): string
{
    $year ??= date('Y');
    return sprintf('%s-%s-%06d', strtoupper($prefix), $year, $number);
}

/** Unique internal reference — safe to use for payments and wallet transactions. */
function next_reference(string $prefix): string
{
    return sprintf('%s-%s-%s',
        strtoupper($prefix),
        date('YmdHis'),
        strtoupper(bin2hex(random_bytes(3)))
    );
}

// ---------- Money helpers (string-safe) ----------

function money_normalize(mixed $value): string
{
    if (is_string($value)) $value = str_replace([',', ' '], '', $value);
    if (!is_numeric($value)) throw new InvalidArgumentException('Invalid money value.');
    return number_format((float) $value, 2, '.', '');
}

function money_add(string $a, string $b): string
{
    if (function_exists('bcadd')) return bcadd($a, $b, 2);
    return number_format((float) $a + (float) $b, 2, '.', '');
}

function money_sub(string $a, string $b): string
{
    if (function_exists('bcsub')) return bcsub($a, $b, 2);
    return number_format((float) $a - (float) $b, 2, '.', '');
}

function money_cmp(string $a, string $b): int
{
    if (function_exists('bccomp')) return bccomp($a, $b, 2);
    return (float) $a <=> (float) $b;
}

// ---------- Path awareness ----------

/** Return the app-relative path of the current request, e.g. "/client/wallet". */
function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $appPath = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_PATH) ?: '';
    if ($appPath !== '' && str_starts_with($uri, $appPath)) {
        $uri = substr($uri, strlen($appPath));
    }
    return '/' . ltrim($uri, '/');
}

function is_active(string $path, bool $exact = true): string
{
    $current = current_path();
    if ($exact) return $current === $path ? 'active' : '';
    return str_starts_with($current, $path) ? 'active' : '';
}

/**
 * Case-tolerant image resolver for service cards on Linux & Windows.
 * Resolves images whether the directory on disk is Assets/ or assets/,
 * whether the icon filename in DB is .svg, .jpg, or .JPG,
 * or by matching service name / slug keywords (jamb, waec, neco, nabteb, post-utme).
 */
function resolve_service_image(array|string $service): ?string
{
    $dirs = [
        APP_ROOT . '/public/Assets/img/services',
        APP_ROOT . '/public/assets/img/services',
    ];

    $icon = '';
    $slug = '';
    $name = '';

    if (is_array($service)) {
        $icon = trim((string) ($service['icon'] ?? ''));
        $slug = strtolower((string) ($service['slug'] ?? ''));
        $name = strtolower((string) ($service['name'] ?? ''));
    } else {
        $icon = trim($service);
    }

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;

        // 1. Direct file existence
        if ($icon !== '' && is_file($dir . '/' . $icon)) {
            return asset('img/services/' . $icon);
        }

        // 2. Case-insensitive search on disk (handles WAEC.JPG vs waec.jpg, etc.)
        $files = scandir($dir) ?: [];
        $files = array_diff($files, ['.', '..']);

        if ($icon !== '') {
            $iconBase = strtolower(pathinfo($icon, PATHINFO_FILENAME));
            foreach ($files as $f) {
                if (strcasecmp($f, $icon) === 0) {
                    return asset('img/services/' . $f);
                }
                if (strtolower(pathinfo($f, PATHINFO_FILENAME)) === $iconBase) {
                    return asset('img/services/' . $f);
                }
            }
        }

        // 3. Keyword / slug matching fallback (e.g. jamb, waec, neco, nabteb, post-utme)
        foreach ($files as $f) {
            $fBase = strtolower(pathinfo($f, PATHINFO_FILENAME));
            if ($fBase === '') continue;

            if (($slug !== '' && str_contains($slug, $fBase)) || ($name !== '' && str_contains($name, $fBase))) {
                return asset('img/services/' . $f);
            }
        }
    }

    return null;
}