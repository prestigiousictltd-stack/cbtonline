<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ClientRepository;

$superAdminUser = Auth::requireSuperAdmin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Client not found.'); redirect('/superadmin/clients'); }

$user = (new ClientRepository())->findById($id);
if (!$user) { flash('danger', 'Client not found.'); redirect('/superadmin/clients'); }

$wallet = Database::selectOne('SELECT * FROM wallets WHERE user_id = :u LIMIT 1', [':u' => $id]);

$submissions = Database::selectAll(
    'SELECT s.id, s.tracking_id, s.status, s.price_charged, s.created_at,
            sv.name AS service_name
     FROM submissions s
     INNER JOIN services sv ON sv.id = s.service_id
     WHERE s.user_id = :u
     ORDER BY s.created_at DESC LIMIT 20',
    [':u' => $id]
);

$txns = Database::selectAll(
    'SELECT transaction_ref, type, category, amount, balance_after, created_at
     FROM wallet_transactions WHERE user_id = :u
     ORDER BY created_at DESC LIMIT 10',
    [':u' => $id]
);

$payments = Database::selectAll(
    'SELECT payment_ref, amount, method, status, created_at
     FROM payments WHERE user_id = :u ORDER BY created_at DESC LIMIT 10',
    [':u' => $id]
);

$pageTitle = $user['full_name'];
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="small text-muted mb-1">
            <a class="text-decoration-none" href="<?= e(url('/superadmin/clients')) ?>">
                <i class="bi bi-arrow-left me-1"></i> Clients
            </a>
        </div>
        <h1 class="h4 mb-1"><?= e($user['full_name']) ?></h1>
        <div class="small text-muted">
            <?= e($user['email']) ?> · <?= e($user['phone']) ?>
            · <span class="badge bg-<?= e(status_badge($user['status'])) ?>"><?= e($user['status']) ?></span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="sa-stat brand">
            <div class="label">Wallet balance</div>
            <div class="value"><?= e(money($wallet['balance'] ?? '0.00')) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="sa-stat green">
            <div class="label">Submissions</div>
            <div class="value"><?= count($submissions) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="sa-stat amber">
            <div class="label">Payments</div>
            <div class="value"><?= count($payments) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="sa-stat purple">
            <div class="label">Joined</div>
            <div class="value" style="font-size:1rem"><?= e(fmt_dt($user['created_at'], 'd M Y')) ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Recent submissions</strong></div>
            <div class="card-body p-0">
                <?php if (empty($submissions)): ?>
                    <div class="p-4 text-muted">No submissions.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Tracking ID</th><th>Service</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($submissions as $s): ?>
                                <tr>
                                    <td><code class="small"><?= e($s['tracking_id']) ?></code></td>
                                    <td class="small"><?= e($s['service_name']) ?></td>
                                    <td><span class="badge bg-<?= e(status_badge($s['status'])) ?>"><?= e($s['status']) ?></span></td>
                                    <td class="small text-muted"><?= e(fmt_dt($s['created_at'], 'd M Y')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Recent payments</strong></div>
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <div class="p-4 text-muted">No payments.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Ref</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><code class="small"><?= e($p['payment_ref']) ?></code></td>
                                    <td class="fw-semibold"><?= e(money($p['amount'])) ?></td>
                                    <td class="small"><?= e(ucwords(str_replace('_', ' ', $p['method']))) ?></td>
                                    <td><span class="badge bg-<?= e(status_badge($p['status'])) ?>"><?= e($p['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Recent wallet transactions</strong></div>
            <div class="card-body p-0">
                <?php if (empty($txns)): ?>
                    <div class="p-4 text-muted">No transactions.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Ref</th><th>Type</th><th>Amount</th><th>Balance</th></tr></thead>
                            <tbody>
                            <?php foreach ($txns as $t): ?>
                                <tr>
                                    <td><code class="small"><?= e($t['transaction_ref']) ?></code></td>
                                    <td class="small"><?= e($t['type'] . ' · ' . $t['category']) ?></td>
                                    <td class="fw-semibold"><?= e(money($t['amount'])) ?></td>
                                    <td><?= e(money($t['balance_after'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>