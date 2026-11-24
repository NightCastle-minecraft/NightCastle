<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\Auth;
use SiteGate\Csrf;
use SiteGate\View;

/** Общие помощники админ-контроллеров. */
final class Page
{
    /** GET-запрос: заголовки, сессия, вход обязателен. @return array{id: int, username: string} */
    public static function begin(): array
    {
        AuthController::headers();
        Auth::startSession();
        return Auth::requireAdmin();
    }

    /** POST-запрос: то же + CSRF (иначе 403 и выход). @return array{id: int, username: string} */
    public static function beginPost(): array
    {
        $admin = self::begin();
        if (!Csrf::verifyRequest()) {
            http_response_code(403);
            echo View::render('admin/forbidden', ['title' => 'Доступ запрещён'], 'admin/layout');
            exit;
        }
        return $admin;
    }

    /** @param array<string, mixed> $data */
    public static function render(string $template, array $admin, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH);
        echo View::render($template, $data + [
            'admin' => $admin,
            'csrf' => Csrf::token(),
            'flash' => is_string($flash) ? $flash : null,
            'navPath' => is_string($path) ? rtrim($path, '/') : '/admin',
        ], 'admin/layout');
    }

    public static function flash(string $message): void
    {
        $_SESSION['flash'] = $message;
    }

    public static function notFound(): void
    {
        http_response_code(404);
        echo View::render('errors/404', ['title' => 'Страница не найдена']);
    }
}
