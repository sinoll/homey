<?php

declare(strict_types=1);

namespace App\Api\Shares;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\ShareService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ListAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private ShareService $shares,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->authentication->authenticate($request);
        if ($userId === null) {
            return $this->responses->fail('Authentication required.', httpCode: Status::UNAUTHORIZED);
        }

        return $this->responses->success($this->shares->list($userId));
    }
}
