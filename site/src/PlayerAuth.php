<?php

declare(strict_types=1);

namespace SiteGate;

/** Вход игрока на сайт. Своя cookie, отдельно от админской. */
final class PlayerAuth
{
    public const SESSION_NAME = 'sg_player';
    private const KEY = 'player';

    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    public static function start(): void
    {
        Auth::startSession(null, self::SESSION_NAME);
    }

    /** @param array<string, mixed> $account */
    public static function login(array $account): void
    {
        session_regenerate_id(true);
        $_SESSION[self::KEY] = [
            'shard' => (int) $account['shard'],
            'id' => (int) $account['id'],
            'at' => time(),
        ];
        self::$current = null;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(self::SESSION_NAME, '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'secure' => (bool) $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_SESSION = [];
            session_destroy();
        }
        self::$current = null;
    }

    /**
     * Текущий игрок или null. Сессия гаснет, если после входа нажали «выйти на всех устройствах».
     *
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $s = $_SESSION[self::KEY] ?? null;
        if (!is_array($s) || !is_int($s['shard'] ?? null) || !is_int($s['id'] ?? null) || !is_int($s['at'] ?? null)) {
            return null;
        }
        $account = Players::byId($s['shard'], $s['id']);
        if ($account === null) {
            unset($_SESSION[self::KEY]);
            return null;
        }
        $reset = $account['sessions_reset_at'] ?? null;
        if (is_string($reset) && $reset !== '' && $s['at'] < strtotime($reset . ' UTC')) {
            unset($_SESSION[self::KEY]);
            return null;
        }
        return self::$current = $account;
    }

    /** Пометить текущую сессию свежей (после «выйти на всех устройствах» этот браузер остаётся). */
    public static function refresh(): void
    {
        if (is_array($_SESSION[self::KEY] ?? null)) {
            $_SESSION[self::KEY]['at'] = time() + 1;
        }
        self::$current = null;
    }

    /** Флеш-сообщение на следующую страницу. */
    public static function flash(string $message): void
    {
        $_SESSION['flash'] = $message;
    }

    public static function takeFlash(): ?string
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_string($f) ? $f : null;
    }
}
