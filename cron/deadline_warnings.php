<?php
declare(strict_types=1);
/**
 * cron/deadline_warnings.php
 *
 * CLI-only. Notifies admins whose claimed tasks are within 60 minutes of
 * their deadline. Schedule: every 15 minutes.
 *
 * Usage:
 *   php cron/deadline_warnings.php [minutes]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Services\TaskLockService;

$minutes = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 60;
$minutes = max(5, min(1440, $minutes));

$svc  = new TaskLockService();
$sent = $svc->warnApproachingDeadlines($minutes);

fwrite(STDOUT, sprintf(
    "[%s] deadline_warnings: within_minutes=%d sent=%d\n",
    date('Y-m-d H:i:s'), $minutes, $sent
));

exit(0);