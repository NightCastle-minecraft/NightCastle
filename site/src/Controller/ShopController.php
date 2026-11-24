<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Auth;
use SiteGate\Csrf;
use SiteGate\Markdown;
use SiteGate\PlayerAuth;
use SiteGate\Shop;
use SiteGate\ShopException;
use SiteGate\View;

/** Витрина /shop: товары за монеты, выдача на сервере плагином SiteGateShop. */
final class ShopController
{
    public static function index(): void
    {
        header('Cache-Control: no-store');
        PlayerAuth::start();
        $player = PlayerAuth::current();
        $codes = Shop::pluginCodes();
        $groups = [];
        foreach (Shop::products(true) as $p) {
            $cat = (string) $p['category'] !== '' ? (string) $p['category'] : 'Разное';
            $groups[$cat][] = [
                'id' => (int) $p['id'],
                'title' => (string) $p['title'],
                'price' => (int) $p['price'],
                'html' => Markdown::render((string) $p['description_md']),
                'available' => array_key_exists((string) $p['code'], $codes),
                'online' => $codes[(string) $p['code']] ?? true,
            ];
        }
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['buy_nonce'] = $nonce;
        echo View::render('pages/shop', [
            'title' => 'Магазин',
            'groups' => $groups,
            'player' => $player !== null ? (string) $player['name'] : null,
            'balance' => $player !== null ? Shop::balance((int) $player['shard'], (int) $player['id']) : 0,
            'flash' => PlayerAuth::takeFlash(),
            'csrf' => Csrf::token(),
            'nonce' => $nonce,
        ]);
    }

    /** @param array<string, string> $p */
    public static function buy(array $p): void
    {
        header('Cache-Control: no-store');
        PlayerAuth::start();
        $player = PlayerAuth::current();
        if ($player === null) {
            Auth::redirect('/login');
        }
        $nonce = $_SESSION['buy_nonce'] ?? null;
        unset($_SESSION['buy_nonce']);
        $given = $_POST['nonce'] ?? null;
        if (!Csrf::verifyRequest() || !is_string($nonce) || !is_string($given) || !hash_equals($nonce, $given)) {
            PlayerAuth::flash('Покупка не прошла: страница устарела. Проверьте баланс и попробуйте ещё раз.');
            Auth::redirect('/shop');
        }
        $id = ctype_digit($p['id'] ?? '') ? (int) $p['id'] : 0;
        try {
            $order = Shop::buy($player, $id);
        } catch (ShopException $ex) {
            PlayerAuth::flash($ex->getMessage());
            Auth::redirect('/shop');
        }
        PlayerAuth::flash(
            'Куплено: ' . $order['title'] . ' за ' . $order['price'] . ' монет. Осталось ' . $order['balance'] . '. '
            . ($order['online']
                ? 'Выдадим на сервере, как только вы будете в игре.'
                : 'Выдача на сервере в течение минуты.')
        );
        Auth::redirect('/shop');
    }
}
