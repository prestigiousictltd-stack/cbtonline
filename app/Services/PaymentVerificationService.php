<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletRepository;
use Throwable;

final class PaymentVerificationService
{
    public function __construct(
        private readonly PaymentRepository    $payments = new PaymentRepository(),
        private readonly WalletRepository     $wallets  = new WalletRepository(),
        private readonly WalletService        $walletSvc = new WalletService(),
        private readonly NotificationService  $notify = new NotificationService(),
        private readonly AuditService         $audit  = new AuditService(),
    ) {}

    /**
     * MANUAL approval — used by Super Admins for bank-transfer proofs.
     */
    public function approve(int $paymentId, string $verifierType, int $verifierId): array
    {
        try {
            return Database::transaction(function () use ($paymentId, $verifierType, $verifierId): array {
                $p = $this->payments->lockForUpdate($paymentId);
                if (!$p) return ['ok' => false, 'error' => 'NOT_FOUND'];

                if ($p['status'] === PAYMENT_SUCCESSFUL) return ['ok' => true, 'already' => true];
                if (!in_array($p['status'], [PAYMENT_PENDING, PAYMENT_AWAITING_VERIFICATION], true)) {
                    return ['ok' => false, 'error' => 'NOT_VERIFIABLE'];
                }

                $amount = money_normalize((string) $p['amount']);

                $this->walletSvc->creditTx([
                    'user_id'         => (int) $p['user_id'],
                    'amount'          => $amount,
                    'category'        => WALLET_CAT_FUNDING,
                    'reference_type'  => 'payment',
                    'reference_id'    => $paymentId,
                    'description'     => 'Wallet funding (' . $p['payment_ref'] . ')',
                    'created_by_type' => $verifierType === 'super_admin' ? 'super_admin' : 'admin',
                    'created_by_id'   => $verifierId,
                ]);

                $this->payments->markVerified($paymentId, $verifierType, $verifierId);

                $this->audit->log(
                    'PAYMENT_VERIFIED', 'payment', $paymentId,
                    'Payment ' . $p['payment_ref'] . ' verified for ' . $amount,
                    userType: $verifierType, userId: $verifierId
                );

                $this->notify->notifyClient(
                    (int) $p['user_id'],
                    'Wallet funded',
                    'Your payment of ' . money($amount) . ' has been verified and credited.',
                    'wallet',
                    '/client/transactions'
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Payment approve failed', ['error' => $e->getMessage(), 'payment_id' => $paymentId]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    public function reject(int $paymentId, string $verifierType, int $verifierId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'REASON_REQUIRED'];

        try {
            return Database::transaction(function () use ($paymentId, $verifierType, $verifierId, $reason): array {
                $p = $this->payments->lockForUpdate($paymentId);
                if (!$p) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if (!in_array($p['status'], [PAYMENT_PENDING, PAYMENT_AWAITING_VERIFICATION], true)) {
                    return ['ok' => false, 'error' => 'NOT_VERIFIABLE'];
                }

                $this->payments->markRejected($paymentId, $verifierType, $verifierId, $reason);

                $this->audit->log(
                    'PAYMENT_REJECTED', 'payment', $paymentId,
                    'Payment ' . $p['payment_ref'] . ' rejected: ' . $reason,
                    userType: $verifierType, userId: $verifierId
                );

                $this->notify->notifyClient(
                    (int) $p['user_id'],
                    'Payment rejected',
                    'Payment ' . $p['payment_ref'] . ' was rejected: ' . $reason,
                    'wallet',
                    '/client/transactions'
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Payment reject failed', ['error' => $e->getMessage(), 'payment_id' => $paymentId]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    /**
     * ONLINE verification — used by the return-URL handler.
     */
    public function verifyOnline(string $gatewayName, string $reference): array
    {
        return (new OnlinePaymentService())->confirmReturn($gatewayName, $reference);
    }
}