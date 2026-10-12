<?php
declare(strict_types=1);
/**
 * cron/release_expired_tasks.php
 *
 * CLI-only. Sweeps expired processing tasks back to the pool.
 * Schedule: every 1–5 minutes (see docs/CRON.md).
 *
 * Usage:
 *   php cron/release_expired_tasks.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Services\TaskLockService;

$started = microtime(true);
$svc     = new TaskLockService();
$svc->resetSweepGuard();

$result = $svc->sweepExpired(500, true);

$elapsed = round((microtime(true) - $started) * 1000);

fwrite(STDOUT, sprintf(
    "[%s] release_expired_tasks: released=%d, ids=[%s], took=%dms\n",
    date('Y-m-d H:i:s'),
    $result['released'],
    implode(',', $result['ids']),
    $elapsed
));

exit(0);