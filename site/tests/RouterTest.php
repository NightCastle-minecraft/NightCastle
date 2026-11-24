<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/', static fn (): string => 'home');
        $r->get('/rules', static fn (): string => 'rules');
        $r->get('/news/{slug}', static fn (array $p): string => 'news:' . $p['slug']);
        $r->post('/rules', static fn (): string => 'post-rules');
        return $r;
    }

    public function testStaticPath(): void
    {
        $this->assertSame('home', $this->router()->dispatch('GET', '/'));
        $this->assertSame('rules', $this->router()->dispatch('GET', '/rules/'));
    }

    public function testSlugParam(): void
    {
        $this->assertSame('news:hello-world', $this->router()->dispatch('GET', '/news/hello-world'));
    }

    public function testUnknownIsNull(): void
    {
        $this->assertNull($this->router()->match('GET', '/nope'));
        $this->assertNull($this->router()->match('GET', '/news/a/b'));
    }

    public function testMethodDiffers(): void
    {
        $this->assertSame('post-rules', $this->router()->dispatch('POST', '/rules'));
        $this->assertNull($this->router()->match('POST', '/'));
    }
}
