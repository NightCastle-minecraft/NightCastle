<?php

declare(strict_types=1);

/** @var string $status */
/** @var list<array<string, mixed>> $orders */
/** @var string $csrf */

$filters = ['' => 'Все', 'pending' => 'Ждут выдачи', 'failed' => 'Ошибки', 'delivered' => 'Выданы', 'cancelled' => 'Отменены'];
?>
<h1 class="admin-title">Заказы</h1>
<p>
<?php foreach ($filters as $key => $label) : ?>
    <a class="admin-btn<?= $status === $key ? '' : ' admin-btn--ghost' ?>" href="/admin/shop/orders<?= $key !== '' ? '?status=' . e($key) : '' ?>"><?= e($label) ?></a>
<?php endforeach; ?>
    <a class="admin-link" href="/admin/shop">← Магазин</a>
</p>
<section class="admin-card admin-section">
<?php if ($orders === []) : ?>
    <p class="admin-muted">Заказов нет.</p>
<?php else : ?>
    <table class="admin-table">
        <thead><tr><th>#</th><th>Игрок</th><th>Товар</th><th>Цена</th><th>Статус</th><th>Дата</th><th></th></tr></thead>
        <tbody>
<?php foreach ($orders as $o) : ?>
            <tr>
                <td><?= e((string) $o['id']) ?></td>
                <td><a class="admin-link" href="/admin/shop/wallet?name=<?= e(rawurlencode((string) $o['name'])) ?>"><?= e((string) $o['name']) ?></a></td>
                <td><?= e((string) $o['title']) ?></td>
                <td><?= e((string) $o['price']) ?></td>
                <td><?= e((string) $o['label']) ?><?= $o['note'] !== null ? '<br><span class="admin-muted">' . e((string) $o['note']) . '</span>' : '' ?></td>
                <td><?= e((string) $o['date']) ?></td>
                <td>
<?php if (in_array($o['status'], ['pending', 'failed'], true)) : ?>
                    <form method="post" action="/admin/shop/orders/<?= e((string) $o['id']) ?>/cancel" class="admin-inline-form">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="admin-btn admin-btn--ghost" type="submit">Отменить и вернуть</button>
                    </form>
<?php endif; ?>
<?php if ($o['status'] === 'failed') : ?>
                    <form method="post" action="/admin/shop/orders/<?= e((string) $o['id']) ?>/retry" class="admin-inline-form">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="admin-btn admin-btn--ghost" type="submit">Выдать снова</button>
                    </form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
