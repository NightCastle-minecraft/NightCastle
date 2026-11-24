<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Db;
use SiteGate\Format;
use SiteGate\Gallery;
use SiteGate\Markdown;
use SiteGate\Settings;
use SiteGate\View;

final class HomeController
{
    public static function index(): void
    {
        $about = Db::one('SELECT title, body_md FROM pages WHERE slug = ?', ['about']);

        $rows = Db::all(
            'SELECT slug, title, body_md, cover_path, published_at FROM news
             WHERE is_published = 1 AND published_at <= UTC_TIMESTAMP()
             ORDER BY is_pinned DESC, published_at DESC LIMIT 3'
        );
        $news = [];
        foreach ($rows as $row) {
            $news[] = [
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'cover' => Format::uploadName($row['cover_path']),
                'date' => Format::date((string) $row['published_at']),
                'excerpt' => Markdown::excerpt((string) $row['body_md'], 160),
            ];
        }

        $serverName = Settings::get('server_name', 'MyServer');
        echo View::render('pages/home', [
            'title' => $serverName,
            'serverName' => $serverName,
            'heroBg' => Format::uploadName(Settings::get('hero_bg')),
            'slogan' => Settings::get('slogan'),
            'ip' => Settings::get('display_ip'),
            'aboutTitle' => $about !== null ? (string) $about['title'] : 'Описание сервера',
            'aboutHtml' => $about !== null ? Markdown::render((string) $about['body_md']) : '',
            'news' => $news,
            'gameVersion' => Settings::get('game_version'),
            'modpackUrl' => Format::httpUrl(Settings::get('modpack_url')),
            'discordUrl' => Format::httpUrl(Settings::get('discord_url')),
            'screenshots' => array_map(Gallery::url(...), Gallery::fromEnv()->files()),
        ]);
    }
}
