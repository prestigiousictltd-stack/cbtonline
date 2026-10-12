<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\SuperAdminRepository;
use App\Services\AuditService;

$superAdminUser = Auth::requireSuperAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/change-password');

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, $superAdminUser['password_hash'])) {
        $errors['current_password'] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors['new_password'] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    if ($new === $current) {
        $errors['new_password'] = 'New password must be different.';
    }

    if (!$errors) {
        (new SuperAdminRepository())->updatePassword(
            (int) $superAdminUser['id'],
            password_hash($new, PASSWORD_DEFAULT)
        );
        (new AuditService())->log(
            'SUPERADMIN_PASSWORD_CHANGED', 'super_admin', (int) $superAdminUser['id'],
            'Super Admin changed password',
            userType: 'super_admin', userId: (int) $superAdminUser['id']
        );
        flash('success', 'Password updated.');
        redirect('/superadmin/dashboard');
    }
}

$pageTitle = 'Change password';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<h1 class="h4 mb-1">Change password</h1>
<p class="text-muted small mb-4">Choose a strong new password.</p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label">Current password</label>
                        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>

                    <button class="btn btn-brand">Update password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>