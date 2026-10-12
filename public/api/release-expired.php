<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Response;
use App\Core\Settings;
use App\Services\TaskLockService;

header('Content-Type: application/json; charset=utf-8');

// -- Authenticate the caller ----------------------------------------------
$expected = (string) Settings::get('sweep_token', '');
if ($expected === '') {
    // First ever call — mint one and lock it in.
    $expected = bin2hex(random_bytes(24));
    Settings::set('sweep_token', $expected);
    Response::json([
        'ok'      => false,
        'error'   => 'SWEEP_TOKEN_INITIALISED',
        'message' => 'A sweep_token has been generated and saved in system_settings. Retry with it.',
    ], 401);
}

$provided = $_SERVER['HTTP_X_SWEEP_TOKEN'] ?? ($_GET['token'] ?? '');
if (!is_string($provided) || !hash_equals($expected, $provided)) {
    Response::json(['ok' => false, 'error' => 'FORBIDDEN'], 403);
}

// -- Run the sweep --------------------------------------------------------
$svc    = new TaskLockService();
$result = $svc->sweepExpired(500, true);  // force = true to bypass per-request guard

Response::json([
    'ok'       => true,
    'released' => (int) $result['released'],
    'ids'      => $result['ids'],
]);