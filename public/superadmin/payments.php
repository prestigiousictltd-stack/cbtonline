<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\PaymentProofRepository;
use App\Repositories\PaymentRepository;
use App\Services\PaymentVerificationService;

$superAdminUser = Auth::requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/payments');

    $pid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $svc    = new PaymentVerificationService();

    $result = match ($action) {
        'approve' => $svc->approve($pid, 'super_admin', (int) $superAdminUser['id']),
        'reject'  => $svc->reject($pid, 'super_admin', (int) $superAdminUser['id'], (string) ($_POST['reason'] ?? '')),
        default   => ['ok' => false, 'error' => 'UNKNOWN_ACTION'],
    };

    if ($result['ok']) {
        flash('success', 'Payment ' . $action . 'd.');
    } else {
        $msg = match ($result['error'] ?? '') {
            'NOT_FOUND'      => 'Payment not found.',
            'NOT_VERIFIABLE' => 'This payment cannot be verified in its current state.',
            'REASON_REQUIRED'=> 'A reason is required to reject.',
            default          => 'Could not process the payment.',
        };
        flash('danger', $msg);
    }
    redirect('/superadmin/payments');
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$status  = trim((string) ($_GET['status'] ?? 'awaiting_verification'));
$method  = trim((string) ($_GET['method'] ?? ''));
$q       = trim((string) ($_GET['q'] ?? ''));

$repo  = new PaymentRepository();
$pager = $repo->paginateForAdmin([
    'status' => $status,
    'method' => $method,
    'q'      => $q,
], $page, 20);

$proofs = new PaymentProofRepository();

$pageTitle = 'Payments';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Payments</h1>
        <p class="text-muted small mb-0">Verify bank transfers and view online payment history.</p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['awaiting_verification','pending','successful','rejected','failed'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Method</label>
                <select name="method" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="bank_transfer" <?= $method === 'bank_transfer' ? 'selected' : '' ?>>Bank transfer</option>
                    <option value="online"        <?= $method === 'online' ? 'selected' : '' ?>>Online</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Payment ref, client name or email" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('/superadmin/payments')) ?>"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">No payments match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Proof</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $p):
                            $fileProofs = $proofs->listForPayment((int) $p['id']);
                        ?>
                            <tr>
                                <td><code class="small"><?= e($p['payment_ref']) ?></code></td>
                                <td>
                                    <div class="small fw-semibold"><?= e($p['client_name']) ?></div>
                                    <div class="small text-muted"><?= e($p['client_email']) ?></div>
                                </td>
                                <td class="fw-semibold"><?= e(money($p['amount'])) ?></td>
                                <td class="small"><?= e(ucwords(str_replace('_', ' ', $p['method']))) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($p['status'])) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $p['status']))) ?>
                                    </span>
                                    <?php if (!empty($p['rejection_reason'])): ?>
                                        <div class="small text-muted mt-1"><?= e($p['rejection_reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($fileProofs)): ?>
                                        <span class="text-muted small">—</span>
                                    <?php else: foreach ($fileProofs as $pr): ?>
                                        <a class="btn btn-sm btn-outline-secondary mb-1"
                                           href="<?= e(url('/download-document?t=proof&id=' . (int) $pr['id'])) ?>">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    <?php endforeach; endif; ?>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($p['created_at'])) ?></td>
                                <td class="text-end">
                                    <?php if (in_array($p['status'], ['pending','awaiting_verification'], true)): ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Approve this payment and credit the wallet?');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                            <button class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <button class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal" data-bs-target="#rejectPaymentModal"
                                                data-id="<?= (int) $p['id'] ?>"
                                                data-ref="<?= e($p['payment_ref']) ?>">
                                            Reject
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

    <?php if ($pager['pages'] > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                        <?php $qs = array_filter(['page' => $i, 'status' => $status, 'method' => $method, 'q' => $q]); ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/payments?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="rejectPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="rejPayId">
            <div class="modal-header">
                <h5 class="modal-title">Reject payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Rejecting <strong id="rejPayRef"></strong> will not credit the wallet.
                </p>
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="500"
                          placeholder="e.g. Proof does not match the amount or account."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Reject payment</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('rejectPaymentModal')?.addEventListener('show.bs.modal', e => {
        const b = e.relatedTarget;
        document.getElementById('rejPayId').value = b.dataset.id;
        document.getElementById('rejPayRef').textContent = b.dataset.ref;
    });
</script>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>