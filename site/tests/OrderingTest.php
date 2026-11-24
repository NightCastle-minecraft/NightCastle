<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Ordering;
use PHPUnit\Framework\TestCase;

final class OrderingTest extends TestCase
{
    public function testMoveUpSwapsWithPrevious(): void
    {
        $this->assertSame([2 => 2, 5 => 1], Ordering::move([2 => 1, 5 => 2, 7 => 3], 5, 'up'));
    }

    public function testMoveDownSwapsWithNext(): void
    {
        $this->assertSame([5 => 3, 7 => 2], Ordering::move([2 => 1, 5 => 2, 7 => 3], 5, 'down'));
    }

    public function testGapsArePreserved(): void
    {
        $this->assertSame([1 => 20, 2 => 10], Ordering::move([1 => 10, 2 => 20], 1, 'down'));
    }

    public function testEdgesReturnNull(): void
    {
        $list = [2 => 1, 5 => 2, 7 => 3];
        $this->assertNull(Ordering::move($list, 2, 'up'));
        $this->assertNull(Ordering::move($list, 7, 'down'));
    }

    public function testUnknownIdOrDirection(): void
    {
        $this->assertNull(Ordering::move([1 => 1, 2 => 2], 9, 'up'));
        $this->assertNull(Ordering::move([1 => 1, 2 => 2], 1, 'left'));
        $this->assertNull(Ordering::move([], 1, 'up'));
    }

    public function testDuplicatesAreNormalized(): void
    {
        // Все position = 1: порядок 4, 5, 6 нумеруется 1..3 (изменятся только 4 и 6, у 5 остаётся 1).
        $this->assertSame([4 => 2, 6 => 3], Ordering::move([4 => 1, 5 => 1, 6 => 1], 5, 'up'));
    }

    public function testDuplicatesMoveDown(): void
    {
        $this->assertSame([4 => 2, 6 => 3], Ordering::move([4 => 1, 5 => 1, 6 => 1], 4, 'down'));
    }

    public function testDuplicatesAtEdgeReturnNull(): void
    {
        $this->assertNull(Ordering::move([4 => 1, 5 => 1], 4, 'up'));
    }
}
