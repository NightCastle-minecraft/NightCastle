<?php

declare(strict_types=1);

use SiteGate\Controller\AccountController;
use SiteGate\Controller\ApiController;
use SiteGate\Controller\AuthApiController;
use SiteGate\Controller\HomeController;
use SiteGate\Controller\MailLinkController;
use SiteGate\Controller\NewsController;
use SiteGate\Controller\PageController;
use SiteGate\Controller\RegisterController;
use SiteGate\Controller\RulesController;
use SiteGate\Controller\ShopApiController;
use SiteGate\Controller\ShopController;
use SiteGate\Controller\TicketController;
use SiteGate\Router;
use SiteGate\SecurityHeaders;
use SiteGate\View;

require dirname(__DIR__) . '/vendor/autoload.php';

SecurityHeaders::send();

$router = new Router();

$router->get('/', [HomeController::class, 'index']);
$router->get('/api/status', [ApiController::class, 'status']);
$router->get('/news', [NewsController::class, 'index']);
$router->get('/news/{slug}', [NewsController::class, 'show']);
$router->get('/rules', [RulesController::class, 'index']);
$router->get('/start', [PageController::class, 'start']);

// Игрок: вход по ссылке из игры и личный кабинет
$router->get('/auth/{ticket}', [TicketController::class, 'show']);
$router->post('/auth/{ticket}/login', [TicketController::class, 'login']);
$router->post('/auth/{ticket}/register', [TicketController::class, 'register']);
$router->post('/auth/{ticket}/confirm', [TicketController::class, 'confirm']);
$router->post('/auth/{ticket}/deny', [TicketController::class, 'deny']);
$router->post('/auth/{ticket}/switch', [TicketController::class, 'switchAccount']);
$router->get('/shop', [ShopController::class, 'index']);
$router->post('/shop/buy/{id}', [ShopController::class, 'buy']);
$router->get('/register', [RegisterController::class, 'show']);
$router->post('/register', [RegisterController::class, 'register']);
$router->get('/login', [AccountController::class, 'showLogin']);
$router->post('/login', [AccountController::class, 'login']);
$router->post('/logout', [AccountController::class, 'logout']);
$router->get('/account', [AccountController::class, 'show']);
$router->post('/account/email', [AccountController::class, 'changeEmail']);
$router->post('/account/email/resend', [AccountController::class, 'resendEmail']);
$router->get('/email/verify/{token}', [MailLinkController::class, 'verify']);
$router->get('/password/forgot', [MailLinkController::class, 'showForgot']);
$router->post('/password/forgot', [MailLinkController::class, 'forgot']);
$router->get('/password/reset/{token}', [MailLinkController::class, 'showReset']);
$router->post('/password/reset/{token}', [MailLinkController::class, 'reset']);
$router->post('/account/password', [AccountController::class, 'changePassword']);
$router->post('/account/sessions', [AccountController::class, 'resetSessions']);

// API для плагина SiteGateAuth (plugins/docs/auth-api.md)
$router->post('/api/auth/check', [AuthApiController::class, 'check']);
$router->post('/api/auth/tickets', [AuthApiController::class, 'createTicket']);
$router->get('/api/auth/tickets/{ticket}', [AuthApiController::class, 'ticketStatus']);
$router->post('/api/stats/sessions', [AuthApiController::class, 'sessions']);
$router->post('/api/server/heartbeat', [AuthApiController::class, 'heartbeat']);

// API для плагина SiteGateShop (plugins/docs/shop-api.md)
$router->get('/api/shop/pending', [ShopApiController::class, 'pending']);
$router->post('/api/shop/delivered', [ShopApiController::class, 'delivered']);
$router->post('/api/shop/codes', [ShopApiController::class, 'codes']);
$router->get('/api/shop/balance/{name}', [ShopApiController::class, 'balance']);

\SiteGate\Controller\Admin\Routes::register($router);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// HEAD = GET без тела (тело отрезает Apache): мониторинги и превью ссылок шлют HEAD
if ($method === 'HEAD') {
    $method = 'GET';
}

// Админка не индексируется никогда: ни страницы, ни 404, ни 500
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    header('X-Robots-Tag: noindex, nofollow');
}

ob_start();
try {
    if ($router->match($method, $path) === null) {
        View::notFound();
    } else {
        $router->dispatch($method, $path);
    }
    ob_end_flush();
} catch (Throwable $ex) {
    error_log((string) $ex);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    try {
        echo View::render('errors/500', ['title' => 'Ошибка']);
    } catch (Throwable $ex2) {
        // Даже страница ошибки не отрисовалась: минимальная статика, без деталей и inline-стилей
        error_log((string) $ex2);
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Ошибка · MyServer</title></head><body>'
            . '<h1>Что-то пошло не так</h1><p>Попробуйте обновить страницу чуть позже.</p>'
            . '<p><a href="/">На главную</a></p></body></html>';
    }
}
