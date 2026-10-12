<?php
declare(strict_types=1);

define('SESSION_NAME',           (string) env('SESSION_NAME', 'servicehub_session'));
define('SESSION_LIFETIME',       (int)    env('SESSION_LIFETIME', 7200));
define('LOGIN_MAX_ATTEMPTS',     (int)    env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES',  (int)    env('LOGIN_LOCKOUT_MINUTES', 15));
define('CSRF_TOKEN_KEY',         '_csrf_token');
define('CSRF_FIELD_NAME',        '_token');

// Files & uploads
define('UPLOAD_MAX_BYTES',       5 * 1024 * 1024);
define('UPLOAD_ALLOWED_EXT',     ['pdf', 'jpg', 'jpeg', 'png']);
define('UPLOAD_ALLOWED_MIME',    [
    'application/pdf',
    'image/jpeg',
    'image/png',
]);