<?php

declare(strict_types=1);

use SiteGate\View;

/** @var string $q */
/** @var list<array{shard: int, id: int, name: string, email: string, verified: bool, created: string, ip: string, last: string, playtime: string, sessions: int}> $results */
/** @var string|null $searchError */
/** @var list<array<string, mixed>> $shards */
/** @var string $csrf */
?>
<h1 class="admin-title">Игроки</h1>

<form method="get" action="/admin/players" class="admin-card admin-form">
    <label class="admin-label" for="f-q">Ник или его начало</label>
    <input class="admin-input" id="f-q" name="q" type="search" maxlength="16" value="<?= e($q) ?>" autocomplete="off">
    <button class="admin-btn" type="submit">Найти во всех трёх базах</button>
</form>

<?php if ($searchError !== null) : ?>
<p class="admin-error" role="alert"><?= e($searchError) ?></p>
<?php endif; ?>

<?php if ($q !== '' && $searchError === null) : ?>
<section class="admin-card admin-section">
<?php if ($results === []) : ?>
    <p class="admin-muted">Никого не нашли.</p>
<?php else : ?>
    <table class="admin-table">
        <thead><tr><th>Ник</th><th>Почта</th><th>База</th><th>Регистрация</th><th>IP регистрации</th><th>Последний вход</th><th>Наиграно</th><th>IP-сессий</th><th>Действия</th></tr></thead>
        <tbody>
<?php foreach ($results as $r) : ?>
<?php $base = '/admin/players/' . $r['shard'] . '/' . $r['id']; ?>
            <tr>
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><?= e($r['email'] !== '' ? $r['email'] : '—') ?><?= $r['email'] !== '' && !$r['verified'] ? ' (не подтв.)' : '' ?></td>
                <td>DB<?= e((string) $r['shard']) ?></td>
                <td><?= e($r['created']) ?></td>
                <td><?= e($r['ip']) ?></td>
                <td><?= e($r['last']) ?></td>
                <td><?= e($r['playtime']) ?></td>
                <td><?= e((string) $r['sessions']) ?></td>
                <td>
                    <form method="post" action="<?= e($base . '/sessions') ?>" class="admin-inline-form">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="admin-btn admin-btn--ghost" type="submit">Сбросить сессии</button>
                    </form>
                    <form method="post" action="<?= e($base . '/password') ?>" class="admin-inline-form">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="admin-btn admin-btn--ghost" type="submit">Новый пароль</button>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
<?php endif; ?>

<?= View::partial('admin/_shards', ['shards' => $shards]) ?>
