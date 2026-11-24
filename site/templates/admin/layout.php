<?php

declare(strict_types=1);

use SiteGate\Settings;

/** @var string $title */
/** @var string $content */
/** @var array{id: int, username: string}|null $admin */
/** @var string|null $csrf */
/** @var string|null $flash */
/** @var string|null $navPath */

$admin ??= null;
$csrf ??= '';
$flash ??= null;
$navPath ??= '';
$nav = [
    '/admin' => 'Дашборд',
    '/admin/players' => 'Игроки',
    '/admin/shop' => 'Магазин',
    '/admin/news' => 'Новости',
    '/admin/rules' => 'Правила',
    '/admin/pages' => 'Страницы',
    '/admin/settings' => 'Настройки',
    '/admin/password' => 'Пароль',
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title . ' · ' . Settings::get('server_name', 'MyServer') . ' admin') ?></title>
    <meta name="theme-color" content="#0b0d14">
    <link rel="icon" href="<?= e(asset('/favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('/assets/img/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(asset('/assets/img/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/main.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/admin.css')) ?>">
</head>
<body class="admin-body">
<?php if ($admin !== null) : ?>
<header class="admin-header">
    <nav class="admin-nav" aria-label="Админка">
<?php foreach ($nav as $href => $label) : ?>
        <a class="admin-nav-link<?= ($navPath === $href || ($href !== '/admin' && str_starts_with($navPath, $href . '/'))) ? ' is-active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
        <a class="admin-nav-link" href="/">Открыть сайт</a>
        <form method="post" action="/admin/logout" class="admin-nav-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="admin-nav-link admin-nav-button" type="submit">Выйти (<?= e($admin['username']) ?>)</button>
        </form>
    </nav>
</header>
<?php endif; ?>
<main class="admin-main<?= $admin !== null ? ' admin-main--wide' : '' ?>" id="main">
<?php if ($flash !== null) : ?>
    <p class="admin-flash" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
