<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\NotificationService;

$superAdminUser = Auth::requireSuperAdmin();
$svc = new NotificationService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/notifications');

    if (($_POST['action'] ?? '') === 'mark_all_read') {
        $svc->markAllRead('super_admin', (int) $superAdminUser['id']);
        flash('success', 'All notifications marked as read.');
        redirect('/superadmin/notifications');
    }
    if (($_POST['action'] ?? '') === 'mark_read' && !empty($_POST['id'])) {
        $svc->markRead('super_admin', (int) $superAdminUser['id'], (int) $_POST['id']);
        redirect('/superadmin/notifications');
    }
}

$page  = max(1, (int) ($_GET['page'] ?? 1));
$pager = $svc->paginateFor('super_admin', (int) $superAdminUser['id'], $page, 20);

$pageTitle = 'Notifications';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Notifications</h1>
        <p class="text-muted small mb-0"><?= (int) $pager['unread'] ?> unread of <?= (int) $pager['total'] ?>.</p>
    </div>
    <?php if ($pager['unread'] > 0): ?>
        <form method="post" class="mt-2 mt-md-0">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-check2-all me-1"></i> Mark all as read
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="list-group list-group-flush">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-4 text-center text-muted">No notifications yet.</div>
        <?php else: foreach ($pager['rows'] as $n): ?>
            <div class="list-group-item d-flex gap-3 <?= $n['is_read'] ? '' : 'bg-light' ?>">
                <div class="flex-shrink-0">
                    <span class="sa-stat brand icon-circle"><i class="bi bi-bell"></i></span>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <strong><?= e($n['title']) ?></strong>
                        <small class="text-muted"><?= e(fmt_dt($n['created_at'])) ?></small>
                    </div>
                    <div class="small text-muted mt-1"><?= e($n['message']) ?></div>
                    <div class="mt-2 d-flex gap-2">
                        <?php if (!empty($n['link'])): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url($n['link'])) ?>">Open</a>
                        <?php endif; ?>
                        <?php if (!$n['is_read']): ?>
                            <form method="post" class="d-inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>