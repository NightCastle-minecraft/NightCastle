<?php

declare(strict_types=1);

/** @var array<string, array<string, mixed>> $fields */
/** @var array<string, string> $values */
/** @var array<string, string> $errors */
/** @var bool $bannerEnabled */
/** @var string $hero */
/** @var string $csrf */
?>
<h1 class="admin-title">Настройки</h1>
<form method="post" action="/admin/settings" enctype="multipart/form-data" class="admin-card admin-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<?php foreach ($fields as $key => $f) : ?>
    <label class="admin-label" for="f-<?= e($key) ?>"><?= e((string) $f['label']) ?><?= $f['required'] ? ' *' : '' ?></label>
    <input class="admin-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" id="f-<?= e($key) ?>" name="<?= e($key) ?>" type="<?= $f['url'] ? 'url' : 'text' ?>" maxlength="<?= e((string) $f['max']) ?>" value="<?= e($values[$key] ?? '') ?>">
<?php if (isset($errors[$key])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors[$key]) ?></p>
<?php endif; ?>
<?php if ($key === 'banner_text') : ?>
    <label class="admin-check"><input type="checkbox" name="banner_enabled" value="1"<?= $bannerEnabled ? ' checked' : '' ?>> Показывать баннер</label>
<?php endif; ?>
<?php endforeach; ?>

    <span class="admin-label">Фон hero</span>
<?php if ($hero !== '') : ?>
    <img class="admin-preview" src="/uploads/<?= e($hero) ?>" alt="Текущий фон hero" width="320">
    <label class="admin-check"><input type="checkbox" name="hero_remove" value="1"> Убрать фон</label>
<?php else : ?>
    <p class="admin-muted">Фон не задан.</p>
<?php endif; ?>
    <label class="admin-label" for="f-hero_file">Загрузить новый (JPG, PNG, WebP, до 5 МБ)</label>
    <input class="admin-input" id="f-hero_file" name="hero_file" type="file" accept="image/jpeg,image/png,image/webp">
<?php if (isset($errors['hero_bg'])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors['hero_bg']) ?></p>
<?php endif; ?>

    <button class="admin-btn" type="submit">Сохранить</button>
</form>
