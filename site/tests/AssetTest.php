<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    public function testVersionFollowsContent(): void
    {
        $url = asset('/assets/css/main.css');
        $hash = substr(hash_file('sha256', dirname(__DIR__) . '/public/assets/css/main.css'), 0, 10);
        $this->assertSame('/assets/css/main.css?v=' . $hash, $url);
        $this->assertSame('/assets/nope.css', asset('/assets/nope.css'), 'нет файла — адрес без версии');
    }
}
