<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\ServiceRepository;
use App\Repositories\WalletRepository;

$clientUser = Auth::requireClient();
$services   = (new ServiceRepository())->allActive();
$wallet     = (new WalletRepository())->findByUserId((int) $clientUser['id']);
$balance    = $wallet['balance'] ?? '0.00';

$pageTitle = 'Services';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Available services</h1>
        <p class="text-muted small mb-0">
            <?= count($services) ?> services available
        </p>
    </div>
</div>

<!-- ================= Wallet balance card ================= -->
<div class="wallet-banner">
    <div class="wallet-banner__left">
        <div class="wallet-banner__icon">
            <i class="bi bi-wallet2"></i>
        </div>
        <div>
            <div class="wallet-banner__label">Wallet balance</div>
            <div class="wallet-banner__amount"><?= e(money($balance)) ?></div>
            <div class="wallet-banner__hint">
                Fund your wallet to start any service.
            </div>
        </div>
    </div>

   <div class="wallet-banner__right">
    <a href="<?= e(url('/client/fund-wallet')) ?>" class="wallet-banner__cta">
        <i class="bi bi-plus-circle-fill"></i>
        Fund wallet
    </a>
    <a href="<?= e(url('/client/submissions')) ?>" class="wallet-banner__cta">
        <i class="bi bi-file-earmark-text"></i>
        My submissions
    </a>
</div>
</div>

<?php if (empty($services)): ?>
    <div class="alert alert-info">
        No services are available at the moment. Please check back soon.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($services as $s):
            $price       = money_normalize((string) $s['price']);
            $canAfford   = money_cmp($balance, $price) >= 0;
        ?>
            <div class="col-md-6 col-xl-4">
                <div class="service-card">
                    <div class="d-flex align-items-start gap-3 mb-2">
                        <span class="icon-circle brand flex-shrink-0" style="width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;background:rgba(37,99,235,.1);color:#2563eb;font-size:1.25rem;">
                            <i class="bi bi-briefcase"></i>
                        </span>
                        <div>
                            <h3><?= e($s['name']) ?></h3>
                            <div class="small text-muted">
                                <i class="bi bi-clock-history me-1"></i>
                                <?= e($s['duration_label'] ?: human_duration((int) $s['processing_minutes'])) ?>
                            </div>
                        </div>
                    </div>

                    <p class="desc"><?= e($s['short_description'] ?: $s['description'] ?: '') ?></p>

                    <div class="d-flex justify-content-between align-items-end mt-3">
                        <div>
                            <div class="small text-muted">Cost</div>
                            <div class="price"><?= e(money($price)) ?></div>
                        </div>
                        <?php if ($canAfford): ?>
                            <a href="<?= e(url('/client/submit?service=' . urlencode($s['slug']))) ?>"
                               class="btn btn-brand btn-sm">
                                Apply now <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <a href="<?= e(url('/client/fund-wallet')) ?>"
                               class="btn btn-outline-warning btn-sm" title="Insufficient wallet balance">
                                <i class="bi bi-wallet2 me-1"></i> Fund wallet
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>