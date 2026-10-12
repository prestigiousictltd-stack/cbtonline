<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class DocumentRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO documents
                (submission_id, feedback_id, payment_id, uploaded_by_type, uploaded_by_id,
                 document_type, original_name, stored_name, file_path, mime_type, file_size, checksum_sha256)
             VALUES
                (:sid, :fid, :pid, :ubt, :ubid, :dt, :on, :sn, :fp, :mt, :fs, :sha)',
            [
                ':sid'  => $d['submission_id'] ?? null,
                ':fid'  => $d['feedback_id']   ?? null,
                ':pid'  => $d['payment_id']    ?? null,
                ':ubt'  => $d['uploaded_by_type'],
                ':ubid' => $d['uploaded_by_id'] ?? null,
                ':dt'   => $d['document_type']  ?? 'other',
                ':on'   => $d['original_name'],
                ':sn'   => $d['stored_name'],
                ':fp'   => $d['file_path'],
                ':mt'   => $d['mime_type'],
                ':fs'   => $d['file_size'],
                ':sha'  => $d['checksum_sha256'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM documents WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function listForSubmission(int $submissionId): array
    {
        return Database::selectAll(
            'SELECT * FROM documents WHERE submission_id = :s ORDER BY id ASC',
            [':s' => $submissionId]
        );
    }

    public function delete(int $id): void
    {
        Database::execute('DELETE FROM documents WHERE id = :id', [':id' => $id]);
    }
}