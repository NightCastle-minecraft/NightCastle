<?php

declare(strict_types=1);

namespace SiteGate;

/** Генерация slug из заголовка (транслит ru → lat). */
final class Slug
{
    public const MAX = 190;
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'є' => 'ye', 'і' => 'i', 'ї' => 'yi', 'ґ' => 'g',
    ];

    public static function make(string $text): string
    {
        $s = strtr(mb_strtolower($text, 'UTF-8'), self::MAP);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > self::MAX) {
            $s = rtrim(substr($s, 0, self::MAX), '-');
        }
        return $s;
    }

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX && preg_match(self::PATTERN, $slug) === 1;
    }
}
