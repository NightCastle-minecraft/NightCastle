<?php

declare(strict_types=1);

/** @var string $heading */
/** @var string $bodyHtml */
/** @var string $ip */
/** @var string $gameVersion */
/** @var string $modpackUrl */
/** @var string $discordUrl */
?>
<article class="container section article">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <div class="card article-card">
        <div class="prose steps"><?= $bodyHtml /* Markdown::render() */ ?></div>
    </div>

    <div class="start-grid">
<?php if ($ip !== '') : ?>
        <div class="card start-ip">
            <h2>Адрес сервера</h2>
            <button class="chip chip-ip chip-big" type="button" data-copy-text="<?= e($ip) ?>" aria-label="Скопировать IP: <?= e($ip) ?>">
                <code class="chip-ip-value"><?= e($ip) ?></code>
                <svg class="icon-copy" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>
                <span class="chip-copied" data-copy-label>Скопировано</span>
            </button>
<?php if ($gameVersion !== '') : ?>
            <p class="muted">Версия игры: <?= e($gameVersion) ?></p>
<?php endif; ?>
        </div>
<?php endif; ?>
        <div class="card start-links">
            <h2>Моды и сообщество</h2>
<?php if ($modpackUrl === '') : ?>
            <p class="muted">Моды не нужны: сервер на чистой игре, заходите с обычного клиента.</p>
<?php endif; ?>
            <div class="hero-actions">
<?php if ($modpackUrl !== '') : ?>
                <a class="btn btn-primary" href="<?= e($modpackUrl) ?>" target="_blank" rel="noopener noreferrer">Скачать сборку</a>
<?php endif; ?>
<?php if ($discordUrl !== '') : ?>
                <a class="btn btn-primary" href="<?= e($discordUrl) ?>" target="_blank" rel="noopener noreferrer">Перейти в Discord</a>
<?php else : ?>
                <span class="btn btn-ghost is-disabled">Discord: скоро</span>
<?php endif; ?>
            </div>
        </div>
    </div>
</article>
