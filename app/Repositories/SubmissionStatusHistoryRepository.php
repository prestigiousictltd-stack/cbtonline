<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SubmissionStatusHistoryRepository
{
    public function record(
        int $submissionId,
        ?string $fromStatus,
        string $toStatus,
        string $changedByType,
        ?int $changedById,
        ?string $reason = null,
        ?array $meta = null
    ): int {
        Database::execute(
            'INSERT INTO submission_status_history
                (submission_id, from_status, to_status, changed_by_type, changed_by_id, reason, meta)
             VALUES (:sid, :from, :to, :cbt, :cbid, :reason, :meta)',
            [
                ':sid'    => $submissionId,
                ':from'   => $fromStatus,
                ':to'     => $toStatus,
                ':cbt'    => $changedByType,
                ':cbid'   => $changedById,
                ':reason' => $reason,
                ':meta'   => $meta ? json_encode($meta) : null,
            ]
        );
        return Database::lastInsertId();
    }

    public function listForSubmission(int $submissionId): array
    {
        return Database::selectAll(
            'SELECT * FROM submission_status_history
             WHERE submission_id = :s
             ORDER BY created_at ASC, id ASC',
            [':s' => $submissionId]
        );
    }
}