<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;

final class AuditExportService
{
    /** Hard cap on rows streamed to CSV to avoid unbounded memory / disk. */
    public const MAX_ROWS = 50000;

    public function __construct(private readonly AuditLogRepository $repo = new AuditLogRepository()) {}

    /**
     * Stream audit logs matching the same filters used on the viewer.
     * Returns the number of rows written.
     */
    public function streamCsv(array $filters, string $filename, int $maxRows = self::MAX_ROWS): int
    {
        $maxRows = max(1, min(self::MAX_ROWS, $maxRows));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        if ($out === false) return 0;

        // UTF-8 BOM for Excel
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'id', 'created_at_utc', 'user_type', 'user_id', 'action',
            'entity_type', 'entity_id', 'description',
            'old_values', 'new_values', 'ip_address', 'user_agent',
        ]);

        $written = 0;
        $page    = 1;
        $perPage = 500;

        while ($written < $maxRows) {
            $chunk = $this->repo->paginate($filters, $page, $perPage);
            if (empty($chunk['rows'])) break;

            foreach ($chunk['rows'] as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['created_at'],
                    $r['user_type'],
                    $r['user_id'],
                    $r['action'],
                    $r['entity_type'],
                    $r['entity_id'],
                    $r['description'],
                    $r['old_values'],
                    $r['new_values'],
                    $r['ip_address'],
                    $r['user_agent'],
                ]);
                $written++;
                if ($written >= $maxRows) break 2;
            }
            if ($chunk['page'] >= $chunk['pages']) break;
            $page++;
        }

        fclose($out);
        return $written;
    }
}