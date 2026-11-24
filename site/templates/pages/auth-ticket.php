<?php

declare(strict_types=1);

/** @var string $view invalid|expired|approved|denied|confirm|other|login|register */
/** @var string $ticket */
/** @var string $name */
/** @var string $ip */
/** @var int $minutesLeft */
/** @var string $playerName */
/** @var string|null $error */
/** @var string $csrf */
/** @var int $passwordMin */
/** @var string $email */
/** @var string|null $note */

$action = '/auth/' . rawurlencode($ticket);
$csrfField = '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
?>
<section class="container section auth-page">
    <div class="card form-card">
<?php if ($note !== null) : ?>
        <p class="alert alert-ok" role="status"><?= e($note) ?></p>
<?php endif; ?>
<?php if ($view === 'invalid' || $view === 'expired') : ?>
        <h1 class="form-title">Ссылка не работает</h1>
        <p>Ссылка устарела или уже использована. Она живёт 10 минут и подходит только для одного входа.</p>
        <p class="muted">Зайдите на сервер ещё раз: игра выдаст новую ссылку.</p>

<?php elseif ($view === 'approved') : ?>
        <h1 class="form-title">Вход подтверждён</h1>
        <p class="alert alert-ok">Готово, <strong><?= e($name) ?></strong>! Возвращайтесь в игру: через пару секунд вы окажетесь на сервере.</p>
        <p class="muted">С этого IP следующие 7 дней игра пустит вас сразу, без сайта.</p>
        <p><a class="btn btn-ghost" href="/account">Личный кабинет</a></p>

<?php elseif ($view === 'denied') : ?>
        <h1 class="form-title">Вход отклонён</h1>
        <p>Игрок, который пытался войти под ником <strong><?= e($name) ?></strong>, отключён от сервера.</p>
        <p class="muted">Если это были не вы, смените пароль в личном кабинете.</p>

<?php else : ?>
        <h1 class="form-title">Вход в игру</h1>
        <dl class="login-facts">
            <dt>Ник</dt><dd><strong><?= e($name) ?></strong></dd>
            <dt>IP</dt><dd><code><?= e($ip) ?></code></dd>
            <dt>Ссылка действует</dt><dd>ещё <?= e((string) $minutesLeft) ?> мин</dd>
        </dl>
<?php if ($error !== null) : ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($view === 'confirm') : ?>
        <p>Вы вошли на сайт как <strong><?= e($playerName) ?></strong>. Подтвердите, что это вы сейчас заходите в игру.</p>
        <form method="post" action="<?= e($action . '/confirm') ?>" class="form-actions">
            <?= $csrfField ?>
            <button class="btn btn-primary" type="submit">Подтвердить вход</button>
        </form>

<?php elseif ($view === 'other') : ?>
        <p>Сейчас на сайте вы вошли как <strong><?= e($playerName) ?></strong>, а в игру заходит <strong><?= e($name) ?></strong>.</p>
        <form method="post" action="<?= e($action . '/switch') ?>" class="form-actions">
            <?= $csrfField ?>
            <button class="btn btn-primary" type="submit">Выйти и войти как <?= e($name) ?></button>
        </form>

<?php elseif ($view === 'login') : ?>
        <p>Аккаунт <strong><?= e($name) ?></strong> уже зарегистрирован. Введите пароль.</p>
        <form method="post" action="<?= e($action . '/login') ?>" class="form" novalidate>
            <?= $csrfField ?>
            <input type="hidden" name="username" value="<?= e($name) ?>" autocomplete="username">
            <label class="label" for="f-password">Пароль</label>
            <input class="input" id="f-password" name="password" type="password" required autocomplete="current-password" autofocus>
            <button class="btn btn-primary" type="submit">Войти</button>
        </form>
        <p class="muted small"><a href="/password/forgot">Забыли пароль?</a></p>

<?php elseif ($view === 'register') : ?>
        <p>Ник <strong><?= e($name) ?></strong> свободен. Придумайте пароль: он нужен, чтобы подтверждать вход в игру и заходить в личный кабинет.</p>
        <form method="post" action="<?= e($action . '/register') ?>" class="form" novalidate>
            <?= $csrfField ?>
            <input type="hidden" name="username" value="<?= e($name) ?>" autocomplete="username">
            <label class="label" for="f-email">Почта</label>
            <input class="input" id="f-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= e($email) ?>" autofocus>
            <p class="muted small">Придёт письмо для подтверждения. По почте можно восстановить пароль.</p>
            <label class="label" for="f-password">Пароль (минимум <?= e((string) $passwordMin) ?> символов)</label>
            <input class="input" id="f-password" name="password" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
            <label class="label" for="f-password2">Повторите пароль</label>
            <input class="input" id="f-password2" name="password2" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
            <label class="check"><input type="checkbox" name="rules" value="1" required> <span>Я прочитал <a href="/rules" target="_blank" rel="noopener">правила сервера</a> и согласен с ними</span></label>
            <button class="btn btn-primary" type="submit">Зарегистрироваться</button>
        </form>
<?php endif; ?>

<?php if ($view !== 'other') : ?>
        <form method="post" action="<?= e($action . '/deny') ?>" class="form-deny">
            <?= $csrfField ?>
            <p class="muted small">Вы сейчас не заходите в игру? Значит, кто-то пытается войти под вашим ником.</p>
            <button class="btn btn-ghost" type="submit">Это не я</button>
        </form>
<?php endif; ?>
<?php endif; ?>
    </div>
</section>
