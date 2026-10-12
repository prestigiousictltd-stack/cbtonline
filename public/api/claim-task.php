<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Response;
use App\Services\TaskLockService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
if (!Auth::adminCheck()) {
    Response::json(['ok' => false, 'error' => 'UNAUTHENTICATED'], 401);
}

$admin = Auth::adminUser();
if (!$admin || $admin['status'] !== STATUS_ACTIVE) {
    Response::json(['ok' => false, 'error' => 'ADMIN_INACTIVE'], 403);
}

$token = $_POST[CSRF_FIELD_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Csrf::check(is_string($token) ? $token : null)) {
    Response::json(['ok' => false, 'error' => 'CSRF_FAILED'], 419);
}

$submissionId = (int) ($_POST['submission_id'] ?? 0);
if ($submissionId <= 0) {
    Response::json(['ok' => false, 'error' => 'INVALID_ID'], 422);
}

$svc    = new TaskLockService();
$result = $svc->claim($submissionId, $admin);

if (!empty($result['ok'])) {
    Response::json([
        'ok'                => true,
        'deadline'          => $result['deadline'] ?? null,
        'seconds_remaining' => $result['seconds_remaining'] ?? null,
        'redirect'          => url('/admin/process-task?id=' . $submissionId),
    ], 200);
}

match ($result['error'] ?? '') {
    'ALREADY_CLAIMED' => Response::json([
        'ok'                  => false,
        'error'               => 'ALREADY_CLAIMED',
        'message'             => 'This task is already being processed by another admin.',
        'assigned_admin_name' => $result['assigned_admin_name'] ?? null,
    ], 409),
    'NOT_FOUND'       => Response::json(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Task not found.'], 404),
    'NOT_PENDING'     => Response::json(['ok' => false, 'error' => 'NOT_PENDING', 'status' => $result['status'] ?? null], 409),
    'MAX_CONCURRENT_REACHED' => Response::json([
        'ok' => false,
        'error' => 'MAX_CONCURRENT_REACHED',
        'message' => 'You already hold the maximum number of active tasks.',
    ], 429),
    'ADMIN_INACTIVE'  => Response::json(['ok' => false, 'error' => 'ADMIN_INACTIVE'], 403),
    default           => Response::json(['ok' => false, 'error' => 'SERVER_ERROR'], 500),
};