<?php

declare(strict_types=1);

namespace SiteGate;

/** Хранилище неудачных попыток входа (время передаётся строкой UTC «Y-m-d H:i:s»). */
interface AttemptStore
{
    public function countSince(string $ip, string $sinceUtc): int;

    public function add(string $ip, string $atUtc): void;

    public function clear(string $ip): void;

    /** Удалить записи всех IP старше указанного момента. */
    public function purgeBefore(string $beforeUtc): void;
}
