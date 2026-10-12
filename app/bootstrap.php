<?php
declare(strict_types=1);

define('APP_ROOT',  dirname(__DIR__));
define('APP_START', microtime(true));

// 1. Helpers must load before .env
require_once APP_ROOT . '/app/support/helpers.php';

// 2. Load environment
load_env(APP_ROOT . '/.env');

// 3. Constants + config
require_once APP_ROOT . '/app/support/constants.php';
require_once APP_ROOT . '/config/app.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/config/security.php';
require_once APP_ROOT . '/config/payments.php';
require_once APP_ROOT . '/app/support/format.php';

// 4. Error handling — production logs, never displays
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

    $logDir = APP_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/php-error.log');
}

// 5. Timezone
date_default_timezone_set(APP_TIMEZONE);

// 6. PSR-4-ish autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 7. Production-only integrity preflight
if (!APP_DEBUG) {
    security_preflight();
}

// 8. Session, CSRF, settings
App\Core\Session::start();
App\Core\Csrf::bootstrap();
App\Core\Settings::load();

// 9. Emit security headers (safe on all responses)
App\Core\Response::emitSecurityHeaders();

// 10. Remember-me auto-login
App\Core\Auth::attemptRememberLogin();

/**
 * Fails the request immediately if an obvious misconfiguration exists.
 * Only runs when APP_DEBUG is off so local dev is unaffected.
 */
function security_preflight(): void
{
    // 1. Sensitive directories must NOT be reachable from the web root.
    //    We detect this by checking that they live above the DocumentRoot.
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $sensitives = ['config', 'app', 'storage', 'database', 'scripts', 'cron', '.env'];

    if ($docRoot !== false) {
        foreach ($sensitives as $name) {
            $path = realpath(APP_ROOT . '/' . $name);
            if ($path === false) continue;
            if (str_starts_with($path, $docRoot . DIRECTORY_SEPARATOR)) {
                error_log('[SECURITY] Sensitive path exposed under DocumentRoot: ' . $path);
                http_response_code(500);
                exit('Configuration error: sensitive path is web-reachable.');
            }
        }
    }

    // 2. .env must be a plain file, not world-readable.
    $envPath = APP_ROOT . '/.env';
    if (is_file($envPath) && (fileperms($envPath) & 0o004)) {
        // world-readable — log and continue (many hosts ignore perms anyway)
        error_log('[SECURITY] .env is world-readable — tighten permissions to 0600.');
    }

    // 3. storage/uploads must not be served statically. We cannot check
    //    Apache config from PHP, but we can check the .htaccess marker file.
    if (!is_file(APP_ROOT . '/storage/.htaccess')) {
        error_log('[SECURITY] storage/.htaccess is missing.');
    }
}

/**
 * Remove any HTTP headers that leak the tech stack. Runs once per request.
 */
if (!headers_sent() && !APP_DEBUG) {
    header_remove('X-Powered-By');
    header_remove('Server');
}