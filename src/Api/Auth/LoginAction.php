<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class LoginAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['email'] ?? null) || !is_string($body['password'] ?? null)) {
            return $this->responses->fail('Email and password are required.');
        }

        $result = $this->authentication->login(strtolower(trim($body['email'])), $body['password']);
        if ($result === null) {
            return $this->responses->fail(
                'Invalid email or password.',
                httpCode: Status::UNAUTHORIZED,
            );
        }

        return $this->responses->success($result);
    }
}
