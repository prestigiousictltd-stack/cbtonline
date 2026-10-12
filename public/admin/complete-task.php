<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\TaskCompletionService;
use App\Services\TaskLockService;

$adminUser = Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/dashboard');
}
Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/admin/dashboard');

$submissionId = (int) ($_POST['submission_id'] ?? 0);
$action       = (string) ($_POST['action'] ?? 'complete');

if ($submissionId <= 0) {
    flash('danger', 'Invalid submission.');
    redirect('/admin/dashboard');
}

if ($action === 'release') {
    $result = (new TaskLockService())->release($submissionId, $adminUser, 'Admin released the task from processing page.');
    if ($result['ok']) {
        flash('success', 'Task released back to the pool.');
    } else {
        flash('danger', 'Could not release: ' . ($result['error'] ?? 'unknown error'));
    }
    redirect('/admin/service?id=' . (int) ($_POST['service_id'] ?? 0));
}

// Complete
$remarks = (string) ($_POST['remarks'] ?? '');
$files   = $_FILES['documents'] ?? ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];

$result = (new TaskCompletionService())->complete($adminUser, $submissionId, $remarks, $files);

if ($result['ok']) {
    flash('success', 'Task completed. Commission earned: ' . money($result['commission']) . '.');
    redirect('/admin/completed-jobs');
}

$msg = match ($result['error'] ?? null) {
    'NOT_YOUR_TASK'    => 'You no longer own this task.',
    'DEADLINE_EXPIRED' => 'The deadline expired and the task was returned to the pool.',
    'NOT_FOUND'        => 'Submission not found.',
    default            => $result['errors']['documents']
                            ?? $result['errors']['remarks']
                            ?? 'Could not complete the task. Please try again.',
};

flash('danger', $msg);
redirect('/admin/process-task?id=' . $submissionId);