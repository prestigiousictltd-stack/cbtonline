<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PaymentProofRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO payment_proofs
                (payment_id, user_id, original_name, stored_name, file_path,
                 mime_type, file_size, checksum_sha256)
             VALUES
                (:pid, :uid, :on, :sn, :fp, :mt, :fs, :sha)',
            [
                ':pid' => $d['payment_id'],
                ':uid' => $d['user_id'],
                ':on'  => $d['original_name'],
                ':sn'  => $d['stored_name'],
                ':fp'  => $d['file_path'],
                ':mt'  => $d['mime_type'],
                ':fs'  => $d['file_size'],
                ':sha' => $d['checksum_sha256'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM payment_proofs WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function listForPayment(int $paymentId): array
    {
        return Database::selectAll(
            'SELECT * FROM payment_proofs WHERE payment_id = :p ORDER BY id ASC',
            [':p' => $paymentId]
        );
    }

    public function listForUser(int $userId): array
    {
        return Database::selectAll(
            'SELECT * FROM payment_proofs WHERE user_id = :u ORDER BY id DESC',
            [':u' => $userId]
        );
    }

    public function delete(int $id): void
    {
        Database::execute('DELETE FROM payment_proofs WHERE id = :id', [':id' => $id]);
    }
}