<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Auth;
use SiteGate\Csrf;
use SiteGate\DbAttemptStore;
use SiteGate\EmailFlow;
use SiteGate\PlayerAuth;
use SiteGate\Players;
use SiteGate\RateLimit;
use SiteGate\View;

/** Страницы по ссылкам из писем (D18): подтверждение почты, «забыли пароль», новый пароль. */
final class MailLinkController
{
    /** @param array<string, string> $p */
    public static function verify(array $p): void
    {
        self::headers();
        $account = EmailFlow::verify($p['token'] ?? '');
        if ($account !== null) {
            EmailFlow::sendWelcome($account);
        }
        http_response_code($account !== null ? 200 : 404);
        echo View::render('pages/mail-result', [
            'title' => 'Подтверждение почты',
            'ok' => $account !== null,
            'heading' => $account !== null ? 'Почта подтверждена' : 'Ссылка не работает',
            'text' => $account !== null
                ? 'Готово, ' . $account['name'] . '! Теперь по этой почте можно восстановить пароль.'
                : 'Ссылка устарела, уже использована или почту в аккаунте с тех пор сменили. Новое письмо можно отправить из личного кабинета.',
        ]);
    }

    public static function showForgot(): void
    {
        self::headers();
        PlayerAuth::start();
        self::renderForgot(null, false, '');
    }

    public static function forgot(): void
    {
        self::headers();
        PlayerAuth::start();
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        if (!Csrf::verifyRequest()) {
            self::renderForgot('Форма устарела. Обновите страницу и попробуйте ещё раз.', false, $name, 403);
            return;
        }
        if (!Players::validName($name)) {
            self::renderForgot('Ник: от 3 до 16 символов, латиница, цифры и _.', false, $name, 422);
            return;
        }
        $byIp = new RateLimit(new DbAttemptStore('mail_attempts'), 5, 3600);
        $ipKey = 'forgot|' . Auth::clientIp();
        $nickKey = 'forgot|' . strtolower($name);
        if ($byIp->isBlocked($ipKey) || $byIp->isBlocked($nickKey)) {
            self::renderForgot('Слишком много запросов. Попробуйте через час.', false, $name, 429);
            return;
        }
        $byIp->recordFailure($ipKey);
        $byIp->recordFailure($nickKey);
        $account = Players::find($name);
        // Ответ одинаковый, есть аккаунт или нет: по этой форме нельзя узнать, чья почта где
        if ($account !== null && $account['email'] !== null && $account['email_verified_at'] !== null) {
            EmailFlow::sendReset($account);
        }
        self::renderForgot(null, true, $name);
    }

    /** @param array<string, string> $p */
    public static function showReset(array $p): void
    {
        self::headers();
        PlayerAuth::start();
        $token = $p['token'] ?? '';
        $account = EmailFlow::resetAccount($token);
        self::renderReset($token, $account, null, $account !== null ? 200 : 404);
    }

    /** @param array<string, string> $p */
    public static function reset(array $p): void
    {
        self::headers();
        PlayerAuth::start();
        $token = $p['token'] ?? '';
        $account = EmailFlow::resetAccount($token);
        if ($account === null) {
            self::renderReset($token, null, null, 404);
            return;
        }
        if (!Csrf::verifyRequest()) {
            self::renderReset($token, $account, 'Форма устарела. Обновите страницу и попробуйте ещё раз.', 403);
            return;
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $repeat = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $error = Players::passwordError($password, $repeat, (string) $account['name']);
        if ($error !== null) {
            self::renderReset($token, $account, $error, 422);
            return;
        }
        if (!EmailFlow::consume($token)) {
            self::renderReset($token, null, null, 404); // ссылку только что использовали в другой вкладке
            return;
        }
        $shard = (int) $account['shard'];
        $id = (int) $account['id'];
        Players::setPassword($shard, $id, $password);
        Players::resetSessions($shard, $id);
        PlayerAuth::logout();
        PlayerAuth::start();
        PlayerAuth::flash('Пароль сменён. Все прошлые входы сброшены: войдите с новым паролем.');
        Auth::redirect('/login');
    }

    private static function renderForgot(?string $error, bool $sent, string $name, int $status = 200): void
    {
        http_response_code($status);
        echo View::render('pages/forgot', [
            'title' => 'Восстановление пароля',
            'error' => $error,
            'sent' => $sent,
            'name' => $name,
            'csrf' => Csrf::token(),
        ]);
    }

    /** @param array<string, mixed>|null $account */
    private static function renderReset(string $token, ?array $account, ?string $error, int $status): void
    {
        http_response_code($status);
        echo View::render('pages/reset', [
            'title' => 'Новый пароль',
            'token' => $token,
            'name' => $account !== null ? (string) $account['name'] : '',
            'valid' => $account !== null,
            'error' => $error,
            'csrf' => Csrf::token(),
            'passwordMin' => Players::PASSWORD_MIN,
        ]);
    }

    private static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer'); // токен в адресе
    }
}
