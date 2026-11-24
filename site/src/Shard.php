<?php

declare(strict_types=1);

namespace SiteGate;

/** В какой из трёх баз живёт ник (D14). Правило общее с плагином, см. plugins/docs/auth-api.md. */
final class Shard
{
    public static function forName(string $name): int
    {
        return crc32(strtolower($name)) % Db::SHARDS + 1;
    }
}
