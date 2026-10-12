<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Logger;
use App\Payments\GatewayManager;
use App\Services\OnlinePaymentService;

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input') ?: '';
if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty body']);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!$headers) {
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$name] = $v;
        }
    }
}

try {
    $driver = GatewayManager::driver('flutterwave');
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Gateway unavailable']);
    exit;
}

$envelope = $driver->handleWebhook($headers, $rawBody);

(new App\Services\AuditService())->log(
    'PAYMENT_WEBHOOK_RECEIVED', 'payment', null,
    'Flutterwave webhook: ' . ($envelope['event'] ?? 'unknown'),
    newValues: [
        'ok'        => !empty($envelope['ok']),
        'reference' => $envelope['reference'] ?? null,
        'status'    => $envelope['status'] ?? null,
        'error'     => $envelope['error'] ?? null,
    ],
    userType: 'system'
);

if (empty($envelope['ok'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $envelope['error'] ?? 'Invalid webhook']);
    exit;
}

$result = (new OnlinePaymentService())->confirmWebhook('flutterwave', $envelope);

http_response_code(!empty($result['ok']) ? 200 : 500);
echo json_encode([
    'ok'          => !empty($result['ok']),
    'status'      => $result['status']      ?? null,
    'payment_ref' => $result['payment_ref'] ?? null,
    'duplicate'   => $result['duplicate']   ?? false,
    'error'       => $result['error']       ?? null,
]);