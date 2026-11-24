<?php

declare(strict_types=1);

/** @var list<array{slug: string, title: string, cover: string, date: string, excerpt: string}> $news */
/** @var int $page */
/** @var int $pages */
?>
<section class="container section">
    <h1 class="page-title">Новости</h1>
<?php if ($news === []) : ?>
    <div class="card empty-card"><p class="muted">Новостей пока нет.</p></div>
<?php else : ?>
    <div class="news-list">
<?php foreach ($news as $item) : ?>
        <article class="card news-card news-card-row">
<?php if ($item['cover'] !== '') : ?>
            <img class="news-cover" src="/uploads/<?= e($item['cover']) ?>" alt="" loading="lazy">
<?php endif; ?>
            <div class="news-body">
                <h2 class="news-title"><a href="/news/<?= e(rawurlencode($item['slug'])) ?>"><?= e($item['title']) ?></a></h2>
                <p class="news-date"><?= e($item['date']) ?></p>
                <p class="news-excerpt"><?= e($item['excerpt']) ?></p>
            </div>
        </article>
<?php endforeach; ?>
    </div>
<?php if ($pages > 1) : ?>
    <nav class="pagination" aria-label="Страницы новостей">
<?php if ($page > 1) : ?>
        <a class="btn btn-ghost" href="<?= e($page - 1 === 1 ? '/news' : '/news?page=' . ($page - 1)) ?>" rel="prev">← Назад</a>
<?php endif; ?>
        <span class="pagination-info">Страница <?= e((string) $page) ?> из <?= e((string) $pages) ?></span>
<?php if ($page < $pages) : ?>
        <a class="btn btn-ghost" href="/news?page=<?= e((string) ($page + 1)) ?>" rel="next">Вперёд →</a>
<?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
</section>
