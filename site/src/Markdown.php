<?php

declare(strict_types=1);

namespace SiteGate;

use League\CommonMark\CommonMarkConverter;

final class Markdown
{
    private static ?CommonMarkConverter $converter = null;

    /**
     * Безопасный рендер markdown в HTML (сырой HTML экранируется).
     */
    public static function render(string $md): string
    {
        if (self::$converter === null) {
            self::$converter = new CommonMarkConverter([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);
        }
        return self::$converter->convert($md)->getContent();
    }

    /**
     * Короткий анонс: текст без разметки, не длиннее $length символов.
     */
    public static function excerpt(string $md, int $length = 160): string
    {
        $text = html_entity_decode(strip_tags(self::render($md)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) ($length * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " \t.,;:!?-") . '…';
    }
}
