<?php

declare(strict_types=1);

/** @var string $name */
/** @var string $createdAt */
/** @var string $lastLogin */
/** @var string $total */
/** @var string $month */
/** @var list<array{date: string, time: string, seconds: int, percent: int}> $days */
/** @var int $sessions */
/** @var int $sessionDays */
/** @var int $passwordMin */
/** @var string $email */
/** @var bool $emailVerified */
/** @var int $balance */
/** @var list<array{id: int, title: string, price: int, status: string, date: string}> $orders */
/** @var list<array{delta: int, reason: string, date: string}> $ledger */
/** @var string|null $flash */
/** @var string $csrf */

$csrfField = '<input type="hidden" name="_csrf" value="' . e($csrf) . '">';
?>
<section class="container section account">
    <div class="section-head">
        <h1 class="page-title"><?= e($name) ?></h1>
        <form method="post" action="/logout">
            <?= $csrfField ?>
            <button class="btn btn-ghost" type="submit">Выйти</button>
        </form>
    </div>
<?php if ($flash !== null) : ?>
    <p class="alert alert-ok" role="status"><?= e($flash) ?></p>
<?php endif; ?>

    <div class="info-grid">
        <div class="card info-card">
            <h3>Наиграно всего</h3>
            <p class="info-value"><?= e($total) ?></p>
        </div>
        <div class="card info-card">
            <h3>За 30 дней</h3>
            <p class="info-value"><?= e($month) ?></p>
        </div>
        <div class="card info-card">
            <h3>Баланс</h3>
            <p class="info-value"><?= e((string) $balance) ?> монет</p>
            <p class="muted"><a href="/shop">В магазин</a></p>
        </div>
        <div class="card info-card">
            <h3>На сервере с</h3>
            <p class="info-value"><?= e($createdAt) ?></p>
<?php if ($lastLogin !== '') : ?>
            <p class="muted">Последний вход: <?= e($lastLogin) ?></p>
<?php endif; ?>
        </div>
    </div>

    <div class="card account-block">
        <h2>Время в игре по дням</h2>
<?php if ($days === []) : ?>
        <p class="muted">За последние 30 дней игры пока нет. Время засчитывается, когда вы выходите с сервера.</p>
<?php else : ?>
        <ul class="days">
<?php foreach ($days as $d) : ?>
            <li class="day">
                <span class="day-date"><?= e($d['date']) ?></span>
                <meter class="day-bar" min="0" max="100" value="<?= e((string) $d['percent']) ?>"></meter>
                <span class="day-time"><?= e($d['time']) ?></span>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </div>

<?php if ($orders !== [] || $ledger !== []) : ?>
    <div class="account-grid">
        <div class="card account-block">
            <h2>Покупки</h2>
<?php if ($orders === []) : ?>
            <p class="muted">Покупок пока нет.</p>
<?php else : ?>
            <ul class="plain-list">
<?php foreach ($orders as $o) : ?>
                <li><strong><?= e($o['title']) ?></strong> · <?= e((string) $o['price']) ?> монет · <?= e($o['status']) ?> <span class="muted">· <?= e($o['date']) ?></span></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </div>
        <div class="card account-block">
            <h2>История баланса</h2>
            <ul class="plain-list">
<?php foreach ($ledger as $l) : ?>
                <li><strong><?= e(($l['delta'] > 0 ? '+' : '') . $l['delta']) ?></strong> · <?= e($l['reason']) ?> <span class="muted">· <?= e($l['date']) ?></span></li>
<?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

    <div class="account-grid">
        <div class="card account-block">
            <h2>Сменить пароль</h2>
            <form method="post" action="/account/password" class="form" novalidate>
                <?= $csrfField ?>
                <input type="hidden" name="username" value="<?= e($name) ?>" autocomplete="username">
                <label class="label" for="f-current">Текущий пароль</label>
                <input class="input" id="f-current" name="current" type="password" required autocomplete="current-password">
                <label class="label" for="f-password">Новый пароль (минимум <?= e((string) $passwordMin) ?> символов)</label>
                <input class="input" id="f-password" name="password" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
                <label class="label" for="f-password2">Повторите новый пароль</label>
                <input class="input" id="f-password2" name="password2" type="password" required minlength="<?= e((string) $passwordMin) ?>" autocomplete="new-password">
                <button class="btn btn-primary" type="submit">Сменить пароль</button>
            </form>
        </div>
        <div class="card account-block">
            <h2>Почта</h2>
<?php if ($email === '') : ?>
            <p class="alert alert-error">Почта не указана: без неё не получится восстановить пароль.</p>
<?php else : ?>
            <p><strong><?= e($email) ?></strong> ·
<?php if ($emailVerified) : ?>
                <span class="ok-text">подтверждена</span>
<?php else : ?>
                <span class="warn-text">не подтверждена</span>
<?php endif; ?>
            </p>
<?php if (!$emailVerified) : ?>
            <p class="muted">Откройте письмо и нажмите «Подтвердить почту». Письма нет — загляните в «Спам».</p>
            <form method="post" action="/account/email/resend">
                <?= $csrfField ?>
                <button class="btn btn-ghost" type="submit">Отправить письмо ещё раз</button>
            </form>
<?php endif; ?>
<?php endif; ?>
            <form method="post" action="/account/email" class="form" novalidate>
                <?= $csrfField ?>
                <label class="label" for="f-new-email">Новая почта</label>
                <input class="input" id="f-new-email" name="email" type="email" required maxlength="254" autocomplete="email">
                <label class="label" for="f-email-pass">Текущий пароль</label>
                <input class="input" id="f-email-pass" name="current" type="password" required autocomplete="current-password">
                <button class="btn btn-ghost" type="submit">Сменить почту</button>
            </form>
        </div>
        <div class="card account-block">
            <h2>Устройства</h2>
            <p>Игра пускает без сайта с IP, где вы подтверждали вход за последние <?= e((string) $sessionDays) ?> дней. Сейчас таких адресов: <strong><?= e((string) $sessions) ?></strong>.</p>
            <p class="muted">Если думаете, что под вашим ником зашёл кто-то чужой, выйдите на всех устройствах и смените пароль.</p>
            <form method="post" action="/account/sessions">
                <?= $csrfField ?>
                <button class="btn btn-ghost" type="submit">Выйти на всех устройствах</button>
            </form>
        </div>
    </div>
</section>
