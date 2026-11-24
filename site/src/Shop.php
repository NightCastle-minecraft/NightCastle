<?php

declare(strict_types=1);

namespace SiteGate;

use PDO;

/**
 * Магазин SiteGateShop (D17). Деньги внутри сайта: монеты на кошельке игрока.
 * Пополняет админ (админка сайта или панель), игрок тратит в /shop, плагин на SMP выдаёт покупку.
 * Все таблицы в DB1, поэтому списание и заказ идут в одной транзакции.
 */
final class Shop
{
    public const MAX_PRICE = 1_000_000;
    public const MAX_DELTA = 1_000_000;

    public static function validCode(string $code): bool
    {
        return preg_match('/^[a-z0-9_]{2,64}$/D', $code) === 1;
    }

    public static function balance(int $shard, int $accountId): int
    {
        $row = Db::one('SELECT balance FROM shop_wallets WHERE shard = ? AND account_id = ?', [$shard, $accountId]);
        return $row === null ? 0 : (int) $row['balance'];
    }

    /**
     * Пополнение или списание админом. Возвращает новый баланс.
     *
     * @param array<string, mixed> $account
     */
    public static function adjust(array $account, int $delta, string $reason, string $admin): int
    {
        if ($delta === 0 || abs($delta) > self::MAX_DELTA) {
            throw new ShopException('Сумма: не ноль и не больше ' . self::MAX_DELTA . ' по модулю.');
        }
        $reason = self::oneLine($reason, 120);
        $label = ($delta > 0 ? 'Пополнение' : 'Списание') . ($reason !== '' ? ': ' . $reason : '');
        return self::transaction(static fn (PDO $pdo): int => self::applyDelta($pdo, $account, $delta, $label, null, $admin));
    }

