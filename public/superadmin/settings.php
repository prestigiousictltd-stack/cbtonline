<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Services\SettingsService;

$superAdminUser = Auth::requireSuperAdmin();
$svc = new SettingsService();

/** Section metadata for labels + types. Grouping is inferred from prefix. */
$schema = [
    // general
    'site_name'                     => 'string',
    'site_tagline'                  => 'string',
    'site_email'                    => 'string',
    'site_phone'                    => 'string',
    'site_address'                  => 'string',
    'currency'                      => 'string',
    'currency_symbol'               => 'string',
    'timezone'                      => 'string',
    // submission
    'tracking_id_prefix'            => 'string',
    'default_task_duration_minutes' => 'integer',
    'max_concurrent_claims'         => 'integer',
    // finance
    'minimum_withdrawal'            => 'decimal',
    'maximum_withdrawal'            => 'decimal',
    'platform_commission_percent'   => 'decimal',
    // uploads
    'maximum_upload_size_kb'        => 'integer',
    'allowed_file_types'            => 'string',
    // security
    'login_max_attempts'            => 'integer',
    'login_lockout_minutes'         => 'integer',
    'session_timeout_minutes'       => 'integer',
    // payments
    'payments_enabled_online'       => 'boolean',
    'payments_enabled_bank'         => 'boolean',
];

/** Group label per key prefix (used to sort/render sections). */
$groups = [
    'site'      => ['title' => 'Site',      'keys' => [
        'site_name','site_tagline','site_email','site_phone','site_address',
        'currency','currency_symbol','timezone',
    ]],
    'submission'=> ['title' => 'Submission', 'keys' => [
        'tracking_id_prefix','default_task_duration_minutes','max_concurrent_claims',
    ]],
    'finance'   => ['title' => 'Finance',    'keys' => [
        'minimum_withdrawal','maximum_withdrawal','platform_commission_percent',
        'payments_enabled_online','payments_enabled_bank',
    ]],
    'uploads'   => ['title' => 'Uploads',    'keys' => [
        'maximum_upload_size_kb','allowed_file_types',
    ]],
    'security'  => ['title' => 'Security',   'keys' => [
        'login_max_attempts','login_lockout_minutes','session_timeout_minutes',
    ]],
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/settings');

    $values = [];
    foreach ($schema as $key => $type) {
        if (array_key_exists($key, $_POST['settings'] ?? [])) {
            $values[$key] = $_POST['settings'][$key];
        }
    }

    $result = $svc->saveMany((int) $superAdminUser['id'], $values, $schema);

    if ($result['ok']) {
        // Reload the settings cache so this request sees fresh values
        App\Core\Settings::refresh();
        flash('success', $result['saved'] . ' setting(s) saved.');
        redirect('/superadmin/settings');
    }
    $errors = $result['errors'];
}

// The bank_transfer_details is stored as JSON — handle separately
$bankDetails = App\Core\Settings::get('bank_transfer_details', []);
if (!is_array($bankDetails)) $bankDetails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bank_transfer_details'])) {
    // already handled above via settings[] — JSON handled below
}

// Current values
$current = App\Core\Settings::all();

$pageTitle = 'Settings';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">System settings</h1>
        <p class="text-muted small mb-0">Values are applied on the next request. Every change is audited.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $k => $v): ?>
            <div><strong><?= e($k) ?>:</strong> <?= e($v) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <?php foreach ($groups as $groupKey => $group): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><strong><?= e($group['title']) ?></strong></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($group['keys'] as $key):
                                $type  = $schema[$key] ?? 'string';
                                $value = $current[$key] ?? '';
                            ?>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted"><?= e(str_replace('_', ' ', $key)) ?></label>

                                    <?php if ($type === 'boolean'): ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox"
                                                   id="s_<?= e($key) ?>"
                                                   name="settings[<?= e($key) ?>]" value="1"
                                                <?= !empty($value) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="s_<?= e($key) ?>">
                                                <?= !empty($value) ? 'Enabled' : 'Disabled' ?>
                                            </label>
                                        </div>
                                    <?php elseif ($type === 'integer'): ?>
                                        <input type="number" class="form-control"
                                               name="settings[<?= e($key) ?>]"
                                               value="<?= e((string) $value) ?>" step="1">
                                    <?php elseif ($type === 'decimal'): ?>
                                        <input type="number" class="form-control" step="0.01"
                                               name="settings[<?= e($key) ?>]"
                                               value="<?= e((string) $value) ?>">
                                    <?php else: ?>
                                        <input type="text" class="form-control"
                                               name="settings[<?= e($key) ?>]"
                                               value="<?= e((string) $value) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Bank transfer</strong></div>
                <div class="card-body">
                    <div class="mb-2">
                        <label class="form-label small text-muted">Bank name</label>
                        <input type="text" name="settings[bank_transfer_details][bank_name]" class="form-control"
                               value="<?= e($bankDetails['bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted">Account name</label>
                        <input type="text" name="settings[bank_transfer_details][account_name]" class="form-control"
                               value="<?= e($bankDetails['account_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted">Account number</label>
                        <input type="text" name="settings[bank_transfer_details][account_number]" class="form-control"
                               value="<?= e($bankDetails['account_number'] ?? '') ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label small text-muted">Instructions</label>
                        <textarea name="settings[bank_transfer_details][instructions]" class="form-control" rows="3"><?= e($bankDetails['instructions'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button class="btn btn-brand">
                    <i class="bi bi-save me-1"></i> Save settings
                </button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/superadmin/dashboard')) ?>">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</form>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>