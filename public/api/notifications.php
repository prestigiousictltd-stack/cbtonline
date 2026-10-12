<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\NotificationService;

header('Content-Type: application/json; charset=utf-8');

if (!Auth::clientCheck()) {
    Response::json(['ok' => false, 'error' => 'unauthenticated'], 401);
}

$count = (new NotificationService())->unreadCount('client', (int) Auth::clientId());
Response::json(['ok' => true, 'unread' => $count]);