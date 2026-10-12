<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\SuperAdminRepository;
use App\Services\AuditService;

$superAdminUser = Auth::requireSuperAdmin();
$repo = new SuperAdminRepository();

$errors = [];
$old = [
    'full_name' => $superAdminUser['full_name'],
    'phone'     => $superAdminUser['phone'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/profile');

    $old = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'phone'     => trim((string) ($_POST['phone'] ?? '')),
    ];

    if (mb_strlen($old['full_name']) < 3) $errors['full_name'] = 'Full name is required (min 3 chars).';
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $old['phone'])) $errors['phone'] = 'Please enter a valid phone number.';

    if (!$errors) {
        \App\Core\Database::execute(
            'UPDATE super_admins SET full_name = :fn, phone = :p WHERE id = :id',
            [':fn' => $old['full_name'], ':p' => $old['phone'], ':id' => (int) $superAdminUser['id']]
        );
        (new AuditService())->log(
            'SUPERADMIN_PROFILE_UPDATED', 'super_admin', (int) $superAdminUser['id'],
            'Super Admin updated profile',
            userType: 'super_admin', userId: (int) $superAdminUser['id']
        );
        flash('success', 'Profile updated.');
        redirect('/superadmin/profile');
    }
}

$pageTitle = 'Profile';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<h1 class="h4 mb-1">Profile</h1>
<p class="text-muted small mb-4">Your personal information.</p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label">Full name</label>
                        <input type="text" name="full_name" class="form-control" required maxlength="150"
                               value="<?= e($old['full_name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= e($superAdminUser['email']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= e($superAdminUser['username']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" required maxlength="30"
                               value="<?= e($old['phone']) ?>">
                    </div>

                    <button class="btn btn-brand">Save changes</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small">Account</h6>
                <div class="mb-2"><span class="text-muted small">Status:</span>
                    <span class="badge bg-<?= e(status_badge($superAdminUser['status'])) ?>">
                        <?= e($superAdminUser['status']) ?>
                    </span>
                </div>
                <div class="mb-2"><span class="text-muted small">Joined:</span> <?= e(fmt_dt($superAdminUser['created_at'], 'd M Y')) ?></div>
                <div class="mb-3"><span class="text-muted small">Last login:</span> <?= e(fmt_dt($superAdminUser['last_login_at'])) ?></div>
                <a class="btn btn-outline-secondary w-100 btn-sm" href="<?= e(url('/superadmin/change-password')) ?>">
                    <i class="bi bi-key me-1"></i> Change password
                </a>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>