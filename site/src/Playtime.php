<?php

declare(strict_types=1);

namespace SiteGate;

/** Чистая логика учёта наигранного времени (без БД). */
final class Playtime
{
    /** Одна сессия не длиннее суток: остальное считаем ошибкой учёта и обрезаем. */
    public const MAX_SESSION = 86400;

    /**
     * Нормализует сессию: [start, end] в секундах Unix. null, если сессия мусорная.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function normalize(mixed $start, mixed $end, int $now): ?array
    {
        if (!is_int($start) || !is_int($end) || $start <= 0 || $end <= $start) {
            return null;
        }
        // Из будущего не принимаем (часы прокси могут чуть убегать: допуск 5 минут)
        if ($start > $now + 300) {
            return null;
        }
        $end = min($end, $start + self::MAX_SESSION, $now + 300);
        return $end > $start ? [$start, $end] : null;
    }

    /**
     * Раскладывает отрезок по дням UTC.
     *
     * @return array<string, int> 'Y-m-d' => секунды
     */
    public static function splitByDay(int $start, int $end): array
    {
        $out = [];
        $t = $start;
        while ($t < $end) {
            $dayEnd = intdiv($t, 86400) * 86400 + 86400;
            $chunkEnd = min($end, $dayEnd);
            $day = gmdate('Y-m-d', $t);
            $out[$day] = ($out[$day] ?? 0) + ($chunkEnd - $t);
            $t = $chunkEnd;
        }
        return $out;
    }

    /** «12 ч 5 мин», «45 мин», «0 мин». */
    public static function human(int $seconds): string
    {
        $h = intdiv(max(0, $seconds), 3600);
        $m = intdiv(max(0, $seconds) % 3600, 60);
        if ($h === 0) {
            return $m . ' мин';
        }
        return $h . ' ч' . ($m > 0 ? ' ' . $m . ' мин' : '');
    }
}
