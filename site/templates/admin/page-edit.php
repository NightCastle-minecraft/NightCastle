<?php

declare(strict_types=1);

/** @var string $slug */
/** @var string $pageTitle */
/** @var string $body */
/** @var array<string, string> $errors */
/** @var string|null $preview */
/** @var string $csrf */
?>
<h1 class="admin-title">Страница: <?= e($slug) ?></h1>
<form method="post" action="/admin/pages/<?= e($slug) ?>" class="admin-card admin-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label class="admin-label" for="f-title">Заголовок</label>
    <input class="admin-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="f-title" name="title" type="text" maxlength="255" value="<?= e($pageTitle) ?>">
<?php if (isset($errors['title'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['title']) ?></p>
<?php endif; ?>
    <label class="admin-label" for="f-body">Текст (Markdown)</label>
    <textarea class="admin-input admin-textarea<?= isset($errors['body_md']) ? ' is-invalid' : '' ?>" id="f-body" name="body_md" rows="18"><?= e($body) ?></textarea>
<?php if (isset($errors['body_md'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['body_md']) ?></p>
<?php endif; ?>
    <div class="admin-actions">
        <button class="admin-btn" type="submit" name="action" value="save">Сохранить</button>
        <button class="admin-btn admin-btn--ghost" type="submit" name="action" value="preview">Предпросмотр</button>
    </div>
</form>
<?php if ($preview !== null) : ?>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Предпросмотр (не сохранено)</h2>
    <h3><?= e($pageTitle) ?></h3>
    <div class="admin-preview-body"><?= $preview /* Markdown::render: сырой HTML экранирован */ ?></div>
</section>
<?php endif; ?>
