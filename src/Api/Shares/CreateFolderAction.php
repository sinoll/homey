<?php

declare(strict_types=1);

namespace App\Api\Shares;

use App\Api\Shared\ResponseFactory;
use App\Api\Shared\ShareExpiry;
use App\Service\AuthenticationService;
use App\Service\ShareService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class CreateFolderAction
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

        $expiresAt = ShareExpiry::normalize($request->getParsedBody());
        if ($expiresAt === false) {
            return $this->responses->fail('Expiry must be a future ISO-8601 date/time or null.');
        }

        $share = $this->shares->createFolderShare($userId, $id, $expiresAt);
        if ($share === null) {
            return $this->responses->notFound('Folder not found.');
        }

        return $this->responses->success($share)->withStatus(Status::CREATED);
    }
}
