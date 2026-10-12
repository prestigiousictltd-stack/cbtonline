<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\UserRepository;
use App\Services\AuditService;

$clientUser = Auth::requireClient();
$repo = new UserRepository();

$errors = [];
$old = [
    'full_name' => $clientUser['full_name'],
    'phone'     => $clientUser['phone'],
    'address'   => $clientUser['address'] ?? '',
    'state'     => $clientUser['state'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/client/profile');

    $old = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'phone'     => trim((string) ($_POST['phone'] ?? '')),
        'address'   => trim((string) ($_POST['address'] ?? '')),
        'state'     => trim((string) ($_POST['state'] ?? '')),
    ];

    if ($old['full_name'] === '' || mb_strlen($old['full_name']) < 3) {
        $errors['full_name'] = 'Full name is required (min 3 characters).';
    }
    if ($old['phone'] === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $old['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    if (!$errors) {
        $repo->updateProfile((int) $clientUser['id'], $old);
        (new AuditService())->log(
            'PROFILE_UPDATED', 'user', (int) $clientUser['id'],
            'Client updated profile',
            userType: 'client', userId: (int) $clientUser['id']
        );
        flash('success', 'Profile updated.');
        redirect('/client/profile');
    }
}

$pageTitle = 'Profile';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<h1 class="h4 mb-1">Profile</h1>
<p class="text-muted small mb-4">Manage your personal information.</p>

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
                        <label class="form-label">Email address</label>
                        <input type="email" class="form-control" value="<?= e($clientUser['email']) ?>" disabled>
                        <div class="form-text">Email cannot be changed. Contact support if needed.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone number</label>
                        <input type="tel" name="phone" class="form-control" required maxlength="30"
                               value="<?= e($old['phone']) ?>">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">State</label>
                            <input type="text" name="state" class="form-control" maxlength="80"
                                   value="<?= e($old['state']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" maxlength="255"
                                   value="<?= e($old['address']) ?>">
                        </div>
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
                    <span class="badge bg-<?= e(status_badge($clientUser['status'])) ?>"><?= e($clientUser['status']) ?></span>
                </div>
                <div class="mb-2"><span class="text-muted small">Joined:</span>
                    <?= e(fmt_dt($clientUser['created_at'], 'd M Y')) ?>
                </div>
                <div class="mb-3"><span class="text-muted small">Last login:</span>
                    <?= e(fmt_dt($clientUser['last_login_at'])) ?>
                </div>
                <a class="btn btn-outline-secondary w-100 btn-sm" href="<?= e(url('/client/change-password')) ?>">
                    <i class="bi bi-key me-1"></i> Change password
                </a>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>