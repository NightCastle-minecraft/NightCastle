<?php

declare(strict_types=1);

namespace SiteGate;

/** Размер трёх баз для админки. Лимит хостинга 4 ГБ на базу, иначе её удаляют без предупреждения. */
final class ShardStats
{
    public const LIMIT_BYTES = 4 * 1024 ** 3;
    public const WARN_BYTES = 3 * 1024 ** 3;

    /** @return list<array{shard: int, name: string, bytes: int|null, accounts: int|null, error: string|null}> */
    public static function all(): array
    {
        $out = [];
        for ($n = 1; $n <= Db::SHARDS; $n++) {
            try {
                $size = Db::shardOne(
                    $n,
                    'SELECT DATABASE() AS db, COALESCE(SUM(data_length + index_length), 0) AS bytes
                     FROM information_schema.tables WHERE table_schema = DATABASE()'
                );
                $acc = Db::shardOne($n, 'SELECT COUNT(*) AS c FROM accounts');
                $out[] = [
                    'shard' => $n,
                    'name' => (string) ($size['db'] ?? ''),
                    'bytes' => (int) ($size['bytes'] ?? 0),
                    'accounts' => (int) ($acc['c'] ?? 0),
                    'error' => null,
                ];
            } catch (\Throwable $ex) {
                error_log("ShardStats DB{$n}: " . $ex->getMessage());
                $out[] = ['shard' => $n, 'name' => '', 'bytes' => null, 'accounts' => null, 'error' => 'база недоступна'];
            }
        }
        return $out;
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 2, ',', ' ') . ' ГБ';
        }
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 1, ',', ' ') . ' МБ';
        }
        return number_format($bytes / 1024, 0, ',', ' ') . ' КБ';
    }
}
