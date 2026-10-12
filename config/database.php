<?php
declare(strict_types=1);

// Support connection URLs (MYSQL_URL, DATABASE_URL) injected by Railway / cloud providers
$dbUrl = (string) (env('DATABASE_URL') ?: env('MYSQL_URL') ?: '');
$urlHost = null;
$urlPort = null;
$urlUser = null;
$urlPass = null;
$urlName = null;

if ($dbUrl !== '') {
    $parsed = parse_url($dbUrl);
    if (is_array($parsed)) {
        $urlHost = $parsed['host'] ?? null;
        $urlPort = isset($parsed['port']) ? (int) $parsed['port'] : null;
        $urlUser = isset($parsed['user']) ? urldecode((string) $parsed['user']) : null;
        $urlPass = isset($parsed['pass']) ? urldecode((string) $parsed['pass']) : null;
        if (!empty($parsed['path'])) {
            $urlName = ltrim($parsed['path'], '/');
        }
    }
}

define('DB_HOST', (string) ($urlHost ?? env('DB_HOST', env('MYSQLHOST', env('MYSQL_HOST', '127.0.0.1')))));
define('DB_PORT', (int)    ($urlPort ?? env('DB_PORT', env('MYSQLPORT', env('MYSQL_PORT', 3306)))));
define('DB_NAME', (string) ($urlName ?? env('DB_NAME', env('MYSQLDATABASE', env('MYSQL_DATABASE', 'servicehub_ng')))));
define('DB_USER', (string) ($urlUser ?? env('DB_USER', env('MYSQLUSER', env('MYSQL_USER', 'root')))));
define('DB_PASS', (string) ($urlPass ?? env('DB_PASS', env('MYSQLPASSWORD', env('MYSQL_PASSWORD', '')))));