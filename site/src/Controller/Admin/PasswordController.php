<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;

final class PasswordController
{
    private const MIN_LENGTH = 12;

    public static function show(): void
    {
        $admin = Page::begin();
        Page::render('admin/password', $admin, ['title' => 'Пароль', 'errors' => []]);
    }

    public static function save(): void
    {
        $admin = Page::beginPost();
        $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
        $new = is_string($_POST['new'] ?? null) ? $_POST['new'] : '';
        $repeat = is_string($_POST['repeat'] ?? null) ? $_POST['repeat'] : '';

        $row = Db::one('SELECT password_hash FROM admins WHERE id = ?', [$admin['id']]);
        $errors = [];
        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            $errors['current'] = 'Текущий пароль неверный';
        }
        if (mb_strlen($new) < self::MIN_LENGTH) {
            $errors['new'] = 'Новый пароль короче ' . self::MIN_LENGTH . ' символов';
        } elseif ($new === $current) {
            $errors['new'] = 'Новый пароль совпадает с текущим';
        }
        if ($new !== $repeat) {
            $errors['repeat'] = 'Пароли не совпадают';
        }
        if ($errors !== []) {
            Page::render('admin/password', $admin, ['title' => 'Пароль', 'errors' => $errors], 422);
            return;
        }

        Db::pdo()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        session_regenerate_id(true);
        AdminLog::write($admin['id'], 'password_change');
        Page::flash('Пароль изменён');
        Auth::redirect('/admin/password');
    }
}
