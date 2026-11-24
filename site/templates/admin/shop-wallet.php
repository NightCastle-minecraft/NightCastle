<?php

declare(strict_types=1);

use SiteGate\Format;
use SiteGate\Shop;

/** @var string $name */
/** @var string|null $error */
/** @var array{name: string, balance: int, ledger: list<array<string, mixed>>, orders: list<array<string, mixed>>}|null $account */
/** @var string $csrf */
?>
<h1 class="admin-title">Кошельки</h1>
<p><a class="admin-link" href="/admin/shop">← Магазин</a></p>
<form method="get" action="/admin/shop/wallet" class="admin-card admin-form">
    <label class="admin-label" for="f-name">Ник игрока</label>
    <input class="admin-input" id="f-name" name="name" maxlength="16" value="<?= e($name) ?>" autocomplete="off">
    <button class="admin-btn" type="submit">Показать</button>
</form>
<?php if ($error !== null) : ?>
<p class="admin-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($account !== null) : ?>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle"><?= e($account['name']) ?>: <?= e((string) $account['balance']) ?> монет</h2>
    <form method="post" action="/admin/shop/wallet" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="name" value="<?= e($account['name']) ?>">
        <label class="admin-label" for="f-amount">Сколько монет (минус = списать)</label>
        <input class="admin-input" id="f-amount" name="amount" type="number" required>
        <label class="admin-label" for="f-reason">За что (увидит игрок в кабинете)</label>
        <input class="admin-input" id="f-reason" name="reason" maxlength="120">
        <button class="admin-btn" type="submit">Изменить баланс</button>
    </form>
</section>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle">История баланса</h2>
<?php if ($account['ledger'] === []) : ?>
    <p class="admin-muted">Пусто.</p>
<?php else : ?>
    <table class="admin-table">
        <thead><tr><th>Когда</th><th>Сумма</th><th>Стало</th><th>Что</th><th>Кто</th></tr></thead>
        <tbody>
<?php foreach ($account['ledger'] as $l) : ?>
            <tr><td><?= e(Format::date((string) $l['created_at'])) ?></td><td><?= e(((int) $l['delta'] > 0 ? '+' : '') . $l['delta']) ?></td>
                <td><?= e((string) $l['balance_after']) ?></td><td><?= e((string) $l['reason']) ?></td><td><?= e((string) ($l['admin'] ?? '')) ?></td></tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Заказы</h2>
<?php if ($account['orders'] === []) : ?>
    <p class="admin-muted">Пусто.</p>
<?php else : ?>
    <table class="admin-table">
        <thead><tr><th>#</th><th>Товар</th><th>Цена</th><th>Статус</th><th>Дата</th></tr></thead>
        <tbody>
<?php foreach ($account['orders'] as $o) : ?>
            <tr><td><?= e((string) $o['id']) ?></td><td><?= e((string) $o['title']) ?></td><td><?= e((string) $o['price']) ?></td>
                <td><?= e(Shop::statusLabel((string) $o['status'])) ?></td><td><?= e(Format::date((string) $o['created_at'])) ?></td></tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
<?php endif; ?>
