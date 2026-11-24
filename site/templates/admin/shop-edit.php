<?php

declare(strict_types=1);

/** @var int|null $id */
/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var string $csrf */

$field = static function (string $key, string $label, string $type = 'text', string $hint = '') use ($values, $errors): string {
    $html = '<label class="admin-label" for="f-' . e($key) . '">' . e($label) . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea class="admin-textarea" id="f-' . e($key) . '" name="' . e($key) . '" rows="8">' . e((string) ($values[$key] ?? '')) . '</textarea>';
    } else {
        $html .= '<input class="admin-input' . (isset($errors[$key]) ? ' is-invalid' : '') . '" id="f-' . e($key) . '" name="' . e($key)
            . '" type="' . e($type) . '" value="' . e((string) ($values[$key] ?? '')) . '">';
    }
    if ($hint !== '') {
        $html .= '<p class="admin-muted">' . e($hint) . '</p>';
    }
    if (isset($errors[$key])) {
        $html .= '<p class="admin-field-error" role="alert">' . e($errors[$key]) . '</p>';
    }
    return $html;
};
$action = $id === null ? '/admin/shop' : '/admin/shop/' . $id;
?>
<h1 class="admin-title"><?= $id === null ? 'Новый товар' : 'Товар' ?></h1>
<form method="post" action="<?= e($action) ?>" class="admin-card admin-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?= $field('title', 'Название *') ?>
    <?= $field('code', 'Код *', 'text', 'Как файл в плагине: plugins/SiteGateShop/products/<код>.yml. Там прописаны команды выдачи.') ?>
    <?= $field('category', 'Категория', 'text', 'Например: Привилегии, Ресурсы. Пусто = «Разное».') ?>
    <?= $field('price', 'Цена в монетах *', 'number') ?>
    <?= $field('description_md', 'Описание (Markdown)', 'textarea') ?>
    <?= $field('position', 'Порядок в категории', 'number') ?>
    <label class="admin-check"><input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?>> В продаже</label>
    <button class="admin-btn" type="submit">Сохранить</button>
</form>
<?php if ($id !== null) : ?>
<form method="post" action="/admin/shop/<?= e((string) $id) ?>/delete" class="admin-card admin-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <p class="admin-muted">Если по товару уже были заказы, он не удалится, а снимется с продажи.</p>
    <button class="admin-btn admin-btn--ghost" type="submit">Удалить товар</button>
</form>
<?php endif; ?>
