<?php
declare(strict_types=1);

define('APP_ENV',      (string) env('APP_ENV', 'production'));
define('APP_DEBUG',    filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));
define('APP_NAME',     (string) env('APP_NAME', 'ServiceHub NG'));
$configuredAppUrl = (string) env('APP_URL', '');
if ($configuredAppUrl === '' || $configuredAppUrl === 'http://localhost') {
    if (env('RAILWAY_PUBLIC_DOMAIN')) {
        $configuredAppUrl = 'https://' . env('RAILWAY_PUBLIC_DOMAIN');
    } elseif (env('RAILWAY_STATIC_URL')) {
        $configuredAppUrl = 'https://' . env('RAILWAY_STATIC_URL');
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $configuredAppUrl = ($isHttps ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    } else {
        $configuredAppUrl = 'http://localhost';
    }
}
define('APP_URL', rtrim($configuredAppUrl, '/'));
define('APP_TIMEZONE', (string) env('APP_TIMEZONE', 'Africa/Lagos'));
define('APP_VERSION',  '1.0.0');