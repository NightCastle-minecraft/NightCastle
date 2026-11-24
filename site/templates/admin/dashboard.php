<?php

declare(strict_types=1);

/** @var array<string, mixed> $status */
/** @var array<string, int> $counts */
/** @var list<array<string, string>> $log */
/** @var list<array<string, mixed>> $shards */

use SiteGate\View;

$online = (bool) $status['online'];
?>
<h1 class="admin-title">Дашборд</h1>

<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Статус сервера</h2>
<?php if ($status['updatedAt'] === null) : ?>
    <p>Нет данных: прокси с плагином SiteGateAuth ещё ни разу не присылал пульс.</p>
<?php else : ?>
    <p>
        <strong><?= $online ? 'Онлайн' : 'Офлайн (пульса нет больше 2 минут)' ?></strong>
<?php if ($online) : ?>
        · игроков <?= e((string) ($status['players'] ?? '?')) ?>/<?= e((string) ($status['maxPlayers'] ?? '?')) ?>
<?php endif; ?>
    </p>
    <p class="admin-muted">Последний пульс: <?= e((string) $status['updatedAt']) ?> (UTC)</p>
<?php endif; ?>
</section>

<?= View::partial('admin/_shards', ['shards' => $shards]) ?>

<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Содержимое</h2>
    <ul class="admin-stats">
        <li>Новостей опубликовано: <strong><?= e((string) $counts['published']) ?></strong></li>
        <li>Черновиков: <strong><?= e((string) $counts['drafts']) ?></strong></li>
        <li>Секций правил: <strong><?= e((string) $counts['sections']) ?></strong></li>
        <li>Пунктов правил: <strong><?= e((string) $counts['rules']) ?></strong></li>
    </ul>
</section>

<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Последние действия</h2>
<?php if ($log === []) : ?>
    <p class="admin-muted">Пока пусто.</p>
<?php else : ?>
    <table class="admin-table">
        <thead><tr><th>Когда (Киев)</th><th>Кто</th><th>Что</th><th>Детали</th></tr></thead>
        <tbody>
<?php foreach ($log as $row) : ?>
            <tr>
                <td><?= e($row['when']) ?></td>
                <td><?= e($row['who']) ?></td>
                <td><?= e($row['action']) ?></td>
                <td><?= e($row['details']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
