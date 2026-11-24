<?php

declare(strict_types=1);

namespace SiteGate;

/** Валидация формы настроек (чистая логика, без БД). hero_bg обрабатывается отдельно (Upload). */
final class SettingsValidator
{
    /** @var array<string, array{label: string, max: int, required: bool, url: bool, multiline?: bool}> */
    public const FIELDS = [
        'server_name' => ['label' => 'Название сервера', 'max' => 64, 'required' => true, 'url' => false],
        'slogan' => ['label' => 'Слоган', 'max' => 200, 'required' => false, 'url' => false],
        'display_ip' => ['label' => 'IP для показа', 'max' => 100, 'required' => true, 'url' => false],
        'game_version' => ['label' => 'Версия игры', 'max' => 32, 'required' => false, 'url' => false],
        'modpack_url' => ['label' => 'Ссылка на сборку', 'max' => 500, 'required' => false, 'url' => true],
        'discord_url' => ['label' => 'Discord', 'max' => 500, 'required' => false, 'url' => true],
        'banner_text' => ['label' => 'Текст баннера', 'max' => 500, 'required' => false, 'url' => false],
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $key => $rule) {
            $raw = $input[$key] ?? '';
            $v = is_string($raw) ? trim($raw) : '';
            $values[$key] = $v;
            if ($v === '') {
                if ($rule['required']) {
                    $errors[$key] = 'Поле обязательно';
                }
                continue;
            }
            if (mb_strlen($v) > $rule['max']) {
                $errors[$key] = 'Слишком длинное значение (максимум ' . $rule['max'] . ' символов)';
            } elseif ($rule['url'] && !self::isHttpUrl($v)) {
                $errors[$key] = 'Нужна ссылка вида https://example.com';
            }
        }
        $values['banner_enabled'] = !empty($input['banner_enabled']) ? '1' : '0';
        return ['values' => $values, 'errors' => $errors];
    }

    public static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
