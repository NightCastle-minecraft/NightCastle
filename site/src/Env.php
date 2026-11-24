<?php

declare(strict_types=1);

namespace SiteGate;

/** Конфиг из окружения. Секреты можно отдать файлом: NAME_FILE=/run/secrets/... (docker secret). */
final class Env
{
    public static function get(string $name, string $default = ''): string
    {
        $v = getenv($name);
        return is_string($v) && $v !== '' ? $v : $default;
    }

    public static function secret(string $name): string
    {
        $v = getenv($name);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        $file = getenv($name . '_FILE');
        if (is_string($file) && $file !== '' && is_file($file) && is_readable($file)) {
            return trim((string) file_get_contents($file));
        }
        return '';
    }

    /** Публичный адрес сайта без слэша в конце (для ссылок, которые уходят в игру). */
    public static function siteUrl(): string
    {
        return rtrim(self::get('SITE_URL', 'https://example.com'), '/');
    }

    /** Адрес сервера для игроков: GAME_ADDRESS из окружения, иначе домен сайта. */
    public static function gameAddress(): string
    {
        return self::get('GAME_ADDRESS', (string) parse_url(self::siteUrl(), PHP_URL_HOST));
    }
}
