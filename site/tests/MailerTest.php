<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\MailException;
use SiteGate\Mailer;
use SiteGate\MailTemplates;
use SiteGate\Players;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    private function mailer(): Mailer
    {
        return new Mailer('mail', 587, 'noreply@example.com', 'MyServer', 'example.com');
    }

    public function testMessageStructure(): void
    {
        $msg = $this->mailer()->build('steve@example.com', 'Подтвердите почту', "Привет\n.точка", '<p>Привет</p>', 1_795_400_000, 'B0UND', 'abc');
        [$head, $body] = explode("\r\n\r\n", $msg, 2);
        $this->assertStringContainsString('From: MyServer <noreply@example.com>', $head);
        $this->assertStringContainsString('To: <steve@example.com>', $head);
        $this->assertStringContainsString('Message-ID: <abc@example.com>', $head);
        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="B0UND"', $head);
        $this->assertMatchesRegularExpression('/^Subject: =\?UTF-8\?B\?/m', $head, 'кириллица в теме закодирована');
        $this->assertSame(1, preg_match('/^Subject: (.+?)\r\nDate:/ms', $head, $sm));
        $this->assertSame('Подтвердите почту', mb_decode_mimeheader(str_replace("\r\n", '', $sm[1])));
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $msg), 'все переводы строк CRLF');
        $this->assertStringEndsWith("--B0UND--\r\n", $msg);
        preg_match_all('/base64\r\n\r\n(.+?)\r\n--B0UND/s', $body, $m);
        $this->assertSame("Привет\n.точка", base64_decode(str_replace("\r\n", '', $m[1][0])));
        $this->assertSame('<p>Привет</p>', base64_decode(str_replace("\r\n", '', $m[1][1])));
    }

    public function testHeaderInjectionIsImpossible(): void
    {
        $msg = $this->mailer()->build('a@example.com', "Тема\r\nBcc: evil@example.com", 't', 'h');
        $this->assertStringNotContainsString("\r\nBcc:", $msg);
        foreach (["a@example.com\r\nBcc: x@y.z", 'a b@example.com', '<a@example.com>', 'not-an-email', str_repeat('a', 250) . '@x.io'] as $bad) {
            $this->assertFalse(Mailer::validAddress($bad), $bad);
        }
        $this->expectException(MailException::class);
        $this->mailer()->build("a@example.com\nBcc: x@y.z", 's', 't', 'h');
    }

    public function testEmailNormalization(): void
    {
        $this->assertSame('Steve.Mine@example.com', Players::normalizeEmail('  Steve.Mine@EXAMPLE.com '));
        $this->assertNull(Players::normalizeEmail('steve@'));
        $this->assertNull(Players::normalizeEmail(''));
        $this->assertNull(Players::normalizeEmail("st\neve@example.com"));
    }

    public function testTemplatesContainLinkAndEscapeName(): void
    {
        [$subject, $text, $html] = MailTemplates::verify('Steve', 'https://example.com/email/verify/1abc');
        $this->assertSame('Подтвердите почту для MyServer', $subject);
        $this->assertStringContainsString('https://example.com/email/verify/1abc', $text);
        $this->assertStringContainsString('href="https://example.com/email/verify/1abc"', $html);
        $this->assertStringContainsString('/assets/img/mail-logo.png', $html);
        [, , $html2] = MailTemplates::reset('<b>x</b>', 'https://example.com/password/reset/2x');
        $this->assertStringNotContainsString('<b>x</b>', $html2);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html2);
        [$s3, $t3] = MailTemplates::welcome('Alex', 'https://example.com');
        $this->assertSame('Добро пожаловать на MyServer', $s3);
        $this->assertStringContainsString('example.com', $t3);
    }
}
