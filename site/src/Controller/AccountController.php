<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use DateTimeImmutable;
use DateTimeZone;
use SiteGate\Auth;
use SiteGate\Csrf;
use SiteGate\Db;
use SiteGate\DbAttemptStore;
use SiteGate\EmailFlow;
use SiteGate\Format;
use SiteGate\PlayerAuth;
use SiteGate\Players;
use SiteGate\Playtime;
use SiteGate\RateLimit;
use SiteGate\Shop;
use SiteGate\View;

/** Личный кабинет игрока: /login, /account. Регистрация только по ссылке из игры. */
final class AccountController
{
    public static function showLogin(): void
    {
        self::headers();
        PlayerAuth::start();
        if (PlayerAuth::current() !== null) {
            Auth::redirect('/account');
        }
        self::renderLogin(null, '');
    }

    public static function login(): void
    {
        self::headers();
        PlayerAuth::start();
        if (!Csrf::verifyRequest()) {
            self::renderLogin('Форма устарела. Обновите страницу и попробуйте ещё раз.', '', 403);
            return;
        }
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $key = Auth::clientIp() . '|' . strtolower($name);
        $limit = new RateLimit(new DbAttemptStore('player_attempts'), 5, 900);
        if ($limit->isBlocked($key)) {
            self::renderLogin('Слишком много неверных попыток. Попробуйте через ' . $limit->windowMinutes() . ' минут.', $name, 429);
            return;
        }
        $account = Players::validName($name) ? Players::find($name) : null;
        if ($account === null) {
            Players::burnPasswordTime($password);
        }
        if ($account === null || $password === '' || !Players::checkPassword($account, $password)) {
            $limit->recordFailure($key);
            self::renderLogin('Неверный ник или пароль.', $name, 401);
            return;
        }
        $limit->clear($key);
        PlayerAuth::login($account);
        Auth::redirect('/account');
    }

    public static function logout(): void
    {
        self::headers();
        PlayerAuth::start();
        if (Csrf::verifyRequest()) {
            PlayerAuth::logout();
        }
        Auth::redirect('/');
    }

    public static function show(): void
    {
        self::headers();
        PlayerAuth::start();
        $player = self::requirePlayer();
        $shard = (int) $player['shard'];
        $id = (int) $player['id'];

        $since = gmdate('Y-m-d', time() - 29 * 86400);
        $rows = Db::shardAll(
            $shard,
            'SELECT day, seconds FROM playtime_daily WHERE account_id = ? AND day >= ? ORDER BY day DESC',
            [$id, $since]
        );
        $days = [];
        $month = 0;
        foreach ($rows as $row) {
            $sec = (int) $row['seconds'];
            $month += $sec;
            $days[] = ['date' => Format::date((string) $row['day'] . ' 12:00:00'), 'time' => Playtime::human($sec), 'seconds' => $sec];
        }
        $max = $days === [] ? 1 : max(1, ...array_column($days, 'seconds'));
        foreach ($days as &$d) {
            $d['percent'] = (int) round($d['seconds'] * 100 / $max);
        }
        unset($d);

        echo View::render('pages/account', [
            'title' => 'Личный кабинет',
            'name' => (string) $player['name'],
            'createdAt' => Format::date((string) $player['created_at']),
            'lastLogin' => $player['last_login_at'] !== null ? self::dateTime((string) $player['last_login_at']) : '',
            'total' => Playtime::human((int) $player['playtime_seconds']),
            'month' => Playtime::human($month),
            'days' => $days,
            'sessions' => Players::liveSessions($shard, $id),
            'email' => (string) ($player['email'] ?? ''),
            'emailVerified' => ($player['email_verified_at'] ?? null) !== null,
            'sessionDays' => Players::SESSION_DAYS,
            'passwordMin' => Players::PASSWORD_MIN,
            'balance' => Shop::balance($shard, $id),
            'orders' => array_map(static fn (array $o): array => [
                'id' => (int) $o['id'],
                'title' => (string) $o['title'],
                'price' => (int) $o['price'],
                'status' => Shop::statusLabel((string) $o['status']),
                'date' => Format::date((string) $o['created_at']),
            ], Shop::orders($shard, $id, 10)),
            'ledger' => array_map(static fn (array $l): array => [
                'delta' => (int) $l['delta'],
                'reason' => (string) $l['reason'],
                'date' => Format::date((string) $l['created_at']),
            ], Shop::ledger($shard, $id, 10)),
            'flash' => PlayerAuth::takeFlash(),
            'error' => null,
            'csrf' => Csrf::token(),
        ]);
    }

