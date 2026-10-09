<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Environment;
use App\InstallationConfig;
use Codeception\Test\Unit;
use RuntimeException;

use function PHPUnit\Framework\assertSame;

final class InstallationConfigTest extends Unit
{
    private string $configPath;
    private string|false $originalDbNameEnvironment;
    private bool $hadDbNameInEnv;
    private string|null $originalDbNameInEnv;

    protected function _before(): void
    {
        $this->originalDbNameEnvironment = getenv('DB_NAME');
        $this->hadDbNameInEnv = array_key_exists('DB_NAME', $_ENV);
        $this->originalDbNameInEnv = $_ENV['DB_NAME'] ?? null;
        $this->configPath = tempnam(sys_get_temp_dir(), 'homey-installation-')
            ?: throw new RuntimeException('Unable to create an installation config test file.');
        putenv('DB_NAME');
        unset($_ENV['DB_NAME']);
    }

    protected function _after(): void
    {
        if ($this->originalDbNameEnvironment === false) {
            putenv('DB_NAME');
        } else {
            putenv('DB_NAME=' . $this->originalDbNameEnvironment);
        }

        if ($this->hadDbNameInEnv) {
            $_ENV['DB_NAME'] = $this->originalDbNameInEnv;
        } else {
            unset($_ENV['DB_NAME']);
        }

        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
        Environment::prepare();
    }

    public function testInstallationConfigOverridesDotEnvValue(): void
    {
        $_ENV['DB_NAME'] = 'dotenv_database';
        file_put_contents($this->configPath, "<?php return ['DB_NAME' => 'installed_database'];");

        InstallationConfig::load($this->configPath);
        Environment::prepare();

        assertSame('installed_database', Environment::databaseName());
    }

    public function testProcessEnvironmentOverridesInstallationConfig(): void
    {
        putenv('DB_NAME=server_database');
        file_put_contents($this->configPath, "<?php return ['DB_NAME' => 'installed_database'];");

        InstallationConfig::load($this->configPath);
        Environment::prepare();

        assertSame('server_database', Environment::databaseName());
    }

    public function testInvalidInstallationConfigThrows(): void
    {
        file_put_contents($this->configPath, "<?php return 'invalid';");

        try {
            InstallationConfig::load($this->configPath);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            assertSame('The installation configuration is invalid.', $exception->getMessage());
        }
    }
}
