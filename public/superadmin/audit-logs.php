<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\AuditLogRepository;

$superAdminUser = Auth::requireSuperAdmin();

$repo = new AuditLogRepository();

$page    = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'q'           => trim((string) ($_GET['q'] ?? '')),
    'user_type'   => trim((string) ($_GET['user_type'] ?? '')),
    'action'      => trim((string) ($_GET['action'] ?? '')),
    'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
    'entity_id'   => (int)    ($_GET['entity_id'] ?? 0),
    'from'        => trim((string) ($_GET['from'] ?? '')),
    'to'          => trim((string) ($_GET['to'] ?? '')),
];

$pager          = $repo->paginate($filters, $page, 30);
$actions        = $repo->distinctActions(300);
$entityTypes    = $repo->distinctEntityTypes();

$pageTitle = 'Audit logs';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Audit logs</h1>
        <p class="text-muted small mb-0">
            <?= (int) $pager['total'] ?> entr<?= $pager['total'] === 1 ? 'y' : 'ies' ?> match your filters.
        </p>
    </div>
    <a class="btn btn-outline-secondary btn-sm mt-2 mt-md-0"
       href="<?= e(url('/superadmin/export?type=audit&' . http_build_query(array_filter($filters)))) ?>">
        <i class="bi bi-download me-1"></i> Export CSV
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Description, action or IP" value="<?= e($filters['q']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Actor type</label>
                <select name="user_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['guest','client','admin','super_admin','system'] as $t): ?>
                        <option value="<?= e($t) ?>" <?= $filters['user_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= e($a['action']) ?>" <?= $filters['action'] === $a['action'] ? 'selected' : '' ?>>
                            <?= e($a['action']) ?> (<?= (int) $a['c'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Entity type</label>
                <select name="entity_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($entityTypes as $et): ?>
                        <option value="<?= e($et['entity_type']) ?>" <?= $filters['entity_type'] === $et['entity_type'] ? 'selected' : '' ?>>
                            <?= e($et['entity_type']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Entity ID</label>
                <input type="number" name="entity_id" class="form-control form-control-sm"
                       value="<?= e((string) $filters['entity_id']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filters['from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filters['to']) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('/superadmin/audit-logs')) ?>"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">No audit entries match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>When (UTC)</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Description</th>
                            <th>IP</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $log): ?>
                            <tr>
                                <td class="small"><?= (int) $log['id'] ?></td>
                                <td class="small text-muted"><?= e(fmt_dt($log['created_at'], 'd M Y H:i:s')) ?></td>
                                <td class="small">
                                    <span class="badge bg-secondary"><?= e($log['user_type']) ?></span>
                                    <?php if ($log['user_id']): ?>
                                        <span class="text-muted">#<?= (int) $log['user_id'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><code><?= e($log['action']) ?></code></td>
                                <td class="small">
                                    <?php if ($log['entity_type']): ?>
                                        <?= e($log['entity_type']) ?>
                                        <?php if ($log['entity_id']): ?>
                                            <span class="text-muted">#<?= (int) $log['entity_id'] ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= e($log['description'] ?? '') ?></td>
                                <td class="small text-muted"><?= e($log['ip_address'] ?? '') ?></td>
                                <td class="text-end">
                                    <?php if (!empty($log['old_values']) || !empty($log['new_values'])): ?>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#diffModal"
                                                data-old="<?= e((string) $log['old_values']) ?>"
                                                data-new="<?= e((string) $log['new_values']) ?>"
                                                data-action="<?= e($log['action']) ?>">
                                            <i class="bi bi-code-square"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pager['pages'] > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                        <?php
                        $qs = array_filter(array_merge($filters, ['page' => $i]));
                        ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/audit-logs?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Diff modal -->
<div class="modal fade" id="diffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2">Action: <code id="diffAction"></code></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="fw-semibold small text-muted mb-1">Before</div>
                        <pre id="diffOld" class="bg-light p-2 rounded small" style="max-height:340px;overflow:auto"></pre>
                    </div>
                    <div class="col-md-6">
                        <div class="fw-semibold small text-muted mb-1">After</div>
                        <pre id="diffNew" class="bg-light p-2 rounded small" style="max-height:340px;overflow:auto"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('diffModal')?.addEventListener('show.bs.modal', e => {
        const b = e.relatedTarget;
        const pretty = s => {
            if (!s) return '—';
            try { return JSON.stringify(JSON.parse(s), null, 2); }
            catch { return s; }
        };
        document.getElementById('diffOld').textContent = pretty(b.dataset.old);
        document.getElementById('diffNew').textContent = pretty(b.dataset.new);
        document.getElementById('diffAction').textContent = b.dataset.action || '';
    });
</script>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>