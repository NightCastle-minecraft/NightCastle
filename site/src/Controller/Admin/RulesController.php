<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;
use SiteGate\Markdown;
use SiteGate\Ordering;

final class RulesController
{
    private const MAX_BODY = 20000;

    public static function index(): void
    {
        $admin = Page::begin();
        $sections = Db::all('SELECT id, title FROM rule_sections ORDER BY position, id');
        $rules = Db::all('SELECT id, section_id, body_md FROM rules ORDER BY position, id');

        $bySection = [];
        foreach ($rules as $rule) {
            $bySection[(int) $rule['section_id']][] = [
                'id' => (int) $rule['id'],
                'body' => (string) $rule['body_md'],
                'html' => Markdown::render((string) $rule['body_md']),
            ];
        }

        $view = [];
        $n = 0;
        foreach ($sections as $section) {
            $items = $bySection[(int) $section['id']] ?? [];
            // Нумерация как на сайте: секции без пунктов номера не получают.
            $number = $items === [] ? '' : (string) ++$n;
            foreach ($items as $i => $item) {
                $items[$i]['number'] = $number . '.' . ($i + 1);
            }
            $view[] = [
                'id' => (int) $section['id'],
                'title' => (string) $section['title'],
                'number' => $number,
                'items' => $items,
            ];
        }
        Page::render('admin/rules', $admin, ['title' => 'Правила', 'sections' => $view]);
    }

    public static function createSection(): void
    {
        $admin = Page::beginPost();
        $title = self::title();
        if ($title === null) {
            Page::flash('Название секции обязательно и не длиннее 255 символов');
            Auth::redirect('/admin/rules');
        }
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO rule_sections (title, position) SELECT ?, COALESCE(MAX(position), 0) + 1 FROM rule_sections')
            ->execute([$title]);
        $id = (int) $pdo->lastInsertId();
        AdminLog::write($admin['id'], 'rule_section_create', 'id=' . $id);
        Page::flash('Секция добавлена');
        Auth::redirect('/admin/rules#section-' . $id);
    }

    /** @param array<string, string> $params */
    public static function renameSection(array $params): void
    {
        $admin = Page::beginPost();
        $section = self::section($params);
        if ($section === null) {
            Page::notFound();
            return;
        }
        $id = (int) $section['id'];
        $title = self::title();
        if ($title === null) {
            Page::flash('Название секции обязательно и не длиннее 255 символов');
            Auth::redirect('/admin/rules#section-' . $id);
        }
        Db::pdo()->prepare('UPDATE rule_sections SET title = ? WHERE id = ?')->execute([$title, $id]);
        AdminLog::write($admin['id'], 'rule_section_rename', 'id=' . $id);
        Page::flash('Секция переименована');
        Auth::redirect('/admin/rules#section-' . $id);
    }

    /** @param array<string, string> $params */
    public static function moveSection(array $params): void
    {
        $admin = Page::beginPost();
        $section = self::section($params);
        if ($section === null) {
            Page::notFound();
            return;
        }
        $id = (int) $section['id'];
        if (self::move('rule_sections', null, $id)) {
            AdminLog::write($admin['id'], 'rule_section_move', 'id=' . $id . ' ' . self::direction());
        }
        Auth::redirect('/admin/rules#section-' . $id);
    }

    /** @param array<string, string> $params */
    public static function confirmDeleteSection(array $params): void
    {
        $admin = Page::begin();
        $section = self::section($params);
        if ($section === null) {
            Page::notFound();
            return;
        }
        $row = Db::one('SELECT COUNT(*) AS c FROM rules WHERE section_id = ?', [(int) $section['id']]);
        $count = (int) ($row['c'] ?? 0);
        Page::render('admin/rules-confirm', $admin, [
            'title' => 'Удаление секции',
            'question' => 'Удалить секцию «' . $section['title'] . '» и пунктов: ' . $count . '?',
            'action' => '/admin/rules/sections/' . (int) $section['id'] . '/delete',
            'back' => '/admin/rules#section-' . (int) $section['id'],
        ]);
    }

