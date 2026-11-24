<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Db;
use SiteGate\Env;
use SiteGate\Players;
use SiteGate\Playtime;
use SiteGate\ServerStatus;
use SiteGate\Shard;
use SiteGate\Ticket;

/**
 * API для плагина SiteGateAuth. Контракт: plugins/docs/auth-api.md.
 * Токен: AUTH_API_TOKEN или AUTH_API_TOKEN_FILE. Пустой токен = API выключено (404).
 */
final class AuthApiController
{
    private const MAX_BODY = 1_048_576;

    public static function check(): void
    {
        $req = self::begin();
        if ($req === null) {
            return;
        }
        [$name, $ip] = self::nameAndIp($req);
        if ($name === null) {
            return;
        }
        self::guarded(static function () use ($name, $ip): void {
            $account = Players::find($name);
            if ($account === null) {
                self::json(200, ['status' => 'need_auth', 'registered' => false]);
                return;
            }
            $shard = (int) $account['shard'];
            $id = (int) $account['id'];
            if (Players::hasSession($shard, $id, $ip)) {
                Players::touchSession($shard, $id, $ip);
                self::json(200, ['status' => 'ok', 'registered' => true]);
                return;
            }
            self::json(200, ['status' => 'need_auth', 'registered' => true]);
        });
    }

