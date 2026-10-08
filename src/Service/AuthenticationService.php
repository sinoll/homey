<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function bin2hex;
use function hash;
use function password_hash;
use function password_verify;
use function random_bytes;
use function rtrim;
use function strtr;
use function time;

final readonly class AuthenticationService
{
    public function __construct(private PDO $database) {}

    /**
     * @return array{userId: string, email: string, token: string}|null
     */
    public function register(string $email, string $password): array|null
    {
        $userId = self::uuid();
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'INSERT INTO users (id, email, password_hash, created_at) VALUES (:id, :email, :password_hash, UTC_TIMESTAMP())',
            );
            $statement->execute([
                'id' => $userId,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $account = $this->issueToken($userId, $email);
            $this->database->commit();

            return $account;
        } catch (PDOException $exception) {
            $this->database->rollBack();
            if ($exception->getCode() === '23000') {
                return null;
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array{userId: string, email: string, token: string}|null
     */
    public function login(string $email, string $password): array|null
    {
        $statement = $this->database->prepare(
            'SELECT id, email, password_hash FROM users WHERE email = :email LIMIT 1',
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        return $this->issueToken($user['id'], $user['email']);
    }

    public function validateCredentials(string $email, string $password): string|null
    {
        $statement = $this->database->prepare(
            'SELECT id, password_hash FROM users WHERE email = :email LIMIT 1',
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) && password_verify($password, $user['password_hash'])
            ? $user['id']
            : null;
    }

    public function authenticate(ServerRequestInterface $request): string|null
    {
        $authorization = $request->getHeaderLine('Authorization');
        if (preg_match('/\ABearer\s+([A-Za-z0-9_-]{40,})\z/i', trim($authorization), $matches) !== 1) {
            return null;
        }

        $statement = $this->database->prepare(
            'SELECT user_id FROM access_tokens WHERE token_hash = :token_hash AND expires_at > UTC_TIMESTAMP() LIMIT 1',
        );
        $statement->execute(['token_hash' => hash('sha256', $matches[1])]);
        $userId = $statement->fetchColumn();

        return is_string($userId) ? $userId : null;
    }

    /**
     * @return array{userId: string, email: string, token: string}
     */
    private function issueToken(string $userId, string $email): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $statement = $this->database->prepare(
            'INSERT INTO access_tokens (token_hash, user_id, expires_at, created_at) VALUES (:token_hash, :user_id, :expires_at, UTC_TIMESTAMP())',
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30),
        ]);

        return [
            'userId' => $userId,
            'email' => $email,
            'token' => $token,
        ];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
