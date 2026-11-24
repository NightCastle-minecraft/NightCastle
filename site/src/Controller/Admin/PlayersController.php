<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;
use SiteGate\Format;
use SiteGate\Players;
use SiteGate\Playtime;
use SiteGate\ShardStats;

/** Игроки во всех трёх базах: поиск, сброс сессий, новый пароль. */
final class PlayersController
{
    public static function index(): void
    {
        $admin = Page::begin();
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $results = [];
        $searchError = null;
        if ($q !== '') {
            if (preg_match('/^[A-Za-z0-9_]{1,16}$/D', $q) !== 1) {
                $searchError = 'Ник: латиница, цифры и _, до 16 символов.';
            } else {
                $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($q)) . '%';
                for ($n = 1; $n <= Db::SHARDS; $n++) {
                    try {
                        $rows = Db::shardAll(
                            $n,
                            'SELECT id, name, email, email_verified_at, created_at, created_ip, last_login_at, playtime_seconds FROM accounts
                             WHERE name_lower LIKE ? ORDER BY name_lower LIMIT 25',
                            [$like]
                        );
                    } catch (\Throwable $ex) {
                        error_log("Admin players DB{$n}: " . $ex->getMessage());
                        $searchError = "База {$n} недоступна, результаты неполные.";
                        continue;
                    }
                    foreach ($rows as $row) {
                        $results[] = [
                            'shard' => $n,
                            'id' => (int) $row['id'],
                            'name' => (string) $row['name'],
                            'email' => (string) ($row['email'] ?? ''),
                            'verified' => $row['email_verified_at'] !== null,
                            'created' => Format::date((string) $row['created_at']),
                            'ip' => (string) $row['created_ip'],
                            'last' => $row['last_login_at'] !== null ? Format::date((string) $row['last_login_at']) : '—',
                            'playtime' => Playtime::human((int) $row['playtime_seconds']),
                            'sessions' => Players::liveSessions($n, (int) $row['id']),
                        ];
                    }
                }
                usort($results, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            }
        }

        Page::render('admin/players', $admin, [
            'title' => 'Игроки',
            'q' => $q,
            'results' => $results,
            'searchError' => $searchError,
            'shards' => ShardStats::all(),
        ]);
    }

    /** @param array<string, string> $p */
    public static function resetSessions(array $p): void
    {
        $admin = Page::beginPost();
        $account = self::account($p);
        if ($account === null) {
            Page::notFound();
            return;
        }
        Players::resetSessions((int) $account['shard'], (int) $account['id']);
        AdminLog::write($admin['id'], 'player_sessions_reset', 'name=' . $account['name']);
        Page::flash('Сессии игрока ' . $account['name'] . ' сброшены: в игре он снова подтвердит вход на сайте.');
        Auth::redirect('/admin/players?q=' . rawurlencode((string) $account['name']));
    }

    /** @param array<string, string> $p */
    public static function newPassword(array $p): void
    {
        $admin = Page::beginPost();
        $account = self::account($p);
        if ($account === null) {
            Page::notFound();
            return;
        }
        $password = Players::randomPassword();
        Players::setPassword((int) $account['shard'], (int) $account['id'], $password);
        Players::resetSessions((int) $account['shard'], (int) $account['id']);
        AdminLog::write($admin['id'], 'player_password_reset', 'name=' . $account['name']);
        Page::flash('Новый пароль для ' . $account['name'] . ': ' . $password . ' (показан один раз, передайте игроку).');
        Auth::redirect('/admin/players?q=' . rawurlencode((string) $account['name']));
    }

    /**
     * @param array<string, string> $p
     * @return array<string, mixed>|null
     */
    private static function account(array $p): ?array
    {
        $shard = ctype_digit($p['shard'] ?? '') ? (int) $p['shard'] : 0;
        $id = ctype_digit($p['id'] ?? '') ? (int) $p['id'] : 0;
        if ($shard < 1 || $shard > Db::SHARDS || $id < 1) {
            return null;
        }
        return Players::byId($shard, $id);
    }
}
