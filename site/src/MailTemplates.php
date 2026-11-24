<?php

declare(strict_types=1);

namespace SiteGate;

/** Тексты писем (D18): подтверждение почты, приветствие, сброс пароля. Каждое в двух видах: текст и HTML. */
final class MailTemplates
{
    /** Название сервера в письмах: SITE_NAME из окружения. */
    public static function name(): string
    {
        return Env::get('SITE_NAME', 'MyServer');
    }

    /** @return array{0: string, 1: string, 2: string} тема, текст, HTML */
    public static function verify(string $name, string $link): array
    {
        return self::make(
            'Подтвердите почту для ' . self::name(),
            $name,
            [
                'Вы зарегистрировали аккаунт ' . $name . ' на сервере ' . self::name() . '.',
                'Подтвердите, что это ваша почта: по ней можно будет восстановить пароль.',
            ],
            'Подтвердить почту',
            $link,
            'Ссылка действует 24 часа. Если вы не регистрировались, просто удалите это письмо.'
        );
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function welcome(string $name, string $siteUrl): array
    {
        return self::make(
            'Добро пожаловать на ' . self::name(),
            $name,
            [
                'Почта подтверждена, аккаунт ' . $name . ' готов. Рады видеть вас на сервере!',
                'Адрес сервера: ' . Env::gameAddress() . ' (Minecraft Java 26.3). С того же IP игра пускает без сайта 7 дней.',
                'Прочитайте правила: без грифа, без PvP, без политики и контента 18+.',
            ],
            'Открыть личный кабинет',
            $siteUrl . '/account',
            'Правила: ' . $siteUrl . '/rules'
        );
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function reset(string $name, string $link): array
    {
        return self::make(
            'Сброс пароля ' . self::name(),
            $name,
            [
                'Кто-то (надеемся, вы) запросил сброс пароля для аккаунта ' . $name . '.',
                'Нажмите кнопку и придумайте новый пароль. После смены все входы в игру и на сайт сбросятся.',
            ],
            'Сменить пароль',
            $link,
            'Ссылка действует 1 час и срабатывает один раз. Если вы ничего не запрашивали, просто удалите это письмо: пароль останется прежним.'
        );
    }

    /**
     * @param list<string> $paragraphs
     * @return array{0: string, 1: string, 2: string}
     */
    private static function make(string $subject, string $name, array $paragraphs, string $button, string $link, string $footnote): array
    {
        $site = Env::siteUrl();
        $text = 'Привет, ' . $name . "!\n\n" . implode("\n\n", $paragraphs) . "\n\n"
            . $button . ': ' . $link . "\n\n" . $footnote . "\n\n-- \n" . self::name() . " · " . $site . "\n";

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ps = '';
        foreach ($paragraphs as $p) {
            $ps .= '<p style="margin:0 0 14px;font:16px/1.55 Arial,Helvetica,sans-serif;color:#2a2740">' . $h($p) . '</p>';
        }
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>' . $h($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#efedf7">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#efedf7;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden">'
            . '<tr><td align="center" style="background:#141229;padding:28px 24px 20px">'
            . '<img src="' . $h($site . '/assets/img/mail-logo.png') . '" width="96" height="96" alt="' . $h(self::name()) . '" style="display:block;border:0;border-radius:18px">'
            . '<div style="margin-top:12px;font:bold 22px/1.2 Georgia,serif;letter-spacing:2px;color:#d9d3ff">' . $h(mb_strtoupper(self::name())) . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:28px 28px 8px">'
            . '<p style="margin:0 0 16px;font:bold 18px/1.4 Arial,Helvetica,sans-serif;color:#141229">Привет, ' . $h($name) . '!</p>'
            . $ps
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0"><tr><td style="background:#7b6cf0;border-radius:10px">'
            . '<a href="' . $h($link) . '" style="display:inline-block;padding:13px 26px;font:bold 16px Arial,Helvetica,sans-serif;color:#ffffff;text-decoration:none">' . $h($button) . '</a>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 8px;font:13px/1.5 Arial,Helvetica,sans-serif;color:#6b6880">Кнопка не работает? Скопируйте ссылку в браузер:<br>'
            . '<a href="' . $h($link) . '" style="color:#5b4ce0;word-break:break-all">' . $h($link) . '</a></p>'
            . '<p style="margin:16px 0 0;font:13px/1.5 Arial,Helvetica,sans-serif;color:#6b6880">' . $h($footnote) . '</p>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px 26px;font:12px/1.5 Arial,Helvetica,sans-serif;color:#8d8aa3;border-top:1px solid #ecebf3">'
            . $h(self::name()) . ' · <a href="' . $h($site) . '" style="color:#8d8aa3">' . $h(preg_replace('#^https?://#', '', $site) ?? $site) . '</a>'
            . ' · Это письмо отправлено автоматически, отвечать на него не нужно.'
            . '</td></tr></table></td></tr></table></body></html>';
        return [$subject, $text, $html];
    }
}
