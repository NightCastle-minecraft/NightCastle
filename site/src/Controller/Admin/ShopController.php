<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;
use SiteGate\Format;
use SiteGate\Players;
use SiteGate\Shop;
use SiteGate\ShopException;
use SiteGate\ShopProductValidator;

/** Админка магазина: товары, заказы, кошельки игроков. */
final class ShopController
{
    public static function index(): void
    {
        $admin = Page::begin();
        $codes = Shop::pluginCodes();
        $products = array_map(static fn (array $p): array => $p + [
            'in_plugin' => array_key_exists((string) $p['code'], $codes),
        ], Shop::products(false));
        $known = array_flip(array_column($products, 'code'));
        $counts = Db::one(
            "SELECT SUM(status = 'pending') AS pending, SUM(status = 'failed') AS failed,
                    SUM(status = 'delivered') AS delivered FROM shop_orders"
        ) ?? [];
        Page::render('admin/shop', $admin, [
            'title' => 'Магазин',
            'products' => $products,
            'orphanCodes' => array_values(array_filter(array_keys($codes), static fn (string $c): bool => !isset($known[$c]))),
            'pluginSeen' => $codes !== [],
            'counts' => array_map('intval', $counts),
        ]);
    }

    public static function create(): void
    {
        $admin = Page::begin();
        self::form($admin, null, ['code' => '', 'title' => '', 'category' => '', 'price' => '', 'description_md' => '',
            'position' => '0', 'is_active' => 1], []);
    }

