<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Environment;
use Codeception\Test\Unit;
use RuntimeException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class EnvironmentTest extends Unit
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function _before(): void
    {
        foreach ([
            'APP_ENV',
            'APP_DEBUG',
            'APP_C3',
            'APP_HOST_PATH',
            'DB_HOST',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'DB_PORT',
            'APP_STORAGE_PATH',
        ] as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
    }

    protected function _after(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
        Environment::prepare();
    }

    public function testAppEnvIsReadFromEnvironment(): void
    {
        $this->setEnv('APP_ENV', 'test');

        assertSame('test', Environment::appEnv());
    }

    public function testAppEnvDefaultsToProdWhenNotSet(): void
    {
        $this->unsetEnv('APP_ENV');

        assertSame('prod', Environment::appEnv());
    }

    public function testAppEnvDefaultsToProdWhenEmpty(): void
    {
        $this->setEnv('APP_ENV', '');

        assertSame('prod', Environment::appEnv());
    }

    public function testInvalidAppEnvThrows(): void
    {
        $this->unsetEnv('APP_ENV');
        putenv('APP_ENV=staging');

        try {
            Environment::prepare();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            assertSame('APP_ENV="staging" is invalid. Valid values are "dev", "test", "prod".', $e->getMessage());
        } finally {
            putenv('APP_ENV');
        }
    }

    public function testIsDev(): void
    {
        $this->setEnv('APP_ENV', 'dev');

        assertTrue(Environment::isDev());
        assertFalse(Environment::isTest());
        assertFalse(Environment::isProd());
    }

    public function testIsTest(): void
    {
        $this->setEnv('APP_ENV', 'test');

        assertTrue(Environment::isTest());
        assertFalse(Environment::isDev());
        assertFalse(Environment::isProd());
    }

    public function testIsProd(): void
    {
        $this->setEnv('APP_ENV', 'prod');

        assertTrue(Environment::isProd());
        assertFalse(Environment::isDev());
        assertFalse(Environment::isTest());
    }

    public function testAppDebugDefaultsToFalse(): void
    {
        $this->unsetEnv('APP_DEBUG');

        assertFalse(Environment::appDebug());
    }

    public function testAppDebugTrue(): void
    {
        $this->setEnv('APP_DEBUG', 'true');

        assertTrue(Environment::appDebug());
    }

    public function testAppDebugFalse(): void
    {
        $this->setEnv('APP_DEBUG', 'false');

        assertFalse(Environment::appDebug());
    }

    public function testAppC3DefaultsToFalse(): void
    {
        $this->unsetEnv('APP_C3');

        assertFalse(Environment::appC3());
    }

    public function testAppC3True(): void
    {
        $this->setEnv('APP_C3', 'true');

        assertTrue(Environment::appC3());
    }

    public function testAppHostPathDefaultsToNull(): void
    {
        $this->unsetEnv('APP_HOST_PATH');

        assertNull(Environment::appHostPath());
    }

    public function testAppHostPathIsRead(): void
    {
        $this->setEnv('APP_HOST_PATH', '/projects/myapp');

        assertSame('/projects/myapp', Environment::appHostPath());
    }

    public function testAppHostPathEmptyStringTreatedAsNull(): void
    {
        $this->setEnv('APP_HOST_PATH', '');

        assertNull(Environment::appHostPath());
    }

    public function testDatabaseSettingsAreReadFromEnvironment(): void
    {
        $this->setEnv('DB_HOST', 'db.internal');
        $this->setEnv('DB_NAME', 'cloud_test');
        $this->setEnv('DB_USER', 'cloud_user');
        $this->setEnv('DB_PASSWORD', 'secret');
        $this->setEnv('DB_PORT', '3307');

        assertSame('db.internal', Environment::databaseHost());
        assertSame('cloud_test', Environment::databaseName());
        assertSame('cloud_user', Environment::databaseUser());
        assertSame('secret', Environment::databasePassword());
        assertSame(3307, Environment::databasePort());
    }

    public function testInvalidDatabasePortThrows(): void
    {
        putenv('DB_PORT=not-a-port');
        $_ENV['DB_PORT'] = 'not-a-port';

        try {
            Environment::prepare();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            assertSame('DB_PORT must be an integer between 1 and 65535.', $e->getMessage());
        }
    }

    public function testAppStoragePathCanBeConfigured(): void
    {
        $this->setEnv('APP_STORAGE_PATH', 'D:\\cloud-data');

        assertSame('D:\\cloud-data', Environment::appStoragePath());
    }

    public function testEmptyAppStoragePathUsesDefault(): void
    {
        $this->setEnv('APP_STORAGE_PATH', '');

        assertSame(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'storage', Environment::appStoragePath());
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        Environment::prepare();
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
        Environment::prepare();
    }
}
