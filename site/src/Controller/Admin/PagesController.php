<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;
use SiteGate\Markdown;

final class PagesController
{
    private const SLUGS = ['about', 'start'];

    public static function index(): void
    {
        $admin = Page::begin();
        $rows = Db::all("SELECT slug, title FROM pages WHERE slug IN ('about', 'start') ORDER BY slug");
        Page::render('admin/pages', $admin, ['title' => 'Страницы', 'pages' => $rows]);
    }

    /** @param array<string, string> $params */
    public static function edit(array $params): void
    {
        $admin = Page::begin();
        $row = self::find($params['slug'] ?? '');
        if ($row === null) {
            Page::notFound();
            return;
        }
        self::form($admin, (string) $row['slug'], (string) $row['title'], (string) $row['body_md'], [], null);
    }

    /** @param array<string, string> $params */
    public static function save(array $params): void
    {
        $admin = Page::beginPost();
        $row = self::find($params['slug'] ?? '');
        if ($row === null) {
            Page::notFound();
            return;
        }
        $slug = (string) $row['slug'];
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $body = is_string($_POST['body_md'] ?? null) ? str_replace("\r\n", "\n", $_POST['body_md']) : '';

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Заголовок обязателен';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Слишком длинный заголовок (максимум 255 символов)';
        }
        if (mb_strlen($body) > 100000) {
            $errors['body_md'] = 'Слишком длинный текст (максимум 100000 символов)';
        }

        if (($_POST['action'] ?? '') === 'preview') {
            self::form($admin, $slug, $title, $body, $errors, $errors === [] ? Markdown::render($body) : null);
            return;
        }
        if ($errors !== []) {
            self::form($admin, $slug, $title, $body, $errors, null, 422);
            return;
        }

        Db::pdo()->prepare('UPDATE pages SET title = ?, body_md = ? WHERE slug = ?')->execute([$title, $body, $slug]);
        AdminLog::write($admin['id'], 'page_update', $slug);
        Page::flash('Сохранено');
        Auth::redirect('/admin/pages/' . $slug);
    }

    /** @return array<string, mixed>|null */
    private static function find(string $slug): ?array
    {
        if (!in_array($slug, self::SLUGS, true)) {
            return null;
        }
        return Db::one('SELECT slug, title, body_md FROM pages WHERE slug = ?', [$slug]);
    }

    /**
     * @param array{id: int, username: string} $admin
     * @param array<string, string> $errors
     */
    private static function form(array $admin, string $slug, string $title, string $body, array $errors, ?string $preview, int $status = 200): void
    {
        Page::render('admin/page-edit', $admin, [
            'title' => 'Страница: ' . $slug,
            'slug' => $slug,
            'pageTitle' => $title,
            'body' => $body,
            'errors' => $errors,
            'preview' => $preview,
        ], $status);
    }
}
