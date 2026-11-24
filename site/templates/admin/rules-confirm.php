<?php

declare(strict_types=1);

/** @var string $question */
/** @var string|null $detail */
/** @var string $action */
/** @var string $back */
/** @var string $csrf */
$detail ??= null;
?>
<h1 class="admin-title"><?= e($title) ?></h1>
<form method="post" action="<?= e($action) ?>" class="admin-card admin-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <p><?= e($question) ?></p>
<?php if ($detail !== null && $detail !== '') : ?>
    <p class="admin-muted"><?= e($detail) ?></p>
<?php endif; ?>
    <div class="admin-actions">
        <button class="admin-btn" type="submit">Удалить</button>
        <a class="admin-btn admin-btn--ghost" href="<?= e($back) ?>">Отмена</a>
    </div>
</form>
