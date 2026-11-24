<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use DateTimeImmutable;
use DateTimeZone;
use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;
use SiteGate\Markdown;
use SiteGate\Slug;
use SiteGate\Upload;
use SiteGate\UploadException;

final class NewsController
{
    private const TZ = 'Europe/Kyiv';
    private const MAX_BODY = 200000;

    public static function index(): void
    {
        $admin = Page::begin();
        $rows = Db::all(
            'SELECT id, slug, title, is_published, is_pinned, published_at, created_at,
                    (is_published = 1 AND published_at <= UTC_TIMESTAMP()) AS is_live
             FROM news
             ORDER BY is_pinned DESC, COALESCE(published_at, created_at) DESC, id DESC'
        );
        $items = [];
        foreach ($rows as $r) {
            if ((int) $r['is_live'] === 1) {
                $status = 'published';
            } elseif ((int) $r['is_published'] === 1) {
                $status = 'scheduled';
            } else {
                $status = 'draft';
            }
            $items[] = [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'title' => (string) $r['title'],
                'status' => $status,
                'pinned' => (int) $r['is_pinned'] === 1,
                'date' => self::displayDate((string) ($r['published_at'] ?? $r['created_at'])),
            ];
        }
        Page::render('admin/news', $admin, ['title' => 'Новости', 'items' => $items]);
    }

    public static function create(): void
    {
        $admin = Page::begin();
        self::form($admin, null, self::emptyValues(), [], '', null, 200);
    }

    /** @param array<string, string> $params */
    public static function edit(array $params): void
    {
        $admin = Page::begin();
        $row = self::find($params['id'] ?? '');
        if ($row === null) {
            Page::notFound();
            return;
        }
        $values = [
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'body_md' => (string) $row['body_md'],
            'is_published' => (int) $row['is_published'] === 1,
            'is_pinned' => (int) $row['is_pinned'] === 1,
            'published_at' => $row['published_at'] === null ? '' : self::toLocalInput((string) $row['published_at']),
            'cover_remove' => false,
        ];
        self::form($admin, (int) $row['id'], $values, [], (string) ($row['cover_path'] ?? ''), null, 200);
    }

    public static function store(): void
    {
        $admin = Page::beginPost();
        self::save($admin, null, null);
    }

    /** @param array<string, string> $params */
    public static function update(array $params): void
    {
        $admin = Page::beginPost();
        $row = self::find($params['id'] ?? '');
        if ($row === null) {
            Page::notFound();
            return;
        }
        self::save($admin, (int) $row['id'], $row);
    }

    /** @param array<string, string> $params */
    public static function confirmDelete(array $params): void
    {
        $admin = Page::begin();
        $row = self::find($params['id'] ?? '');
        if ($row === null) {
            Page::notFound();
            return;
        }
        Page::render('admin/news-delete', $admin, [
            'title' => 'Удалить новость',
            'newsId' => (int) $row['id'],
            'newsTitle' => (string) $row['title'],
        ]);
    }

    /** @param array<string, string> $params */
    public static function delete(array $params): void
    {
        $admin = Page::beginPost();
        $row = self::find($params['id'] ?? '');
        if ($row === null) {
            Page::notFound();
            return;
        }
        Db::pdo()->prepare('DELETE FROM news WHERE id = ?')->execute([(int) $row['id']]);
        AdminLog::write($admin['id'], 'news_delete', 'id=' . (int) $row['id'] . ', slug=' . (string) $row['slug']);
        Page::flash('Новость удалена');
        Auth::redirect('/admin/news');
    }

    /**
     * @param array{id: int, username: string} $admin
     * @param array<string, mixed>|null $row существующая запись (при редактировании)
     */
    private static function save(array $admin, ?int $id, ?array $row): void
    {
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $slugIn = is_string($_POST['slug'] ?? null) ? trim($_POST['slug']) : '';
        $body = is_string($_POST['body_md'] ?? null) ? str_replace("\r\n", "\n", $_POST['body_md']) : '';
        $dateIn = is_string($_POST['published_at'] ?? null) ? trim($_POST['published_at']) : '';
        $values = [
            'title' => $title,
            'slug' => $slugIn,
            'body_md' => $body,
            'is_published' => !empty($_POST['is_published']),
            'is_pinned' => !empty($_POST['is_pinned']),
            'published_at' => $dateIn,
            'cover_remove' => !empty($_POST['cover_remove']),
        ];
        $savedCover = $row === null ? '' : (string) ($row['cover_path'] ?? '');

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Заголовок обязателен';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Слишком длинный заголовок (максимум 255 символов)';
        }
        if ($body === '') {
            $errors['body_md'] = 'Текст обязателен';
        } elseif (mb_strlen($body) > self::MAX_BODY) {
            $errors['body_md'] = 'Слишком длинный текст (максимум 200000 символов)';
        }
        if ($slugIn !== '') {
            if (!Slug::isValid($slugIn)) {
                $errors['slug'] = 'Slug: только латиница в нижнем регистре, цифры и дефисы (до 190 символов)';
            } elseif (self::slugTaken($slugIn, $id)) {
                $errors['slug'] = 'Такой slug уже занят';
            }
        }
        $publishedUtc = null;
        if ($dateIn !== '') {
            $publishedUtc = self::fromLocalInput($dateIn);
            if ($publishedUtc === null) {
                $errors['published_at'] = 'Неверная дата публикации';
            }
        }

