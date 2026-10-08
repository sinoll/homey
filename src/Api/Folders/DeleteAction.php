<?php

declare(strict_types=1);

namespace App\Api\Folders;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FolderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class DeleteAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private FolderService $folders,
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

        $deleted = $this->folders->deleteIfEmpty($userId, $id);
        if ($deleted === null) {
            return $this->responses->fail('Folder must be empty before it can be deleted.', httpCode: Status::CONFLICT);
        }
        if (!$deleted) {
            return $this->responses->notFound('Folder not found.');
        }

        return $this->responses->success(['deleted' => true]);
    }
}
