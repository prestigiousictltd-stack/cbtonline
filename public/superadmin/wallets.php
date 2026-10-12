<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;

$superAdminUser = Auth::requireSuperAdmin();

$page = max(1, (int) ($_GET['page'] ?? 1));
$q    = trim((string) ($_GET['q'] ?? ''));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);

$rows = Database::selectAll(
    "SELECT w.id, w.balance, w.currency, w.status, w.created_at,
            u.id AS user_id, u.full_name AS client_name, u.email AS client_email,
            (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions
              WHERE wallet_id = w.id AND type = 'credit' AND category = 'funding' AND status = 'successful') AS total_funded,
            (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions
              WHERE wallet_id = w.id AND type = 'debit' AND category = 'service_charge' AND status = 'successful') AS total_spent
     FROM wallets w
     INNER JOIN users u ON u.id = w.user_id
     WHERE $whereSql
     ORDER BY w.balance DESC, w.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);
$total = (int) Database::selectOne(
    "SELECT COUNT(*) AS c FROM wallets w INNER JOIN users u ON u.id = w.user_id WHERE $whereSql",
    $params
)['c'];
$pages = (int) ceil($total / $perPage);

$totalBalance = (string) (Database::selectOne('SELECT COALESCE(SUM(balance),0) AS s FROM wallets')['s'] ?? '0.00');
$totalFunded  = (string) (Database::selectOne(
    'SELECT COALESCE(SUM(amount),0) AS s FROM wallet_transactions
     WHERE type="credit" AND category="funding" AND status="successful"'
)['s'] ?? '0.00');
$totalSpent   = (string) (Database::selectOne(
    'SELECT COALESCE(SUM(amount),0) AS s FROM wallet_transactions
     WHERE type="debit" AND category="service_charge" AND status="successful"'
)['s'] ?? '0.00');

$pageTitle = 'Wallets';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Wallets</h1>
        <p class="text-muted small mb-0"><?= (int) $total ?> wallet(s).</p>
    </div>
    <form class="d-flex gap-2 mt-2 mt-md-0" method="get">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Search by client" value="<?= e($q) ?>">
        <button class="btn btn-sm btn-brand">Search</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="sa-stat brand">
            <div class="label">Total wallet balance</div>
            <div class="value"><?= e(money($totalBalance)) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="sa-stat green">
            <div class="label">Total funded</div>
            <div class="value"><?= e(money($totalFunded)) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="sa-stat amber">
            <div class="label">Total spent</div>
            <div class="value"><?= e(money($totalSpent)) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-5 text-center text-muted">No wallets match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Client</th>
                            <th>Balance</th>
                            <th>Funded</th>
                            <th>Spent</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $w): ?>
                            <tr>
                                <td>
                                    <a class="text-decoration-none small fw-semibold"
                                       href="<?= e(url('/superadmin/client-view?id=' . (int) $w['user_id'])) ?>">
                                        <?= e($w['client_name']) ?>
                                    </a>
                                    <div class="small text-muted"><?= e($w['client_email']) ?></div>
                                </td>
                                <td class="fw-semibold"><?= e(money($w['balance'])) ?></td>
                                <td class="text-success"><?= e(money($w['total_funded'])) ?></td>
                                <td class="text-danger"><?= e(money($w['total_spent'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($w['status'])) ?>"><?= e($w['status']) ?></span>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($w['created_at'])) ?></td>
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
                        <?php $qs = array_filter(['page' => $i, 'q' => $q]); ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/wallets?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>