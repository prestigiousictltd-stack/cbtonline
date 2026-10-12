<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Payments\GatewayManager;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletRepository;
use Throwable;

final class OnlinePaymentService
{
    public function __construct(
        private readonly PaymentRepository $payments = new PaymentRepository(),
        private readonly WalletRepository  $wallets  = new WalletRepository(),
        private readonly AuditService      $audit    = new AuditService(),
        private readonly NotificationService $notify = new NotificationService(),
    ) {}

    /**
     * Create a payment intent and call the gateway to get an authorization URL.
     *
     * @return array{ok:bool,redirect_url?:string,payment_ref?:string,error?:string}
     */
    public function initiate(int $userId, string $gatewayName, string $amountInput, string $email): array
    {
        try {
            $amount = money_normalize($amountInput);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Invalid amount.'];
        }

        if (money_cmp($amount, '100.00') < 0) {
            return ['ok' => false, 'error' => 'Minimum funding amount is ₦100.00.'];
        }
        if (money_cmp($amount, '10000000.00') > 0) {
            return ['ok' => false, 'error' => 'Maximum single funding amount is ₦10,000,000.00.'];
        }

        try {
            $driver = GatewayManager::driver($gatewayName);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Unknown gateway.'];
        }

        if (!$driver->isConfigured()) {
            return ['ok' => false, 'error' => $driver->label() . ' is not configured.'];
        }

        $wallet = $this->wallets->findByUserId($userId);
        if (!$wallet) return ['ok' => false, 'error' => 'Wallet not found.'];
        if ($wallet['status'] !== 'active') return ['ok' => false, 'error' => 'Your wallet is not active.'];

        $paymentRef = next_reference('PAY');

        // 1. Persist the intent BEFORE calling the gateway so we can recover
        //    even if the gateway call times out.
        try {
            $paymentId = $this->payments->create([
                'payment_ref'  => $paymentRef,
                'user_id'      => $userId,
                'wallet_id'    => (int) $wallet['id'],
                'gateway'      => $gatewayName,
                'method'       => 'online',
                'amount'       => $amount,
                'fee'          => '0.00',
                'net_amount'   => $amount,
                'status'       => PAYMENT_PENDING,
            ]);
        } catch (Throwable $e) {
            Logger::error('Could not create payment intent', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Could not start the payment. Please try again.'];
        }

        // 2. Ask the gateway for an authorization URL.
        $init = $driver->initialize([
            'amount'       => $amount,
            'email'        => $email,
            'reference'    => $paymentRef,
            'callback_url' => url('/payment/callback?gateway=' . urlencode($gatewayName)),
            'currency'     => 'NGN',
            'description'  => 'Wallet funding',
            'metadata'     => ['user_id' => $userId],
        ]);

        if (empty($init['ok'])) {
            // Mark the intent failed and return the error
            try {
                Database::execute(
                    'UPDATE payments SET status = "failed", rejection_reason = :r WHERE id = :id',
                    [':r' => substr((string) ($init['error'] ?? 'Initialize failed'), 0, 500), ':id' => $paymentId]
                );
            } catch (Throwable) {}
            return ['ok' => false, 'error' => $init['error'] ?? 'Could not reach the payment gateway.'];
        }

        // 3. Persist the gateway reference + raw payload.
        try {
            Database::execute(
                'UPDATE payments
                    SET gateway_reference = :ref, gateway_payload = :payload
                  WHERE id = :id',
                [
                    ':ref'     => $init['gateway_reference'] ?? null,
                    ':payload' => json_encode($init['raw'] ?? []),
                    ':id'      => $paymentId,
                ]
            );
        } catch (Throwable $e) {
            Logger::warning('Could not persist gateway reference', ['error' => $e->getMessage()]);
        }

        $this->audit->log(
            'PAYMENT_INITIATED', 'payment', $paymentId,
            'Online payment initiated via ' . $gatewayName . ' for ' . $amount,
            newValues: [
                'gateway'      => $gatewayName,
                'amount'       => $amount,
                'payment_ref'  => $paymentRef,
            ],
            userType: 'client', userId: $userId
        );

        return [
            'ok'           => true,
            'redirect_url' => $init['authorization_url'],
            'payment_ref'  => $paymentRef,
        ];
    }

    /**
     * Called by the return-URL handler. Verifies with the gateway and
     * credits the wallet idempotently. Returns a normalised envelope:
     *
     *   ['ok'=>bool, 'status'=>..., 'payment_ref'=>..., 'error'=>?]
     */
    public function confirmReturn(string $gatewayName, string $reference): array
    {
        try {
            $driver = GatewayManager::driver($gatewayName);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Unknown gateway.'];
        }

        if (!$driver->isConfigured()) {
            return ['ok' => false, 'error' => $driver->label() . ' is not configured.'];
        }

        $verify = $driver->verify($reference);
        if (empty($verify['ok'])) {
            return ['ok' => false, 'error' => $verify['error'] ?? 'Gateway verification failed.'];
        }

        return $this->finaliseFromGateway($gatewayName, $reference, $verify);
    }

    /**
     * Called by webhook handlers. Accepts the normalised envelope from
     * PaymentGatewayInterface::handleWebhook() and credits the wallet
     * idempotently.
     */
    public function confirmWebhook(string $gatewayName, array $envelope): array
    {
        if (empty($envelope['ok'])) {
            return ['ok' => false, 'error' => $envelope['error'] ?? 'Invalid webhook envelope.'];
        }
        if (empty($envelope['reference'])) {
            return ['ok' => false, 'error' => 'Webhook had no reference.'];
        }
        // Only finalise on a successful charge event
        if (($envelope['status'] ?? '') !== 'successful') {
            return ['ok' => true, 'status' => $envelope['status'] ?? 'pending'];
        }

        return $this->finaliseFromGateway($gatewayName, (string) $envelope['reference'], $envelope);
    }

    /**
     * Shared finalisation path used by both the return-URL and webhook flows.
     * Locks the payment row and credits the wallet at most once.
     */
    private function finaliseFromGateway(string $gatewayName, string $reference, array $result): array
    {
        try {
            return Database::transaction(function () use ($gatewayName, $reference, $result): array {
                $payment = $this->payments->findByRef($reference);
                if (!$payment) {
                    return ['ok' => false, 'error' => 'Payment not found.'];
                }

                // Row lock, then re-read status under the lock
                $locked = $this->payments->lockForUpdate((int) $payment['id']);
                if (!$locked) return ['ok' => false, 'error' => 'Payment lock failed.'];

                // Idempotent short-circuit
                if ($locked['status'] === PAYMENT_SUCCESSFUL) {
                    $this->audit->log(
                        'PAYMENT_DUPLICATE_IGNORED', 'payment', (int) $locked['id'],
                        'Duplicate confirmation ignored for ' . $locked['payment_ref'],
                        userType: 'system'
                    );
                    return ['ok' => true, 'status' => 'successful', 'payment_ref' => $locked['payment_ref'], 'duplicate' => true];
                }

                if (!in_array($locked['status'], [PAYMENT_PENDING, PAYMENT_AWAITING_VERIFICATION], true)) {
                    return ['ok' => false, 'error' => 'Payment is not in a confirmable state.'];
                }

                if ((string) $locked['gateway'] !== $gatewayName) {
                    return ['ok' => false, 'error' => 'Gateway mismatch.'];
                }

                // Amount check — the gateway must report the same amount we recorded.
                $reported = isset($result['amount']) ? (string) $result['amount'] : '0.00';
                $expected = money_normalize((string) $locked['amount']);

                if (money_cmp($reported, $expected) !== 0) {
                    $this->audit->log(
                        'PAYMENT_AMOUNT_MISMATCH', 'payment', (int) $locked['id'],
                        'Gateway reported ' . $reported . ' but intent was ' . $expected,
                        newValues: ['reported' => $reported, 'expected' => $expected],
                        userType: 'system'
                    );
                    return ['ok' => false, 'error' => 'Amount mismatch — payment not credited.'];
                }

                // Credit wallet via the same transaction-scoped path used by
                // the Super Admin's manual verification (Step 11).
                (new WalletService())->creditTx([
                    'user_id'         => (int) $locked['user_id'],
                    'amount'          => $expected,
                    'category'        => WALLET_CAT_FUNDING,
                    'reference_type'  => 'payment',
                    'reference_id'    => (int) $locked['id'],
                    'description'     => 'Wallet funding (' . $locked['payment_ref'] . ') via ' . $gatewayName,
                    'created_by_type' => 'system',
                ]);

                // Mark payment successful
                Database::execute(
                    'UPDATE payments
                        SET status = "successful",
                            verified_by_type = "system",
                            verified_at = UTC_TIMESTAMP(),
                            gateway_payload = :payload
                      WHERE id = :id',
                    [
                        ':payload' => json_encode($result['payload'] ?? $result),
                        ':id'      => (int) $locked['id'],
                    ]
                );

                $this->audit->log(
                    'PAYMENT_VERIFIED', 'payment', (int) $locked['id'],
                    'Payment verified via ' . $gatewayName . ' for ' . $expected,
                    userType: 'system'
                );

                $this->notify->notifyClient(
                    (int) $locked['user_id'],
                    'Wallet funded',
                    'Your payment of ' . money($expected) . ' was successful.',
                    'wallet',
                    '/client/transactions'
                );

                return ['ok' => true, 'status' => 'successful', 'payment_ref' => $locked['payment_ref']];
            });
        } catch (Throwable $e) {
            Logger::error('Payment finalisation failed', [
                'error'     => $e->getMessage(),
                'reference' => $reference,
                'gateway'   => $gatewayName,
            ]);
            return ['ok' => false, 'error' => 'Could not finalise the payment.'];
        }
    }
}