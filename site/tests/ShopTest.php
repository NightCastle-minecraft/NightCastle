<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Shop;
use SiteGate\ShopProductValidator;
use PHPUnit\Framework\TestCase;

final class ShopTest extends TestCase
{
    /** @return array<string, string> */
    private function ok(): array
    {
        return ['code' => 'vip_30d', 'title' => 'VIP на 30 дней', 'category' => 'Привилегии', 'price' => '250',
            'description_md' => 'Цветной ник', 'position' => '1', 'is_active' => '1'];
    }

    public function testValidProduct(): void
    {
        $r = ShopProductValidator::validate($this->ok());
        $this->assertSame([], $r['errors']);
        $this->assertSame(250, $r['values']['price']);
        $this->assertSame(1, $r['values']['is_active']);
        $this->assertSame(0, ShopProductValidator::validate(['is_active' => ''] + $this->ok())['values']['is_active']);
    }

    public function testCodeIsLoweredAndChecked(): void
    {
        $this->assertSame('vip_30d', ShopProductValidator::validate(['code' => ' VIP_30d '] + $this->ok())['values']['code']);
        foreach (['v', 'vip-30', '../x', 'vip 30', str_repeat('a', 65), "vi\np"] as $bad) {
            $this->assertArrayHasKey('code', ShopProductValidator::validate(['code' => $bad] + $this->ok())['errors'], $bad);
        }
    }

    public function testPriceAndTitle(): void
    {
        foreach (['0', '-5', '1.5', 'abc', '', (string) (Shop::MAX_PRICE + 1)] as $bad) {
            $this->assertArrayHasKey('price', ShopProductValidator::validate(['price' => $bad] + $this->ok())['errors'], $bad);
        }
        $this->assertArrayHasKey('title', ShopProductValidator::validate(['title' => ' '] + $this->ok())['errors']);
        $this->assertArrayHasKey('title', ShopProductValidator::validate(['title' => ['x']] + $this->ok())['errors']);
    }

    public function testOneLineAndLabels(): void
    {
        $this->assertSame('a b c', Shop::oneLine("a\nb\r\nc", 100));
        $this->assertSame('абв', Shop::oneLine('абвгд', 3));
        $this->assertSame('ждёт выдачи', Shop::statusLabel('pending'));
        $this->assertSame('weird', Shop::statusLabel('weird'));
    }
}
