<?php

declare(strict_types=1);

/** @var array<string, list<array{id: int, title: string, price: int, html: string, available: bool, online: bool}>> $groups */
/** @var string|null $player */
/** @var int $balance */
/** @var string|null $flash */
/** @var string $csrf */
/** @var string $nonce */
?>
<section class="container section shop">
    <div class="section-head">
        <h1 class="page-title">Магазин</h1>
<?php if ($player !== null) : ?>
        <p class="shop-balance"><?= e($player) ?>: <strong><?= e((string) $balance) ?></strong> монет · <a href="/account">кабинет</a></p>
<?php else : ?>
        <p class="shop-balance"><a href="/login">Войдите</a>, чтобы покупать.</p>
<?php endif; ?>
    </div>
<?php if ($flash !== null) : ?>
    <p class="alert alert-ok" role="status"><?= e($flash) ?></p>
<?php endif; ?>
    <p class="muted">Покупки выдаются на сервере автоматически. Монеты начисляет администрация: напишите в Discord, как пополнить баланс.</p>

<?php if ($groups === []) : ?>
    <div class="card"><p class="muted">Товаров пока нет.</p></div>
<?php endif; ?>
<?php foreach ($groups as $category => $items) : ?>
    <h2 class="shop-category"><?= e($category) ?></h2>
    <div class="shop-grid">
<?php foreach ($items as $item) : ?>
        <article class="card shop-item">
            <h3><?= e($item['title']) ?></h3>
            <div class="prose"><?= $item['html'] /* Markdown::render() */ ?></div>
            <div class="shop-buy">
                <span class="shop-price"><?= e((string) $item['price']) ?> монет</span>
<?php if (!$item['available']) : ?>
                <span class="btn btn-ghost is-disabled">Временно недоступно</span>
<?php elseif ($player === null) : ?>
                <a class="btn btn-ghost" href="/login">Войти и купить</a>
<?php elseif ($balance < $item['price']) : ?>
                <span class="btn btn-ghost is-disabled">Не хватает монет</span>
<?php else : ?>
                <form method="post" action="/shop/buy/<?= e((string) $item['id']) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="nonce" value="<?= e($nonce) ?>">
                    <button class="btn btn-primary" type="submit">Купить</button>
                </form>
<?php endif; ?>
            </div>
<?php if ($item['online']) : ?>
            <p class="muted small">Выдаётся, когда вы в игре.</p>
<?php endif; ?>
        </article>
<?php endforeach; ?>
    </div>
<?php endforeach; ?>
</section>
