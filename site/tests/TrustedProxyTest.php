<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrustedProxyTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function rules(): array
    {
        return [
            'ip equal' => ['172.18.0.5', '172.18.0.5', true],
            'ip differs' => ['172.18.0.6', '172.18.0.5', false],
            'cidr /16 in' => ['172.18.3.9', '172.18.0.0/16', true],
            'cidr /16 out' => ['172.19.0.1', '172.18.0.0/16', false],
            'cidr /20 edge in' => ['172.18.15.255', '172.18.0.0/20', true],
            'cidr /20 edge out' => ['172.18.16.0', '172.18.0.0/20', false],
            'cidr /32' => ['10.0.0.1', '10.0.0.1/32', true],
            'cidr /0' => ['8.8.8.8', '0.0.0.0/0', true],
            'cidr bad len' => ['10.0.0.1', '10.0.0.0/33', false],
            'cidr empty len' => ['10.0.0.1', '10.0.0.0/', false],
            'cidr junk len' => ['10.0.0.1', '10.0.0.0/x', false],
            'v6 equal' => ['fd00::5', 'fd00::5', true],
            'v6 cidr in' => ['fd00:0:0:1::7', 'fd00::/16', true],
            'v6 cidr out' => ['fe80::1', 'fd00::/16', false],
            'v6 /63 in' => ['fd00:0:0:1::1', 'fd00::/63', true],
            'v6 /63 out' => ['fd00:0:0:2::1', 'fd00::/63', false],
            'v4 vs v6 rule' => ['10.0.0.1', 'fd00::/8', false],
            'v6 vs v4 rule' => ['fd00::1', '10.0.0.0/8', false],
            'hostname is not ip' => ['10.0.0.1', 'caddy', false],
            'bad remote' => ['not-an-ip', '10.0.0.0/8', false],
        ];
    }

    #[DataProvider('rules')]
    public function testIpMatches(string $ip, string $rule, bool $expected): void
    {
        self::assertSame($expected, Auth::ipMatches($ip, $rule));
    }

    public function testListWithIpCidrAndHost(): void
    {
        $resolver = static fn (string $h): array => $h === 'caddy' ? ['172.20.0.9'] : [];
        $trusted = '10.0.0.1, 192.168.0.0/16 ,caddy';
        $t = static fn (string $ip): bool => Auth::isTrustedProxy(['REMOTE_ADDR' => $ip], $trusted, $resolver);
        self::assertTrue($t('10.0.0.1'));
        self::assertTrue($t('192.168.5.5'));
        self::assertTrue($t('172.20.0.9'));
        self::assertFalse($t('172.20.0.10'));
        self::assertFalse($t('1.2.3.4'));
    }

    public function testEmptyTrustedIgnoresForwardedFor(): void
    {
        $server = ['REMOTE_ADDR' => '172.20.0.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertFalse(Auth::isTrustedProxy($server, ''));
        self::assertSame('172.20.0.9', Auth::clientIp($server, ''));
        self::assertFalse(Auth::isHttps($server, ''));
    }

    public function testClientIpFromCidrProxy(): void
    {
        $server = ['REMOTE_ADDR' => '172.20.0.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];
        self::assertSame('203.0.113.7', Auth::clientIp($server, '172.20.0.0/16'));
    }

    public function testUnresolvableHostIsNotTrusted(): void
    {
        $resolver = static fn (string $h): array => [];
        self::assertFalse(Auth::isTrustedProxy(['REMOTE_ADDR' => '1.2.3.4'], 'nohost', $resolver));
    }
}