    public static function store(): void
    {
        $admin = Page::beginPost();
        $r = ShopProductValidator::validate($_POST);
        if ($r['errors'] === [] && Db::one('SELECT 1 FROM shop_products WHERE code = ?', [$r['values']['code']]) !== null) {
            $r['errors']['code'] = 'Товар с таким кодом уже есть.';
        }
        if ($r['errors'] !== []) {
            self::form($admin, null, $r['values'], $r['errors'], 422);
            return;
        }
        $v = $r['values'];
        Db::pdo()->prepare(
            'INSERT INTO shop_products (code, title, description_md, category, price, is_active, position) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$v['code'], $v['title'], $v['description_md'], $v['category'], $v['price'], $v['is_active'], $v['position']]);
        AdminLog::write($admin['id'], 'shop_product_create', 'code=' . $v['code'] . ' price=' . $v['price']);
        Page::flash('Товар «' . $v['title'] . '» добавлен.');
        Auth::redirect('/admin/shop');
    }

    /** @param array<string, string> $p */
    public static function edit(array $p): void
    {
        $admin = Page::begin();
        $product = self::product($p);
        if ($product === null) {
            Page::notFound();
            return;
        }
        self::form($admin, (int) $product['id'], $product, []);
    }

    /** @param array<string, string> $p */
    public static function update(array $p): void
    {
        $admin = Page::beginPost();
        $product = self::product($p);
        if ($product === null) {
            Page::notFound();
            return;
        }
        $id = (int) $product['id'];
        $r = ShopProductValidator::validate($_POST);
        if ($r['errors'] === [] && Db::one('SELECT 1 FROM shop_products WHERE code = ? AND id <> ?', [$r['values']['code'], $id]) !== null) {
            $r['errors']['code'] = 'Товар с таким кодом уже есть.';
        }
        if ($r['errors'] !== []) {
            self::form($admin, $id, $r['values'], $r['errors'], 422);
            return;
        }
        $v = $r['values'];
        Db::pdo()->prepare(
            'UPDATE shop_products SET code = ?, title = ?, description_md = ?, category = ?, price = ?, is_active = ?, position = ? WHERE id = ?'
        )->execute([$v['code'], $v['title'], $v['description_md'], $v['category'], $v['price'], $v['is_active'], $v['position'], $id]);
        AdminLog::write($admin['id'], 'shop_product_update', 'code=' . $v['code'] . ' price=' . $v['price'] . ' active=' . $v['is_active']);
        Page::flash('Товар «' . $v['title'] . '» сохранён.');
        Auth::redirect('/admin/shop');
    }

    /** @param array<string, string> $p */
    public static function delete(array $p): void
    {
        $admin = Page::beginPost();
        $product = self::product($p);
        if ($product === null) {
            Page::notFound();
            return;
        }
        $id = (int) $product['id'];
        if (Db::one('SELECT 1 FROM shop_orders WHERE product_id = ? LIMIT 1', [$id]) !== null) {
            Db::pdo()->prepare('UPDATE shop_products SET is_active = 0 WHERE id = ?')->execute([$id]);
            Page::flash('У товара уже есть заказы, поэтому он не удалён, а снят с продажи.');
        } else {
            Db::pdo()->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$id]);
            Page::flash('Товар удалён.');
        }
        AdminLog::write($admin['id'], 'shop_product_delete', 'code=' . $product['code']);
        Auth::redirect('/admin/shop');
    }

    public static function orders(): void
    {
        $admin = Page::begin();
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $allowed = ['pending', 'failed', 'delivered', 'cancelled'];
        $where = in_array($status, $allowed, true) ? 'WHERE status = ?' : '';
        $rows = Db::all(
            "SELECT id, name, title, price, status, note, created_at, delivered_at FROM shop_orders {$where} ORDER BY id DESC LIMIT 200",
            $where !== '' ? [$status] : []
        );
        Page::render('admin/shop-orders', $admin, [
            'title' => 'Заказы',
            'status' => $status,
            'orders' => array_map(static fn (array $o): array => $o + [
                'label' => Shop::statusLabel((string) $o['status']),
                'date' => Format::date((string) $o['created_at']),
            ], $rows),
        ]);
    }

    /** @param array<string, string> $p */
    public static function cancel(array $p): void
    {
        $admin = Page::beginPost();
        $id = ctype_digit($p['id'] ?? '') ? (int) $p['id'] : 0;
        try {
            Shop::cancel($id, $admin['username']);
            AdminLog::write($admin['id'], 'shop_order_cancel', 'order=' . $id);
            Page::flash('Заказ #' . $id . ' отменён, монеты вернулись игроку.');
        } catch (ShopException $ex) {
            Page::flash($ex->getMessage());
        }
        Auth::redirect('/admin/shop/orders');
    }

    /** @param array<string, string> $p */
    public static function retry(array $p): void
    {
        $admin = Page::beginPost();
        $id = ctype_digit($p['id'] ?? '') ? (int) $p['id'] : 0;
        if (Shop::retry($id)) {
            AdminLog::write($admin['id'], 'shop_order_retry', 'order=' . $id);
            Page::flash('Заказ #' . $id . ' снова в очереди выдачи.');
        } else {
            Page::flash('Повторить можно только заказ с ошибкой выдачи.');
        }
        Auth::redirect('/admin/shop/orders');
    }

    public static function wallet(): void
    {
        $admin = Page::begin();
        $name = is_string($_GET['name'] ?? null) ? trim($_GET['name']) : '';
        self::renderWallet($admin, $name, null);
    }

    public static function adjust(): void
    {
        $admin = Page::beginPost();
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $amountRaw = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
        $reason = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';
        $account = Players::validName($name) ? Players::find($name) : null;
        if ($account === null) {
            self::renderWallet($admin, $name, 'Аккаунта с таким ником на сайте нет.');
            return;
        }
        if (preg_match('/^-?\d{1,7}$/D', $amountRaw) !== 1) {
            self::renderWallet($admin, $name, 'Сумма: целое число, например 100 или -50.');
            return;
        }
        try {
            $balance = Shop::adjust($account, (int) $amountRaw, $reason, $admin['username']);
        } catch (ShopException $ex) {
            self::renderWallet($admin, $name, $ex->getMessage());
            return;
        }
        AdminLog::write($admin['id'], 'shop_wallet_adjust', 'name=' . $account['name'] . ' delta=' . $amountRaw);
        Page::flash('Баланс ' . $account['name'] . ': ' . $balance . ' монет.');
        Auth::redirect('/admin/shop/wallet?name=' . rawurlencode((string) $account['name']));
    }

    /** @param array{id: int, username: string} $admin */
    private static function renderWallet(array $admin, string $name, ?string $error): void
    {
        $account = $name !== '' && Players::validName($name) ? Players::find($name) : null;
        $data = ['title' => 'Кошельки', 'name' => $name, 'error' => $error, 'account' => null];
        if ($account !== null) {
            $shard = (int) $account['shard'];
            $id = (int) $account['id'];
            $data['account'] = [
                'name' => (string) $account['name'],
                'balance' => Shop::balance($shard, $id),
                'ledger' => Shop::ledger($shard, $id, 50),
                'orders' => Shop::orders($shard, $id, 50),
            ];
        } elseif ($name !== '' && $error === null) {
            $data['error'] = 'Аккаунта с таким ником на сайте нет.';
        }
        Page::render('admin/shop-wallet', $admin, $data, $error !== null ? 422 : 200);
    }

    /**
     * @param array{id: int, username: string} $admin
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private static function form(array $admin, ?int $id, array $values, array $errors, int $status = 200): void
    {
        Page::render('admin/shop-edit', $admin, [
            'title' => $id === null ? 'Новый товар' : 'Товар',
            'id' => $id,
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param array<string, string> $p
     * @return array<string, mixed>|null
     */
    private static function product(array $p): ?array
    {
        $id = ctype_digit($p['id'] ?? '') ? (int) $p['id'] : 0;
        return $id > 0 ? Db::one('SELECT * FROM shop_products WHERE id = ?', [$id]) : null;
    }
}
