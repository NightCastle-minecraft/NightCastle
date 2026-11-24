<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $products */
/** @var list<string> $orphanCodes */
/** @var bool $pluginSeen */
/** @var array<string, int> $counts */
/** @var string $csrf */
?>
<h1 class="admin-title">Магазин</h1>
<p>
    <a class="admin-btn" href="/admin/shop/new">Новый товар</a>
    <a class="admin-btn admin-btn--ghost" href="/admin/shop/orders">Заказы (ждут: <?= e((string) ($counts['pending'] ?? 0)) ?>, ошибки: <?= e((string) ($counts['failed'] ?? 0)) ?>)</a>
    <a class="admin-btn admin-btn--ghost" href="/admin/shop/wallet">Кошельки и пополнение</a>
</p>
<?php if (!$pluginSeen) : ?>
<p class="admin-error">Плагин SiteGateShop ещё ни разу не присылал список товаров. Пока он не на связи, купить ничего нельзя.</p>
<?php endif; ?>
<section class="admin-card admin-section">
<?php if ($products === []) : ?>
    <p class="admin-muted">Товаров пока нет. Сначала опиши выдачу в плагине (products/&lt;код&gt;.yml), потом заведи товар с тем же кодом.</p>
<?php else : ?>
    <table class="admin-table">
        <thead><tr><th>Товар</th><th>Код</th><th>Категория</th><th>Цена</th><th>В продаже</th><th>Плагин</th><th></th></tr></thead>
        <tbody>
<?php foreach ($products as $p) : ?>
            <tr>
                <td><?= e((string) $p['title']) ?></td>
                <td><code><?= e((string) $p['code']) ?></code></td>
                <td><?= e((string) $p['category']) ?></td>
                <td><?= e((string) $p['price']) ?></td>
                <td><?= (int) $p['is_active'] === 1 ? 'да' : 'нет' ?></td>
                <td><?= $p['in_plugin'] ? 'есть' : '<strong class="admin-error">нет файла</strong>' ?></td>
                <td><a class="admin-link" href="/admin/shop/<?= e((string) $p['id']) ?>/edit">Изменить</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php if ($orphanCodes !== []) : ?>
    <p class="admin-muted">В плагине есть выдача без товара на сайте: <?= e(implode(', ', $orphanCodes)) ?>.</p>
<?php endif; ?>
</section>
