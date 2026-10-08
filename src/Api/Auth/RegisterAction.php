<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class RegisterAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responses->fail('A JSON request body is required.');
        }

        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($email) || !is_string($password)) {
            return $this->responses->fail('Email and password are required.');
        }

        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            return $this->responses->fail('A valid email address is required.');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            return $this->responses->fail('Password must contain between 8 and 72 bytes.');
        }

        $account = $this->authentication->register($email, $password);
        if ($account === null) {
            return $this->responses->fail(
                'An account with this email already exists.',
                httpCode: Status::CONFLICT,
            );
        }

        return $this->responses->success($account)->withStatus(Status::CREATED);
    }
}
