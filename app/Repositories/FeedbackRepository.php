<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class FeedbackRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO feedback
                (submission_id, admin_id, feedback_type, remarks, status_snapshot, is_client_visible)
             VALUES (:sid, :aid, :type, :remarks, :status, :visible)',
            [
                ':sid'     => $d['submission_id'],
                ':aid'     => $d['admin_id'] ?? null,
                ':type'    => $d['feedback_type'] ?? 'completion',
                ':remarks' => $d['remarks'],
                ':status'  => $d['status_snapshot'],
                ':visible' => $d['is_client_visible'] ?? 1,
            ]
        );
        return Database::lastInsertId();
    }

    public function listForSubmission(int $submissionId): array
    {
        return Database::selectAll(
            'SELECT * FROM feedback WHERE submission_id = :s ORDER BY id ASC',
            [':s' => $submissionId]
        );
    }

    public function listVisibleForSubmission(int $submissionId): array
    {
        return Database::selectAll(
            'SELECT * FROM feedback
             WHERE submission_id = :s AND is_client_visible = 1
             ORDER BY id ASC',
            [':s' => $submissionId]
        );
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM feedback WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    /**
     * The most recent feedback of a given type for a submission.
     * Used to pre-fill the edit form on the completed-job page.
     */
    public function findLatestForSubmission(int $submissionId, string $type = 'completion'): ?array
    {
        return Database::selectOne(
            'SELECT * FROM feedback
             WHERE submission_id = :s AND feedback_type = :t
             ORDER BY id DESC LIMIT 1',
            [':s' => $submissionId, ':t' => $type]
        );
    }

    /**
     * Replace the remarks text on an existing feedback row.
     * Does not touch feedback_type, admin_id, or created_at.
     */
    public function updateRemarks(int $id, string $remarks): void
    {
        Database::execute(
            'UPDATE feedback SET remarks = :r WHERE id = :id',
            [':r' => $remarks, ':id' => $id]
        );
    }
}