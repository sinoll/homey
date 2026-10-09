<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Environment;
use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;
use PDO;

use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertSame;

final readonly class RegisterCest
{
    public function registerAndLogin(ApiTester $I): void
    {
        require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

        $email = 'registration-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $password = 'RegistrationTest-2026!';
        $userId = null;

        try {
            $I->haveHttpHeader('Content-Type', 'application/json');
            $I->sendPOST('/api/auth/register', ['email' => $email, 'password' => $password]);
            $I->seeResponseCodeIs(HttpCode::CREATED);
            $I->seeResponseIsJson();

            $response = json_decode($I->grabResponse(), true);
            assertIsArray($response);
            assertSame('success', $response['status'] ?? null);
            assertIsArray($response['data'] ?? null);
            assertSame($email, $response['data']['email'] ?? null);
            assertIsString($response['data']['userId'] ?? null);
            $userId = $response['data']['userId'];

            $I->sendPOST('/api/auth/login', ['email' => $email, 'password' => $password]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson(['status' => 'success', 'data' => ['email' => $email]]);

            $I->sendPOST('/api/auth/register', ['email' => $email, 'password' => $password]);
            $I->seeResponseCodeIs(HttpCode::CONFLICT);
            $I->seeResponseContainsJson([
                'status' => 'failed',
                'error_message' => 'An account with this email already exists.',
            ]);
        } finally {
            if ($userId !== null) {
                $database = new PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        Environment::databaseHost(),
                        Environment::databasePort(),
                        Environment::databaseName(),
                    ),
                    Environment::databaseUser(),
                    Environment::databasePassword(),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $statement = $database->prepare('DELETE FROM users WHERE id = :id');
                $statement->execute(['id' => $userId]);
            }
        }
    }
}
