<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\ServerStatus;

final class ApiController
{
    /** Статус для шапки сайта: онлайн из пульса плагина. */
    public static function status(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: max-age=30');
        echo json_encode(ServerStatus::get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
