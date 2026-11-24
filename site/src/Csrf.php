<?php

declare(strict_types=1);

namespace SiteGate;

/** CSRF-токен: один на сессию, лежит в $_SESSION (сессия должна быть уже запущена). */
final class Csrf
{
    public const FIELD = '_csrf';
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $t = $_SESSION[self::KEY] ?? null;
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $t;
        }
        return $t;
    }

    /** Готовое скрытое поле формы (значение — hex, дополнительно экранируется). */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $given): bool
    {
        $t = $_SESSION[self::KEY] ?? null;
        if (!is_string($t) || $t === '' || $given === null || $given === '') {
            return false;
        }
        return hash_equals($t, $given);
    }

    /** Проверка токена из текущего POST-запроса. */
    public static function verifyRequest(): bool
    {
        $given = $_POST[self::FIELD] ?? null;
        return self::verify(is_string($given) ? $given : null);
    }
}
