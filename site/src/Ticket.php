<?php

declare(strict_types=1);

namespace SiteGate;

/**
 * Тикет входа из игры: «<номер базы><43 символа base64url>» (32 случайных байта).
 * В базе лежит только sha256 тикета.
 */
final class Ticket
{
    public const TTL = 600;

    public static function generate(int $shard): string
    {
        if ($shard < 1 || $shard > Db::SHARDS) {
            throw new \InvalidArgumentException('shard');
        }
        return $shard . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Номер базы из тикета или null, если тикет кривой. */
    public static function shardOf(string $ticket): ?int
    {
        return preg_match('/^([1-3])[A-Za-z0-9_-]{43}$/D', $ticket, $m) === 1 ? (int) $m[1] : null;
    }

    public static function hash(string $ticket): string
    {
        return hash('sha256', $ticket);
    }
}
