<?php

declare(strict_types=1);

/** @var string $serverName */
/** @var string $heroBg */
/** @var string $slogan */
/** @var string $ip */
/** @var string $aboutTitle */
/** @var string $aboutHtml */
/** @var list<array{slug: string, title: string, cover: string, date: string, excerpt: string}> $news */
/** @var string $gameVersion */
/** @var string $modpackUrl */
/** @var string $discordUrl */
/** @var list<string> $screenshots */
?>
<section class="hero<?= $heroBg !== '' ? ' hero--has-bg' : '' ?>">
<?php if ($heroBg !== '') : ?>
    <img class="hero-bg" src="/uploads/<?= e($heroBg) ?>" alt="" loading="eager" decoding="async">
<?php endif; ?>
    <div class="container hero-inner">
        <div class="hero-text">
            <h1 class="hero-title"><?= e($serverName) ?></h1>
<?php if ($slogan !== '') : ?>
            <p class="hero-slogan"><?= e($slogan) ?></p>
<?php endif; ?>
            <div class="hero-actions">
<?php if ($ip !== '') : ?>
                <button class="btn btn-primary" type="button" data-copy-text="<?= e($ip) ?>">
                    <span data-copy-label>Скопировать IP</span>
                </button>
<?php endif; ?>
                <a class="btn btn-ghost" href="/start">Как начать</a>
            </div>
<?php if ($ip !== '') : ?>
            <p class="hero-ip">IP сервера: <code><?= e($ip) ?></code></p>
<?php endif; ?>
        </div>
<?php if ($aboutHtml !== '') : ?>
        <article class="card about">
            <h2><?= e($aboutTitle) ?></h2>
            <div class="prose"><?= $aboutHtml /* Markdown::render() */ ?></div>
        </article>
<?php endif; ?>
    </div>
</section>

<?php if ($news !== []) : ?>
<section class="container section" aria-labelledby="news-title">
    <div class="section-head">
        <h2 id="news-title">Последние новости</h2>
        <a class="link-more" href="/news">Все новости</a>
    </div>
    <div class="news-grid">
<?php foreach ($news as $item) : ?>
        <article class="card news-card">
<?php if ($item['cover'] !== '') : ?>
            <img class="news-cover" src="/uploads/<?= e($item['cover']) ?>" alt="" loading="lazy">
<?php endif; ?>
            <div class="news-body">
                <h3><a href="/news/<?= e(rawurlencode($item['slug'])) ?>"><?= e($item['title']) ?></a></h3>
                <p class="news-date"><?= e($item['date']) ?></p>
                <p class="news-excerpt"><?= e($item['excerpt']) ?></p>
            </div>
        </article>
<?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="container section" aria-label="Информация о сервере">
    <div class="info-grid">
        <div class="card info-card">
            <h3>Версия игры</h3>
            <p class="info-value"><?= e($gameVersion !== '' ? $gameVersion : '—') ?></p>
        </div>
        <div class="card info-card">
            <h3>Моды</h3>
<?php if ($modpackUrl !== '') : ?>
            <p><a class="btn btn-ghost" href="<?= e($modpackUrl) ?>" target="_blank" rel="noopener noreferrer">Скачать сборку</a></p>
<?php else : ?>
            <p class="info-value">Не нужны</p>
            <p class="muted">Чистая игра, без модов</p>
<?php endif; ?>
        </div>
        <div class="card info-card">
            <h3>Discord</h3>
<?php if ($discordUrl !== '') : ?>
            <p><a class="btn btn-ghost" href="<?= e($discordUrl) ?>" target="_blank" rel="noopener noreferrer">Перейти в Discord</a></p>
<?php else : ?>
            <p class="info-value muted">скоро</p>
<?php endif; ?>
        </div>
    </div>
</section>

<?php if ($screenshots !== []) : ?>
<?php $shotsTotal = count($screenshots); ?>
<section class="container section" aria-labelledby="gallery-title">
    <div class="section-head">
        <h2 id="gallery-title">Скриншоты</h2>
    </div>
    <div class="gallery" data-gallery>
        <ul class="gallery-track" data-gallery-track>
<?php foreach ($screenshots as $i => $shotUrl) : ?>
            <li class="gallery-slide" data-gallery-slide>
                <a class="gallery-link" href="<?= e($shotUrl) ?>" target="_blank" rel="noopener" draggable="false">
                    <img class="gallery-img" src="<?= e($shotUrl) ?>" alt="Скриншот <?= e((string) ($i + 1)) ?> из <?= e((string) $shotsTotal) ?>" loading="lazy" decoding="async" draggable="false">
                </a>
            </li>
<?php endforeach; ?>
        </ul>
        <div class="gallery-controls" data-gallery-controls hidden>
            <button class="gallery-nav" type="button" data-gallery-prev aria-label="Предыдущий скриншот">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4-4.6-4.6z"/></svg>
            </button>
            <span class="gallery-counter" data-gallery-counter aria-live="polite"></span>
            <button class="gallery-nav" type="button" data-gallery-next aria-label="Следующий скриншот">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8.6 16.6 10 18l6-6-6-6-1.4 1.4 4.6 4.6z"/></svg>
            </button>
        </div>
    </div>
</section>
<?php endif; ?>
