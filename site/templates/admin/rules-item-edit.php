<?php

declare(strict_types=1);

/** @var int $itemId */
/** @var int $sectionId */
/** @var string $sectionTitle */
/** @var string $body */
/** @var string|null $error */
/** @var string|null $preview */
/** @var string $csrf */
?>
<h1 class="admin-title">Пункт правил</h1>
<p class="admin-muted">Секция: <a class="admin-link" href="/admin/rules#section-<?= $sectionId ?>"><?= e($sectionTitle) ?></a></p>
<form method="post" action="/admin/rules/items/<?= $itemId ?>" class="admin-card admin-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label class="admin-label" for="f-body">Текст (Markdown)</label>
    <textarea class="admin-input admin-textarea<?= $error !== null ? ' is-invalid' : '' ?>" id="f-body" name="body_md" rows="10"><?= e($body) ?></textarea>
<?php if ($error !== null) : ?>
    <p class="admin-field-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
    <div class="admin-actions">
        <button class="admin-btn" type="submit" name="action" value="save">Сохранить</button>
        <button class="admin-btn admin-btn--ghost" type="submit" name="action" value="preview">Предпросмотр</button>
        <a class="admin-btn admin-btn--ghost" href="/admin/rules#section-<?= $sectionId ?>">Отмена</a>
    </div>
</form>
<?php if ($preview !== null) : ?>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Предпросмотр (не сохранено)</h2>
    <div class="admin-preview-body"><?= $preview /* Markdown::render: сырой HTML экранирован */ ?></div>
</section>
<?php endif; ?>
