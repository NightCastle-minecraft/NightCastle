<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    public function testTokenIsStablePerSession(): void
    {
        $a = Csrf::token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertSame($a, Csrf::token());
    }

    public function testVerifyAcceptsOnlyOwnToken(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::verify($t));
        $this->assertFalse(Csrf::verify($t . 'x'));
        $this->assertFalse(Csrf::verify(strrev($t) === $t ? 'a' : strrev($t)));
        $this->assertFalse(Csrf::verify(''));
        $this->assertFalse(Csrf::verify(null));
    }

    public function testVerifyFailsWithoutSessionToken(): void
    {
        $this->assertFalse(Csrf::verify('anything'));
        $this->assertArrayNotHasKey('_csrf_token', $_SESSION);
    }

    public function testNewSessionGetsNewToken(): void
    {
        $a = Csrf::token();
        $_SESSION = [];
        $this->assertNotSame($a, Csrf::token());
    }

    public function testVerifyRequest(): void
    {
        $t = Csrf::token();
        $this->assertFalse(Csrf::verifyRequest());
        $_POST['_csrf'] = 'bad';
        $this->assertFalse(Csrf::verifyRequest());
        $_POST['_csrf'] = ['array'];
        $this->assertFalse(Csrf::verifyRequest());
        $_POST['_csrf'] = $t;
        $this->assertTrue(Csrf::verifyRequest());
    }

    public function testFieldContainsToken(): void
    {
        $html = Csrf::field();
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('value="' . Csrf::token() . '"', $html);
    }
}
