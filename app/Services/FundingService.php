<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\DocumentRepository;
use App\Repositories\PaymentProofRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletRepository;
use RuntimeException;
use Throwable;

final class FundingService
{
    public function __construct(
        private readonly PaymentRepository       $payments = new PaymentRepository(),
        private readonly PaymentProofRepository  $proofs   = new PaymentProofRepository(),
        private readonly WalletRepository        $wallets  = new WalletRepository(),
        private readonly DocumentService         $docs     = new DocumentService(),
        private readonly NotificationService     $notify   = new NotificationService(),
        private readonly AuditService            $audit    = new AuditService(),
    ) {}

    /**
     * Bank transfer funding: client uploads proof, payment sits awaiting verification.
     * Wallet is NEVER credited here — a Super Admin must approve it later.
     *
     * @return array{ok:bool,payment_ref?:string,error?:string}
     */
    public function submitBankTransfer(int $userId, string $amountInput, array $proofFile): array
    {
        try {
            $amount = money_normalize($amountInput);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Invalid amount.'];
        }

        if (money_cmp($amount, '100.00') < 0) {
            return ['ok' => false, 'error' => 'Minimum funding amount is ₦100.00.'];
        }
        if (money_cmp($amount, '10000000.00') > 0) {
            return ['ok' => false, 'error' => 'Maximum single funding amount is ₦10,000,000.00.'];
        }

        $wallet = $this->wallets->findByUserId($userId);
        if (!$wallet) return ['ok' => false, 'error' => 'Wallet not found.'];
        if ($wallet['status'] !== 'active') return ['ok' => false, 'error' => 'Your wallet is not active.'];

        // Store proof file first — if this fails, no DB row is created.
        try {
            $meta = $this->docs->storeUpload($proofFile, 'proofs');
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $paymentId = Database::transaction(function () use ($userId, $wallet, $amount, $meta): int {
                $paymentId = $this->payments->create([
                    'payment_ref' => next_reference('PAY'),
                    'user_id'     => $userId,
                    'wallet_id'   => (int) $wallet['id'],
                    'gateway'     => 'bank_transfer',
                    'method'      => 'bank_transfer',
                    'amount'      => $amount,
                    'fee'         => '0.00',
                    'net_amount'  => $amount,
                    'status'      => PAYMENT_AWAITING_VERIFICATION,
                ]);

                $this->proofs->create([
                    'payment_id'      => $paymentId,
                    'user_id'         => $userId,
                    'original_name'   => $meta['original_name'],
                    'stored_name'     => $meta['stored_name'],
                    'file_path'       => $meta['file_path'],
                    'mime_type'       => $meta['mime_type'],
                    'file_size'       => $meta['file_size'],
                    'checksum_sha256' => $meta['checksum_sha256'],
                ]);

                (new DocumentRepository())->create([
                    'payment_id'        => $paymentId,
                    'uploaded_by_type'  => 'client',
                    'uploaded_by_id'    => $userId,
                    'document_type'     => 'payment_proof',
                    'original_name'     => $meta['original_name'],
                    'stored_name'       => 'doc_' . $meta['stored_name'],
                    'file_path'         => $meta['file_path'],
                    'mime_type'         => $meta['mime_type'],
                    'file_size'         => $meta['file_size'],
                    'checksum_sha256'   => $meta['checksum_sha256'],
                ]);

                $this->audit->log(
                    'PAYMENT_PROOF_SUBMITTED', 'payment', $paymentId,
                    'Bank transfer proof uploaded for ' . $amount,
                    userType: 'client', userId: $userId
                );

                return $paymentId;
            });

            $payment = $this->payments->findById($paymentId);

            $this->notify->notifyClient(
                $userId,
                'Funding submitted',
                'Your bank transfer of ' . money($amount) . ' is awaiting verification. Reference: ' . $payment['payment_ref'],
                'wallet',
                '/client/transactions'
            );
            $this->notify->notifyAllSuperAdmins(
                'New bank transfer awaiting verification',
                'Payment ' . $payment['payment_ref'] . ' for ' . money($amount) . ' requires review.',
                'wallet',
                '/superadmin/payments'
            );

            return ['ok' => true, 'payment_ref' => $payment['payment_ref']];
        } catch (Throwable $e) {
            Logger::error('Bank transfer submission failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Could not submit funding request. Please try again.'];
        }
    }

    public function paginateUserPayments(int $userId, int $page = 1): array
    {
        return $this->payments->paginateForUser($userId, $page, 20);
    }

    public function getPaymentByRef(string $ref, int $userId): ?array
    {
        $p = $this->payments->findByRef($ref);
        if (!$p || (int) $p['user_id'] !== $userId) return null;
        return $p;
    }
}