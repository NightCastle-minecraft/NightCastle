<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Env;
use SiteGate\Players;
use SiteGate\Shop;

/**
 * API для плагина SiteGateShop на SMP. Свой токен SHOP_API_TOKEN(_FILE), не тот, что у SiteGateAuth.
 * Контракт: plugins/docs/shop-api.md.
 */
final class ShopApiController
{
    public static function pending(): void
    {
        if (self::begin(false) === null) {
            return;
        }
        $limit = ctype_digit((string) ($_GET['limit'] ?? '')) ? (int) $_GET['limit'] : 50;
        self::guarded(static function () use ($limit): void {
            $orders = array_map(static fn (array $o): array => [
                'id' => (int) $o['id'],
                'name' => (string) $o['name'],
                'code' => (string) $o['code'],
                'title' => (string) $o['title'],
            ], Shop::pending($limit));
            self::json(200, ['orders' => $orders]);
        });
    }

    public static function delivered(): void
    {
        $req = self::begin();
        if ($req === null) {
            return;
        }
        $delivered = [];
        foreach (is_array($req['delivered'] ?? null) ? $req['delivered'] : [] as $id) {
            if (is_int($id) && $id > 0) {
                $delivered[] = $id;
            }
        }
        $failed = [];
        foreach (is_array($req['failed'] ?? null) ? $req['failed'] : [] as $f) {
            if (is_array($f) && is_int($f['id'] ?? null) && $f['id'] > 0) {
                $failed[$f['id']] = is_string($f['error'] ?? null) ? $f['error'] : 'ошибка выдачи';
            }
        }
        if (count($delivered) + count($failed) > 500) {
            self::json(400, ['error' => 'too_many']);
            return;
        }
        self::guarded(static function () use ($delivered, $failed): void {
            self::json(200, ['updated' => Shop::markDelivered(array_values(array_unique($delivered)), $failed)]);
        });
    }

    public static function codes(): void
    {
        $req = self::begin();
        if ($req === null) {
            return;
        }
        $list = $req['codes'] ?? null;
        if (!is_array($list) || count($list) > 500) {
            self::json(400, ['error' => 'bad_codes']);
            return;
        }
        $codes = [];
        foreach ($list as $c) {
            if (!is_array($c) || !is_string($c['code'] ?? null) || !Shop::validCode($c['code'])) {
                self::json(400, ['error' => 'bad_code']);
                return;
            }
            $codes[$c['code']] = ['code' => $c['code'], 'online' => ($c['online'] ?? true) !== false];
        }
        self::guarded(static function () use ($codes): void {
            Shop::syncCodes(array_values($codes));
            self::json(200, ['count' => count($codes)]);
        });
    }

    /** @param array<string, string> $p */
    public static function balance(array $p): void
    {
        if (self::begin(false) === null) {
            return;
        }
        $name = $p['name'] ?? '';
        if (!Players::validName($name)) {
            self::json(400, ['error' => 'bad_name']);
            return;
        }
        self::guarded(static function () use ($name): void {
            $account = Players::find($name);
            self::json(200, [
                'registered' => $account !== null,
                'balance' => $account !== null ? Shop::balance((int) $account['shard'], (int) $account['id']) : 0,
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    private static function begin(bool $withBody = true): ?array
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $token = Env::secret('SHOP_API_TOKEN');
        if ($token === '') {
            self::json(404, ['error' => 'not_found']);
            return null;
        }
        if (!hash_equals($token, AuthApiController::bearer())) {
            self::json(401, ['error' => 'unauthorized']);
            return null;
        }
        if (!$withBody) {
            return [];
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 262_145);
        if (strlen($raw) > 262_144) {
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

    private static function guarded(callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $ex) {
            error_log('ShopApi: ' . $ex->getMessage());
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
