<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Ticket;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    public function testFormatMatchesPluginRegex(): void
    {
        for ($shard = 1; $shard <= 3; $shard++) {
            $t = Ticket::generate($shard);
            $this->assertSame(44, strlen($t));
            $this->assertMatchesRegularExpression('/^[1-3][A-Za-z0-9_-]{20,64}$/', $t, 'регэксп плагина');
            $this->assertSame($shard, Ticket::shardOf($t));
        }
        $this->assertNotSame(Ticket::generate(1), Ticket::generate(1));
    }

    public function testBadTicketsRejected(): void
    {
        $this->assertNull(Ticket::shardOf(''));
        $this->assertNull(Ticket::shardOf('4' . str_repeat('a', 43)));
        $this->assertNull(Ticket::shardOf('1' . str_repeat('a', 42)));
        $this->assertNull(Ticket::shardOf('1' . str_repeat('a', 42) . '/'));
        $this->assertNull(Ticket::shardOf('1' . str_repeat('a', 42) . '='));
        $this->assertNull(Ticket::shardOf("1" . str_repeat('a', 43) . "\n"));
    }

    public function testHashIsSha256Hex(): void
    {
        $this->assertSame(hash('sha256', 'x'), Ticket::hash('x'));
        $this->assertSame(64, strlen(Ticket::hash(Ticket::generate(2))));
    }

    public function testShardOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ticket::generate(4);
    }
}
