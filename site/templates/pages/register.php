<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var string $name */
/** @var string $email */
/** @var int $captchaA */
/** @var int $captchaB */
/** @var int $passwordMin */
/** @var string $csrf */
?>
<section class="container section auth-page">
    <div class="card form-card">
        <h1 class="form-title">Регистрация</h1>
<?php if ($error !== null) : ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
        <p>Ник должен совпадать с тем, под которым вы заходите в Minecraft.</p>
        <form method="post" action="/register" class="form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="label" for="f-name">Ник в игре</label>
            <input class="input" id="f-name" name="name" type="text" required minlength="3" maxlength="16" pattern="[A-Za-z0-9_]{3,16}" autocomplete="username" value="<?= e($name) ?>" autofocus>
            <p class="muted small">3–16 символов: латиница, цифры и _.</p>
            <label class="label" for="f-email">Почта</label>
            <input class="input" id="f-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= e($email) ?>">
            <p class="muted small">Придёт письмо для подтверждения. По почте можно восстановить пароль.</p>
            <label class="label" for="f-password">Пароль (минимум <?= e((string) $passwordMin) ?> символов)</label>
            <input class="input" id="f-password" name="password" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
            <label class="label" for="f-password2">Повторите пароль</label>
            <input class="input" id="f-password2" name="password2" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
            <label class="label" for="f-captcha">Проверка: сколько будет <?= e((string) $captchaA) ?> + <?= e((string) $captchaB) ?>?</label>
            <input class="input input-short" id="f-captcha" name="captcha" type="text" inputmode="numeric" required maxlength="3" autocomplete="off">
            <div class="hp" aria-hidden="true">
                <label for="f-website">Сайт (не заполняйте)</label>
                <input id="f-website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>
            <label class="check"><input type="checkbox" name="rules" value="1" required> <span>Я прочитал <a href="/rules" target="_blank" rel="noopener">правила сервера</a> и согласен с ними</span></label>
            <button class="btn btn-primary" type="submit">Зарегистрироваться</button>
        </form>
        <p class="muted small">Уже есть аккаунт? <a href="/login">Войти</a></p>
    </div>
</section>
