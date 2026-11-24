<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use SiteGate\AdminLog;
use SiteGate\Auth;
use SiteGate\Db;
use SiteGate\Settings;
use SiteGate\SettingsValidator;
use SiteGate\Upload;
use SiteGate\UploadException;

final class SettingsController
{
    public static function show(): void
    {
        $admin = Page::begin();
        $current = Settings::all();
        self::form($admin, $current, [], 200);
    }

    public static function save(): void
    {
        $admin = Page::beginPost();
        $current = Settings::all();

        $result = SettingsValidator::validate($_POST);
        $values = $result['values'];
        $errors = $result['errors'];

        $hero = $current['hero_bg'] ?? '';
        if (!empty($_POST['hero_remove'])) {
            $hero = '';
        }
        $file = isset($_FILES['hero_file']) && is_array($_FILES['hero_file']) ? $_FILES['hero_file'] : null;
        if ($errors === [] && !Upload::isEmpty($file)) {
            try {
                $hero = Upload::saveImage($file);
            } catch (UploadException $e) {
                $errors['hero_bg'] = $e->getMessage();
            }
        }
        $values['hero_bg'] = $hero;

        if ($errors !== []) {
            self::form($admin, $values, $errors, 422, $current['hero_bg'] ?? '');
            return;
        }

        $changed = [];
        foreach ($values as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            foreach ($changed as $key) {
                $stmt->execute([$key, $values[$key]]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($changed !== []) {
            AdminLog::write($admin['id'], 'settings_update', implode(', ', $changed));
        }
        Page::flash('Сохранено');
        Auth::redirect('/admin/settings');
    }

    /**
     * @param array{id: int, username: string} $admin
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private static function form(array $admin, array $values, array $errors, int $status, ?string $savedHero = null): void
    {
        $hero = $savedHero ?? ($values['hero_bg'] ?? '');
        Page::render('admin/settings', $admin, [
            'title' => 'Настройки',
            'fields' => SettingsValidator::FIELDS,
            'values' => $values,
            'errors' => $errors,
            'bannerEnabled' => ($values['banner_enabled'] ?? '0') === '1',
            'hero' => Upload::isValidName($hero) ? $hero : '',
        ], $status);
    }
}
