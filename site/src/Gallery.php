<?php

declare(strict_types=1);

namespace SiteGate;

/**
 * Скриншоты для карусели на главной.
 *
 * Файлы лежат в папке на сервере (SCREENSHOTS_DIR в .env), она смонтирована read-only
 * в php (для списка) и в caddy (раздаёт /screenshots/<имя>). PHP только читает имена.
 */
final class Gallery
{
    public const DEFAULT_DIR = '/srv/screenshots';
    public const MAX_ITEMS = 60;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromEnv(): self
    {
        $dir = getenv('SCREENSHOTS_PATH');
        return new self(is_string($dir) && $dir !== '' ? $dir : self::DEFAULT_DIR);
    }

    /**
     * Имена картинок в естественном порядке без учёта регистра (2.png раньше 10.png),
     * не больше MAX_ITEMS. Нет папки или она пустая: пустой список.
     *
     * @return list<string>
     */
    public function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $names = @scandir($this->dir);
        if ($names === false) {
            return [];
        }
        $files = [];
        foreach ($names as $name) {
            if (self::isImageName($name) && is_file($this->dir . '/' . $name)) {
                $files[] = $name;
            }
        }
        usort($files, 'strnatcasecmp');
        return array_slice($files, 0, self::MAX_ITEMS);
    }

    /**
     * Допустимое имя: валидный UTF-8, не скрытый файл, без слешей и управляющих символов,
     * до 200 символов плюс расширение jpg, jpeg, png или webp.
     */
    public static function isImageName(string $name): bool
    {
        return preg_match('/^[^.\/\\\\\x00-\x1f\x7f][^\/\\\\\x00-\x1f\x7f]{0,199}\.(?:jpe?g|png|webp)$/iu', $name) === 1;
    }

    /**
     * Публичный URL файла (Caddy раздаёт папку по /screenshots/).
     */
    public static function url(string $name): string
    {
        return '/screenshots/' . rawurlencode($name);
    }
}