    public static function changePassword(): void
    {
        self::headers();
        PlayerAuth::start();
        $player = self::requirePlayer();
        if (!Csrf::verifyRequest()) {
            PlayerAuth::flash('Форма устарела, попробуйте ещё раз.');
            Auth::redirect('/account');
        }
        $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $repeat = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $key = Auth::clientIp() . '|' . strtolower((string) $player['name']);
        $limit = new RateLimit(new DbAttemptStore('player_attempts'), 5, 900);
        if ($limit->isBlocked($key)) {
            PlayerAuth::flash('Слишком много неверных паролей. Попробуйте через ' . $limit->windowMinutes() . ' минут.');
            Auth::redirect('/account');
        }
        if (!Players::checkPassword($player, $current)) {
            $limit->recordFailure($key);
            PlayerAuth::flash('Текущий пароль неверный, пароль не изменён.');
            Auth::redirect('/account');
        }
        $error = Players::passwordError($password, $repeat, (string) $player['name']);
        if ($error !== null) {
            PlayerAuth::flash($error . ' Пароль не изменён.');
            Auth::redirect('/account');
        }
        Players::setPassword((int) $player['shard'], (int) $player['id'], $password);
        PlayerAuth::flash('Пароль изменён.');
        Auth::redirect('/account');
    }

    public static function resetSessions(): void
    {
        self::headers();
        PlayerAuth::start();
        $player = self::requirePlayer();
        if (!Csrf::verifyRequest()) {
            PlayerAuth::flash('Форма устарела, попробуйте ещё раз.');
            Auth::redirect('/account');
        }
        Players::resetSessions((int) $player['shard'], (int) $player['id']);
        PlayerAuth::refresh();
        PlayerAuth::flash('Готово: все устройства вышли. В следующий раз игра попросит подтвердить вход на сайте.');
        Auth::redirect('/account');
    }

    /** Повторно отправить письмо для подтверждения почты (не чаще 3 раз в час). */
    public static function resendEmail(): void
    {
        self::headers();
        PlayerAuth::start();
        $player = self::requirePlayer();
        if (!Csrf::verifyRequest()) {
            PlayerAuth::flash('Форма устарела, попробуйте ещё раз.');
            Auth::redirect('/account');
        }
        if (($player['email'] ?? null) === null || $player['email_verified_at'] !== null) {
            Auth::redirect('/account');
        }
        $limit = new RateLimit(new DbAttemptStore('mail_attempts'), 3, 3600);
        $key = 'verify|' . $player['shard'] . '|' . $player['id'];
        if ($limit->isBlocked($key)) {
            PlayerAuth::flash('Письма уходили уже 3 раза за час. Проверьте «Спам» или попробуйте позже.');
            Auth::redirect('/account');
        }
        $limit->recordFailure($key);
        PlayerAuth::flash(EmailFlow::sendVerify($player)
            ? 'Письмо отправлено на ' . $player['email'] . '. Ссылка действует 24 часа.'
            : 'Письмо не ушло: почтовый сервер не ответил. Попробуйте чуть позже.');
        Auth::redirect('/account');
    }

    /** Сменить почту: нужен текущий пароль, новая почта сразу уходит на подтверждение. */
    public static function changeEmail(): void
    {
        self::headers();
        PlayerAuth::start();
        $player = self::requirePlayer();
        if (!Csrf::verifyRequest()) {
            PlayerAuth::flash('Форма устарела, попробуйте ещё раз.');
            Auth::redirect('/account');
        }
        $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
        $email = Players::normalizeEmail(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $limit = new RateLimit(new DbAttemptStore('player_attempts'), 5, 900);
        $key = Auth::clientIp() . '|' . strtolower((string) $player['name']);
        if ($limit->isBlocked($key)) {
            PlayerAuth::flash('Слишком много неверных паролей. Попробуйте через ' . $limit->windowMinutes() . ' минут.');
            Auth::redirect('/account');
        }
        if (!Players::checkPassword($player, $current)) {
            $limit->recordFailure($key);
            PlayerAuth::flash('Пароль неверный, почта не изменена.');
            Auth::redirect('/account');
        }
        if ($email === null) {
            PlayerAuth::flash('Это не похоже на адрес почты, почта не изменена.');
            Auth::redirect('/account');
        }
        $shard = (int) $player['shard'];
        $id = (int) $player['id'];
        Players::setEmail($shard, $id, $email);
        $fresh = Players::byId($shard, $id);
        PlayerAuth::flash($fresh !== null && EmailFlow::sendVerify($fresh)
            ? 'Почта изменена. На ' . $email . ' ушло письмо для подтверждения.'
            : 'Почта изменена, но письмо не ушло. Отправьте его ещё раз кнопкой ниже.');
        Auth::redirect('/account');
    }

    /** @return array<string, mixed> */
    private static function requirePlayer(): array
    {
        $player = PlayerAuth::current();
        if ($player === null) {
            Auth::redirect('/login');
        }
        return $player;
    }

    private static function renderLogin(?string $error, string $name, int $status = 200): void
    {
        http_response_code($status);
        echo View::render('pages/login', [
            'title' => 'Вход',
            'error' => $error,
            'name' => $name,
            'flash' => PlayerAuth::takeFlash(),
            'csrf' => Csrf::token(),
        ]);
    }

    private static function dateTime(string $utc): string
    {
        $dt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Kyiv'));
        return Format::date($utc) . ', ' . $dt->format('H:i');
    }

    private static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
}
