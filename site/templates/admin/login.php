<?php

declare(strict_types=1);

/** @var ?string $error */
/** @var string $username */
/** @var string $csrf */
?>
<section class="admin-card admin-login">
    <h1 class="admin-title">Вход в админку</h1>
<?php if ($error !== null) : ?>
    <p class="admin-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
    <form method="post" action="/admin/login" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="admin-label" for="username">Логин</label>
        <input class="admin-input" id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" required autofocus>
        <label class="admin-label" for="password">Пароль</label>
        <input class="admin-input" id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="admin-btn" type="submit">Войти</button>
    </form>
</section>
