<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Csrf;

$superAdminUser = Auth::requireSuperAdmin();

// ------------------------------------------------------------
// Super Admin action: release a held task from the list
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'release_task') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/submissions');

    $submissionId = (int) ($_POST['id'] ?? 0);
    $reason       = trim((string) ($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Released by Super Admin';
    }

    if ($submissionId > 0) {
        $result = (new App\Services\TaskLockService())->releaseBySuperAdmin(
            $submissionId, $superAdminUser, $reason
        );
        if (!empty($result['ok'])) {
            flash('success', 'Task released back to the pool.');
        } else {
            $msg = match ($result['error'] ?? '') {
                'NOT_RELEASABLE' => 'That task cannot be released in its current state.',
                'NOT_FOUND'      => 'Submission not found.',
                'RELEASE_FAILED' => 'The task was already released by someone else.',
                default          => 'Could not release the task.',
            };
            flash('danger', $msg);
        }
    }
    redirect('/superadmin/submissions');
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$status  = trim((string) ($_GET['status'] ?? ''));
$q       = trim((string) ($_GET['q'] ?? ''));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['1'];
$params = [];
if ($status !== '') { $where[] = 's.status = :status'; $params[':status'] = $status; }
if ($q !== '') {
    $where[] = '(s.tracking_id LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);

$rows = Database::selectAll(
    "SELECT s.id, s.tracking_id, s.status, s.price_charged, s.created_at, s.updated_at,
            sv.name AS service_name, u.full_name AS client_name, u.email AS client_email,
            a.full_name AS admin_name
     FROM submissions s
     INNER JOIN services sv ON sv.id = s.service_id
     INNER JOIN users u     ON u.id  = s.user_id
     LEFT  JOIN admins a    ON a.id  = s.assigned_admin_id
     WHERE $whereSql
     ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);
$total = (int) Database::selectOne(
    "SELECT COUNT(*) AS c FROM submissions s
     INNER JOIN users u ON u.id = s.user_id
     WHERE $whereSql",
    $params
)['c'];
$pages = (int) ceil($total / $perPage);

$pageTitle = 'Submissions';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Submissions</h1>
        <p class="text-muted small mb-0"><?= (int) $total ?> submission(s).</p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['pending','processing','completed','error_detected','cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Tracking ID, client name or email" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('/superadmin/submissions')) ?>"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-5 text-center text-muted">No submissions match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tracking ID</th>
                            <th>Service</th>
                            <th>Client</th>
                            <th>Assigned admin</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $s): ?>
                            <tr>
                                <td>
                                    <a class="text-decoration-none" href="<?= e(url('/superadmin/submission-view?id=' . (int) $s['id'])) ?>">
                                        <code class="small"><?= e($s['tracking_id']) ?></code>
                                    </a>
                                </td>
                                <td class="small"><?= e($s['service_name']) ?></td>
                                <td class="small">
                                    <div class="fw-semibold"><?= e($s['client_name']) ?></div>
                                    <div class="text-muted"><?= e($s['client_email']) ?></div>
                                </td>
                                <td class="small"><?= e($s['admin_name'] ?? '—') ?></td>
                                <td class="fw-semibold"><?= e(money($s['price_charged'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($s['status'])) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $s['status']))) ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($s['created_at'])) ?></td>

                                <td class="text-end">
                                    <?php if (in_array($s['status'], ['processing', 'error_detected'], true)): ?>
                                        <form method="post" class="d-inline"
                                            onsubmit="return confirm('Release <?= e($s['tracking_id']) ?> back to the pool?');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="release_task">
                                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                            <input type="hidden" name="reason" value="Released by Super Admin from submissions list">
                                            <button class="release-task-chip" title="Release this task back to the pool">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                            <span>Release</span>
                                        </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php $qs = array_filter(['page' => $i, 'status' => $status, 'q' => $q]); ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/submissions?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<style>
    /* ---------- Release task pill (submissions list) ---------- */
    .release-task-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 10px;
        font-size: .82rem;
        font-weight: 700;
        letter-spacing: .2px;
        line-height: 1.2;
        color: #ffffff;
        background: linear-gradient(135deg, #b45309 0%, #92400e 100%);
        border: 1px solid #78350f;
        box-shadow: 0 4px 12px rgba(120, 53, 15, 0.35),
                    inset 0 1px 0 rgba(255, 255, 255, 0.15);
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        animation: chip-pulse 2.4s ease-in-out infinite;
        white-space: nowrap;
        text-decoration: none;
        vertical-align: middle;
        cursor: pointer;
        max-width: 100%;
    }
    .release-task-chip i {
        font-size: 1rem;
        flex-shrink: 0;
    }
    .release-task-chip span {
        display: inline-block;
        white-space: nowrap;
    }
    .release-task-chip:hover {
        color: #ffffff;
        background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(120, 53, 15, 0.45),
                    inset 0 1px 0 rgba(255, 255, 255, 0.20);
        animation-play-state: paused;
    }
    .release-task-chip:active {
        transform: translateY(0);
    }
    .release-task-chip:focus-visible {
        outline: 3px solid rgba(217, 119, 6, 0.5);
        outline-offset: 2px;
    }

    @keyframes chip-pulse {
        0%, 100% {
            box-shadow:
                0 4px 12px rgba(120, 53, 15, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.15),
                0 0 0 0 rgba(217, 119, 6, 0.5);
        }
        50% {
            box-shadow:
                0 4px 12px rgba(120, 53, 15, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.15),
                0 0 0 8px rgba(217, 119, 6, 0);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .release-task-chip { animation: none; }
    }

    /* Make sure the action cell doesn't clip the button */
    .table td.text-end {
        overflow: visible;
    }
</style>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>