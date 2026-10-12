<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\AuditLogRepository;
use App\Services\AuditExportService;
use App\Services\AuditService;
use App\Services\ReportService;

$superAdminUser = Auth::requireSuperAdmin();

$type   = (string) ($_GET['type'] ?? '');
$from   = (string) ($_GET['from'] ?? '') ?: null;
$to     = (string) ($_GET['to']   ?? '') ?: null;

$allowed = [
    'revenue_by_service' => 'revenue_by_service',
    'revenue_by_day'     => 'revenue_by_day',
    'clients'            => 'clients',
    'admins'             => 'admins',
    'services'           => 'services',
    'audit'              => 'audit',
];

if (!isset($allowed[$type])) {
    http_response_code(400);
    exit('Invalid export type.');
}

$stamp    = date('Ymd_His');
$audit    = new AuditService();
$report   = new ReportService();

// Log the export *before* streaming — this way a failed stream still leaves a trail.
$audit->log(
    'EXPORT_GENERATED', 'export', null,
    'CSV export requested: ' . $type,
    newValues: ['type' => $type, 'from' => $from, 'to' => $to],
    userType: 'super_admin', userId: (int) $superAdminUser['id']
);

$filename = "servicehub_{$type}_{$stamp}.csv";

// Audit export streams in chunks (potentially large)
if ($type === 'audit') {
    $filters = [
        'q'           => trim((string) ($_GET['q'] ?? '')),
        'user_type'   => trim((string) ($_GET['user_type'] ?? '')),
        'action'      => trim((string) ($_GET['action'] ?? '')),
        'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
        'entity_id'   => (int)    ($_GET['entity_id'] ?? 0),
        'from'        => trim((string) ($_GET['from'] ?? '')),
        'to'          => trim((string) ($_GET['to'] ?? '')),
    ];
    (new AuditExportService())->streamCsv($filters, $filename);
    exit;
}

// Simple reports — build in memory then stream
$rows = match ($type) {
    'revenue_by_service' => $report->revenueByService($from, $to),
    'revenue_by_day'     => $report->revenueByDay($from, $to),
    'clients'            => $report->clientReport($from, $to),
    'admins'             => $report->adminReport($from, $to),
    'services'           => $report->serviceUsageReport($from, $to),
};

stream_csv($filename, $rows);
exit;

/**
 * @param array<int,array> $rows
 */
function stream_csv(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    if ($out === false) return;

    // BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");

    if (empty($rows)) {
        fputcsv($out, ['No data for the selected filters.']);
        fclose($out);
        return;
    }

    // Header row from the first record's keys
    $headers = array_keys($rows[0]);
    fputcsv($out, $headers);

    foreach ($rows as $row) {
        // Preserve column order even if a row is missing a key
        $line = [];
        foreach ($headers as $h) {
            $line[] = $row[$h] ?? '';
        }
        fputcsv($out, $line);
    }

    fclose($out);
}