<?php

declare(strict_types=1);

use SiteGate\Env;
use SiteGate\Settings;

/** @var string|null $error */
/** @var string $name */
/** @var string $csrf */

$gameAddress = Settings::get('display_ip') ?: Env::gameAddress();
?>
<section class="container section auth-page">
    <div class="card form-card">
        <h1 class="form-title">Вход в личный кабинет</h1>
<?php if (($flash ?? null) !== null) : ?>
        <p class="alert alert-ok" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($error !== null) : ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
        <form method="post" action="/login" class="form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="label" for="f-name">Ник в игре</label>
            <input class="input" id="f-name" name="name" type="text" required maxlength="16" autocomplete="username" value="<?= e($name) ?>" autofocus>
            <label class="label" for="f-password">Пароль</label>
            <input class="input" id="f-password" name="password" type="password" required autocomplete="current-password">
            <button class="btn btn-primary" type="submit">Войти</button>
        </form>
        <p class="muted small"><a href="/password/forgot">Забыли пароль?</a></p>
        <p class="muted small">Аккаунта ещё нет? <a href="/register">Зарегистрируйтесь</a> или просто зайдите на сервер <code><?= e($gameAddress) ?></code> в Minecraft Java 26.3: игра сама покажет кнопку регистрации. <a href="/start">Подробнее</a></p>
    </div>
</section>
