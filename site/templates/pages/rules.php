<?php

declare(strict_types=1);

/** @var list<array{number: string, anchor: string, title: string, rules: list<array{number: string, anchor: string, html: string}>}> $sections */
?>
<section class="container section">
    <h1 class="page-title">Правила сервера</h1>
<?php if ($sections === []) : ?>
    <div class="card empty-card"><p class="muted">Правила скоро появятся.</p></div>
<?php else : ?>
<?php foreach ($sections as $section) : ?>
    <section class="rules-section" aria-labelledby="<?= e($section['anchor']) ?>">
        <h2 id="<?= e($section['anchor']) ?>"><?= e($section['number']) ?>. <?= e($section['title']) ?></h2>
        <ol class="rules-list">
<?php foreach ($section['rules'] as $rule) : ?>
            <li class="rule card" id="<?= e($rule['anchor']) ?>">
                <span class="rule-number"><?= e($rule['number']) ?></span>
                <div class="rule-body prose"><?= $rule['html'] /* Markdown::render() */ ?></div>
                <button class="rule-copy" type="button" data-copy-anchor="<?= e($rule['anchor']) ?>" aria-label="Копировать ссылку на пункт <?= e($rule['number']) ?>" title="Копировать ссылку на пункт">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.9 12a3.1 3.1 0 0 1 3.1-3.1h4V7H7a5 5 0 0 0 0 10h4v-1.9H7A3.1 3.1 0 0 1 3.9 12zM8 13h8v-2H8v2zm9-6h-4v1.9h4a3.1 3.1 0 0 1 0 6.2h-4V17h4a5 5 0 0 0 0-10z"/></svg>
                    <span class="rule-copy-label" data-copy-label>Ссылка</span>
                </button>
            </li>
<?php endforeach; ?>
        </ol>
    </section>
<?php endforeach; ?>
<?php endif; ?>
</section>
