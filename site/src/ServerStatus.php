<?php

declare(strict_types=1);

namespace SiteGate;

/** Онлайн сервера из пульса плагина SiteGateAuth (POST /api/server/heartbeat раз в 30 с). */
final class ServerStatus
{
    /** Пульса нет дольше двух минут: сервер считаем выключенным. */
    public const STALE = 120;

    public static function record(int $online, int $max): void
    {
        Db::pdo()->prepare(
            'REPLACE INTO server_status (id, online, max_players, updated_at) VALUES (1, ?, ?, UTC_TIMESTAMP())'
        )->execute([$online, $max]);
    }

    /** @return array{online: bool, players: int|null, maxPlayers: int|null, updatedAt: string|null} */
    public static function get(): array
    {
        try {
            $row = Db::one(
                'SELECT online, max_players, updated_at,
                        TIMESTAMPDIFF(SECOND, updated_at, UTC_TIMESTAMP()) AS age
                 FROM server_status WHERE id = 1'
            );
        } catch (\Throwable $ex) {
            error_log('ServerStatus: ' . $ex->getMessage());
            $row = null;
        }
        if ($row === null) {
            return ['online' => false, 'players' => null, 'maxPlayers' => null, 'updatedAt' => null];
        }
        $alive = (int) $row['age'] <= self::STALE;
        return [
            'online' => $alive,
            'players' => $alive ? (int) $row['online'] : null,
            'maxPlayers' => $alive ? (int) $row['max_players'] : null,
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
}
