<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Repositories\AdminCommissionRepository;
use App\Repositories\CommissionTransactionRepository;
use App\Repositories\WithdrawalRepository;
use RuntimeException;
use Throwable;

final class WithdrawalService
{
    public function __construct(
        private readonly WithdrawalRepository            $withdrawals = new WithdrawalRepository(),
        private readonly AdminCommissionRepository       $admins      = new AdminCommissionRepository(),
        private readonly CommissionTransactionRepository $ledger      = new CommissionTransactionRepository(),
        private readonly NotificationService             $notify      = new NotificationService(),
        private readonly AuditService                    $audit       = new AuditService(),
    ) {}

    // ----------------------------------------------------------------
    // ADMIN: REQUEST
    // ----------------------------------------------------------------

    /**
     * @return array{ok:bool,withdrawal_ref?:string,error?:string,errors?:array<string,string>,available?:string}
     */
    public function request(int $adminId, array $input): array
    {
        $bank       = trim((string) ($input['bank_name']      ?? ''));
        $accountName= trim((string) ($input['account_name']   ?? ''));
        $accountNo  = trim((string) ($input['account_number'] ?? ''));
        $amountRaw  = trim((string) ($input['amount']         ?? ''));
        $note       = trim((string) ($input['admin_note']     ?? ''));

        $errors = [];
        if ($bank === '' || mb_strlen($bank) < 2)                 $errors['bank_name']      = 'Bank name is required.';
        if ($accountName === '' || mb_strlen($accountName) < 3)   $errors['account_name']   = 'Account name is required.';
        if ($accountNo === '' || !preg_match('/^[0-9]{6,20}$/', $accountNo)) $errors['account_number'] = 'Enter a valid account number (6–20 digits).';

        try {
            $amount = money_normalize($amountRaw);
        } catch (Throwable) {
            $errors['amount'] = 'Enter a valid amount.';
            $amount = '0.00';
        }

        $min = money_normalize((string) Settings::get('minimum_withdrawal', '5000.00'));
        $max = money_normalize((string) Settings::get('maximum_withdrawal', '500000.00'));

        if (money_cmp($amount, $min) < 0) {
            $errors['amount'] = 'Minimum withdrawal is ' . money($min) . '.';
        }
        if (money_cmp($amount, $max) > 0) {
            $errors['amount'] = 'Maximum withdrawal is ' . money($max) . '.';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        try {
            return Database::transaction(function () use ($adminId, $amount, $bank, $accountName, $accountNo, $note): array {
                $admin = $this->admins->lockForUpdate($adminId);
                if (!$admin) return ['ok' => false, 'error' => 'ADMIN_NOT_FOUND'];
                if ($admin['status'] !== STATUS_ACTIVE) return ['ok' => false, 'error' => 'ADMIN_INACTIVE'];

                $balance  = money_normalize((string) $admin['commission_balance']);
                $reserved = money_normalize((string) $admin['commission_reserved']);

                if (money_cmp($balance, $reserved) < 0) {
                    // Should be impossible by construction
                    throw new RuntimeException('Reserved balance exceeds total balance — data integrity issue.');
                }
                $available = money_sub($balance, $reserved);

                if (money_cmp($available, $amount) < 0) {
                    return [
                        'ok'        => false,
                        'error'     => 'INSUFFICIENT_AVAILABLE',
                        'available' => $available,
                    ];
                }

                // Reserve the funds
                $newReserved = money_add($reserved, $amount);
                $this->admins->setReserved($adminId, $newReserved);

                // Create the withdrawal record
                $ref = next_reference('WDR');
                $withdrawalId = $this->withdrawals->create([
                    'withdrawal_ref' => $ref,
                    'admin_id'       => $adminId,
                    'amount'         => $amount,
                    'bank_name'      => $bank,
                    'account_name'   => $accountName,
                    'account_number' => $accountNo,
                    'status'         => 'pending',
                    'admin_note'     => $note !== '' ? $note : null,
                ]);

                // Ledger: record the request. We use balance_after = balance (unchanged),
                // because commission_balance is only actually reduced when paid.
                // The reserved amount is a separate column on admins.
                $this->ledger->create([
                    'transaction_ref' => next_reference('CTX'),
                    'admin_id'        => $adminId,
                    'withdrawal_id'   => $withdrawalId,
                    'type'            => 'withdrawal_request',
                    'direction'       => 'debit',
                    'amount'          => $amount,
                    'balance_before'  => $balance,
                    'balance_after'   => $balance, // reserved, not yet deducted
                    'status'          => 'pending',
                    'description'     => 'Withdrawal request ' . $ref . ' reserved',
                    'created_by_type' => 'admin',
                    'created_by_id'   => $adminId,
                ]);

                $this->audit->log(
                    'WITHDRAWAL_REQUESTED', 'withdrawal', $withdrawalId,
                    'Admin requested withdrawal of ' . $amount . ' (' . $ref . ')',
                    newValues: [
                        'amount'    => $amount,
                        'available' => $available,
                        'reserved'  => $newReserved,
                    ],
                    userType: 'admin', userId: $adminId
                );

                $this->notify->notifyAllSuperAdmins(
                    'New withdrawal request',
                    'Admin #' . $adminId . ' requested ' . money($amount) . ' (' . $ref . ').',
                    'withdrawal',
                    '/superadmin/withdrawals'
                );

                return ['ok' => true, 'withdrawal_ref' => $ref];
            });
        } catch (Throwable $e) {
            Logger::error('Withdrawal request failed', ['error' => $e->getMessage(), 'admin_id' => $adminId]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ----------------------------------------------------------------
    // ADMIN: CANCEL PENDING REQUEST
    // ----------------------------------------------------------------

    public function cancelByAdmin(int $withdrawalId, int $adminId, ?string $reason = null): array
    {
        try {
            return Database::transaction(function () use ($withdrawalId, $adminId, $reason): array {
                $w = $this->withdrawals->lockForUpdate($withdrawalId);
                if (!$w) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if ((int) $w['admin_id'] !== $adminId) return ['ok' => false, 'error' => 'NOT_YOURS'];
                if ($w['status'] !== 'pending') return ['ok' => false, 'error' => 'NOT_CANCELLABLE'];

                $amount = money_normalize((string) $w['amount']);

                // Release reserved
                $newReserved = $this->admins->releaseReserved($adminId, $amount);

                // Mark withdrawal rejected (cancel)
                $this->withdrawals->markCancelled($withdrawalId, $reason);

                // Ledger: reversal credit
                $balance = $this->admins->currentBalance($adminId);
                $this->ledger->create([
                    'transaction_ref' => next_reference('CTX'),
                    'admin_id'        => $adminId,
                    'withdrawal_id'   => $withdrawalId,
                    'type'            => 'withdrawal_reversal',
                    'direction'       => 'credit',
                    'amount'          => $amount,
                    'balance_before'  => $balance,
                    'balance_after'   => $balance,
                    'status'          => 'successful',
                    'description'     => 'Withdrawal ' . $w['withdrawal_ref'] . ' cancelled by admin',
                    'created_by_type' => 'admin',
                    'created_by_id'   => $adminId,
                ]);

                $this->audit->log(
                    'WITHDRAWAL_CANCELLED', 'withdrawal', $withdrawalId,
                    'Admin cancelled withdrawal ' . $w['withdrawal_ref'],
                    oldValues: ['status' => 'pending'],
                    newValues: ['status' => 'rejected', 'reserved' => $newReserved],
                    userType: 'admin', userId: $adminId
                );

                $this->notify->notifyAllSuperAdmins(
                    'Withdrawal cancelled',
                    'Admin cancelled withdrawal ' . $w['withdrawal_ref'] . '.',
                    'withdrawal',
                    '/superadmin/withdrawals'
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Withdrawal cancel failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ----------------------------------------------------------------
    // SUPER ADMIN: APPROVE
    // ----------------------------------------------------------------

    public function approve(int $withdrawalId, int $superAdminId, ?string $note = null): array
    {
        try {
            return Database::transaction(function () use ($withdrawalId, $superAdminId, $note): array {
                $w = $this->withdrawals->lockForUpdate($withdrawalId);
                if (!$w) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if ($w['status'] !== 'pending') return ['ok' => false, 'error' => 'NOT_PENDING'];

                $this->withdrawals->markApproved($withdrawalId, $superAdminId, $note);

                $this->audit->log(
                    'WITHDRAWAL_APPROVED', 'withdrawal', $withdrawalId,
                    'Withdrawal ' . $w['withdrawal_ref'] . ' approved',
                    oldValues: ['status' => 'pending'],
                    newValues: ['status' => 'approved', 'note' => $note],
                    userType: 'super_admin', userId: $superAdminId
                );

                $this->notify->notifyAdmin(
                    (int) $w['admin_id'],
                    'Withdrawal approved',
                    'Your withdrawal ' . $w['withdrawal_ref'] . ' has been approved. Payment follows shortly.',
                    'withdrawal',
                    '/admin/withdrawals'
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Withdrawal approve failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ----------------------------------------------------------------
    // SUPER ADMIN: REJECT
    // ----------------------------------------------------------------

    public function reject(int $withdrawalId, int $superAdminId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'REASON_REQUIRED'];
        }

        try {
            return Database::transaction(function () use ($withdrawalId, $superAdminId, $reason): array {
                $w = $this->withdrawals->lockForUpdate($withdrawalId);
                if (!$w) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if (!in_array($w['status'], ['pending', 'approved'], true)) {
                    return ['ok' => false, 'error' => 'NOT_REJECTABLE'];
                }

                $adminId = (int) $w['admin_id'];
                $amount  = money_normalize((string) $w['amount']);

                // Release reserved
                $newReserved = $this->admins->releaseReserved($adminId, $amount);

                $this->withdrawals->markRejected($withdrawalId, $superAdminId, $reason);

                // Find the request ledger row and mark it reversed
                $requestRows = $this->ledger->listForWithdrawal($withdrawalId);
                foreach ($requestRows as $row) {
                    if ($row['type'] === 'withdrawal_request' && $row['status'] === 'pending') {
                        $this->ledger->updateStatus((int) $row['id'], 'reversed');
                        break;
                    }
                }

                // Ledger: reversal credit
                $balance = $this->admins->currentBalance($adminId);
                $this->ledger->create([
                    'transaction_ref' => next_reference('CTX'),
                    'admin_id'        => $adminId,
                    'withdrawal_id'   => $withdrawalId,
                    'type'            => 'withdrawal_reversal',
                    'direction'       => 'credit',
                    'amount'          => $amount,
                    'balance_before'  => $balance,
                    'balance_after'   => $balance,
                    'status'          => 'successful',
                    'description'     => 'Withdrawal ' . $w['withdrawal_ref'] . ' rejected: ' . $reason,
                    'created_by_type' => 'super_admin',
                    'created_by_id'   => $superAdminId,
                ]);

                $this->audit->log(
                    'WITHDRAWAL_REJECTED', 'withdrawal', $withdrawalId,
                    'Withdrawal ' . $w['withdrawal_ref'] . ' rejected: ' . $reason,
                    oldValues: ['status' => $w['status']],
                    newValues: ['status' => 'rejected', 'reserved' => $newReserved, 'reason' => $reason],
                    userType: 'super_admin', userId: $superAdminId
                );

                $this->notify->notifyAdmin(
                    $adminId,
                    'Withdrawal rejected',
                    'Your withdrawal ' . $w['withdrawal_ref'] . ' was rejected: ' . $reason,
                    'withdrawal',
                    '/admin/withdrawals'
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Withdrawal reject failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ----------------------------------------------------------------
    // SUPER ADMIN: MARK PAID
    // ----------------------------------------------------------------

    public function markPaid(int $withdrawalId, int $superAdminId, string $paymentReference, ?string $note = null): array
    {
        $paymentReference = trim($paymentReference);
        if ($paymentReference === '') {
            return ['ok' => false, 'error' => 'PAYMENT_REF_REQUIRED'];
        }

        try {
            return Database::transaction(function () use ($withdrawalId, $superAdminId, $paymentReference, $note): array {
                $w = $this->withdrawals->lockForUpdate($withdrawalId);
                if (!$w) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if ($w['status'] !== 'approved') return ['ok' => false, 'error' => 'NOT_APPROVED'];

                $adminId = (int) $w['admin_id'];
                $amount  = money_normalize((string) $w['amount']);

                // Deduct from balance and reserved simultaneously
                $settled = $this->admins->settlePaid($adminId, $amount);

                $this->withdrawals->markPaid($withdrawalId, $superAdminId, $paymentReference, $note);

                // Find the pending request ledger row and finalise it
                $requestRows = $this->ledger->listForWithdrawal($withdrawalId);
                foreach ($requestRows as $row) {
                    if ($row['type'] === 'withdrawal_request' && $row['status'] === 'pending') {
                        $this->ledger->updateBalanceSnapshot(
                            (int) $row['id'],
                            $settled['balance_before'],
                            $settled['balance_after']
                        );
                        $this->ledger->updateStatus((int) $row['id'], 'successful');
                        break;
                    }
                }

                $this->audit->log(
                    'WITHDRAWAL_PAID', 'withdrawal', $withdrawalId,
                    'Withdrawal ' . $w['withdrawal_ref'] . ' paid, ref ' . $paymentReference,
                    oldValues: ['status' => 'approved'],
                    newValues: [
                        'status'         => 'paid',
                        'payment_reference' => $paymentReference,
                        'balance_after'  => $settled['balance_after'],
                        'reserved_after' => $settled['reserved_after'],
                    ],
                    userType: 'super_admin', userId: $superAdminId
                );

                $this->notify->notifyAdmin(
                    $adminId,
                    'Withdrawal paid',
                    'Your withdrawal ' . $w['withdrawal_ref'] . ' has been paid. Reference: ' . $paymentReference . '.',
                    'withdrawal',
                    '/admin/withdrawals'
                );

                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Withdrawal markPaid failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }
}