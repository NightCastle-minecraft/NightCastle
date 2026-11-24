<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Playtime;
use PHPUnit\Framework\TestCase;

final class PlaytimeTest extends TestCase
{
    private const NOW = 1_795_400_000;

    public function testSplitAcrossMidnightUtc(): void
    {
        $midnight = intdiv(self::NOW, 86400) * 86400;
        $days = Playtime::splitByDay($midnight - 600, $midnight + 1200);
        $this->assertSame([
            gmdate('Y-m-d', $midnight - 1) => 600,
            gmdate('Y-m-d', $midnight) => 1200,
        ], $days);
        $this->assertSame(1800, array_sum($days));
    }

    public function testSingleDay(): void
    {
        $this->assertSame([gmdate('Y-m-d', self::NOW) => 60], Playtime::splitByDay(self::NOW, self::NOW + 60));
    }

    public function testNormalize(): void
    {
        $this->assertSame([self::NOW - 100, self::NOW], Playtime::normalize(self::NOW - 100, self::NOW, self::NOW));
        $this->assertNull(Playtime::normalize(self::NOW, self::NOW, self::NOW), 'нулевая сессия');
        $this->assertNull(Playtime::normalize(self::NOW, self::NOW - 5, self::NOW), 'конец раньше начала');
        $this->assertNull(Playtime::normalize('1', self::NOW, self::NOW), 'не число');
        $this->assertNull(Playtime::normalize(self::NOW + 3600, self::NOW + 7200, self::NOW), 'из будущего');
        $long = Playtime::normalize(self::NOW - 200_000, self::NOW, self::NOW);
        $this->assertSame(Playtime::MAX_SESSION, $long[1] - $long[0], 'больше суток обрезается');
    }

    public function testHuman(): void
    {
        $this->assertSame('0 мин', Playtime::human(30));
        $this->assertSame('45 мин', Playtime::human(45 * 60));
        $this->assertSame('2 ч', Playtime::human(7200));
        $this->assertSame('12 ч 5 мин', Playtime::human(12 * 3600 + 300));
    }
}
