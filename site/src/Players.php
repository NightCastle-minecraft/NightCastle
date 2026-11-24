<?php

declare(strict_types=1);

namespace SiteGate;

/** Аккаунты игроков в трёх базах (D14). Каждый ник живёт в базе Shard::forName(ник). */
final class Players
{
    /** IP-сессия: столько дней игрок заходит в игру без подтверждения на сайте. */
    public const SESSION_DAYS = 7;
    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 128;

    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]{3,16}$/D', $name) === 1;
    }

    /** Текст ошибки пароля или null, если пароль годится. */
    public static function passwordError(string $password, string $repeat, string $name): ?string
    {
        $len = mb_strlen($password);
        if ($len < self::PASSWORD_MIN) {
            return 'Пароль слишком короткий: минимум ' . self::PASSWORD_MIN . ' символов.';
        }
        if ($len > self::PASSWORD_MAX) {
            return 'Пароль слишком длинный: максимум ' . self::PASSWORD_MAX . ' символов.';
        }
        if (strtolower($password) === strtolower($name)) {
            return 'Пароль не должен совпадать с ником.';
        }
        if ($password !== $repeat) {
            return 'Пароли не совпадают.';
        }
        return null;
    }

    /** @return array<string, mixed>|null аккаунт + ключ shard */
    public static function find(string $name): ?array
    {
        if (!self::validName($name)) {
            return null;
        }
        $shard = Shard::forName($name);
        $row = Db::shardOne($shard, 'SELECT * FROM accounts WHERE name_lower = ?', [strtolower($name)]);
        return $row === null ? null : $row + ['shard' => $shard];
    }

    /** @return array<string, mixed>|null */
    public static function byId(int $shard, int $id): ?array
    {
        $row = Db::shardOne($shard, 'SELECT * FROM accounts WHERE id = ?', [$id]);
        return $row === null ? null : $row + ['shard' => $shard];
    }

    /**
     * Регистрация. Если ник уже занят (гонка двух вкладок), вернёт null.
     *
     * @return array<string, mixed>|null
     */
    public static function create(string $name, string $password, string $ip, string $email): ?array
    {
        $shard = Shard::forName($name);
        try {
            Db::shardExec(
                $shard,
                'INSERT INTO accounts (name, name_lower, password_hash, created_at, created_ip, email)
                 VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?)',
                [$name, strtolower($name), password_hash($password, PASSWORD_DEFAULT), $ip, $email]
            );
        } catch (\PDOException $ex) {
            if ((string) $ex->getCode() === '23000') {
                return null;
            }
            throw $ex;
        }
        return self::find($name);
    }

    /** @param array<string, mixed> $account */
    public static function checkPassword(array $account, string $password): bool
    {
        $hash = (string) $account['password_hash'];
        if (!password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            self::setPassword((int) $account['shard'], (int) $account['id'], $password);
        }
        return true;
    }

    /** Для несуществующего ника тоже тратим время на хеш, чтобы не выдавать его по времени ответа. */
    public static function burnPasswordTime(string $password): void
    {
        // Хеш с той же ценой, что у password_verify настоящего аккаунта
        password_hash($password, PASSWORD_DEFAULT);
    }

    public static function setPassword(int $shard, int $id, string $password): void
    {
        Db::shardExec($shard, 'UPDATE accounts SET password_hash = ? WHERE id = ?', [
            password_hash($password, PASSWORD_DEFAULT),
            $id,
        ]);
    }

    /** Почта нормализуется: пробелы по краям прочь, домен в нижний регистр. null, если адрес кривой. */
    public static function normalizeEmail(string $email): ?string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $email = substr($email, 0, $at) . '@' . strtolower(substr($email, $at + 1));
        return Mailer::validAddress($email) ? $email : null;
    }

    /** Новая почта: отметка о подтверждении сбрасывается. */
    public static function setEmail(int $shard, int $id, string $email): void
    {
        Db::shardExec($shard, 'UPDATE accounts SET email = ?, email_verified_at = NULL WHERE id = ?', [$email, $id]);
    }

    /** Живая IP-сессия для входа в игру без подтверждения. */
    public static function hasSession(int $shard, int $accountId, string $ip): bool
    {
        return Db::shardOne(
            $shard,
            'SELECT 1 FROM ip_sessions WHERE account_id = ? AND ip = ? AND expires_at > UTC_TIMESTAMP()',
            [$accountId, $ip]
        ) !== null;
    }

    /** Создать или продлить IP-сессию на SESSION_DAYS и отметить вход. */
    public static function touchSession(int $shard, int $accountId, string $ip): void
    {
        $until = gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400);
        Db::shardExec(
            $shard,
            'INSERT INTO ip_sessions (account_id, ip, expires_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)',
            [$accountId, $ip, $until]
        );
        Db::shardExec($shard, 'UPDATE accounts SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$accountId]);
    }

    /** «Выйти на всех устройствах»: гасит IP-сессии игры и все браузерные сессии сайта. */
    public static function resetSessions(int $shard, int $accountId): void
    {
        Db::shardExec($shard, 'DELETE FROM ip_sessions WHERE account_id = ?', [$accountId]);
        Db::shardExec($shard, 'UPDATE accounts SET sessions_reset_at = UTC_TIMESTAMP() WHERE id = ?', [$accountId]);
    }

    public static function liveSessions(int $shard, int $accountId): int
    {
        $row = Db::shardOne(
            $shard,
            'SELECT COUNT(*) AS c FROM ip_sessions WHERE account_id = ? AND expires_at > UTC_TIMESTAMP()',
            [$accountId]
        );
        return $row === null ? 0 : (int) $row['c'];
    }

    /** Случайный пароль для сброса из админки: без похожих символов. */
    public static function randomPassword(int $length = 14): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
