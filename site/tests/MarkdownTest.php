<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function testScriptTagIsEscaped(): void
    {
        $html = Markdown::render('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testJavascriptLinkHasNoHref(): void
    {
        $html = Markdown::render('[x](javascript:alert(1))');
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']?\s*javascript:/i', $html);
    }

    public function testExcerptStripsMarkup(): void
    {
        $text = Markdown::excerpt("Привет **мир**\n\n# Заголовок", 160);
        $this->assertSame('Привет мир Заголовок', $text);
    }

    public function testExcerptIsTruncated(): void
    {
        $text = Markdown::excerpt(str_repeat('слово ', 100), 50);
        $this->assertLessThanOrEqual(51, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
