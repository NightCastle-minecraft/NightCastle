<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Db;
use SiteGate\Format;
use SiteGate\Markdown;
use SiteGate\View;

final class NewsController
{
    private const PER_PAGE = 10;

    public static function index(): void
    {
        $page = 1;
        if (isset($_GET['page'])) {
            $raw = $_GET['page'];
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,6}$/', $raw) !== 1) {
                View::notFound();
                return;
            }
            $page = (int) $raw;
        }

        $totalRow = Db::one(
            'SELECT COUNT(*) AS c FROM news WHERE is_published = 1 AND published_at <= UTC_TIMESTAMP()'
        );
        $total = (int) ($totalRow['c'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            View::notFound();
            return;
        }

        $offset = ($page - 1) * self::PER_PAGE;
        $rows = Db::all(
            'SELECT slug, title, body_md, cover_path, published_at FROM news
             WHERE is_published = 1 AND published_at <= UTC_TIMESTAMP()
             ORDER BY is_pinned DESC, published_at DESC, id DESC
             LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset
        );
        $news = [];
        foreach ($rows as $row) {
            $news[] = [
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'cover' => self::cover($row['cover_path']),
                'date' => Format::date((string) $row['published_at']),
                'excerpt' => Markdown::excerpt((string) $row['body_md'], 160),
            ];
        }

        echo View::render('pages/news-list', [
            'title' => $page > 1 ? 'Новости, страница ' . $page : 'Новости',
            'news' => $news,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public static function show(array $params = []): void
    {
        $row = Db::one(
            'SELECT title, body_md, cover_path, published_at FROM news
             WHERE slug = ? AND is_published = 1 AND published_at <= UTC_TIMESTAMP()',
            [$params['slug'] ?? '']
        );
        if ($row === null) {
            View::notFound();
            return;
        }

        echo View::render('pages/news-show', [
            'title' => (string) $row['title'],
            'heading' => (string) $row['title'],
            'cover' => self::cover($row['cover_path']),
            'date' => Format::date((string) $row['published_at']),
            'bodyHtml' => Markdown::render((string) $row['body_md']),
        ]);
    }

    private static function cover(mixed $path): string
    {
        return Format::uploadName($path);
    }
}
