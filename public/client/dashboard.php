<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\SubmissionRepository;
use App\Repositories\WalletRepository;

$clientUser = Auth::requireClient();

$wallet     = (new WalletRepository())->findByUserId((int) $clientUser['id']);
$subRepo    = new SubmissionRepository();

$stats = [
    'balance'    => $wallet['balance'] ?? '0.00',
    'total'      => $subRepo->countForUser((int) $clientUser['id']),
    'pending'    => $subRepo->countForUserByStatus((int) $clientUser['id'], SUB_PENDING),
    'processing' => $subRepo->countForUserByStatus((int) $clientUser['id'], SUB_PROCESSING),
    'completed'  => $subRepo->countForUserByStatus((int) $clientUser['id'], SUB_COMPLETED),
    'failed'     => $subRepo->countForUserByStatus((int) $clientUser['id'], SUB_ERROR)
                  + $subRepo->countForUserByStatus((int) $clientUser['id'], SUB_CANCELLED),
];
$recent = $subRepo->recentForUser((int) $clientUser['id'], 5);

$pageTitle = 'Dashboard';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Welcome back, <?= e($clientUser['full_name']) ?></h1>
        <p class="text-muted mb-0 small">Here's a snapshot of your account.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-md-0">
        <a href="<?= e(url('/client/fund-wallet')) ?>" class="btn btn-brand btn-sm">
            <i class="bi bi-plus-circle me-1"></i> Fund wallet
        </a>
        <a href="<?= e(url('/client/services')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-grid me-1"></i> Browse services
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card brand">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Wallet balance</div>
                    <div class="value"><?= e(money($stats['balance'])) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-wallet2"></i></span>
            </div>
            <a href="<?= e(url('/client/fund-wallet')) ?>" class="small d-inline-block mt-2">
                Fund wallet <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-card purple">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Total purchases</div>
                    <div class="value"><?= (int) $stats['total'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-bag-check"></i></span>
            </div>
            <a href="<?= e(url('/client/submissions')) ?>" class="small d-inline-block mt-2">
                View submissions <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-card amber">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Pending</div>
                    <div class="value"><?= (int) $stats['pending'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-hourglass-split"></i></span>
            </div>
            <div class="small text-muted mt-2">
                <?= (int) $stats['processing'] ?> processing
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="stat-card green">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Completed</div>
                    <div class="value"><?= (int) $stats['completed'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-check2-circle"></i></span>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="stat-card red">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Failed / cancelled</div>
                    <div class="value"><?= (int) $stats['failed'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-exclamation-octagon"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Recent submissions</strong>
        <a href="<?= e(url('/client/submissions')) ?>" class="small">View all</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                No submissions yet. Browse our services to get started.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tracking ID</th>
                            <th>Service</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $s): ?>
                            <tr>
                                <td><code><?= e($s['tracking_id']) ?></code></td>
                                <td><?= e($s['service_name']) ?></td>
                                <td><?= e(money($s['price_charged'])) ?></td>
                                <td><span class="badge bg-<?= e(status_badge($s['status'])) ?>"><?= e(str_replace('_', ' ', $s['status'])) ?></span></td>
                                <td class="small text-muted"><?= e(fmt_dt($s['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>