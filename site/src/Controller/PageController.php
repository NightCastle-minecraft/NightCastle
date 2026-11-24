<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Db;
use SiteGate\Format;
use SiteGate\Markdown;
use SiteGate\Settings;
use SiteGate\View;

final class PageController
{
    /**
     * Общий рендер страницы из таблицы pages (title + Markdown).
     */
    public static function show(string $slug): void
    {
        $page = Db::one('SELECT title, body_md FROM pages WHERE slug = ?', [$slug]);
        if ($page === null) {
            View::notFound();
            return;
        }
        echo View::render('pages/page', [
            'title' => (string) $page['title'],
            'heading' => (string) $page['title'],
            'bodyHtml' => Markdown::render((string) $page['body_md']),
        ]);
    }

    public static function start(): void
    {
        $page = Db::one('SELECT title, body_md FROM pages WHERE slug = ?', ['start']);
        if ($page === null) {
            View::notFound();
            return;
        }
        echo View::render('pages/start', [
            'title' => (string) $page['title'],
            'heading' => (string) $page['title'],
            'bodyHtml' => Markdown::render((string) $page['body_md']),
            'ip' => Settings::get('display_ip'),
            'gameVersion' => Settings::get('game_version'),
            'modpackUrl' => Format::httpUrl(Settings::get('modpack_url')),
            'discordUrl' => Format::httpUrl(Settings::get('discord_url')),
        ]);
    }
}
