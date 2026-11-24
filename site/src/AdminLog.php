<?php

declare(strict_types=1);

namespace SiteGate;

final class AdminLog
{
    public static function write(?int $adminId, string $action, ?string $details = null): void
    {
        Db::pdo()->prepare('INSERT INTO admin_log (admin_id, action, details, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$adminId, $action, $details]);
    }
}
