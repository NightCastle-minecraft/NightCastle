<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\SettingsValidator;
use PHPUnit\Framework\TestCase;

final class SettingsValidatorTest extends TestCase
{
    /** @return array<string, string> */
    private function ok(): array
    {
        return ['server_name' => 'MyServer', 'display_ip' => '1.2.3.4', 'modpack_url' => '', 'discord_url' => 'https://discord.gg/x'];
    }

    public function testValid(): void
    {
        $r = SettingsValidator::validate($this->ok());
        $this->assertSame([], $r['errors']);
        $this->assertSame('0', $r['values']['banner_enabled']);
    }

    public function testBannerCheckbox(): void
    {
        $this->assertSame('1', SettingsValidator::validate($this->ok() + ['banner_enabled' => '1'])['values']['banner_enabled']);
    }

    public function testBadUrlsRejected(): void
    {
        foreach (['javascript:alert(1)', 'ftp://x.com/a', 'not a url', '//evil.com', 'https://'] as $bad) {
            $r = SettingsValidator::validate(['modpack_url' => $bad, 'discord_url' => $bad] + $this->ok());
            $this->assertArrayHasKey('modpack_url', $r['errors'], $bad);
            $this->assertArrayHasKey('discord_url', $r['errors'], $bad);
            $this->assertSame($bad, $r['values']['modpack_url']);
        }
    }

    public function testEmptyUrlAllowedHttpAccepted(): void
    {
        $r = SettingsValidator::validate(['modpack_url' => 'http://example.com/pack', 'discord_url' => ''] + $this->ok());
        $this->assertSame([], $r['errors']);
    }

    public function testRequiredAndLength(): void
    {
        $r = SettingsValidator::validate(['server_name' => ' ', 'display_ip' => str_repeat('a', 101)]);
        $this->assertArrayHasKey('server_name', $r['errors']);
        $this->assertArrayHasKey('display_ip', $r['errors']);
    }

    public function testNonStringInputIsEmpty(): void
    {
        $r = SettingsValidator::validate(['server_name' => ['x'], 'display_ip' => '1.1.1.1']);
        $this->assertArrayHasKey('server_name', $r['errors']);
    }
}