    /** @param array<string, string> $params */
    public static function deleteSection(array $params): void
    {
        $admin = Page::beginPost();
        $section = self::section($params);
        if ($section === null) {
            Page::notFound();
            return;
        }
        $id = (int) $section['id'];
        Db::pdo()->prepare('DELETE FROM rule_sections WHERE id = ?')->execute([$id]); // rules: ON DELETE CASCADE
        AdminLog::write($admin['id'], 'rule_section_delete', 'id=' . $id);
        Page::flash('Секция удалена');
        Auth::redirect('/admin/rules');
    }

    /** @param array<string, string> $params */
    public static function createItem(array $params): void
    {
        $admin = Page::beginPost();
        $section = self::section($params);
        if ($section === null) {
            Page::notFound();
            return;
        }
        $sid = (int) $section['id'];
        $body = self::body();
        $error = self::bodyError($body);
        if ($error !== null) {
            Page::flash($error);
            Auth::redirect('/admin/rules#section-' . $sid);
        }
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO rules (section_id, body_md, position) SELECT ?, ?, COALESCE(MAX(position), 0) + 1 FROM rules WHERE section_id = ?')
            ->execute([$sid, $body, $sid]);
        $id = (int) $pdo->lastInsertId();
        AdminLog::write($admin['id'], 'rule_create', 'id=' . $id);
        Page::flash('Пункт добавлен');
        Auth::redirect('/admin/rules#section-' . $sid);
    }

    /** @param array<string, string> $params */
    public static function editItem(array $params): void
    {
        $admin = Page::begin();
        $item = self::item($params);
        if ($item === null) {
            Page::notFound();
            return;
        }
        self::form($admin, $item, (string) $item['body_md'], null, null);
    }

    /** @param array<string, string> $params */
    public static function saveItem(array $params): void
    {
        $admin = Page::beginPost();
        $item = self::item($params);
        if ($item === null) {
            Page::notFound();
            return;
        }
        $body = self::body();
        $error = self::bodyError($body);

        if (($_POST['action'] ?? '') === 'preview') {
            self::form($admin, $item, $body, $error, $error === null ? Markdown::render($body) : null);
            return;
        }
        if ($error !== null) {
            self::form($admin, $item, $body, $error, null, 422);
            return;
        }
        $id = (int) $item['id'];
        Db::pdo()->prepare('UPDATE rules SET body_md = ? WHERE id = ?')->execute([$body, $id]);
        AdminLog::write($admin['id'], 'rule_update', 'id=' . $id);
        Page::flash('Пункт сохранён');
        Auth::redirect('/admin/rules#section-' . (int) $item['section_id']);
    }

    /** @param array<string, string> $params */
    public static function moveItem(array $params): void
    {
        $admin = Page::beginPost();
        $item = self::item($params);
        if ($item === null) {
            Page::notFound();
            return;
        }
        $id = (int) $item['id'];
        if (self::move('rules', (int) $item['section_id'], $id)) {
            AdminLog::write($admin['id'], 'rule_move', 'id=' . $id . ' ' . self::direction());
        }
        Auth::redirect('/admin/rules#section-' . (int) $item['section_id']);
    }

    /** @param array<string, string> $params */
    public static function confirmDeleteItem(array $params): void
    {
        $admin = Page::begin();
        $item = self::item($params);
        if ($item === null) {
            Page::notFound();
            return;
        }
        Page::render('admin/rules-confirm', $admin, [
            'title' => 'Удаление пункта',
            'question' => 'Удалить пункт?',
            'detail' => mb_strimwidth((string) $item['body_md'], 0, 300, '…'),
            'action' => '/admin/rules/items/' . (int) $item['id'] . '/delete',
            'back' => '/admin/rules#section-' . (int) $item['section_id'],
        ]);
    }

