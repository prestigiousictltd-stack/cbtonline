<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\DocumentRepository;
use App\Repositories\PaymentProofRepository;
use App\Services\AuditService;
use App\Services\DocumentService;

// Guard: must be signed in as some role
$client     = Auth::clientCheck()     ? Auth::clientUser()      : null;
$admin      = Auth::adminCheck()      ? Auth::adminUser()       : null;
$superAdmin = Auth::superAdminCheck() ? Auth::superAdminUser()  : null;

if (!$client && !$admin && !$superAdmin) {
    flash('warning', 'Please sign in to download files.');
    redirect('/login');
}

$id   = (int) ($_GET['id'] ?? 0);
$type = (string) ($_GET['t'] ?? 'doc'); // 'doc' | 'proof'

if ($id <= 0) {
    http_response_code(400); exit('Missing file id.');
}

$docSvc = new DocumentService();
$file = null;

if ($type === 'proof') {
    $proof = (new PaymentProofRepository())->findById($id);
    if (!$proof) { http_response_code(404); exit('File not found.'); }

    $isOwner = $client && (int) $proof['user_id'] === (int) $client['id'];
    $isStaff = $admin || $superAdmin;

    if (!$isOwner && !$isStaff) {
        (new AuditService())->log('UNAUTHORIZED_DOWNLOAD_ATTEMPT', 'payment_proof', $id,
            'Unauthorized proof download attempt', userType: 'guest');
        http_response_code(403); exit('Forbidden.');
    }

    $file = [
        'original_name' => $proof['original_name'],
        'file_path'     => $proof['file_path'],
        'mime_type'     => $proof['mime_type'],
    ];

} else {
    $doc = (new DocumentRepository())->findById($id);
    if (!$doc) { http_response_code(404); exit('File not found.'); }

    $allowed = false;
    if ($superAdmin) {
        $allowed = true;
    } elseif ($admin) {
        $allowed = true;
    } elseif ($client) {
        if (!empty($doc['submission_id'])) {
            $row = App\Core\Database::selectOne(
                'SELECT user_id FROM submissions WHERE id = :id',
                [':id' => (int) $doc['submission_id']]
            );
            $allowed = $row && (int) $row['user_id'] === (int) $client['id'];
        } elseif (!empty($doc['payment_id'])) {
            $row = App\Core\Database::selectOne(
                'SELECT user_id FROM payments WHERE id = :id',
                [':id' => (int) $doc['payment_id']]
            );
            $allowed = $row && (int) $row['user_id'] === (int) $client['id'];
        }
    }

    if (!$allowed) {
        (new AuditService())->log('UNAUTHORIZED_DOWNLOAD_ATTEMPT', 'document', $id,
            'Unauthorized document download attempt', userType: 'guest');
        http_response_code(403); exit('Forbidden.');
    }

    $file = [
        'original_name' => $doc['original_name'],
        'file_path'     => $doc['file_path'],
        'mime_type'     => $doc['mime_type'],
    ];
}

// Resolve file inside the storage sandbox
try {
    $path = $docSvc->absolutePath($file['file_path']);
} catch (Throwable) {
    http_response_code(404); exit('File missing from storage.');
}

if (!is_file($path)) { http_response_code(404); exit('File missing from storage.'); }

// Audit BEFORE streaming — a dropped connection still leaves a trail
$actorType = $client ? 'client' : ($admin ? 'admin' : 'super_admin');
$actorId   = $client['id'] ?? $admin['id'] ?? $superAdmin['id'] ?? null;
(new AuditService())->log('DOCUMENT_DOWNLOADED', $type === 'proof' ? 'payment_proof' : 'document', $id,
    'Downloaded ' . $file['original_name'], userType: $actorType, userId: (int) $actorId);

// ---- Stream safely -------------------------------------------------------
while (ob_get_level()) ob_end_clean();

// Strict MIME whitelist for the response — anything not whitelisted is
// forced to application/octet-stream so the browser downloads instead of
// rendering it (defence against stored-XSS via malicious SVG/HTML).
$safeMime = in_array($file['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)
    ? $file['mime_type']
    : 'application/octet-stream';

// Sanitise the filename for the Content-Disposition header
$safeName = preg_replace('/[^\w\-\. ]+/u', '_', (string) $file['original_name']) ?? 'download';
$safeName = str_replace(['..', '/', '\\', "\0"], '_', $safeName);
$safeName = mb_substr($safeName, 0, 180);

header('Content-Type: ' . $safeMime);
header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

readfile($path);
exit;