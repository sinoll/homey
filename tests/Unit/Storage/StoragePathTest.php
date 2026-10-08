<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\StoragePath;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class StoragePathTest extends Unit
{
    public function testRecognizesAbsolutePathsForCurrentPlatform(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            assertTrue(StoragePath::isAbsolute('D:\\homey\\storage'));
            assertTrue(StoragePath::isAbsolute('D:/homey/storage'));
            assertTrue(StoragePath::isAbsolute('\\\\server\\share\\homey'));
            assertFalse(StoragePath::isAbsolute('D:homey\\storage'));
        } else {
            assertTrue(StoragePath::isAbsolute('/srv/homey/storage'));
            assertFalse(StoragePath::isAbsolute('D:\\homey\\storage'));
        }

        assertFalse(StoragePath::isAbsolute('data/storage'));
        assertFalse(StoragePath::isAbsolute(''));
    }

    public function testDetectsStoragePathsInsidePublicDirectory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $publicDirectory = 'D:\\Homey\\Public';
            assertTrue(StoragePath::isWithin('d:\\homey\\public\\assets', $publicDirectory));
            assertTrue(StoragePath::isWithin($publicDirectory, $publicDirectory));
            assertFalse(StoragePath::isWithin('D:\\Homey\\Public-backup\\storage', $publicDirectory));
        } else {
            $publicDirectory = '/srv/homey/public';
            assertTrue(StoragePath::isWithin('/srv/homey/public/assets', $publicDirectory));
            assertTrue(StoragePath::isWithin($publicDirectory, $publicDirectory));
            assertFalse(StoragePath::isWithin('/srv/homey/public-backup/storage', $publicDirectory));
        }
    }
}
