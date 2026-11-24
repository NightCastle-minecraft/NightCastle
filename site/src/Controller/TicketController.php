<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Auth;
use SiteGate\Csrf;
use SiteGate\Db;
use SiteGate\DbAttemptStore;
use SiteGate\EmailFlow;
use SiteGate\Format;
use SiteGate\PlayerAuth;
use SiteGate\Players;
use SiteGate\RateLimit;
use SiteGate\Ticket;
use SiteGate\View;

/**
 * Страница по ссылке из игры: /auth/{ticket}.
 * Нет аккаунта → регистрация; есть, но браузер не вошёл → пароль; дальше «Подтвердить» / «Это не я».
 */
final class TicketController
{
    /** @param array<string, string> $p */
    public static function show(array $p): void
    {
        self::headers();
        PlayerAuth::start();
        $t = self::load($p['ticket'] ?? '');
        self::render($t, null);
    }

    /** @param array<string, string> $p */
    public static function login(array $p): void
    {
        $t = self::beginPost($p);
        if ($t === null) {
            return;
        }
        if ($t['state'] !== 'pending') {
            self::back($t);
        }
        $account = Players::find((string) $t['name']);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $key = Auth::clientIp() . '|' . $t['name_lower'];
        $limit = new RateLimit(new DbAttemptStore('player_attempts'), 5, 900);
        if ($limit->isBlocked($key)) {
            self::render($t, 'Слишком много неверных паролей. Попробуйте через ' . $limit->windowMinutes() . ' минут.', 429);
            return;
        }
        if ($account === null) {
            self::back($t);
        }
        if ($password === '' || !Players::checkPassword($account, $password)) {
            $limit->recordFailure($key);
            self::render($t, 'Неверный пароль.', 401);
            return;
        }
        $limit->clear($key);
        PlayerAuth::login($account);
        self::back($t);
    }

