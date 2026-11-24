<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Shard;
use PHPUnit\Framework\TestCase;

final class ShardTest extends TestCase
{
    public function testSameAsContractAndCaseInsensitive(): void
    {
        // crc32("steve") = 2527860901 → % 3 = 1 → база 2 (сверено с zlib.crc32 в Python)
        $this->assertSame(2, Shard::forName('Steve'));
        $this->assertSame(Shard::forName('steve'), Shard::forName('STEVE'));
        $this->assertSame(Shard::forName('Notch_2'), Shard::forName('notch_2'));
    }

    public function testRangeAndRoughlyEvenSpread(): void
    {
        $counts = [1 => 0, 2 => 0, 3 => 0];
        for ($i = 0; $i < 9000; $i++) {
            $n = Shard::forName('player_' . $i);
            $this->assertContains($n, [1, 2, 3]);
            $counts[$n]++;
        }
        foreach ($counts as $c) {
            $this->assertGreaterThan(2700, $c, 'база не должна получать заметно меньше трети');
            $this->assertLessThan(3300, $c, 'база не должна получать заметно больше трети');
        }
    }
}
