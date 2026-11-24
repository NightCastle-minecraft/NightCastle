<?php

declare(strict_types=1);

/*
 * Аккаунт игрока из командной строки: для панели управления (docker exec sg-web php bin/player.php ...).
 * Ответ всегда одной строкой JSON: {"ok": true, ...} или {"ok": false, "error": "..."}.
 *
 *   php bin/player.php info <ник>
 *   php bin/player.php reset-sessions <ник>
 *   php bin/player.php password <ник>            новый случайный пароль + сброс входов
 *   php bin/player.php balance <ник> <±монеты> <причина>
 */

use SiteGate\Players;
use SiteGate\Shop;
use SiteGate\ShopException;

require dirname(__DIR__) . '/vendor/autoload.php';

function reply(array $data, int $code = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

$cmd = $argv[1] ?? '';
$name = $argv[2] ?? '';
if (!in_array($cmd, ['info', 'reset-sessions', 'password', 'balance'], true)) {
    reply(['ok' => false, 'error' => 'команды: info, reset-sessions, password, balance'], 2);
}
if (!Players::validName($name)) {
    reply(['ok' => false, 'error' => 'ник: 3–16 символов, латиница, цифры и _'], 2);
}

try {
    $account = Players::find($name);
    if ($cmd === 'info') {
        if ($account === null) {
            reply(['ok' => true, 'exists' => false, 'name' => $name]);
        }
        $shard = (int) $account['shard'];
        $id = (int) $account['id'];
        reply([
            'ok' => true,
            'exists' => true,
            'name' => (string) $account['name'],
            'shard' => $shard,
            'email' => $account['email'],
            'email_verified' => $account['email_verified_at'] !== null,
            'created_at' => (string) $account['created_at'],
            'last_login_at' => $account['last_login_at'],
            'playtime_seconds' => (int) $account['playtime_seconds'],
            'sessions' => Players::liveSessions($shard, $id),
            'balance' => Shop::balance($shard, $id),
        ]);
    }
    if ($account === null) {
        reply(['ok' => false, 'error' => 'на сайте нет аккаунта ' . $name], 1);
    }
    $shard = (int) $account['shard'];
    $id = (int) $account['id'];
    if ($cmd === 'reset-sessions') {
        Players::resetSessions($shard, $id);
        reply(['ok' => true, 'name' => (string) $account['name']]);
    }
    if ($cmd === 'password') {
        $password = Players::randomPassword();
        Players::setPassword($shard, $id, $password);
        Players::resetSessions($shard, $id);
        reply(['ok' => true, 'name' => (string) $account['name'], 'password' => $password]);
    }
    $amount = $argv[3] ?? '';
    if (preg_match('/^-?\d{1,7}$/D', $amount) !== 1) {
        reply(['ok' => false, 'error' => 'сумма: целое число'], 2);
    }
    $balance = Shop::adjust($account, (int) $amount, (string) ($argv[4] ?? 'панель'), 'панель');
    reply(['ok' => true, 'name' => (string) $account['name'], 'balance' => $balance]);
} catch (ShopException $ex) {
    reply(['ok' => false, 'error' => $ex->getMessage()], 1);
} catch (Throwable $ex) {
    fwrite(STDERR, (string) $ex . "\n");
    reply(['ok' => false, 'error' => 'база недоступна или ошибка сайта'], 1);
}
