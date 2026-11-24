<?php

declare(strict_types=1);

/** @var array<string, string> $errors */
/** @var string $csrf */

$fields = [
    'current' => ['Текущий пароль', 'current-password'],
    'new' => ['Новый пароль (минимум 12 символов)', 'new-password'],
    'repeat' => ['Повтор нового пароля', 'new-password'],
];
?>
<h1 class="admin-title">Смена пароля</h1>
<form method="post" action="/admin/password" class="admin-card admin-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<?php foreach ($fields as $name => [$label, $auto]) : ?>
    <label class="admin-label" for="f-<?= e($name) ?>"><?= e($label) ?></label>
    <input class="admin-input<?= isset($errors[$name]) ? ' is-invalid' : '' ?>" id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="password" autocomplete="<?= e($auto) ?>" required>
<?php if (isset($errors[$name])) : ?>
    <p class="admin-field-error" role="alert"><?= e($errors[$name]) ?></p>
<?php endif; ?>
<?php endforeach; ?>
    <button class="admin-btn" type="submit">Сменить пароль</button>
</form>
