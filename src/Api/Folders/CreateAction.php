<?php

declare(strict_types=1);

namespace App\Api\Folders;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FolderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class CreateAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private FolderService $folders,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->authentication->authenticate($request);
        if ($userId === null) {
            return $this->responses->fail('Authentication required.', httpCode: Status::UNAUTHORIZED);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['name'] ?? null)) {
            return $this->responses->fail('A folder name is required.');
        }

        $parentId = $body['parentId'] ?? null;
        if ($parentId !== null && !is_string($parentId)) {
            return $this->responses->fail('Invalid parent folder identifier.');
        }

        $folder = $this->folders->create($userId, $body['name'], $parentId);
        if ($folder === null) {
            return $this->responses->fail('Folder name is invalid or already exists, or the parent folder is unavailable.');
        }

        return $this->responses->success($folder)->withStatus(Status::CREATED);
    }
}