    /** @param array<string, string> $p */
    public static function register(array $p): void
    {
        $t = self::beginPost($p);
        if ($t === null) {
            return;
        }
        if ($t['state'] !== 'pending' || Players::find((string) $t['name']) !== null) {
            self::back($t);
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $repeat = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $email = Players::normalizeEmail(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $error = $email === null ? 'Укажите настоящую почту: на неё придёт письмо для подтверждения.' : null;
        $error ??= Players::passwordError($password, $repeat, (string) $t['name']);
        if ($error === null && empty($_POST['rules'])) {
            $error = 'Нужно согласиться с правилами сервера.';
        }
        if ($error !== null) {
            self::render($t, $error, 422, is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
            return;
        }
        $ip = Auth::clientIp();
        $limit = new RateLimit(new DbAttemptStore('player_registrations'), 5, 3600);
        if ($limit->isBlocked($ip)) {
            self::render($t, 'С вашего адреса уже зарегистрировали несколько аккаунтов. Попробуйте через час.', 429);
            return;
        }
        $account = Players::create((string) $t['name'], $password, $ip, (string) $email);
        if ($account === null) {
            self::back($t); // ник только что заняли в соседней вкладке: покажем вход
        }
        $limit->recordFailure($ip); // считаем каждую регистрацию, не только неудачные
        PlayerAuth::login($account);
        PlayerAuth::flash(EmailFlow::sendVerify($account)
            ? 'На ' . $email . ' ушло письмо: подтвердите почту, когда будет минутка. В игру можно заходить уже сейчас.'
            : 'Письмо на ' . $email . ' не ушло. Отправить ещё раз можно в личном кабинете.');
        self::back($t);
    }

    /** @param array<string, string> $p */
    public static function confirm(array $p): void
    {
        $t = self::beginPost($p);
        if ($t === null) {
            return;
        }
        $player = PlayerAuth::current();
        if ($t['state'] !== 'pending' || $player === null || !self::owns($player, $t)) {
            self::back($t);
        }
        $shard = (int) $t['shard'];
        $id = (int) $player['id'];
        $ok = Db::shardExec(
            $shard,
            "UPDATE auth_tickets SET status = 'approved', decided_at = UTC_TIMESTAMP(), account_id = ?
             WHERE hash = ? AND status = 'pending' AND expires_at > UTC_TIMESTAMP()",
            [$id, $t['hash']]
        );
        if ($ok === 1) {
            Players::touchSession($shard, $id, (string) $t['ip']);
        }
        self::back($t);
    }

    /** @param array<string, string> $p */
    public static function deny(array $p): void
    {
        $t = self::beginPost($p);
        if ($t === null) {
            return;
        }
        Db::shardExec(
            (int) $t['shard'],
            "UPDATE auth_tickets SET status = 'denied', decided_at = UTC_TIMESTAMP() WHERE hash = ? AND status = 'pending'",
            [$t['hash']]
        );
        self::back($t);
    }

    /** Вошли не тем аккаунтом: выйти и вернуться на ту же ссылку. */
    /** @param array<string, string> $p */
    public static function switchAccount(array $p): void
    {
        $t = self::beginPost($p);
        if ($t === null) {
            return;
        }
        PlayerAuth::logout();
        self::back($t);
    }

    /**
     * Тикет из базы. state: invalid | pending | approved | denied | expired.
     *
     * @return array<string, mixed>
     */
    private static function load(string $ticket): array
    {
        $shard = Ticket::shardOf($ticket);
        if ($shard === null) {
            return ['state' => 'invalid', 'ticket' => ''];
        }
        $row = Db::shardOne(
            $shard,
            'SELECT hash, name, name_lower, ip, status, expires_at,
                    expires_at <= UTC_TIMESTAMP() AS is_expired,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS seconds_left
             FROM auth_tickets WHERE hash = ?',
            [Ticket::hash($ticket)]
        );
        if ($row === null) {
            return ['state' => 'invalid', 'ticket' => ''];
        }
        $state = (string) $row['status'];
        if ($state === 'pending' && (int) $row['is_expired'] === 1) {
            $state = 'expired';
        }
        return $row + ['state' => $state, 'ticket' => $ticket, 'shard' => $shard];
    }

    /** @param array<string, mixed> $t */
    private static function render(array $t, ?string $error, int $status = 200, string $email = ''): void
    {
        $view = (string) $t['state'];
        $player = null;
        if ($view === 'pending') {
            $player = PlayerAuth::current();
            $account = Players::find((string) $t['name']);
            if ($player !== null && self::owns($player, $t)) {
                $view = 'confirm';
            } elseif ($player !== null) {
                $view = 'other';
            } elseif ($account !== null) {
                $view = 'login';
            } else {
                $view = 'register';
            }
        }
        if ($status === 200 && in_array($view, ['invalid', 'expired'], true)) {
            $status = 404;
        }
        http_response_code($status);
        echo View::render('pages/auth-ticket', [
            'title' => 'Вход в игру',
            'view' => $view,
            'ticket' => (string) $t['ticket'],
            'name' => (string) ($t['name'] ?? ''),
            'ip' => (string) ($t['ip'] ?? ''),
            'minutesLeft' => max(1, (int) ceil(((int) ($t['seconds_left'] ?? 0)) / 60)),
            'playerName' => $player !== null ? (string) $player['name'] : '',
            'error' => $error,
            'csrf' => Csrf::token(),
            'passwordMin' => Players::PASSWORD_MIN,
            'email' => $email,
            'note' => $view === 'confirm' || $view === 'approved' ? PlayerAuth::takeFlash() : null,
            'expiresAt' => isset($t['expires_at']) ? Format::date((string) $t['expires_at']) : '',
        ]);
    }

    /**
     * POST: заголовки, сессия, CSRF, тикет. null = ответ уже отправлен.
     *
     * @param array<string, string> $p
     * @return array<string, mixed>|null
     */
    private static function beginPost(array $p): ?array
    {
        self::headers();
        PlayerAuth::start();
        $t = self::load($p['ticket'] ?? '');
        if (!Csrf::verifyRequest()) {
            self::render($t, 'Форма устарела. Обновите страницу и попробуйте ещё раз.', 403);
            return null;
        }
        if ($t['state'] === 'invalid') {
            self::render($t, null);
            return null;
        }
        return $t;
    }

    /**
     * @param array<string, mixed> $player
     * @param array<string, mixed> $t
     */
    private static function owns(array $player, array $t): bool
    {
        return (int) $player['shard'] === (int) $t['shard']
            && strtolower((string) $player['name']) === (string) $t['name_lower'];
    }

    /** @param array<string, mixed> $t */
    private static function back(array $t): never
    {
        Auth::redirect('/auth/' . rawurlencode((string) $t['ticket']));
    }

    private static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        // Тикет в адресе страницы: никуда его не отдаём через Referer
        header('Referrer-Policy: no-referrer');
    }
}