    /** @param array<string, string> $params */
    public static function deleteItem(array $params): void
    {
        $admin = Page::beginPost();
        $item = self::item($params);
        if ($item === null) {
            Page::notFound();
            return;
        }
        $id = (int) $item['id'];
        Db::pdo()->prepare('DELETE FROM rules WHERE id = ?')->execute([$id]);
        AdminLog::write($admin['id'], 'rule_delete', 'id=' . $id);
        Page::flash('Пункт удалён');
        Auth::redirect('/admin/rules#section-' . (int) $item['section_id']);
    }

    /** Обмен позиций в транзакции. $sectionId = null: секции, иначе пункты этой секции. */
    private static function move(string $table, ?int $sectionId, int $id): bool
    {
        $dir = self::direction();
        if ($dir === '') {
            return false;
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($table === 'rules') {
                $stmt = $pdo->prepare('SELECT id, position FROM rules WHERE section_id = ? ORDER BY position, id FOR UPDATE');
                $stmt->execute([$sectionId]);
            } else {
                $stmt = $pdo->prepare('SELECT id, position FROM rule_sections ORDER BY position, id FOR UPDATE');
                $stmt->execute();
            }
            $positions = [];
            foreach ($stmt->fetchAll() as $row) {
                $positions[(int) $row['id']] = (int) $row['position'];
            }
            $changes = Ordering::move($positions, $id, $dir);
            if ($changes === null) {
                $pdo->commit();
                return false;
            }
            $upd = $pdo->prepare($table === 'rules' ? 'UPDATE rules SET position = ? WHERE id = ?' : 'UPDATE rule_sections SET position = ? WHERE id = ?');
            foreach ($changes as $rowId => $pos) {
                $upd->execute([$pos, $rowId]);
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function direction(): string
    {
        $d = $_POST['direction'] ?? '';
        return $d === 'up' || $d === 'down' ? $d : '';
    }

    private static function title(): ?string
    {
        $t = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        return $t === '' || mb_strlen($t) > 255 ? null : $t;
    }

    private static function body(): string
    {
        return is_string($_POST['body_md'] ?? null) ? trim(str_replace("\r\n", "\n", $_POST['body_md'])) : '';
    }

    private static function bodyError(string $body): ?string
    {
        if ($body === '') {
            return 'Текст пункта обязателен';
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            return 'Слишком длинный текст (максимум ' . self::MAX_BODY . ' символов)';
        }
        return null;
    }

    /** @param array<string, string> $params @return array<string, mixed>|null */
    private static function section(array $params): ?array
    {
        $id = self::id($params);
        return $id === null ? null : Db::one('SELECT id, title FROM rule_sections WHERE id = ?', [$id]);
    }

    /** @param array<string, string> $params @return array<string, mixed>|null */
    private static function item(array $params): ?array
    {
        $id = self::id($params);
        return $id === null ? null : Db::one('SELECT id, section_id, body_md FROM rules WHERE id = ?', [$id]);
    }

    /** @param array<string, string> $params */
    private static function id(array $params): ?int
    {
        $raw = $params['id'] ?? '';
        if (!ctype_digit($raw) || strlen($raw) > 10 || (int) $raw < 1) {
            return null;
        }
        return (int) $raw;
    }

    /**
     * @param array{id: int, username: string} $admin
     * @param array<string, mixed> $item
     */
    private static function form(array $admin, array $item, string $body, ?string $error, ?string $preview, int $status = 200): void
    {
        $section = Db::one('SELECT title FROM rule_sections WHERE id = ?', [(int) $item['section_id']]);
        Page::render('admin/rules-item-edit', $admin, [
            'title' => 'Пункт правил',
            'itemId' => (int) $item['id'],
            'sectionId' => (int) $item['section_id'],
            'sectionTitle' => (string) ($section['title'] ?? ''),
            'body' => $body,
            'error' => $error,
            'preview' => $preview,
        ], $status);
    }
}
