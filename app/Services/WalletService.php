<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use RuntimeException;

final class WalletService
{
    public function __construct(
        private readonly WalletRepository            $wallets = new WalletRepository(),
        private readonly WalletTransactionRepository $ledger  = new WalletTransactionRepository(),
        private readonly AuditService                $audit   = new AuditService(),
    ) {}

    /** Self-contained: opens its own transaction. */
    public function credit(array $d): string
    {
        return Database::transaction(fn() => $this->creditTx($d));
    }

    /** Self-contained: opens its own transaction. */
    public function debit(array $d): string
    {
        return Database::transaction(fn() => $this->debitTx($d));
    }

    /**
     * Transaction-scoped credit. Caller MUST already be inside a transaction.
     */
    public function creditTx(array $d): string
    {
        $amount = money_normalize($d['amount']);
        if (money_cmp($amount, '0.00') <= 0) {
            throw new RuntimeException('Credit amount must be positive.');
        }

        $wallet = $this->wallets->findByUserId((int) $d['user_id']);
        if (!$wallet) throw new RuntimeException('Wallet not found.');

        $locked = $this->wallets->lockForUpdate((int) $wallet['id']);
        if (!$locked) throw new RuntimeException('Wallet lock failed.');
        if ($locked['status'] !== 'active') throw new RuntimeException('Wallet is not active.');

        $before = money_normalize($locked['balance']);
        $after  = money_add($before, $amount);

        $this->wallets->setBalance((int) $locked['id'], $after);

        $this->ledger->create([
            'transaction_ref' => next_reference('WTX'),
            'wallet_id'       => (int) $locked['id'],
            'user_id'         => (int) $d['user_id'],
            'type'            => WALLET_CREDIT,
            'category'        => $d['category'],
            'amount'          => $amount,
            'balance_before'  => $before,
            'balance_after'   => $after,
            'status'          => 'successful',
            'reference_type'  => $d['reference_type'] ?? null,
            'reference_id'    => $d['reference_id']   ?? null,
            'description'     => $d['description']    ?? null,
            'created_by_type' => $d['created_by_type'] ?? 'system',
            'created_by_id'   => $d['created_by_id']   ?? null,
        ]);

        $this->audit->log(
            'WALLET_CREDIT', 'wallet', (int) $locked['id'],
            'Wallet credited ' . $amount,
            oldValues: ['balance' => $before],
            newValues: ['balance' => $after, 'category' => $d['category']]
        );

        return $after;
    }

    /**
     * Transaction-scoped debit. Caller MUST already be inside a transaction.
     * Throws RuntimeException('INSUFFICIENT_FUNDS') if balance < amount.
     */
    public function debitTx(array $d): string
    {
        $amount = money_normalize($d['amount']);
        if (money_cmp($amount, '0.00') <= 0) {
            throw new RuntimeException('Debit amount must be positive.');
        }

        $wallet = $this->wallets->findByUserId((int) $d['user_id']);
        if (!$wallet) throw new RuntimeException('Wallet not found.');

        $locked = $this->wallets->lockForUpdate((int) $wallet['id']);
        if (!$locked) throw new RuntimeException('Wallet lock failed.');
        if ($locked['status'] !== 'active') throw new RuntimeException('Wallet is not active.');

        $before = money_normalize($locked['balance']);
        if (money_cmp($before, $amount) < 0) {
            throw new RuntimeException('INSUFFICIENT_FUNDS');
        }

        $after = money_sub($before, $amount);
        $this->wallets->setBalance((int) $locked['id'], $after);

        $this->ledger->create([
            'transaction_ref' => next_reference('WTX'),
            'wallet_id'       => (int) $locked['id'],
            'user_id'         => (int) $d['user_id'],
            'type'            => WALLET_DEBIT,
            'category'        => $d['category'],
            'amount'          => $amount,
            'balance_before'  => $before,
            'balance_after'   => $after,
            'status'          => 'successful',
            'reference_type'  => $d['reference_type'] ?? null,
            'reference_id'    => $d['reference_id']   ?? null,
            'description'     => $d['description']    ?? null,
            'created_by_type' => $d['created_by_type'] ?? 'system',
            'created_by_id'   => $d['created_by_id']   ?? null,
        ]);

        $this->audit->log(
            'WALLET_DEBIT', 'wallet', (int) $locked['id'],
            'Wallet debited ' . $amount,
            oldValues: ['balance' => $before],
            newValues: ['balance' => $after, 'category' => $d['category']]
        );

        return $after;
    }
}