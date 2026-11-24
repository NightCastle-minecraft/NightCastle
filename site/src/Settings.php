<?php

declare(strict_types=1);

namespace SiteGate;

final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Все настройки одним запросом (кеш на время запроса).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $result = [];
            try {
                foreach (Db::all('SELECT name, value FROM settings') as $row) {
                    $result[(string) $row['name']] = (string) ($row['value'] ?? '');
                }
            } catch (\Throwable $ex) {
                // БД недоступна: отдаём дефолты, чтобы layout и страница 500 всё же отрисовались
                error_log('Settings: ' . $ex->getMessage());
                $result = [];
            }
            self::$cache = $result;
        }
        return self::$cache;
    }

    public static function get(string $name, string $default = ''): string
    {
        return self::all()[$name] ?? $default;
    }
}
