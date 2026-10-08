<?php

declare(strict_types=1);

namespace App\Api\Shares;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\ShareService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class DeleteAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private ShareService $shares,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $id,
    ): ResponseInterface {
        $userId = $this->authentication->authenticate($request);
        if ($userId === null) {
            return $this->responses->fail('Authentication required.', httpCode: Status::UNAUTHORIZED);
        }
        if (!$this->shares->delete($userId, $id)) {
            return $this->responses->notFound('Share link not found.');
        }

        return $this->responses->success(['deleted' => true]);
    }
}
