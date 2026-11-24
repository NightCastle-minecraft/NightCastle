<?php

declare(strict_types=1);

namespace SiteGate\Tests;

use SiteGate\Upload;
use SiteGate\UploadException;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nc-up-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    private function file(string $content, string $clientName): array
    {
        $tmp = $this->dir . '/src-' . bin2hex(random_bytes(3));
        file_put_contents($tmp, $content);
        return ['name' => $clientName, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }

    public function testPngAcceptedExtensionFromMimeNotName(): void
    {
        $name = Upload::saveImage($this->file($this->png(), 'evil.php'), $this->dir, false);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $name);
        $this->assertTrue(Upload::isValidName($name));
        $this->assertFileExists($this->dir . '/' . $name);
    }

    public function testPhpCodeRejected(): void
    {
        foreach (['shell.php', 'shell.php.jpg', 'shell.jpg'] as $clientName) {
            try {
                Upload::saveImage($this->file("<?php system(\$_GET['c']); ?>", $clientName), $this->dir, false);
                $this->fail("принят $clientName");
            } catch (UploadException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], array_filter(glob($this->dir . '/*') ?: [], static fn (string $f): bool => !str_contains($f, 'src-')));
    }

    public function testPhpAfterImageHeaderMimeMismatchOrText(): void
    {
        $this->expectException(UploadException::class);
        Upload::saveImage($this->file("GIF89a<?php echo 1; ?>", 'a.jpg'), $this->dir, false);
    }

    public function testUploadErrorAndOversize(): void
    {
        $this->expectException(UploadException::class);
        Upload::saveImage(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'a.jpg'], $this->dir, false);
    }

    public function testTooLargeRejected(): void
    {
        $f = $this->file($this->png() . str_repeat("\0", Upload::MAX_BYTES), 'big.png');
        $this->expectException(UploadException::class);
        Upload::saveImage($f, $this->dir, false);
    }

    public function testIsEmptyAndValidName(): void
    {
        $this->assertTrue(Upload::isEmpty(null));
        $this->assertTrue(Upload::isEmpty(['error' => UPLOAD_ERR_NO_FILE]));
        $this->assertFalse(Upload::isEmpty(['error' => UPLOAD_ERR_OK]));
        $this->assertFalse(Upload::isValidName('../x.png'));
        $this->assertFalse(Upload::isValidName('shell.php'));
    }
}
