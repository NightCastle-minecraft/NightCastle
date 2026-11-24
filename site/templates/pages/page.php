<?php

declare(strict_types=1);

/** @var string $heading */
/** @var string $bodyHtml */
?>
<article class="container section article">
    <div class="card article-card">
        <h1 class="article-title"><?= e($heading) ?></h1>
        <div class="prose"><?= $bodyHtml /* Markdown::render() */ ?></div>
    </div>
</article>
