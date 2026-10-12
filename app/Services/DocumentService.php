<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\DocumentRepository;
use RuntimeException;

final class DocumentService
{
    public function __construct(
        private readonly DocumentRepository $documents = new DocumentRepository()
    ) {}

    /**
     * Validate and persist an uploaded file to storage, returning its metadata.
     *
     * @param array  $file      $_FILES entry
     * @param string $subfolder 'proofs' or 'documents'
     * @return array{original_name:string,stored_name:string,file_path:string,mime_type:string,file_size:int,checksum_sha256:string}
     */
    public function storeUpload(array $file, string $subfolder): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Invalid upload.');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) $file['error']));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > UPLOAD_MAX_BYTES) {
            throw new RuntimeException('File exceeds the maximum allowed size ('
                . round(UPLOAD_MAX_BYTES / 1024 / 1024, 1) . ' MB).');
        }

        $original = (string) ($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
            throw new RuntimeException('File type not allowed. Allowed: ' . implode(', ', UPLOAD_ALLOWED_EXT));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload verification failed.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
            throw new RuntimeException('File MIME type not allowed.');
        }

        $stored   = bin2hex(random_bytes(16)) . '.' . $ext;
        $relative = trim($subfolder, '/') . '/' . date('Y') . '/' . date('m');
        $dir      = APP_ROOT . '/storage/uploads/' . $relative;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $target = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }
        @chmod($target, 0644);

        return [
            'original_name'   => mb_substr($original, 0, 255),
            'stored_name'     => $stored,
            'file_path'       => $relative . '/' . $stored,
            'mime_type'       => $mime,
            'file_size'       => $size,
            'checksum_sha256' => hash_file('sha256', $target) ?: '',
        ];
    }

    /** Insert a documents row for an already-stored file. */
    public function linkSubmission(int $submissionId, ?int $feedbackId, array $meta, array $options = []): int
    {
        return $this->documents->create([
            'submission_id'    => $submissionId,
            'feedback_id'      => $feedbackId,
            'uploaded_by_type' => $options['uploaded_by_type'] ?? 'system',
            'uploaded_by_id'   => $options['uploaded_by_id']   ?? null,
            'document_type'    => $options['document_type']    ?? 'completion_result',
            'original_name'    => $meta['original_name'],
            'stored_name'      => $meta['stored_name'],
            'file_path'        => $meta['file_path'],
            'mime_type'        => $meta['mime_type'],
            'file_size'        => $meta['file_size'],
            'checksum_sha256'  => $meta['checksum_sha256'] ?? null,
        ]);
    }

    public function listForSubmission(int $submissionId): array
    {
        return $this->documents->listForSubmission($submissionId);
    }

    public function absolutePath(string $relativePath): string
    {
        $base = realpath(APP_ROOT . '/storage/uploads');
        $full = realpath(APP_ROOT . '/storage/uploads/' . ltrim($relativePath, '/'));
        if ($base === false || $full === false || !str_starts_with($full, $base)) {
            throw new RuntimeException('Invalid file path.');
        }
        return $full;
    }

    public function exists(string $relativePath): bool
    {
        try { return is_file($this->absolutePath($relativePath)); }
        catch (\Throwable) { return false; }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
            UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server storage error.',
            UPLOAD_ERR_EXTENSION                      => 'Upload blocked by server.',
            default                                   => 'Upload failed.',
        };
    }
}