<?php

declare(strict_types=1);

/** @var string $heading */
/** @var string $cover */
/** @var string $date */
/** @var string $bodyHtml */
?>
<article class="container section article">
    <p><a class="link-more" href="/news">← Все новости</a></p>
    <div class="card article-card">
<?php if ($cover !== '') : ?>
        <img class="article-cover" src="/uploads/<?= e($cover) ?>" alt="">
<?php endif; ?>
        <h1 class="article-title"><?= e($heading) ?></h1>
        <p class="news-date"><?= e($date) ?></p>
        <div class="prose"><?= $bodyHtml /* Markdown::render() */ ?></div>
    </div>
</article>
