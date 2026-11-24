<?php

declare(strict_types=1);

/*
 * Удалить ВСЕ аккаунты игроков во всех трёх базах (D19). Админы сайта, новости, правила и товары остаются.
 * Вместе с аккаунтами уходят: IP-сессии, наигранное время, ссылки из писем (каскадом),
 * тикеты входа, кошельки, заказы и история баланса магазина, счётчики попыток.
 *
 *   php bin/wipe-players.php          — только показать, что будет удалено
 *   php bin/wipe-players.php --yes    — удалить
 */

use SiteGate\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$really = in_array('--yes', array_slice($argv, 1), true);

$plan = [];
for ($n = 1; $n <= Db::SHARDS; $n++) {
    $plan[] = [$n, 'accounts', 'аккаунты (+ сессии, время, ссылки из писем)'];
    $plan[] = [$n, 'auth_tickets', 'тикеты входа'];
}
foreach (['shop_orders' => 'заказы', 'shop_ledger' => 'история баланса', 'shop_wallets' => 'кошельки',
    'player_attempts' => 'неверные пароли', 'player_registrations' => 'счётчик регистраций', 'mail_attempts' => 'счётчик писем'] as $table => $label) {
    $plan[] = [1, $table, $label];
}

foreach ($plan as [$n, $table, $label]) {
    $count = (int) (Db::shardOne($n, "SELECT COUNT(*) AS c FROM {$table}")['c'] ?? 0);
    if ($really) {
        // DELETE, а не TRUNCATE: на accounts ссылаются внешние ключи, каскад чистит связанные таблицы
        Db::shardExec($n, "DELETE FROM {$table}");
    }
    printf("DB%d %-22s %5d  %s%s\n", $n, $table, $count, $label, $really ? '  → удалено' : '');
}
echo $really ? "Готово: аккаунтов игроков больше нет.\n" : "Ничего не удалено. Чтобы удалить, запусти с --yes.\n";
