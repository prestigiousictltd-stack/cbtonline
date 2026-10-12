
<?php if (!empty($__flashes)): ?>
    <?php foreach ($__flashes as $__f): ?>
        <div class="alert alert-<?= e($__f['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($__f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>