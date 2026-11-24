<?php

declare(strict_types=1);

use SiteGate\ServerStatus;
use SiteGate\Settings;

/** @var string $title */
/** @var string $content */

$siteName = Settings::get('server_name', 'MyServer');
$ip = Settings::get('display_ip');
$bannerText = trim(Settings::get('banner_text'));
$bannerOn = Settings::get('banner_enabled') === '1' && $bannerText !== '';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '';
$nav = [
    '/news' => 'Новости',
    '/rules' => 'Правила',
    '/start' => 'Как начать',
    '/shop' => 'Магазин',
    '/account' => 'Кабинет',
];
// Онлайн из пульса плагина SiteGateAuth (одна строка в DB1)
$status = ServerStatus::get();
if (($status['updatedAt'] ?? null) === null) {
    $statusState = 'unknown';
    $statusText = 'нет данных';
    $statusCount = '';
} elseif (!empty($status['online'])) {
    $statusState = 'online';
    $statusText = 'ONLINE';
    $players = $status['players'] ?? null;
    $maxPlayers = $status['maxPlayers'] ?? null;
    $statusCount = $players === null ? '' : ((int) $players) . ($maxPlayers === null ? '' : '/' . ((int) $maxPlayers));
} else {
    $statusState = 'offline';
    $statusText = 'OFFLINE';
    $statusCount = '';
}
$pageTitle = (string) $title === $siteName ? $siteName : $title . ' · ' . $siteName;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="theme-color" content="#0b0d14">
    <link rel="icon" href="<?= e(asset('/favicon.ico')) ?>" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('/assets/img/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(asset('/assets/img/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('/apple-touch-icon.png')) ?>">
    <link rel="preload" href="/assets/fonts/inter-latin-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/main.css')) ?>">
    <script src="<?= e(asset('/assets/js/main.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">К содержимому</a>
<?php if ($bannerOn) : ?>
<div class="banner" role="region" aria-label="Объявление">
    <p class="banner-text"><?= e($bannerText) ?></p>
</div>
<?php endif; ?>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="/">
            <svg class="brand-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 21V9l3 2V7l3 2V5l3 2 3-2v4l3-2v4l3-2v12h-6v-5a3 3 0 0 0-6 0v5H3z"/></svg>
            <span class="brand-name"><?= e($siteName) ?></span>
        </a>
        <span class="chip chip-status" data-status-chip data-state="<?= e($statusState) ?>">
            <span class="chip-dot" aria-hidden="true"></span>
            <span class="chip-text" data-status-text><?= e($statusText) ?></span>
            <span class="chip-count" data-status-count><?= e($statusCount) ?></span>
        </span>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-menu" data-nav-toggle>
            <span class="nav-toggle-bars" aria-hidden="true"></span>
            <span class="sr-only">Меню</span>
        </button>
        <div class="site-menu" id="site-menu" data-nav-menu>
<?php if ($ip !== '') : ?>
            <button class="chip chip-ip" type="button" data-copy-text="<?= e($ip) ?>" aria-label="Скопировать IP: <?= e($ip) ?>">
                <span class="chip-label">IP</span>
                <code class="chip-ip-value"><?= e($ip) ?></code>
                <svg class="icon-copy" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>
                <span class="chip-copied" data-copy-label>Скопировано</span>
            </button>
<?php endif; ?>
            <nav class="main-nav" aria-label="Основная навигация">
                <ul>
<?php foreach ($nav as $href => $label) : ?>
<?php $active = $path === $href || str_starts_with($path, $href . '/'); ?>
                    <li><a href="<?= e($href) ?>"<?= $active ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a></li>
<?php endforeach; ?>
                </ul>
            </nav>
        </div>
    </div>
</header>
<span class="sr-only" role="status" aria-live="polite" data-copy-live></span>
<main id="main">
<?= $content /* уже отрендеренный шаблон страницы */ ?>
</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <nav class="footer-nav" aria-label="Ссылки в подвале">
            <a href="/">Главная</a>
<?php foreach ($nav as $href => $label) : ?>
            <a href="<?= e($href) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
        </nav>
        <p class="footer-copy">© <?= e($siteName) ?>. Копирование материалов без разрешения запрещено. <?= e(date('Y')) ?></p>
    </div>
</footer>
</body>
</html>
