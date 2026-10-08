<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\LocalFileStorage;
use Codeception\Test\Unit;
use HttpSoft\Message\UploadedFile;
use RuntimeException;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringEqualsFile;
use function PHPUnit\Framework\assertTrue;

final class LocalFileStorageTest extends Unit
{
    private string $rootPath;
    private string $sourcePath;
    private ?string $storedPath = null;
    private ?string $storedDirectory = null;

    protected function _before(): void
    {
        $this->rootPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yii-cloud-' . bin2hex(random_bytes(8));
        $this->sourcePath = $this->rootPath . '-upload';
        file_put_contents($this->sourcePath, 'cloud file contents');
    }

    protected function _after(): void
    {
        if ($this->storedPath !== null && is_file($this->storedPath)) {
            unlink($this->storedPath);
        }
        if ($this->storedDirectory !== null && is_dir($this->storedDirectory)) {
            rmdir($this->storedDirectory);
        }
        if (is_dir($this->rootPath)) {
            rmdir($this->rootPath);
        }
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }
    }

    public function testStoresWithRandomKeyAndCleansClientFilename(): void
    {
        $storage = new LocalFileStorage($this->rootPath);
        $result = $storage->store(new UploadedFile($this->sourcePath, 19, UPLOAD_ERR_OK, '..\\private\\notes.txt'));
        $this->storedPath = $storage->pathFor($result['key']);
        $this->storedDirectory = dirname($this->storedPath);

        assertSame('notes.txt', $result['name']);
        assertSame(19, $result['size']);
        assertSame('text/plain; charset=utf-8', $result['mimeType']);
        assertTrue(is_file($this->storedPath));
        assertStringEqualsFile($this->storedPath, 'cloud file contents');
    }

    public function testRejectsInvalidStorageKey(): void
    {
        $this->expectException(RuntimeException::class);

        (new LocalFileStorage($this->rootPath))->pathFor('../private/file');
    }

    public function testStoresAndReplacesContents(): void
    {
        $storage = new LocalFileStorage($this->rootPath);
        $result = $storage->storeContents('notes.txt', 'original');
        $this->storedPath = $storage->pathFor($result['key']);
        $this->storedDirectory = dirname($this->storedPath);

        assertSame(8, $result['size']);
        assertSame(11, $storage->replaceContents($result['key'], 'replacement'));
        assertStringEqualsFile($this->storedPath, 'replacement');
    }
}
