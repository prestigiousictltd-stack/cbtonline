<?php
declare(strict_types=1);

define('DB_HOST', (string) env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int)    env('DB_PORT', 3306));
define('DB_NAME', (string) env('DB_NAME', 'servicehub_ng'));
define('DB_USER', (string) env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));