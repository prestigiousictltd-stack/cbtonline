<?php
declare(strict_types=1);

define('APP_ENV',      (string) env('APP_ENV', 'production'));
define('APP_DEBUG',    filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));
define('APP_NAME',     (string) env('APP_NAME', 'ServiceHub NG'));
define('APP_URL',      rtrim((string) env('APP_URL', 'http://localhost'), '/'));
define('APP_TIMEZONE', (string) env('APP_TIMEZONE', 'Africa/Lagos'));
define('APP_VERSION',  '1.0.0');