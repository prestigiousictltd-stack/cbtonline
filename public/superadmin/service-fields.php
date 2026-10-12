<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\ServiceFieldRepository;
use App\Repositories\ServiceRepository;
use App\Services\ServiceAdminService;
use App\Support\DynamicForm;

$superAdminUser = Auth::requireSuperAdmin();

$svcRepo   = new ServiceRepository();
$fieldRepo = new ServiceFieldRepository();
$admin     = new ServiceAdminService();

$serviceId = (int) ($_GET['service'] ?? $_POST['service_id'] ?? 0);
if ($serviceId <= 0) {
    flash('warning', 'Choose a service first.');
    redirect('/superadmin/services');
}
$service = $svcRepo->findById($serviceId);
if (!$service) {
    flash('danger', 'Service not found.');
    redirect('/superadmin/services');
}

$editId  = (int) ($_GET['edit']   ?? 0);
$newOpen = !empty($_GET['new']);

$errors = [];
$editField = $editId > 0 ? $fieldRepo->findById($editId) : null;
if ($editField && (int) $editField['service_id'] !== $serviceId) {
    $editField = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null,
        '/superadmin/service-fields?service=' . $serviceId);

// Normalise the raw comma-separated options into the expected key
if (!empty($_POST['field_options_raw']) && empty($_POST['field_options'])) {
    $_POST['field_options'] = array_values(array_filter(
        array_map('trim', explode(',', (string) $_POST['field_options_raw'])),
        fn($v) => $v !== ''
    ));
}

    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'create':
            $result = $admin->createField((int) $superAdminUser['id'], $serviceId, $_POST);
            if ($result['ok']) {
                flash('success', 'Field added.');
                redirect('/superadmin/service-fields?service=' . $serviceId);
            }
            $errors = $result['errors'] ?? ['general' => 'Could not add the field.'];
            $editField = null;
            $newOpen   = true;
            // Preserve submitted input
            $old = $_POST;
            break;

        case 'update':
            $fieldId = (int) ($_POST['id'] ?? 0);
            $result  = $admin->updateField((int) $superAdminUser['id'], $fieldId, $_POST);
            if ($result['ok']) {
                flash('success', 'Field updated.');
                redirect('/superadmin/service-fields?service=' . $serviceId);
            }
            $errors    = $result['errors'] ?? ['general' => 'Could not update the field.'];
            $editField = $fieldRepo->findById($fieldId);
            $newOpen   = false;
            $old       = $_POST;
            break;

        case 'delete':
            $fieldId = (int) ($_POST['id'] ?? 0);
            $result  = $admin->deleteField((int) $superAdminUser['id'], $fieldId);
            if ($result['ok']) {
                flash('success', !empty($result['soft'])
                    ? 'Field deactivated (already used in submissions).'
                    : 'Field deleted.');
            } else {
                flash('danger', $result['errors']['general'] ?? 'Could not delete the field.');
            }
            redirect('/superadmin/service-fields?service=' . $serviceId);

        case 'toggle':
            $fieldId = (int) ($_POST['id'] ?? 0);
            $current = $fieldRepo->findById($fieldId);
            if ($current) {
                $next   = $current['status'] === 'active' ? 'inactive' : 'active';
                $result = $admin->setFieldStatus((int) $superAdminUser['id'], $fieldId, $next);
                flash($result['ok'] ? 'success' : 'danger',
                      $result['ok'] ? 'Field status changed.' : 'Could not change status.');
            }
            redirect('/superadmin/service-fields?service=' . $serviceId);

        case 'move_up':
        case 'move_down':
            $fieldId   = (int) ($_POST['id'] ?? 0);
            $direction = $action === 'move_up' ? 'up' : 'down';
            $admin->moveField((int) $superAdminUser['id'], $fieldId, $direction);
            redirect('/superadmin/service-fields?service=' . $serviceId);
    }
}

$fields = $fieldRepo->listAllForService($serviceId);

// Live preview using DynamicForm with an empty submission
$previewHtml = (new DynamicForm($fieldRepo->listActiveForService($serviceId)))
    ->render([], []);

