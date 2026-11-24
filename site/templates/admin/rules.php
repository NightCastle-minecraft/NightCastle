<?php

declare(strict_types=1);

/** @var list<array{id: int, title: string, number: string, items: list<array{id: int, body: string, html: string, number: string}>}> $sections */
/** @var string $csrf */
?>
<h1 class="admin-title">Правила</h1>
<?php foreach ($sections as $s) : ?>
<section class="admin-card admin-rule-section" id="section-<?= (int) $s['id'] ?>">
    <div class="admin-rule-head">
        <h2 class="admin-subtitle"><?= $s['number'] !== '' ? e($s['number']) . '. ' : '' ?><?= e($s['title']) ?></h2>
        <div class="admin-rule-tools">
            <form method="post" action="/admin/rules/sections/<?= (int) $s['id'] ?>/move" class="admin-inline-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="admin-btn admin-btn--ghost" type="submit" name="direction" value="up" aria-label="Секцию выше">↑</button>
                <button class="admin-btn admin-btn--ghost" type="submit" name="direction" value="down" aria-label="Секцию ниже">↓</button>
            </form>
            <a class="admin-btn admin-btn--ghost" href="/admin/rules/sections/<?= (int) $s['id'] ?>/delete">Удалить секцию</a>
        </div>
    </div>
    <form method="post" action="/admin/rules/sections/<?= (int) $s['id'] ?>" class="admin-inline-form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="admin-label" for="rename-<?= (int) $s['id'] ?>">Название секции</label>
        <input class="admin-input" id="rename-<?= (int) $s['id'] ?>" name="title" type="text" maxlength="255" value="<?= e($s['title']) ?>">
        <button class="admin-btn" type="submit">Переименовать</button>
    </form>
<?php if ($s['items'] === []) : ?>
    <p class="admin-muted">Пунктов нет, на сайте секция скрыта.</p>
<?php endif; ?>
<?php foreach ($s['items'] as $it) : ?>
    <div class="admin-rule-item">
        <div class="admin-rule-num"><?= e($it['number']) ?></div>
        <div class="admin-rule-body">
            <div class="admin-preview-body"><?= $it['html'] /* Markdown::render: сырой HTML экранирован */ ?></div>
            <div class="admin-rule-tools">
                <a class="admin-btn admin-btn--ghost" href="/admin/rules/items/<?= (int) $it['id'] ?>/edit">Редактировать</a>
                <form method="post" action="/admin/rules/items/<?= (int) $it['id'] ?>/move" class="admin-inline-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="admin-btn admin-btn--ghost" type="submit" name="direction" value="up" aria-label="Пункт выше">↑</button>
                    <button class="admin-btn admin-btn--ghost" type="submit" name="direction" value="down" aria-label="Пункт ниже">↓</button>
                </form>
                <a class="admin-btn admin-btn--ghost" href="/admin/rules/items/<?= (int) $it['id'] ?>/delete">Удалить</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
    <form method="post" action="/admin/rules/sections/<?= (int) $s['id'] ?>/items" class="admin-form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="admin-label" for="add-<?= (int) $s['id'] ?>">Новый пункт (Markdown)</label>
        <textarea class="admin-input admin-textarea" id="add-<?= (int) $s['id'] ?>" name="body_md" rows="3"></textarea>
        <div class="admin-actions">
            <button class="admin-btn" type="submit">Добавить пункт</button>
        </div>
    </form>
</section>
<?php endforeach; ?>
<?php if ($sections === []) : ?>
<p class="admin-muted">Секций пока нет.</p>
<?php endif; ?>
<form method="post" action="/admin/rules/sections" class="admin-card admin-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <h2 class="admin-subtitle">Новая секция</h2>
    <label class="admin-label" for="new-section">Название</label>
    <input class="admin-input" id="new-section" name="title" type="text" maxlength="255">
    <div class="admin-actions">
        <button class="admin-btn" type="submit">Создать секцию</button>
    </div>
</form>
