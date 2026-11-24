<?php

declare(strict_types=1);

/** @var int $newsId */
/** @var string $newsTitle */
/** @var string $csrf */
?>
<h1 class="admin-title">Удалить новость</h1>
<form method="post" action="/admin/news/<?= e((string) $newsId) ?>/delete" class="admin-card admin-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <p>Удалить новость «<?= e($newsTitle) ?>»? Это действие нельзя отменить.</p>
    <div class="admin-actions">
        <button class="admin-btn" type="submit">Удалить</button>
        <a class="admin-btn admin-btn--ghost" href="/admin/news">Отмена</a>
    </div>
</form>
