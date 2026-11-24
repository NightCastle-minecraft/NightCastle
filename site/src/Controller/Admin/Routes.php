<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\Router;

final class Routes
{
    public static function register(Router $r): void
    {
        $r->get('/admin/login', [AuthController::class, 'showLogin']);
        $r->post('/admin/login', [AuthController::class, 'login']);
        $r->post('/admin/logout', [AuthController::class, 'logout']);

        $r->get('/admin', [DashboardController::class, 'index']);

        $r->get('/admin/settings', [SettingsController::class, 'show']);
        $r->post('/admin/settings', [SettingsController::class, 'save']);

        $r->get('/admin/pages', [PagesController::class, 'index']);
        $r->get('/admin/pages/{slug}', [PagesController::class, 'edit']);
        $r->post('/admin/pages/{slug}', [PagesController::class, 'save']);

        $r->get('/admin/news', [NewsController::class, 'index']);
        $r->get('/admin/news/new', [NewsController::class, 'create']);
        $r->post('/admin/news', [NewsController::class, 'store']);
        $r->get('/admin/news/{id}/edit', [NewsController::class, 'edit']);
        $r->post('/admin/news/{id}', [NewsController::class, 'update']);
        $r->get('/admin/news/{id}/delete', [NewsController::class, 'confirmDelete']);
        $r->post('/admin/news/{id}/delete', [NewsController::class, 'delete']);

        $r->get('/admin/rules', [RulesController::class, 'index']);
        $r->post('/admin/rules/sections', [RulesController::class, 'createSection']);
        $r->post('/admin/rules/sections/{id}', [RulesController::class, 'renameSection']);
        $r->post('/admin/rules/sections/{id}/move', [RulesController::class, 'moveSection']);
        $r->get('/admin/rules/sections/{id}/delete', [RulesController::class, 'confirmDeleteSection']);
        $r->post('/admin/rules/sections/{id}/delete', [RulesController::class, 'deleteSection']);
        $r->post('/admin/rules/sections/{id}/items', [RulesController::class, 'createItem']);
        $r->get('/admin/rules/items/{id}/edit', [RulesController::class, 'editItem']);
        $r->post('/admin/rules/items/{id}', [RulesController::class, 'saveItem']);
        $r->post('/admin/rules/items/{id}/move', [RulesController::class, 'moveItem']);
        $r->get('/admin/rules/items/{id}/delete', [RulesController::class, 'confirmDeleteItem']);
        $r->post('/admin/rules/items/{id}/delete', [RulesController::class, 'deleteItem']);

        $r->get('/admin/players', [PlayersController::class, 'index']);
        $r->post('/admin/players/{shard}/{id}/sessions', [PlayersController::class, 'resetSessions']);
        $r->post('/admin/players/{shard}/{id}/password', [PlayersController::class, 'newPassword']);

        $r->get('/admin/shop', [ShopController::class, 'index']);
        $r->get('/admin/shop/new', [ShopController::class, 'create']);
        $r->post('/admin/shop', [ShopController::class, 'store']);
        $r->get('/admin/shop/orders', [ShopController::class, 'orders']);
        $r->post('/admin/shop/orders/{id}/cancel', [ShopController::class, 'cancel']);
        $r->post('/admin/shop/orders/{id}/retry', [ShopController::class, 'retry']);
        $r->get('/admin/shop/wallet', [ShopController::class, 'wallet']);
        $r->post('/admin/shop/wallet', [ShopController::class, 'adjust']);
        $r->get('/admin/shop/{id}/edit', [ShopController::class, 'edit']);
        $r->post('/admin/shop/{id}', [ShopController::class, 'update']);
        $r->post('/admin/shop/{id}/delete', [ShopController::class, 'delete']);

        $r->get('/admin/password', [PasswordController::class, 'show']);
        $r->post('/admin/password', [PasswordController::class, 'save']);
    }
}
