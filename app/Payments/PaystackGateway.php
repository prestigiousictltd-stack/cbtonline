<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\Logger;

/**
 * Paystack adapter.
 *
 * Reference: https://paystack.com/docs/api/
 *   - Initialize: POST /transaction/initialize
 *   - Verify:     GET  /transaction/verify/:reference
 *   - Webhook:    HMAC-SHA512 signature over the raw body with the secret key
 *                 sent in header `x-paystack-signature`
 */
final class PaystackGateway implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.paystack.co';

    public function name(): string  { return 'paystack'; }
    public function label(): string { return 'Paystack (card, bank, USSD)'; }

    public function isConfigured(): bool
    {
        return PAYSTACK_SECRET_KEY !== '' && PAYSTACK_PUBLIC_KEY !== '';
    }

    public function supportsWebhook(): bool { return true; }

    // ================================================================
    // INITIALIZE
    // ================================================================

    public function initialize(array $data): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paystack is not configured.'];
        }

        $amountKobo = (int) round((float) $data['amount'] * 100);
        if ($amountKobo <= 0) {
            return ['ok' => false, 'error' => 'Invalid amount.'];
        }

        $payload = [
            'email'        => (string) $data['email'],
            'amount'       => $amountKobo,
            'reference'    => (string) $data['reference'],
            'callback_url' => (string) $data['callback_url'],
            'currency'     => (string) ($data['currency'] ?? 'NGN'),
            'metadata'     => array_merge(
                ['reference' => (string) $data['reference']],
                is_array($data['metadata'] ?? null) ? $data['metadata'] : []
            ),
        ];

        $res = $this->request('POST', '/transaction/initialize', $payload);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Paystack initialize failed.'];
        }

        $body = $res['body'] ?? [];
        if (empty($body['status']) || empty($body['data']['authorization_url'])) {
            return [
                'ok'    => false,
                'error' => (string) ($body['message'] ?? 'Paystack did not return an authorization URL.'),
            ];
        }

        return [
            'ok'                => true,
            'authorization_url' => (string) $body['data']['authorization_url'],
            'gateway_reference' => (string) ($body['data']['reference'] ?? $data['reference']),
            'raw'               => $body,
        ];
    }

    // ================================================================
    // VERIFY
    // ================================================================

    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Paystack is not configured.'];
        }

        $res = $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Paystack verify failed.'];
        }

        $body = $res['body'] ?? [];
        if (empty($body['status']) || empty($body['data'])) {
            return [
                'ok'    => false,
                'error' => (string) ($body['message'] ?? 'Paystack verify returned no data.'),
            ];
        }

        $tx = $body['data'];
        $amount = isset($tx['amount']) ? number_format((float) $tx['amount'] / 100, 2, '.', '') : '0.00';
        $status = $this->mapStatus((string) ($tx['status'] ?? ''));

        return [
            'ok'                => true,
            'status'            => $status,
            'amount'            => $amount,
            'reference'         => (string) ($tx['reference'] ?? $reference),
            'gateway_reference' => (string) ($tx['id'] ?? ''),
            'payload'           => $tx,
        ];
    }

    // ================================================================
    // WEBHOOK
    // ================================================================

    public function handleWebhook(array $headers, string $rawBody): array
    {
        $signature = $this->header($headers, 'x-paystack-signature');
        if ($signature === '') {
            return ['ok' => false, 'error' => 'Missing webhook signature.'];
        }

        $expected = hash_hmac('sha512', $rawBody, PAYSTACK_SECRET_KEY);
        if (!hash_equals($expected, $signature)) {
            Logger::warning('Paystack webhook signature mismatch');
            return ['ok' => false, 'error' => 'Invalid signature.'];
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return ['ok' => false, 'error' => 'Invalid JSON payload.'];
        }

        $eventName = (string) ($event['event'] ?? '');
        $tx        = $event['data'] ?? [];

        // Only handle charge.success — others can be ignored idempotently.
        if ($eventName !== 'charge.success') {
            return ['ok' => true, 'event' => $eventName, 'status' => 'pending', 'payload' => $event];
        }

        $amount = isset($tx['amount']) ? number_format((float) $tx['amount'] / 100, 2, '.', '') : '0.00';
        $status = $this->mapStatus((string) ($tx['status'] ?? ''));

        // Our reference lives in metadata.reference, with a fallback to the gateway reference
        $ourRef = (string) ($tx['metadata']['reference'] ?? ($tx['reference'] ?? ''));

        return [
            'ok'                => true,
            'event'             => $eventName,
            'reference'         => $ourRef,
            'gateway_reference' => (string) ($tx['reference'] ?? ''),
            'amount'            => $amount,
            'status'            => $status,
            'payload'           => $event,
        ];
    }

    // ================================================================
    // HTTP
    // ================================================================

    /**
     * @return array{ok:bool,body?:array,error?:string,http?:int}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init();
        $url = self::BASE_URL . $path;
        $headers = [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Accept: application/json',
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        // Disable SSL verification ONLY in local/dev to help on Windows XAMPP
        if (APP_ENV === 'local') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            Logger::warning('Paystack HTTP error', ['url' => $url, 'error' => $err]);
            return ['ok' => false, 'error' => $err ?: 'Network error.'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Invalid JSON from Paystack.'];
        }

        // Paystack returns 4xx with a JSON `message` — surface it
        if ($http >= 400) {
            return [
                'ok'    => false,
                'error' => (string) ($decoded['message'] ?? ('HTTP ' . $http)),
                'body'  => $decoded,
                'http'  => $http,
            ];
        }

        return ['ok' => true, 'body' => $decoded, 'http' => $http];
    }

    // ================================================================
    // HELPERS
    // ================================================================

    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'success', 'successful' => 'successful',
            'failed', 'reversed'    => 'failed',
            default                 => 'pending',
        };
    }

    private function header(array $headers, string $name): string
    {
        // Normalise keys to lowercase for a case-insensitive lookup
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === strtolower($name)) {
                return is_array($v) ? (string) $v[0] : (string) $v;
            }
        }
        return '';
    }
}