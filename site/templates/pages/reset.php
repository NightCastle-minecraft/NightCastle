<?php

declare(strict_types=1);

/** @var string $token */
/** @var string $name */
/** @var bool $valid */
/** @var string|null $error */
/** @var string $csrf */
/** @var int $passwordMin */
?>
<section class="container section auth-page">
    <div class="card form-card">
        <h1 class="form-title">Новый пароль</h1>
<?php if (!$valid) : ?>
        <p class="alert alert-error">Ссылка устарела или уже использована. Она живёт 1 час и срабатывает один раз.</p>
        <p><a class="btn btn-ghost" href="/password/forgot">Запросить новую</a></p>
<?php else : ?>
<?php if ($error !== null) : ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
        <p>Аккаунт <strong><?= e($name) ?></strong>. После смены пароля все входы в игру и на сайт сбросятся.</p>
        <form method="post" action="/password/reset/<?= e(rawurlencode($token)) ?>" class="form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="username" value="<?= e($name) ?>" autocomplete="username">
            <label class="label" for="f-password">Новый пароль (минимум <?= e((string) $passwordMin) ?> символов)</label>
            <input class="input" id="f-password" name="password" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password" autofocus>
            <label class="label" for="f-password2">Повторите пароль</label>
            <input class="input" id="f-password2" name="password2" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
            <button class="btn btn-primary" type="submit">Сменить пароль</button>
        </form>
<?php endif; ?>
    </div>
</section>
