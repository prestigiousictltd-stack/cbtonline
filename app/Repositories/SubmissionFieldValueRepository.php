<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SubmissionFieldValueRepository
{
    public function create(int $submissionId, array $value): int
    {
        Database::execute(
            'INSERT INTO submission_field_values
                (submission_id, field_id, field_key_snapshot, field_label_snapshot, value_text, value_file_id)
             VALUES (:sid, :fid, :fk, :fl, :vt, :vf)',
            [
                ':sid' => $submissionId,
                ':fid' => $value['field_id'],
                ':fk'  => $value['field_key'],
                ':fl'  => $value['field_label'],
                ':vt'  => $value['value_text'] ?? null,
                ':vf'  => $value['value_file_id'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function listForSubmission(int $submissionId): array
    {
        return Database::selectAll(
            'SELECT * FROM submission_field_values
             WHERE submission_id = :s
             ORDER BY id ASC',
            [':s' => $submissionId]
        );
    }

    /**
     * Find an existing field-value row for the given submission + field.
     */
    public function findForField(int $submissionId, int $fieldId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM submission_field_values
             WHERE submission_id = :s AND field_id = :f
             LIMIT 1',
            [':s' => $submissionId, ':f' => $fieldId]
        );
    }

    /**
     * Update an existing row or insert a new one.
     * Used when a client re-submits corrected details.
     */
    public function upsertForSubmission(int $submissionId, array $value): void
    {
        $existing = $this->findForField($submissionId, (int) $value['field_id']);

        if ($existing) {
            Database::execute(
                'UPDATE submission_field_values
                    SET value_text = :vt,
                        value_file_id = :vf,
                        field_key_snapshot = :fk,
                        field_label_snapshot = :fl
                  WHERE id = :id',
                [
                    ':vt' => $value['value_text'] ?? null,
                    ':vf' => $value['value_file_id'] ?? null,
                    ':fk' => $value['field_key'],
                    ':fl' => $value['field_label'],
                    ':id' => (int) $existing['id'],
                ]
            );
            return;
        }

        $this->create($submissionId, $value);
    }
}