    public static function createTicket(): void
    {
        $req = self::begin();
        if ($req === null) {
            return;
        }
        [$name, $ip] = self::nameAndIp($req);
        if ($name === null) {
            return;
        }
        self::guarded(static function () use ($name, $ip): void {
            $shard = Shard::forName($name);
            $lower = strtolower($name);
            // Старые неиспользованные тикеты ника гасим, просроченные больше суток чистим
            Db::shardExec($shard, "UPDATE auth_tickets SET status = 'expired' WHERE name_lower = ? AND status = 'pending'", [$lower]);
            Db::shardExec($shard, 'DELETE FROM auth_tickets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
            $ticket = Ticket::generate($shard);
            Db::shardExec(
                $shard,
                "INSERT INTO auth_tickets (hash, name, name_lower, ip, status, created_at, expires_at)
                 VALUES (?, ?, ?, ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL " . Ticket::TTL . ' SECOND)',
                [Ticket::hash($ticket), $name, $lower, $ip]
            );
            self::json(200, [
                'ticket' => $ticket,
                'url' => Env::siteUrl() . '/auth/' . $ticket,
                'expiresIn' => Ticket::TTL,
            ]);
        });
    }

    /** @param array<string, string> $params */
    public static function ticketStatus(array $params): void
    {
        if (self::begin(false) === null) {
            return;
        }
        $ticket = $params['ticket'] ?? '';
        $shard = Ticket::shardOf($ticket);
        if ($shard === null) {
            self::json(400, ['error' => 'bad_ticket']);
            return;
        }
        self::guarded(static function () use ($shard, $ticket): void {
            $row = Db::shardOne(
                $shard,
                'SELECT status, expires_at <= UTC_TIMESTAMP() AS is_expired FROM auth_tickets WHERE hash = ?',
                [Ticket::hash($ticket)]
            );
            $status = 'expired';
            if ($row !== null) {
                $status = (string) $row['status'];
                if ($status === 'pending' && (int) $row['is_expired'] === 1) {
                    $status = 'expired';
                }
            }
            self::json(200, ['status' => $status]);
        });
    }

    public static function sessions(): void
    {
        $req = self::begin();
        if ($req === null) {
            return;
        }
        $list = $req['sessions'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > 1000) {
            self::json(400, ['error' => 'bad_sessions']);
            return;
        }
        // Группируем по базам, чтобы ходить в каждую один раз
        $byShard = [];
        $now = time();
        foreach ($list as $s) {
            if (!is_array($s) || !is_string($s['name'] ?? null) || !Players::validName($s['name'])) {
                continue;
            }
            $span = Playtime::normalize($s['start'] ?? null, $s['end'] ?? null, $now);
            if ($span === null) {
                continue;
            }
            $byShard[Shard::forName($s['name'])][] = [strtolower($s['name']), $span[0], $span[1]];
        }
        self::guarded(static function () use ($byShard): void {
            $accepted = 0;
            foreach ($byShard as $shard => $items) {
                $accepted += self::storeSessions($shard, $items);
            }
            self::json(200, ['accepted' => $accepted]);
        });
    }

    public static function heartbeat(): void
    {
        $req = self::begin();
        if ($req === null) {
            return;
        }
        $online = $req['online'] ?? null;
        $max = $req['max'] ?? null;
        if (!is_int($online) || !is_int($max) || $online < 0 || $max < 0 || $online > 100000 || $max > 100000) {
            self::json(400, ['error' => 'bad_heartbeat']);
            return;
        }
        self::guarded(static function () use ($online, $max): void {
            ServerStatus::record($online, $max);
            self::json(200, new \stdClass());
        });
    }

    /**
     * Сессии одной базы. Повтор той же пары (аккаунт, start) не считается второй раз.
     *
     * @param list<array{0: string, 1: int, 2: int}> $items
     */
    private static function storeSessions(int $shard, array $items): int
    {
        $names = array_values(array_unique(array_column($items, 0)));
        $ids = [];
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        foreach (Db::shardAll($shard, "SELECT id, name_lower FROM accounts WHERE name_lower IN ($placeholders)", $names) as $row) {
            $ids[(string) $row['name_lower']] = (int) $row['id'];
        }
        $pdo = Db::shard($shard);
        $accepted = 0;
        foreach ($items as [$lower, $start, $end]) {
            $id = $ids[$lower] ?? null;
            if ($id === null) {
                continue; // ник без аккаунта
            }
            $pdo->beginTransaction();
            try {
                $new = Db::shardExec(
                    $shard,
                    'INSERT IGNORE INTO play_sessions (account_id, started_at, ended_at) VALUES (?, ?, ?)',
                    [$id, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end)]
                );
                if ($new === 1) {
                    Db::shardExec($shard, 'UPDATE accounts SET playtime_seconds = playtime_seconds + ? WHERE id = ?', [$end - $start, $id]);
                    $days = Playtime::splitByDay($start, $end);
                    $values = [];
                    $params = [];
                    foreach ($days as $day => $sec) {
                        $values[] = '(?, ?, ?)';
                        array_push($params, $id, $day, $sec);
                    }
                    Db::shardExec(
                        $shard,
                        'INSERT INTO playtime_daily (account_id, day, seconds) VALUES ' . implode(',', $values)
                        . ' ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds)',
                        $params
                    );
                    $accepted++;
                }
                $pdo->commit();
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
        }
        return $accepted;
    }

    /**
     * Общая часть: заголовки, токен, тело. null = ответ уже отправлен.
     *
     * @return array<string, mixed>|null
     */
    private static function begin(bool $withBody = true): ?array
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $token = Env::secret('AUTH_API_TOKEN');
        if ($token === '') {
            self::json(404, ['error' => 'not_found']);
            return null;
        }
        if (!hash_equals($token, self::bearer())) {
            self::json(401, ['error' => 'unauthorized']);
            return null;
        }
        if (!$withBody) {
            return [];
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            self::json(413, ['error' => 'too_large']);
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::json(400, ['error' => 'bad_json']);
            return null;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $req
     * @return array{0: string|null, 1: string}
     */
    private static function nameAndIp(array $req): array
    {
        $name = $req['name'] ?? null;
        $ip = $req['ip'] ?? null;
        if (!is_string($name) || !Players::validName($name)) {
            self::json(400, ['error' => 'bad_name']);
            return [null, ''];
        }
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            self::json(400, ['error' => 'bad_ip']);
            return [null, ''];
        }
        return [$name, $ip];
    }

    /** Токен из «Authorization: Bearer …». Apache кладёт заголовок в разные места. */
    public static function bearer(?array $server = null, ?array $headers = null): string
    {
        $server ??= $_SERVER;
        $value = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($value)) {
            $headers ??= function_exists('getallheaders') ? (getallheaders() ?: []) : [];
            foreach ($headers as $k => $v) {
                if (strtolower((string) $k) === 'authorization' && is_string($v)) {
                    $value = $v;
                    break;
                }
            }
        }
        if (!is_string($value) || preg_match('/^Bearer\s+(\S+)\s*$/i', $value, $m) !== 1) {
            return '';
        }
        return $m[1];
    }

    private static function guarded(callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $ex) {
            error_log('AuthApi: ' . $ex->getMessage());
            self::json(503, ['error' => 'db_unavailable']);
        }
    }

    private static function json(int $code, array|object $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
