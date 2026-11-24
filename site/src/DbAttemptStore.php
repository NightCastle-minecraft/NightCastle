<?php

declare(strict_types=1);

namespace SiteGate;

final class DbAttemptStore implements AttemptStore
{
    /**
     * login_attempts: вход в админку; player_attempts: вход игроков (ключ «ip|ник»);
     * player_registrations: регистрации с IP; mail_attempts: письма (повтор подтверждения, сброс пароля).
     * Таблицы раздельные, потому что у лимитов разные окна,
     * а RateLimit чистит свою таблицу по своему окну.
     */
    private const TABLES = ['login_attempts', 'player_attempts', 'player_registrations', 'mail_attempts'];

    public function __construct(private string $table = 'login_attempts')
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Неизвестная таблица попыток: ' . $table);
        }
    }

    public function countSince(string $ip, string $sinceUtc): int
    {
        $row = Db::one("SELECT COUNT(*) AS c FROM {$this->table} WHERE ip = ? AND attempted_at > ?", [$ip, $sinceUtc]);
        return $row === null ? 0 : (int) $row['c'];
    }

    public function add(string $ip, string $atUtc): void
    {
        Db::pdo()->prepare("INSERT INTO {$this->table} (ip, attempted_at) VALUES (?, ?)")->execute([$ip, $atUtc]);
    }

    public function clear(string $ip): void
    {
        Db::pdo()->prepare("DELETE FROM {$this->table} WHERE ip = ?")->execute([$ip]);
    }

    public function purgeBefore(string $beforeUtc): void
    {
        Db::pdo()->prepare("DELETE FROM {$this->table} WHERE attempted_at <= ?")->execute([$beforeUtc]);
    }
}
