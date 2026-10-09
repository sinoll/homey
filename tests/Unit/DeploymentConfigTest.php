<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;

use function PHPUnit\Framework\assertIsArray;

final class DeploymentConfigTest extends Unit
{
    public function testYiiMergePlanIsAvailableForDeployment(): void
    {
        $mergePlan = dirname(__DIR__, 2) . '/config/.merge-plan.php';

        $this->assertFileExists($mergePlan);
        assertIsArray(require $mergePlan);
    }
}
