<?php

declare(strict_types=1);

namespace SiteGate;

use DateTimeImmutable;
use DateTimeZone;

final class Format
{
    private const MONTHS = [
        1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
    ];

    /**
     * Дата из БД (UTC) в виде «18 ноября 2026» по Europe/Kyiv.
     */
    public static function date(string $utc): string
    {
        $dt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Kyiv'));
        return $dt->format('j') . ' ' . self::MONTHS[(int) $dt->format('n')] . ' ' . $dt->format('Y');
    }

    /**
     * Внешняя ссылка допустима только с http/https, иначе пустая строка.
     */
    public static function httpUrl(string $url): string
    {
        return preg_match('#^https?://#i', trim($url)) === 1 ? trim($url) : '';
    }

    /**
     * Имя загруженного файла (32 hex + расширение) или пустая строка.
     * Публичный URL: /uploads/<имя>.
     */
    public static function uploadName(mixed $name): string
    {
        return is_string($name) && preg_match('/^[a-f0-9]{32}\.(jpe?g|png|webp)$/', $name) === 1 ? $name : '';
    }
}
