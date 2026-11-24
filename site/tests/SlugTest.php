<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testTranslit(): void
    {
        $this->assertSame('dobro-pozhalovat-na-sitegate', Slug::make('Добро пожаловать на MyServer!'));
        $this->assertSame('ezhik-v-tumane', Slug::make('Ёжик в тумане'));
        $this->assertSame('shchuka-obem-yuliya', Slug::make('Щука, объём, Юлия'));
    }

    public function testEmpty(): void
    {
        $this->assertSame('', Slug::make(''));
        $this->assertSame('', Slug::make('!!! ??? ---'));
    }

    public function testTruncate(): void
    {
        $slug = Slug::make(str_repeat('а', 300));
        $this->assertSame(190, strlen($slug));
        $slug = Slug::make(str_repeat('ab ', 100));
        $this->assertLessThanOrEqual(190, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Slug::isValid('a-b-1'));
        $this->assertFalse(Slug::isValid('A-b'));
        $this->assertFalse(Slug::isValid('a--b'));
        $this->assertFalse(Slug::isValid('-a'));
        $this->assertFalse(Slug::isValid(''));
    }
}
