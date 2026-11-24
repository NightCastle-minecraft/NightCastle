<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Csrf;
use SiteGate\DbAttemptStore;
use SiteGate\RateLimit;
use SiteGate\View;

final class AuthController
{
    private const GENERIC_ERROR = 'Неверный логин или пароль';

    public static function headers(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }

    public static function showLogin(): void
    {
        self::headers();
        Auth::startSession();
        if (Auth::currentAdmin() !== null) {
            Auth::redirect('/admin');
        }
        self::renderLogin(null);
    }

    public static function login(): void
    {
        self::headers();
        Auth::startSession();
        if (!Csrf::verifyRequest()) {
            self::forbidden();
            return;
        }

        $ip = Auth::clientIp();
        $limit = new RateLimit(new DbAttemptStore());
        if ($limit->isBlocked($ip)) {
            http_response_code(429);
            self::renderLogin('Слишком много неудачных попыток входа. Попробуйте снова через ' . $limit->windowMinutes() . ' минут.');
            return;
        }

        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($username !== '' && $password !== '' && Auth::login($username, $password)) {
            $limit->clear($ip);
            $admin = Auth::currentAdmin();
            AdminLog::write($admin['id'] ?? null, 'login', 'ip=' . $ip);
            Auth::redirect('/admin');
        }

        $limit->recordFailure($ip);
        http_response_code(401);
        self::renderLogin(self::GENERIC_ERROR, $username);
    }

    public static function logout(): void
    {
        self::headers();
        Auth::startSession();
        if (!Csrf::verifyRequest()) {
            self::forbidden();
            return;
        }
        $admin = Auth::currentAdmin();
        if ($admin !== null) {
            AdminLog::write($admin['id'], 'logout');
        }
        Auth::logout();
        Auth::redirect('/admin/login');
    }

    private static function renderLogin(?string $error, string $username = ''): void
    {
        echo View::render('admin/login', [
            'title' => 'Вход',
            'error' => $error,
            'username' => $username,
            'csrf' => Csrf::token(),
        ], 'admin/layout');
    }

    private static function forbidden(): void
    {
        http_response_code(403);
        echo View::render('admin/forbidden', ['title' => 'Доступ запрещён'], 'admin/layout');
    }
}
