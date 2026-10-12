<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\TaskCompletionService;

$adminUser = Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/dashboard');
}
Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/admin/dashboard');

$submissionId = (int) ($_POST['submission_id'] ?? 0);
$remarks      = (string) ($_POST['remarks'] ?? '');
$disposition  = (string) ($_POST['disposition'] ?? 'release');

if ($submissionId <= 0) {
    flash('danger', 'Invalid submission.');
    redirect('/admin/dashboard');
}
if (!in_array($disposition, ['release', 'hold'], true)) {
    $disposition = 'release';
}

$result = (new TaskCompletionService())->reportError($adminUser, $submissionId, $remarks, $disposition);

if ($result['ok']) {
    flash('success', 'Error reported to the client and recorded on the task.');
    redirect('/admin/completed-jobs');
}

$msg = match ($result['error'] ?? null) {
    'NOT_YOUR_TASK' => 'You no longer own this task.',
    'NOT_FOUND'     => 'Submission not found.',
    default         => $result['errors']['remarks'] ?? 'Could not report the error. Please try again.',
};

flash('danger', $msg);
redirect('/admin/process-task?id=' . $submissionId);