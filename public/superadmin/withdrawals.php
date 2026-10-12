<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\WithdrawalRepository;
use App\Services\WithdrawalService;

$superAdminUser = Auth::requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/withdrawals');

    $wid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $svc    = new WithdrawalService();

    $result = match ($action) {
        'approve' => $svc->approve($wid, (int) $superAdminUser['id'], trim((string) ($_POST['note'] ?? '')) ?: null),
        'reject'  => $svc->reject($wid, (int) $superAdminUser['id'], (string) ($_POST['reason'] ?? '')),
        'paid'    => $svc->markPaid(
                        $wid,
                        (int) $superAdminUser['id'],
                        (string) ($_POST['payment_reference'] ?? ''),
                        trim((string) ($_POST['note'] ?? '')) ?: null
                     ),
        default   => ['ok' => false, 'error' => 'UNKNOWN_ACTION'],
    };

    if ($result['ok']) {
        flash('success', 'Withdrawal ' . $action . 'd successfully.');
    } else {
        $msg = match ($result['error'] ?? '') {
            'NOT_FOUND'          => 'Withdrawal not found.',
            'NOT_PENDING'        => 'Only pending withdrawals can be approved.',
            'NOT_APPROVED'       => 'Only approved withdrawals can be marked as paid.',
            'NOT_REJECTABLE'     => 'This withdrawal cannot be rejected in its current state.',
            'REASON_REQUIRED'    => 'A reason is required to reject a withdrawal.',
            'PAYMENT_REF_REQUIRED' => 'A payment reference is required to mark as paid.',
            default              => 'Could not process the withdrawal.',
        };
        flash('danger', $msg);
    }
    redirect('/superadmin/withdrawals');
}

$page   = max(1, (int) ($_GET['page'] ?? 1));
$status = trim((string) ($_GET['status'] ?? ''));

$repo = new WithdrawalRepository();
$pager = $repo->paginateForAdmin(0, $page, 20, $status ?: null);

// paginateForAdmin filters by admin_id, so we need a variant for all admins.
// Quick inline: use a Database query directly.
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$where   = '1'; $params = [];
if ($status !== '') { $where .= ' AND w.status = :s'; $params[':s'] = $status; }

$rows = \App\Core\Database::selectAll(
    "SELECT w.*, a.full_name AS admin_name, a.email AS admin_email
     FROM withdrawals w
     INNER JOIN admins a ON a.id = w.admin_id
     WHERE $where
     ORDER BY
        CASE w.status
            WHEN 'pending'  THEN 1
            WHEN 'approved' THEN 2
            WHEN 'paid'     THEN 3
            ELSE 4
        END ASC,
        w.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);
$total = (int) \App\Core\Database::selectOne(
    "SELECT COUNT(*) AS c FROM withdrawals w WHERE $where",
    $params
)['c'];
$pages = (int) ceil($total / $perPage);

$pageTitle = 'Withdrawals';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Withdrawal requests</h1>
        <p class="text-muted small mb-0"><?= (int) $total ?> request(s). Approve, reject, or mark as paid.</p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="d-flex gap-2" method="get">
            <select name="status" class="form-select form-select-sm" style="max-width:220px" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['pending','approved','paid','rejected'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-5 text-center text-muted">No withdrawal requests match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Admin</th>
                            <th>Amount</th>
                            <th>Bank</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $w): ?>
                            <tr>
                                <td><code class="small"><?= e($w['withdrawal_ref']) ?></code></td>
                                <td>
                                    <div class="small fw-semibold"><?= e($w['admin_name']) ?></div>
                                    <div class="small text-muted"><?= e($w['admin_email']) ?></div>
                                </td>
                                <td class="fw-semibold"><?= e(money($w['amount'])) ?></td>
                                <td class="small">
                                    <?= e($w['bank_name']) ?><br>
                                    <span class="text-muted"><?= e($w['account_number']) ?> · <?= e($w['account_name']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($w['status'])) ?>">
                                        <?= e(ucfirst($w['status'])) ?>
                                    </span>
                                    <?php if ($w['super_admin_note']): ?>
                                        <div class="small text-muted mt-1"><?= e($w['super_admin_note']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($w['payment_reference']): ?>
                                        <div class="small text-muted mt-1">Ref: <?= e($w['payment_reference']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($w['created_at'])) ?></td>
                                <td class="text-end">
                                    <?php if ($w['status'] === 'pending'): ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Approve this withdrawal?');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <button class="btn btn-sm btn-success">Approve</button>
                                        </form>

                                        <button class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#rejectModal"
                                                data-id="<?= (int) $w['id'] ?>"
                                                data-ref="<?= e($w['withdrawal_ref']) ?>">
                                            Reject
                                        </button>
                                    <?php elseif ($w['status'] === 'approved'): ?>
                                        <button class="btn btn-sm btn-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#paidModal"
                                                data-id="<?= (int) $w['id'] ?>"
                                                data-ref="<?= e($w['withdrawal_ref']) ?>">
                                            Mark paid
                                        </button>
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
                        <?php $qs = array_filter(['page' => $i, 'status' => $status]); ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/withdrawals?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="rejectId">
            <div class="modal-header">
                <h5 class="modal-title">Reject withdrawal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Rejecting <strong id="rejectRef"></strong> releases the reserved funds back to the admin.
                </p>
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="500"
                          placeholder="e.g. Bank details do not match the account name on file."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Reject withdrawal</button>
            </div>
        </form>
    </div>
</div>

<!-- Mark-paid modal -->
<div class="modal fade" id="paidModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="paid">
            <input type="hidden" name="id" id="paidId">
            <div class="modal-header">
                <h5 class="modal-title">Mark as paid</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Confirming the payment for <strong id="paidRef"></strong>. The admin's balance
                    will be reduced and a ledger entry recorded.
                </p>
                <div class="mb-3">
                    <label class="form-label">Payment reference <span class="text-danger">*</span></label>
                    <input type="text" name="payment_reference" class="form-control" required maxlength="100"
                           placeholder="e.g. bank transaction ID">
                </div>
                <div class="mb-3">
                    <label class="form-label">Note (optional)</label>
                    <input type="text" name="note" class="form-control" maxlength="500">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Mark as paid</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('rejectModal')?.addEventListener('show.bs.modal', e => {
        const b = e.relatedTarget;
        document.getElementById('rejectId').value  = b.dataset.id;
        document.getElementById('rejectRef').textContent = b.dataset.ref;
    });
    document.getElementById('paidModal')?.addEventListener('show.bs.modal', e => {
        const b = e.relatedTarget;
        document.getElementById('paidId').value  = b.dataset.id;
        document.getElementById('paidRef').textContent = b.dataset.ref;
    });
</script>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>