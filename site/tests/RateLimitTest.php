<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\AttemptStore;
use SiteGate\Auth;
use SiteGate\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private function store(): AttemptStore
    {
        return new class () implements AttemptStore {
            /** @var list<array{string, string}> */
            public array $rows = [];

            public function countSince(string $ip, string $sinceUtc): int
            {
                return count(array_filter($this->rows, static fn (array $r): bool => $r[0] === $ip && $r[1] > $sinceUtc));
            }

            public function add(string $ip, string $atUtc): void
            {
                $this->rows[] = [$ip, $atUtc];
            }

            public function clear(string $ip): void
            {
                $this->rows = array_values(array_filter($this->rows, static fn (array $r): bool => $r[0] !== $ip));
            }

            public function purgeBefore(string $beforeUtc): void
            {
                $this->rows = array_values(array_filter($this->rows, static fn (array $r): bool => $r[1] > $beforeUtc));
            }
        };
    }

    public function testBlocksOnSixthAttempt(): void
    {
        $rl = new RateLimit($this->store());
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($rl->isBlocked('1.1.1.1', $now + $i), "попытка $i");
            $rl->recordFailure('1.1.1.1', $now + $i);
        }
        $this->assertTrue($rl->isBlocked('1.1.1.1', $now + 10));
    }

    public function testWindowExpires(): void
    {
        $rl = new RateLimit($this->store());
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            $rl->recordFailure('1.1.1.1', $now);
        }
        $this->assertTrue($rl->isBlocked('1.1.1.1', $now + 899));
        $this->assertFalse($rl->isBlocked('1.1.1.1', $now + 901));
    }

    public function testIpsAreIndependentAndClearOnlyOwn(): void
    {
        $rl = new RateLimit($this->store());
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            $rl->recordFailure('1.1.1.1', $now);
            $rl->recordFailure('2.2.2.2', $now);
        }
        $this->assertTrue($rl->isBlocked('1.1.1.1', $now));
        $rl->clear('1.1.1.1');
        $this->assertFalse($rl->isBlocked('1.1.1.1', $now));
        $this->assertTrue($rl->isBlocked('2.2.2.2', $now));
        $this->assertFalse($rl->isBlocked('3.3.3.3', $now));
    }

    public function testFewerThanMaxNotBlocked(): void
    {
        $rl = new RateLimit($this->store());
        for ($i = 0; $i < 4; $i++) {
            $rl->recordFailure('1.1.1.1', 1_800_000_000);
        }
        $this->assertFalse($rl->isBlocked('1.1.1.1', 1_800_000_001));
    }

    public function testClientIpIgnoresForwardedForWithoutTrustedProxy(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
        $this->assertSame('10.0.0.5', Auth::clientIp($server, ''));
        $this->assertSame('10.0.0.5', Auth::clientIp($server, '10.0.0.99'));
    }

    public function testClientIpUsesFirstForwardedForFromTrustedProxy(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.5'];
        $this->assertSame('9.9.9.9', Auth::clientIp($server, '10.0.0.5'));
        $server['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $this->assertSame('10.0.0.5', Auth::clientIp($server, '10.0.0.5'));
    }

    public function testHttpsDetection(): void
    {
        $this->assertTrue(Auth::isHttps(['HTTPS' => 'on'], ''));
        $this->assertFalse(Auth::isHttps(['HTTPS' => 'off'], ''));
        $proxied = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertTrue(Auth::isHttps($proxied, '10.0.0.5'));
        $this->assertFalse(Auth::isHttps($proxied, ''));
        $this->assertFalse(Auth::isHttps($proxied, '10.0.0.6'));
    }
}