// Input values for the "new" or "edit" form
$formValues = $old ?? ($editField ? [
    'field_key'        => $editField['field_key'],
    'field_label'      => $editField['field_label'],
    'field_type'       => $editField['field_type'],
    'field_options'    => is_array($editField['field_options'])
        ? $editField['field_options']
        : json_decode((string) $editField['field_options'], true) ?? [],
    'placeholder'      => $editField['placeholder']      ?? '',
    'help_text'        => $editField['help_text']        ?? '',
    'is_required'      => (int) $editField['is_required'],
    'validation_regex' => $editField['validation_regex'] ?? '',
    'max_length'       => $editField['max_length']       ?? '',
    'sort_order'       => $editField['sort_order']       ?? 0,
    'status'           => $editField['status'],
] : [
    'field_key'        => '',
    'field_label'      => '',
    'field_type'       => 'text',
    'field_options'    => [],
    'placeholder'      => '',
    'help_text'        => '',
    'is_required'      => 0,
    'validation_regex' => '',
    'max_length'       => '',
    'sort_order'       => $fieldRepo->nextSortOrder($serviceId),
    'status'           => 'active',
]);

$pageTitle = 'Form fields — ' . $service['name'];
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <div class="small text-muted mb-1">
            <a class="text-decoration-none" href="<?= e(url('/superadmin/services')) ?>">
                <i class="bi bi-arrow-left me-1"></i> Services
            </a>
            · <a class="text-decoration-none" href="<?= e(url('/superadmin/service-edit?id=' . $serviceId)) ?>">
                Edit service
            </a>
        </div>
        <h1 class="h4 mb-1">Form fields — <?= e($service['name']) ?></h1>
        <p class="text-muted small mb-0">
            These are the fields clients fill when submitting <em><?= e($service['name']) ?></em>.
            Add, edit, disable, delete and reorder them. No code change required.
        </p>
    </div>
    <?php if (!$newOpen && !$editField): ?>
        <a href="<?= e(url('/superadmin/service-fields?service=' . $serviceId . '&new=1')) ?>"
           class="btn btn-brand btn-sm mt-2 mt-md-0">
            <i class="bi bi-plus-circle me-1"></i> Add field
        </a>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: form builder -->
    <div class="col-lg-7">

        <?php if ($newOpen || $editField): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <strong><?= $editField ? 'Edit field' : 'New field' ?></strong>
                </div>
                <div class="card-body">
                    <form method="post" novalidate>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="service_id" value="<?= (int) $serviceId ?>">
                        <input type="hidden" name="action" value="<?= $editField ? 'update' : 'create' ?>">
                        <?php if ($editField): ?>
                            <input type="hidden" name="id" value="<?= (int) $editField['id'] ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Field label <span class="text-danger">*</span></label>
                                <input type="text" name="field_label" class="form-control" required maxlength="150"
                                       placeholder="e.g. Exam Number" value="<?= e($formValues['field_label']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Field type <span class="text-danger">*</span></label>
                                <select name="field_type" class="form-select" id="fieldType">
                                    <?php foreach (ServiceAdminService::FIELD_TYPES as $t): ?>
                                        <option value="<?= e($t) ?>" <?= $formValues['field_type'] === $t ? 'selected' : '' ?>>
                                            <?= e($t) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Field key</label>
                                <input type="text" name="field_key" class="form-control"
                                       placeholder="auto-generated from label if empty"
                                       value="<?= e($formValues['field_key']) ?>"
                                       pattern="[a-zA-Z][a-zA-Z0-9_]*">
                                <div class="form-text">Machine name (a–z, 0–9, underscore). Cannot be changed after first use.</div>
                            </div>

                            <div class="col-12" id="optionsRow" style="display:none">
                                <label class="form-label">Options</label>
                                <input type="text" name="field_options_raw" class="form-control"
                                       placeholder="Comma-separated, e.g. WAEC, NECO, NABTEB"
                                       value="<?= e(is_array($formValues['field_options']) ? implode(', ', $formValues['field_options']) : '') ?>">
                                <div class="form-text">Only used for select / radio / checkbox fields.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Placeholder</label>
                                <input type="text" name="placeholder" class="form-control" maxlength="150"
                                       value="<?= e($formValues['placeholder']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max length</label>
                                <input type="number" name="max_length" class="form-control" min="1"
                                       value="<?= e((string) $formValues['max_length']) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Help text</label>
                                <input type="text" name="help_text" class="form-control" maxlength="255"
                                       value="<?= e($formValues['help_text']) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Validation regex (optional)</label>
                                <input type="text" name="validation_regex" class="form-control" maxlength="255"
                                       placeholder="e.g. ^[0-9]{10}$"
                                       value="<?= e($formValues['validation_regex']) ?>">
                                <div class="form-text">Applied server-side. Leave blank for no extra validation.</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Sort order</label>
                                <input type="number" name="sort_order" class="form-control"
                                       value="<?= e((string) $formValues['sort_order']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="active"   <?= $formValues['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $formValues['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_required" id="is_required" value="1"
                                        <?= !empty($formValues['is_required']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_required">Required</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <a class="btn btn-outline-secondary flex-grow-1"
                               href="<?= e(url('/superadmin/service-fields?service=' . $serviceId)) ?>">
                                Cancel
                            </a>
                            <button class="btn btn-brand flex-grow-1">
                                <i class="bi bi-save me-1"></i> <?= $editField ? 'Save field' : 'Add field' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <strong>Fields</strong>
                <span class="text-muted small">(<?= count($fields) ?> total)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($fields)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-list-check fs-2 d-block mb-2"></i>
                        No fields yet. Add the first one above.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px"></th>
                                    <th>Label</th>
                                    <th>Key</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fields as $f): ?>
                                    <tr>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="service_id" value="<?= (int) $serviceId ?>">
                                                <input type="hidden" name="action" value="move_up">
                                                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                                <button class="btn btn-sm btn-link p-0" title="Move up">
                                                    <i class="bi bi-arrow-up"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="service_id" value="<?= (int) $serviceId ?>">
                                                <input type="hidden" name="action" value="move_down">
                                                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                                <button class="btn btn-sm btn-link p-0" title="Move down">
                                                    <i class="bi bi-arrow-down"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="fw-semibold small"><?= e($f['field_label']) ?></td>
                                        <td><code class="small"><?= e($f['field_key']) ?></code></td>
                                        <td class="small"><?= e($f['field_type']) ?></td>
                                        <td><?= (int) $f['is_required'] ? '<span class="badge bg-danger">Yes</span>' : '<span class="text-muted small">No</span>' ?></td>
                                        <td>
                                            <span class="badge bg-<?= $f['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                <?= e($f['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="<?= e(url('/superadmin/service-fields?service=' . $serviceId . '&edit=' . (int) $f['id'])) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="post" class="d-inline">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="service_id" value="<?= (int) $serviceId ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                                <button class="btn btn-sm btn-outline-secondary" title="Toggle status">
                                                    <i class="bi <?= $f['status'] === 'active' ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Delete or deactivate this field? Fields already used by submissions are deactivated, not deleted.');">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="service_id" value="<?= (int) $serviceId ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: live preview -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Client preview</strong></div>
            <div class="card-body">
                <p class="small text-muted">
                    This is how the form appears to a client submitting
                    <em><?= e($service['name']) ?></em>.
                </p>
                <div class="border rounded p-3 bg-light">
                    <?= $previewHtml ?: '<div class="text-muted small">No active fields yet.</div>' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Show/hide options row based on field type
    (function () {
        const typeSelect = document.getElementById('fieldType');
        const row        = document.getElementById('optionsRow');
        if (!typeSelect || !row) return;

        function update() {
            const show = ['select', 'radio', 'checkbox'].includes(typeSelect.value);
            row.style.display = show ? '' : 'none';
        }
        typeSelect.addEventListener('change', update);
        update();
    })();
</script>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>