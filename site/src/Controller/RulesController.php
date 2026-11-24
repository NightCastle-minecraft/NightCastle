<?php

declare(strict_types=1);

namespace SiteGate\Controller;

use SiteGate\Db;
use SiteGate\Markdown;
use SiteGate\View;

final class RulesController
{
    public static function index(): void
    {
        $sections = Db::all('SELECT id, title FROM rule_sections ORDER BY position, id');
        $rules = Db::all('SELECT section_id, body_md FROM rules ORDER BY position, id');

        $bySection = [];
        foreach ($rules as $rule) {
            $bySection[(int) $rule['section_id']][] = (string) $rule['body_md'];
        }

        $result = [];
        $n = 0;
        foreach ($sections as $section) {
            $items = $bySection[(int) $section['id']] ?? [];
            if ($items === []) {
                continue;
            }
            $n++;
            $list = [];
            foreach ($items as $i => $body) {
                $m = $i + 1;
                $list[] = [
                    'number' => $n . '.' . $m,
                    'anchor' => 'r-' . $n . '-' . $m,
                    'html' => Markdown::render($body),
                ];
            }
            $result[] = [
                'number' => (string) $n,
                'anchor' => 's-' . $n,
                'title' => (string) $section['title'],
                'rules' => $list,
            ];
        }

        echo View::render('pages/rules', ['title' => 'Правила', 'sections' => $result]);
    }
}
