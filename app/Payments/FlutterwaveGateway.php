<?php
declare(strict_types=1);

namespace App\Payments;

use App\Core\Logger;

/**
 * Flutterwave v3 adapter.
 *
 * Reference: https://developer.flutterwave.com/reference
 *   - Initialize: POST /v3/payments
 *   - Verify:     GET  /v3/transactions/verify_by_reference?tx_ref=
 *   - Webhook:    Shared secret hash in header `verif-hash`
 */
final class FlutterwaveGateway implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.flutterwave.com';

    public function name(): string  { return 'flutterwave'; }
    public function label(): string { return 'Flutterwave (card, bank, USSD)'; }

    public function isConfigured(): bool
    {
        return FLUTTERWAVE_SECRET_KEY !== '' && FLUTTERWAVE_PUBLIC_KEY !== '';
    }

    public function supportsWebhook(): bool { return true; }

    // ================================================================
    // INITIALIZE
    // ================================================================

    public function initialize(array $data): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Flutterwave is not configured.'];
        }

        $amount = number_format((float) $data['amount'], 2, '.', '');
        if ((float) $amount <= 0) {
            return ['ok' => false, 'error' => 'Invalid amount.'];
        }

        $payload = [
            'tx_ref'       => (string) $data['reference'],
            'amount'       => $amount,
            'currency'     => (string) ($data['currency'] ?? 'NGN'),
            'redirect_url' => (string) $data['callback_url'],
            'payment_options' => 'card,banktransfer,ussd',
            'customer'     => [
                'email' => (string) $data['email'],
            ],
            'customizations' => [
                'title'       => APP_NAME,
                'description' => (string) ($data['description'] ?? 'Wallet funding'),
            ],
            'meta' => array_merge(
                ['reference' => (string) $data['reference']],
                is_array($data['metadata'] ?? null) ? $data['metadata'] : []
            ),
        ];

        $res = $this->request('POST', '/v3/payments', $payload);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Flutterwave initialize failed.'];
        }

        $body = $res['body'] ?? [];
        if (($body['status'] ?? '') !== 'success' || empty($body['data']['link'])) {
            return [
                'ok'    => false,
                'error' => (string) ($body['message'] ?? 'Flutterwave did not return a payment link.'),
            ];
        }

        return [
            'ok'                => true,
            'authorization_url' => (string) $body['data']['link'],
            'gateway_reference' => (string) ($body['data']['tx_ref'] ?? $data['reference']),
            'raw'               => $body,
        ];
    }

    // ================================================================
    // VERIFY
    // ================================================================

    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Flutterwave is not configured.'];
        }

        $res = $this->request(
            'GET',
            '/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference)
        );
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Flutterwave verify failed.'];
        }

        $body = $res['body'] ?? [];
        if (($body['status'] ?? '') !== 'success' || empty($body['data'])) {
            return [
                'ok'    => false,
                'error' => (string) ($body['message'] ?? 'Flutterwave verify returned no data.'),
            ];
        }

        $tx     = $body['data'];
        $amount = isset($tx['amount']) ? number_format((float) $tx['amount'], 2, '.', '') : '0.00';
        $status = $this->mapStatus((string) ($tx['status'] ?? ''));

        return [
            'ok'                => true,
            'status'            => $status,
            'amount'            => $amount,
            'reference'         => (string) ($tx['tx_ref'] ?? $reference),
            'gateway_reference' => (string) ($tx['id'] ?? ''),
            'payload'           => $tx,
        ];
    }

    // ================================================================
    // WEBHOOK
    // ================================================================

    public function handleWebhook(array $headers, string $rawBody): array
    {
        $signature = $this->header($headers, 'verif-hash');
        if ($signature === '') {
            return ['ok' => false, 'error' => 'Missing verif-hash header.'];
        }

        // Flutterwave's legacy "hash" is the secret_key in older dashboards;
        // v3 lets you set a "secret hash" — we use FLUTTERWAVE_SECRET_KEY for
        // both cases; if the deployment uses a different secret hash, store
        // it in system_settings under `flutterwave_webhook_hash` and pass it
        // via \App\Core\Settings::get(). Falls back to the secret key.
        $expected = (string) (\App\Core\Settings::get('flutterwave_webhook_hash', FLUTTERWAVE_SECRET_KEY));
        if ($expected === '' || !hash_equals($expected, $signature)) {
            Logger::warning('Flutterwave webhook hash mismatch');
            return ['ok' => false, 'error' => 'Invalid webhook hash.'];
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            return ['ok' => false, 'error' => 'Invalid JSON payload.'];
        }

        $data      = $event['data'] ?? [];
        $eventName = (string) ($event['event'] ?? '');
        $status    = $this->mapStatus((string) ($data['status'] ?? ''));

        // Flutterwave's tx_ref is our payment_ref
        $ourRef = (string) ($data['tx_ref'] ?? '');
        $amount = isset($data['amount']) ? number_format((float) $data['amount'], 2, '.', '') : '0.00';

        return [
            'ok'                => true,
            'event'             => $eventName,
            'reference'         => $ourRef,
            'gateway_reference' => (string) ($data['id'] ?? ''),
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
            'Authorization: Bearer ' . FLUTTERWAVE_SECRET_KEY,
            'Accept: application/json',
            'Content-Type: application/json',
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

        if (APP_ENV === 'local') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            Logger::warning('Flutterwave HTTP error', ['url' => $url, 'error' => $err]);
            return ['ok' => false, 'error' => $err ?: 'Network error.'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Invalid JSON from Flutterwave.'];
        }
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

    private function mapStatus(string $s): string
    {
        return match (strtolower($s)) {
            'successful', 'success' => 'successful',
            'failed', 'cancelled'   => 'failed',
            default                 => 'pending',
        };
    }

    private function header(array $headers, string $name): string
    {
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === strtolower($name)) {
                return is_array($v) ? (string) $v[0] : (string) $v;
            }
        }
        return '';
    }
}