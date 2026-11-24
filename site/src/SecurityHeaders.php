<?php

declare(strict_types=1);

namespace SiteGate;

final class SecurityHeaders
{
    /**
     * Ставит защитные заголовки. Никогда не бросает исключений и не ходит в базу:
     * заголовки ставятся и на API, а он не должен ждать лишнего соединения с DB1.
     */
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Security-Policy: ' . self::buildCsp());
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');
    }

    public static function buildCsp(): string
    {
        return "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "font-src 'self'; connect-src 'self'; frame-src 'none'; frame-ancestors 'none'; "
            . "base-uri 'self'; form-action 'self'; object-src 'none'";
    }
}
