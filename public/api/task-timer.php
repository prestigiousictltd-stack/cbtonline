<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

header('Content-Type: application/json; charset=utf-8');

if (!Auth::adminCheck()) {
    Response::json(['ok' => false, 'error' => 'UNAUTHENTICATED'], 401);
}

$admin = Auth::adminUser();
$id    = (int) ($_GET['id'] ?? 0);
if ($id <= 0) Response::json(['ok' => false, 'error' => 'INVALID_ID'], 422);

$row = Database::selectOne(
    'SELECT id, tracking_id, status, assigned_admin_id, deadline_at
     FROM submissions WHERE id = :id LIMIT 1',
    [':id' => $id]
);
if (!$row) Response::json(['ok' => false, 'error' => 'NOT_FOUND'], 404);

// Only the owning admin (or a Super Admin, for viewing) may read the timer.
$isOwner = (int) $row['assigned_admin_id'] === (int) $admin['id'];
if (!$isOwner && !Auth::superAdminCheck()) {
    Response::json(['ok' => false, 'error' => 'FORBIDDEN'], 403);
}

$deadlineTs = $row['deadline_at'] ? strtotime($row['deadline_at'] . ' UTC') : null;
$remaining  = $deadlineTs !== null ? max(0, $deadlineTs - time()) : null;

Response::json([
    'ok'                => true,
    'status'            => $row['status'],
    'deadline_utc'      => $row['deadline_at'],
    'deadline_unix'     => $deadlineTs,
    'seconds_remaining' => $remaining,
    'server_now_unix'   => time(),
]);