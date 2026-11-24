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

/**
 * Регистрация на сайте без игры (D19). Вход в игру остаётся прежним: капча → ссылка → пароль на сайте.
 * От ботов: пример на сложение, скрытое поле-ловушка, не больше 5 регистраций в час с IP.
 */
final class RegisterController
{
    public static function show(): void
    {
        self::headers();
        PlayerAuth::start();
        if (PlayerAuth::current() !== null) {
            Auth::redirect('/account');
        }
        self::render(null, ['name' => '', 'email' => '']);
    }

    public static function register(): void
    {
        self::headers();
        PlayerAuth::start();
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $emailRaw = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $old = ['name' => $name, 'email' => $emailRaw];
        if (!Csrf::verifyRequest()) {
            self::render('Форма устарела. Обновите страницу и попробуйте ещё раз.', $old, 403);
            return;
        }
        $captcha = $_SESSION['reg_captcha'] ?? null;
        unset($_SESSION['reg_captcha']);
        $answer = is_string($_POST['captcha'] ?? null) ? trim($_POST['captcha']) : '';
        // Ловушка: поле спрятано от людей, его заполняют только боты
        if (($_POST['website'] ?? '') !== '') {
            self::render('Регистрация не прошла. Попробуйте ещё раз.', $old, 422);
            return;
        }
        if (!is_int($captcha) || !ctype_digit($answer) || (int) $answer !== $captcha) {
            self::render('Пример решён неверно, вот новый.', $old, 422);
            return;
        }
        if (!Players::validName($name)) {
            self::render('Ник: от 3 до 16 символов, только латиница, цифры и _. Такой же, как в Minecraft.', $old, 422);
            return;
        }
        $email = Players::normalizeEmail($emailRaw);
        if ($email === null) {
            self::render('Укажите настоящую почту: на неё придёт письмо для подтверждения.', $old, 422);
            return;
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $repeat = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $error = Players::passwordError($password, $repeat, $name);
        if ($error === null && empty($_POST['rules'])) {
            $error = 'Нужно согласиться с правилами сервера.';
        }
        if ($error !== null) {
            self::render($error, $old, 422);
            return;
        }
        if (Players::find($name) !== null) {
            self::render('Ник ' . $name . ' уже занят. Если это ваш аккаунт, войдите, а пароль можно восстановить.', $old, 409);
            return;
        }
        $ip = Auth::clientIp();
        $limit = new RateLimit(new DbAttemptStore('player_registrations'), 5, 3600);
        if ($limit->isBlocked($ip)) {
            self::render('С вашего адреса уже зарегистрировали несколько аккаунтов. Попробуйте через час.', $old, 429);
            return;
        }
        $account = Players::create($name, $password, $ip, $email);
        if ($account === null) {
            self::render('Ник ' . $name . ' только что заняли. Выберите другой.', $old, 409);
            return;
        }
        $limit->recordFailure($ip);
        PlayerAuth::login($account);
        PlayerAuth::flash((EmailFlow::sendVerify($account)
            ? 'Аккаунт ' . $name . ' создан! На ' . $email . ' ушло письмо для подтверждения почты. '
            : 'Аккаунт ' . $name . ' создан, но письмо на ' . $email . ' не ушло: отправьте его ещё раз ниже. ')
            . 'Чтобы играть, зайдите на сервер example.com: игра покажет кнопку входа, а на сайте введёте этот пароль.');
        Auth::redirect('/account');
    }

    /** @param array{name: string, email: string} $old */
    private static function render(?string $error, array $old, int $status = 200): void
    {
        $a = random_int(2, 19);
        $b = random_int(2, 19);
        $_SESSION['reg_captcha'] = $a + $b;
        http_response_code($status);
        echo View::render('pages/register', [
            'title' => 'Регистрация',
            'error' => $error,
            'name' => $old['name'],
            'email' => $old['email'],
            'captchaA' => $a,
            'captchaB' => $b,
            'passwordMin' => Players::PASSWORD_MIN,
            'csrf' => Csrf::token(),
        ]);
    }

    private static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
}
