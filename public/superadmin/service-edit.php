<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\ServiceRepository;
use App\Services\ServiceAdminService;

$superAdminUser = Auth::requireSuperAdmin();

$repo = new ServiceRepository();
$admin = new ServiceAdminService();

$id = (int) ($_GET['id'] ?? 0);
$existing = $id > 0 ? $repo->findById($id) : null;

$errors = [];
$isEdit = $existing !== null;

$old = [
    'name'               => $existing['name']               ?? '',
    'slug'               => $existing['slug']               ?? '',
    'description'        => $existing['description']        ?? '',
    'short_description'  => $existing['short_description']  ?? '',
    'price'              => $existing['price']              ?? '0.00',
    'admin_commission'   => $existing['admin_commission']   ?? '0.00',
    'processing_minutes' => $existing['processing_minutes'] ?? 1440,
    'duration_label'     => $existing['duration_label']     ?? '',
    'icon'               => $existing['icon']               ?? '',
    'sort_order'         => $existing['sort_order']         ?? 0,
    'status'             => $existing['status']             ?? 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null,
        '/superadmin/service-edit' . ($id > 0 ? '?id=' . $id : ''));

    $old = [
        'name'               => trim((string) ($_POST['name']               ?? '')),
        'slug'               => trim((string) ($_POST['slug']               ?? '')),
        'description'        => trim((string) ($_POST['description']        ?? '')),
        'short_description'  => trim((string) ($_POST['short_description']  ?? '')),
        'price'              => trim((string) ($_POST['price']              ?? '0')),
        'admin_commission'   => trim((string) ($_POST['admin_commission']   ?? '0')),
        'processing_minutes' => (int)   ($_POST['processing_minutes'] ?? 1440),
        'duration_label'     => trim((string) ($_POST['duration_label']     ?? '')),
        'icon'               => trim((string) ($_POST['icon']               ?? '')),
        'sort_order'         => (int)   ($_POST['sort_order']         ?? 0),
        'status'             => (string)($_POST['status']             ?? 'active'),
    ];

    $result = $isEdit
        ? $admin->updateService((int) $superAdminUser['id'], $id, $old)
        : $admin->createService((int) $superAdminUser['id'], $old);

    if ($result['ok']) {
        flash('success', $isEdit ? 'Service updated.' : 'Service created.');
        redirect('/superadmin/services');
    }
    $errors = $result['errors'] ?? ['general' => 'Could not save the service.'];
}

$pageTitle = $isEdit ? 'Edit service' : 'New service';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <div class="small text-muted mb-1">
            <a class="text-decoration-none" href="<?= e(url('/superadmin/services')) ?>">
                <i class="bi bi-arrow-left me-1"></i> Services
            </a>
        </div>
        <h1 class="h4 mb-1"><?= $isEdit ? 'Edit service' : 'Create a new service' ?></h1>
        <p class="text-muted small mb-0">
            <?= $isEdit
                ? 'Changes affect future submissions only; historical transactions keep their original price.'
                : 'Set up pricing, commission and processing duration. You can add dynamic fields next.' ?>
        </p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Details</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Service name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="150"
                               value="<?= e($old['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control" maxlength="160"
                               placeholder="auto-generated from name if empty"
                               value="<?= e($old['slug']) ?>">
                        <div class="form-text">Lowercase letters, digits and dashes. Must be unique.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Short description</label>
                        <input type="text" name="short_description" class="form-control" maxlength="255"
                               value="<?= e($old['short_description']) ?>">
                        <div class="form-text">One-line summary shown on service cards.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full description</label>
                        <textarea name="description" class="form-control" rows="4"><?= e($old['description']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Pricing & processing</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Price (₦) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="price" class="form-control" required
                                   value="<?= e($old['price']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Admin commission (₦)</label>
                            <input type="number" step="0.01" min="0" name="admin_commission" class="form-control"
                                   value="<?= e($old['admin_commission']) ?>">
                            <div class="form-text">Cannot exceed the price.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Processing duration (minutes) <span class="text-danger">*</span></label>
                            <input type="number" min="1" name="processing_minutes" class="form-control" required
                                   value="<?= e((string) $old['processing_minutes']) ?>">
                            <div class="form-text">
                                e.g. 60 = 1 hour, 1440 = 1 day, 2880 = 2 days.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Duration label (optional)</label>
                            <input type="text" name="duration_label" class="form-control" maxlength="60"
                                   placeholder="e.g. 24 Hours"
                                   value="<?= e($old['duration_label']) ?>">
                            <div class="form-text">Shown to clients. Leave blank to auto-format the duration.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Presentation</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Icon path (optional)</label>
                        <input type="text" name="icon" class="form-control" maxlength="255"
                               placeholder="e.g. jamb.svg"
                               value="<?= e($old['icon']) ?>">
                        <div class="form-text">Relative to /assets/img/service-icons/.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort order</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= e((string) $old['sort_order']) ?>">
                        <div class="form-text">Lower numbers appear first.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <a href="<?= e(url('/superadmin/services')) ?>" class="btn btn-outline-secondary flex-grow-1">
                    Cancel
                </a>
                <button class="btn btn-brand flex-grow-1">
                    <i class="bi bi-save me-1"></i> <?= $isEdit ? 'Save changes' : 'Create service' ?>
                </button>
            </div>

            <?php if ($isEdit): ?>
                <a class="btn btn-outline-primary w-100 mt-2"
                   href="<?= e(url('/superadmin/service-fields?service=' . (int) $existing['id'])) ?>">
                    <i class="bi bi-list-check me-1"></i> Manage form fields
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>