<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Gallery;
use PHPUnit\Framework\TestCase;

final class GalleryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nc-gallery-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $name) {
            $path = $this->dir . '/' . $name;
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }

    private function touch(string ...$names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }
    }

    public function testNaturalOrderAndFilter(): void
    {
        $this->touch('10-замок.png', '2-ферма.JPG', '1-старт.webp', 'notes.txt', '.hidden.png', 'readme.md');
        mkdir($this->dir . '/folder.png');

        $this->assertSame(['1-старт.webp', '2-ферма.JPG', '10-замок.png'], (new Gallery($this->dir))->files());
    }

    public function testMissingDirIsEmpty(): void
    {
        $this->assertSame([], (new Gallery($this->dir . '/nope'))->files());
    }

    public function testLimit(): void
    {
        for ($i = 1; $i <= Gallery::MAX_ITEMS + 5; $i++) {
            $this->touch($i . '.png');
        }
        $files = (new Gallery($this->dir))->files();
        $this->assertCount(Gallery::MAX_ITEMS, $files);
        $this->assertSame('1.png', $files[0]);
    }

    public function testIsImageName(): void
    {
        $this->assertTrue(Gallery::isImageName('screen 01.jpeg'));
        $this->assertTrue(Gallery::isImageName('Скрин_ночь.PNG'));
        $this->assertFalse(Gallery::isImageName('.png'));
        $this->assertFalse(Gallery::isImageName('../etc.png'));
        $this->assertFalse(Gallery::isImageName('a\\b.png'));
        $this->assertFalse(Gallery::isImageName("bad\nname.png"));
        $this->assertFalse(Gallery::isImageName("\xff\xfe.png"));
        $this->assertFalse(Gallery::isImageName('image.gif'));
        $this->assertFalse(Gallery::isImageName('image.png.php'));
    }

    public function testUrlIsEncoded(): void
    {
        $this->assertSame('/screenshots/a%20b%23.png', Gallery::url('a b#.png'));
    }
}
