<?php

declare(strict_types=1);

namespace SiteGate\Controller\Admin;

use DateTimeImmutable;
use DateTimeZone;
use SiteGate\Db;
use SiteGate\ServerStatus;
use SiteGate\ShardStats;

final class DashboardController
{
    public static function index(): void
    {
        $admin = Page::begin();

        $status = ServerStatus::get();
        $counts = Db::one(
            'SELECT
                (SELECT COUNT(*) FROM news WHERE is_published = 1) AS published,
                (SELECT COUNT(*) FROM news WHERE is_published = 0) AS drafts,
                (SELECT COUNT(*) FROM rule_sections) AS sections,
                (SELECT COUNT(*) FROM rules) AS rules'
        ) ?? [];

        $log = [];
        $rows = Db::all(
            'SELECT l.action, l.details, l.created_at, a.username
             FROM admin_log l LEFT JOIN admins a ON a.id = l.admin_id
             ORDER BY l.id DESC LIMIT 10'
        );
        $tz = new DateTimeZone('Europe/Kyiv');
        foreach ($rows as $row) {
            $when = (new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            $log[] = [
                'who' => $row['username'] !== null ? (string) $row['username'] : '—',
                'action' => (string) $row['action'],
                'details' => (string) ($row['details'] ?? ''),
                'when' => $when->format('d.m.Y H:i'),
            ];
        }

        Page::render('admin/dashboard', $admin, [
            'title' => 'Дашборд',
            'status' => $status,
            'counts' => [
                'published' => (int) ($counts['published'] ?? 0),
                'drafts' => (int) ($counts['drafts'] ?? 0),
                'sections' => (int) ($counts['sections'] ?? 0),
                'rules' => (int) ($counts['rules'] ?? 0),
            ],
            'log' => $log,
            'shards' => ShardStats::all(),
        ]);
    }
}
