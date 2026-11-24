<?php

declare(strict_types=1);

use SiteGate\ShardStats;

/** @var list<array{shard: int, name: string, bytes: int|null, accounts: int|null, error: string|null}> $shards */
?>
<section class="admin-card admin-section">
    <h2 class="admin-subtitle">Базы данных (лимит хостинга 4 ГБ на базу)</h2>
    <table class="admin-table">
        <thead><tr><th>База</th><th>Имя</th><th>Размер</th><th>Аккаунтов</th><th>Состояние</th></tr></thead>
        <tbody>
<?php foreach ($shards as $s) : ?>
            <tr>
                <td>DB<?= e((string) $s['shard']) ?></td>
                <td><?= e($s['name'] !== '' ? $s['name'] : '—') ?></td>
                <td><?= e($s['bytes'] !== null ? ShardStats::human($s['bytes']) : '—') ?></td>
                <td><?= e($s['accounts'] !== null ? (string) $s['accounts'] : '—') ?></td>
<?php if ($s['error'] !== null) : ?>
                <td><strong class="admin-error"><?= e($s['error']) ?></strong></td>
<?php elseif ($s['bytes'] !== null && $s['bytes'] >= ShardStats::WARN_BYTES) : ?>
                <td><strong class="admin-error">больше 3 ГБ: срочно чистить, на 4 ГБ хостинг удалит базу</strong></td>
<?php else : ?>
                <td>норма</td>
<?php endif; ?>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</section>
