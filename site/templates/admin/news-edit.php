<?php

declare(strict_types=1);

/** @var int|null $newsId */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var string $cover */
/** @var string|null $preview */
/** @var string $csrf */
$action = $newsId === null ? '/admin/news' : '/admin/news/' . $newsId;
?>
<h1 class="admin-title"><?= $newsId === null ? 'Новая новость' : 'Редактирование новости' ?></h1>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="admin-card admin-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <label class="admin-label" for="f-title">Заголовок *</label>
    <input class="admin-input<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="f-title" name="title" type="text" maxlength="255" value="<?= e((string) $values['title']) ?>">
<?php if (isset($errors['title'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['title']) ?></p>
<?php endif; ?>

    <label class="admin-label" for="f-slug">Slug (пусто = из заголовка)</label>
    <input class="admin-input<?= isset($errors['slug']) ? ' is-invalid' : '' ?>" id="f-slug" name="slug" type="text" maxlength="190" value="<?= e((string) $values['slug']) ?>">
<?php if (isset($errors['slug'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['slug']) ?></p>
<?php endif; ?>

    <label class="admin-label" for="f-body">Текст (Markdown) *</label>
    <textarea class="admin-input admin-textarea<?= isset($errors['body_md']) ? ' is-invalid' : '' ?>" id="f-body" name="body_md" rows="18"><?= e((string) $values['body_md']) ?></textarea>
<?php if (isset($errors['body_md'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['body_md']) ?></p>
<?php endif; ?>

    <span class="admin-label">Обложка</span>
<?php if ($cover !== '') : ?>
    <img class="admin-preview" src="/uploads/<?= e($cover) ?>" alt="Текущая обложка" width="320">
    <label class="admin-check"><input type="checkbox" name="cover_remove" value="1"<?= !empty($values['cover_remove']) ? ' checked' : '' ?>> Убрать обложку</label>
<?php else : ?>
    <p class="admin-muted">Обложка не задана.</p>
<?php endif; ?>
    <label class="admin-label" for="f-cover_file">Загрузить новую (JPG, PNG, WebP, до 5 МБ)</label>
    <input class="admin-input" id="f-cover_file" name="cover_file" type="file" accept="image/jpeg,image/png,image/webp">
<?php if (isset($errors['cover_file'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['cover_file']) ?></p>
<?php endif; ?>

    <label class="admin-check"><input type="checkbox" name="is_published" value="1"<?= !empty($values['is_published']) ? ' checked' : '' ?>> Опубликовать</label>
    <label class="admin-check"><input type="checkbox" name="is_pinned" value="1"<?= !empty($values['is_pinned']) ? ' checked' : '' ?>> Закрепить</label>

    <label class="admin-label" for="f-published_at">Дата публикации (Киев; пусто = сейчас)</label>
    <input class="admin-input<?= isset($errors['published_at']) ? ' is-invalid' : '' ?>" id="f-published_at" name="published_at" type="datetime-local" value="<?= e((string) $values['published_at']) ?>">
<?php if (isset($errors['published_at'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['published_at']) ?></p>
<?php endif; ?>

    <div class="admin-actions">
        <button class="admin-btn" type="submit" name="action" value="save">Сохранить</button>
        <button class="admin-btn admin-btn--ghost" type="submit" name="action" value="preview">Предпросмотр</button>
        <a class="admin-btn admin-btn--ghost" href="/admin/news">К списку</a>
    </div>
</form>
<?php if ($preview !== null) : ?>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Предпросмотр (не сохранено)</h2>
    <h3><?= e((string) $values['title']) ?></h3>
    <div class="admin-preview-body"><?= $preview /* Markdown::render: сырой HTML экранирован */ ?></div>
</section>
<?php endif; ?>
