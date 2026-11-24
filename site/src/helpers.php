<?php

declare(strict_types=1);

/**
 * Экранирование вывода для HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Адрес файла из public/assets с версией по содержимому: /assets/css/main.css?v=1a2b3c4d.
 * Caddy разрешает браузеру кешировать /assets на неделю, а с версией в адресе
 * новый CSS или JS подхватывается сразу после выката.
 */
function asset(string $path): string
{
    /** @var array<string, string> $cache */
    static $cache = [];
    if (!isset($cache[$path])) {
        $file = dirname(__DIR__) . '/public' . $path;
        $hash = is_file($file) ? substr(hash_file('sha256', $file) ?: '', 0, 10) : '';
        $cache[$path] = $path . ($hash !== '' ? '?v=' . $hash : '');
    }
    return $cache[$path];
}