        if (($_POST['action'] ?? '') === 'preview') {
            $preview = ($errors['body_md'] ?? null) === null ? Markdown::render($body) : null;
            self::form($admin, $id, $values, $errors, $savedCover, $preview, 200);
            return;
        }

        $cover = $values['cover_remove'] ? '' : $savedCover;
        $file = isset($_FILES['cover_file']) && is_array($_FILES['cover_file']) ? $_FILES['cover_file'] : null;
        if ($errors === [] && !Upload::isEmpty($file)) {
            try {
                $cover = Upload::saveImage($file);
            } catch (UploadException $e) {
                $errors['cover_file'] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            self::form($admin, $id, $values, $errors, $savedCover, null, 422);
            return;
        }

        $slug = $slugIn !== '' ? $slugIn : self::uniqueSlug($title, $id);
        if ($values['is_published'] && $publishedUtc === null) {
            $publishedUtc = gmdate('Y-m-d H:i:s');
        }
        $coverDb = Upload::isValidName($cover) ? $cover : null;
        $pdo = Db::pdo();

        try {
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO news (slug, title, body_md, cover_path, is_published, is_pinned, published_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                )->execute([$slug, $title, $body, $coverDb, (int) $values['is_published'], (int) $values['is_pinned'], $publishedUtc]);
                $id = (int) $pdo->lastInsertId();
                $action = 'news_create';
            } else {
                $pdo->prepare(
                    'UPDATE news SET slug = ?, title = ?, body_md = ?, cover_path = ?, is_published = ?, is_pinned = ?,
                            published_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
                )->execute([$slug, $title, $body, $coverDb, (int) $values['is_published'], (int) $values['is_pinned'], $publishedUtc, $id]);
                $action = 'news_update';
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { // гонка по UNIQUE(slug)
                $errors['slug'] = 'Такой slug уже занят';
                self::form($admin, $row === null ? null : $id, $values, $errors, $savedCover, null, 422);
                return;
            }
            throw $e;
        }

        AdminLog::write($admin['id'], $action, 'id=' . $id . ', slug=' . $slug);
        Page::flash('Сохранено');
        Auth::redirect('/admin/news/' . $id . '/edit');
    }

    /**
     * @param array{id: int, username: string} $admin
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private static function form(array $admin, ?int $id, array $values, array $errors, string $savedCover, ?string $preview, int $status): void
    {
        Page::render('admin/news-edit', $admin, [
            'title' => $id === null ? 'Новая новость' : 'Редактирование новости',
            'newsId' => $id,
            'values' => $values,
            'errors' => $errors,
            'cover' => Upload::isValidName($savedCover) ? $savedCover : '',
            'preview' => $preview,
        ], $status);
    }

    /** @return array<string, mixed> */
    private static function emptyValues(): array
    {
        return [
            'title' => '', 'slug' => '', 'body_md' => '', 'is_published' => false,
            'is_pinned' => false, 'published_at' => '', 'cover_remove' => false,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function find(string $id): ?array
    {
        if ($id === '' || !ctype_digit($id) || strlen($id) > 10 || (int) $id < 1) {
            return null;
        }
        return Db::one(
            'SELECT id, slug, title, body_md, cover_path, is_published, is_pinned, published_at FROM news WHERE id = ?',
            [(int) $id]
        );
    }

    private static function slugTaken(string $slug, ?int $exceptId): bool
    {
        return Db::one('SELECT id FROM news WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null;
    }

    private static function uniqueSlug(string $title, ?int $id): string
    {
        $base = Slug::make($title);
        if ($base === '') {
            $base = 'news';
        }
        $slug = $base;
        for ($n = 2; self::slugTaken($slug, $id); $n++) {
            $suffix = '-' . $n;
            $slug = rtrim(substr($base, 0, Slug::MAX - strlen($suffix)), '-') . $suffix;
        }
        return $slug;
    }

    /** Значение datetime-local (Киев) в UTC 'Y-m-d H:i:s' или null, если формат неверный. */
    private static function fromLocalInput(string $s): ?string
    {
        $tz = new DateTimeZone(self::TZ);
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $s, $tz);
            $err = DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /** UTC из БД в значение для input datetime-local (Киев). */
    private static function toLocalInput(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->format('Y-m-d\TH:i');
    }

    private static function displayDate(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->format('d.m.Y H:i');
    }
}
