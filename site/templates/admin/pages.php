<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $pages */
?>
<h1 class="admin-title">Страницы</h1>
<section class="admin-card">
    <ul class="admin-list">
<?php foreach ($pages as $p) : ?>
        <li><a class="admin-link" href="/admin/pages/<?= e((string) $p['slug']) ?>"><?= e((string) $p['title']) ?></a> <span class="admin-muted">(<?= e((string) $p['slug']) ?>)</span></li>
<?php endforeach; ?>
    </ul>
</section>
