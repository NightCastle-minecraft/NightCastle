<?php

declare(strict_types=1);

namespace SiteGate;

use PDO;

/**
 * Три базы на хостинге (D14). DB1 хранит таблицы сайта и свою долю аккаунтов, DB2 и DB3 только аккаунты.
 * Настройки подключения: DB{n}_HOST, DB{n}_PORT, DB{n}_NAME, DB{n}_USER, DB{n}_PASSWORD (или DB{n}_PASSWORD_FILE).
 */
final class Db
{
    public const SHARDS = 3;

    /** @var array<int, PDO> */
    private static array $pdo = [];

    /** База сайта (DB1). */
    public static function pdo(): PDO
    {
        return self::shard(1);
    }

    public static function shard(int $n): PDO
    {
        if ($n < 1 || $n > self::SHARDS) {
            throw new \InvalidArgumentException('Нет базы с номером ' . $n);
        }
        if (!isset(self::$pdo[$n])) {
            $p = 'DB' . $n . '_';
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get($p . 'HOST', 'localhost'),
                Env::get($p . 'PORT', '3306'),
                Env::get($p . 'NAME')
            );
            self::$pdo[$n] = new PDO($dsn, Env::get($p . 'USER'), Env::secret($p . 'PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                // Базы на чужом хостинге, до них ~56 мс: постоянное соединение экономит рукопожатие.
                // PDO сам проверяет, живо ли соединение, прежде чем отдать его снова.
                PDO::ATTR_PERSISTENT => Env::get('DB_PERSISTENT', '1') === '1',
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }
        return self::$pdo[$n];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::shardAll(1, $sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        return self::shardOne(1, $sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function shardAll(int $n, string $sql, array $params = []): array
    {
        $stmt = self::shard($n)->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function shardOne(int $n, string $sql, array $params = []): ?array
    {
        $stmt = self::shard($n)->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Выполнить запрос, вернуть число затронутых строк.
     *
     * @param array<int|string, mixed> $params
     */
    public static function shardExec(int $n, string $sql, array $params = []): int
    {
        $stmt = self::shard($n)->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
