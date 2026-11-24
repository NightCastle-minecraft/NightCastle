<?php

declare(strict_types=1);

/** @var list<array{id: int, slug: string, title: string, status: string, pinned: bool, date: string}> $items */
$labels = ['published' => 'Опубликована', 'draft' => 'Черновик', 'scheduled' => 'Запланирована'];
?>
<h1 class="admin-title">Новости</h1>
<p><a class="admin-btn" href="/admin/news/new">Новая новость</a></p>
<section class="admin-card news-admin">
<?php if ($items === []) : ?>
    <p class="admin-muted">Новостей пока нет.</p>
<?php else : ?>
    <table class="admin-table">
        <thead>
            <tr><th>Заголовок</th><th>Статус</th><th>Закреп</th><th>Дата</th><th>Действия</th></tr>
        </thead>
        <tbody>
<?php foreach ($items as $n) : ?>
            <tr>
                <td><?= e($n['title']) ?></td>
                <td><?= e($labels[$n['status']] ?? '') ?></td>
                <td><?= $n['pinned'] ? 'да' : '' ?></td>
                <td><?= e($n['date']) ?></td>
                <td class="news-admin-actions">
                    <a class="admin-link" href="/admin/news/<?= e((string) $n['id']) ?>/edit">Редактировать</a>
                    <a class="admin-link" href="/admin/news/<?= e((string) $n['id']) ?>/delete">Удалить</a>
<?php if ($n['status'] === 'published') : ?>
                    <a class="admin-link" href="/news/<?= e(rawurlencode($n['slug'])) ?>" target="_blank" rel="noopener">Открыть на сайте</a>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
