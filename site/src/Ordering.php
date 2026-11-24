<?php

declare(strict_types=1);

namespace SiteGate;

/** Чистая логика обмена позиций соседних элементов списка. */
final class Ordering
{
    /**
     * @param array<int, int> $positions [id => position] в порядке показа (ORDER BY position, id)
     * @return array<int, int>|null [id => новая position] только для изменившихся; null, если двигать нечего
     */
    public static function move(array $positions, int $id, string $direction): ?array
    {
        if (!array_key_exists($id, $positions)) {
            return null;
        }
        if ($direction !== 'up' && $direction !== 'down') {
            return null;
        }
        $ids = array_keys($positions);
        $idx = array_search($id, $ids, true);
        $neighbor = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ($neighbor < 0 || $neighbor >= count($ids)) {
            return null;
        }

        $new = $positions;
        if (count(array_unique($positions)) !== count($positions)) {
            // Дубли position: пронумеровать 1..n в порядке показа.
            $n = 0;
            foreach ($ids as $key) {
                $new[$key] = ++$n;
            }
        }
        $other = $ids[$neighbor];
        $tmp = $new[$id];
        $new[$id] = $new[$other];
        $new[$other] = $tmp;

        $changes = [];
        foreach ($new as $key => $pos) {
            if ($pos !== $positions[$key]) {
                $changes[$key] = $pos;
            }
        }
        return $changes === [] ? null : $changes;
    }
}
