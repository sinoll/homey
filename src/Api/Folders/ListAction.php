<?php

declare(strict_types=1);

namespace App\Api\Folders;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FolderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ListAction
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

        $parentId = $request->getQueryParams()['parentId'] ?? null;
        if ($parentId !== null && (!is_string($parentId) || !$this->folders->belongsToUser($parentId, $userId))) {
            return $this->responses->notFound('Parent folder not found.');
        }

        return $this->responses->success($this->folders->list($userId, $parentId));
    }
}