    /**
     * Покупка: списать монеты и поставить заказ в очередь выдачи.
     *
     * @param array<string, mixed> $account
     * @return array{id: int, title: string, price: int, balance: int, online: bool}
     */
    public static function buy(array $account, int $productId): array
    {
        $product = Db::one('SELECT * FROM shop_products WHERE id = ? AND is_active = 1', [$productId]);
        if ($product === null) {
            throw new ShopException('Такого товара нет или он снят с продажи.');
        }
        $codes = self::pluginCodes();
        if (!array_key_exists((string) $product['code'], $codes)) {
            throw new ShopException('Этот товар сейчас нельзя выдать на сервере. Попробуйте позже.');
        }
        $price = (int) $product['price'];
        return self::transaction(static function (PDO $pdo) use ($account, $product, $price, $codes): array {
            $pdo->prepare(
                "INSERT INTO shop_orders (shard, account_id, name, product_id, code, title, price, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP())"
            )->execute([
                (int) $account['shard'], (int) $account['id'], (string) $account['name'],
                (int) $product['id'], (string) $product['code'], (string) $product['title'], $price,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $balance = self::applyDelta($pdo, $account, -$price, 'Покупка: ' . $product['title'], $orderId, null);
            return [
                'id' => $orderId,
                'title' => (string) $product['title'],
                'price' => $price,
                'balance' => $balance,
                'online' => $codes[(string) $product['code']],
            ];
        });
    }

    /** Отменить невыданный заказ и вернуть монеты. */
    public static function cancel(int $orderId, string $admin): void
    {
        self::transaction(static function (PDO $pdo) use ($orderId, $admin): void {
            $stmt = $pdo->prepare("SELECT * FROM shop_orders WHERE id = ? AND status IN ('pending', 'failed') FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if ($order === false) {
                throw new ShopException('Заказ уже выдан или отменён.');
            }
            $pdo->prepare("UPDATE shop_orders SET status = 'cancelled', note = ? WHERE id = ?")
                ->execute(['Отменён админом ' . $admin, $orderId]);
            $account = ['shard' => (int) $order['shard'], 'id' => (int) $order['account_id'], 'name' => (string) $order['name']];
            self::applyDelta($pdo, $account, (int) $order['price'], 'Возврат: ' . $order['title'], $orderId, $admin);
        });
    }

    /** Заказ, который не выдался (ошибка команды), вернуть в очередь. */
    public static function retry(int $orderId): bool
    {
        $stmt = Db::pdo()->prepare("UPDATE shop_orders SET status = 'pending', note = NULL WHERE id = ? AND status = 'failed'");
        $stmt->execute([$orderId]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> */
    public static function products(bool $onlyActive): array
    {
        $where = $onlyActive ? 'WHERE is_active = 1' : '';
        return Db::all("SELECT * FROM shop_products {$where} ORDER BY category, position, id");
    }

    /** Товары, которые плагин умеет выдавать: code => нужен ли игрок онлайн. @return array<string, bool> */
    public static function pluginCodes(): array
    {
        $out = [];
        foreach (Db::all('SELECT code, require_online FROM shop_plugin_codes') as $row) {
            $out[(string) $row['code']] = (int) $row['require_online'] === 1;
        }
        return $out;
    }

    /** @param list<array{code: string, online: bool}> $codes */
    public static function syncCodes(array $codes): void
    {
        self::transaction(static function (PDO $pdo) use ($codes): void {
            $pdo->exec('DELETE FROM shop_plugin_codes');
            $stmt = $pdo->prepare('INSERT INTO shop_plugin_codes (code, require_online, seen_at) VALUES (?, ?, UTC_TIMESTAMP())');
            foreach ($codes as $c) {
                $stmt->execute([$c['code'], $c['online'] ? 1 : 0]);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public static function pending(int $limit): array
    {
        $limit = max(1, min(200, $limit));
        return Db::all(
            "SELECT id, name, code, title FROM shop_orders WHERE status = 'pending' ORDER BY id LIMIT " . $limit
        );
    }

    /**
     * @param list<int> $delivered
     * @param array<int, string> $failed id => текст ошибки
     */
    public static function markDelivered(array $delivered, array $failed): int
    {
        $n = 0;
        $pdo = Db::pdo();
        if ($delivered !== []) {
            $in = implode(',', array_fill(0, count($delivered), '?'));
            $stmt = $pdo->prepare("UPDATE shop_orders SET status = 'delivered', delivered_at = UTC_TIMESTAMP()
                                   WHERE status = 'pending' AND id IN ({$in})");
            $stmt->execute($delivered);
            $n += $stmt->rowCount();
        }
        $stmt = $pdo->prepare("UPDATE shop_orders SET status = 'failed', note = ? WHERE id = ? AND status = 'pending'");
        foreach ($failed as $id => $error) {
            $stmt->execute([self::oneLine($error, 250), $id]);
            $n += $stmt->rowCount();
        }
        return $n;
    }

    /** @return list<array<string, mixed>> */
    public static function orders(int $shard, int $accountId, int $limit = 20): array
    {
        return Db::all(
            'SELECT id, title, price, status, created_at, delivered_at FROM shop_orders
             WHERE shard = ? AND account_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$shard, $accountId]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function ledger(int $shard, int $accountId, int $limit = 20): array
    {
        return Db::all(
            'SELECT delta, balance_after, reason, admin, created_at FROM shop_ledger
             WHERE shard = ? AND account_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$shard, $accountId]
        );
    }

    public static function statusLabel(string $status): string
    {
        return [
            'pending' => 'ждёт выдачи',
            'delivered' => 'выдан',
            'failed' => 'ошибка выдачи',
            'cancelled' => 'отменён, монеты вернули',
        ][$status] ?? $status;
    }

    /** @param array<string, mixed> $account */
    private static function applyDelta(PDO $pdo, array $account, int $delta, string $reason, ?int $orderId, ?string $admin): int
    {
        $shard = (int) $account['shard'];
        $id = (int) $account['id'];
        $name = (string) $account['name'];
        $pdo->prepare('INSERT IGNORE INTO shop_wallets (shard, account_id, name, balance, updated_at) VALUES (?, ?, ?, 0, UTC_TIMESTAMP())')
            ->execute([$shard, $id, $name]);
        $stmt = $pdo->prepare('SELECT balance FROM shop_wallets WHERE shard = ? AND account_id = ? FOR UPDATE');
        $stmt->execute([$shard, $id]);
        $balance = (int) $stmt->fetchColumn();
        $new = $balance + $delta;
        if ($new < 0) {
            throw new ShopException($delta < 0 && $orderId !== null
                ? 'Не хватает монет: на балансе ' . $balance . ', нужно ' . (-$delta) . '.'
                : 'Нельзя уйти в минус: на балансе ' . $balance . '.');
        }
        $pdo->prepare('UPDATE shop_wallets SET balance = ?, name = ?, updated_at = UTC_TIMESTAMP() WHERE shard = ? AND account_id = ?')
            ->execute([$new, $name, $shard, $id]);
        $pdo->prepare(
            'INSERT INTO shop_ledger (shard, account_id, name, delta, balance_after, reason, order_id, admin, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$shard, $id, $name, $delta, $new, self::oneLine($reason, 160), $orderId, $admin]);
        return $new;
    }

    /**
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    private static function transaction(callable $fn): mixed
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    public static function oneLine(string $text, int $limit): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text)), 0, $limit);
    }
}
