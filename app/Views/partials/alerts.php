<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $__err): ?>
                <li><?= e(is_array($__err) ? implode(' ', $__err) : $__err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>