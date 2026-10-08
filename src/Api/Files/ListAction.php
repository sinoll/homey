<?php

declare(strict_types=1);

namespace App\Api\Files;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FileService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ListAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private FileService $files,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->authentication->authenticate($request);
        if ($userId === null) {
            return $this->responses->fail('Authentication required.', httpCode: Status::UNAUTHORIZED);
        }

        $query = $request->getQueryParams();
        $folderId = $query['folderId'] ?? null;
        if ($folderId !== null && !is_string($folderId)) {
            return $this->responses->fail('Invalid folder identifier.');
        }
        $search = $query['search'] ?? null;
        if ($search !== null && (!is_string($search) || preg_match('/\A.{0,200}\z/us', trim($search)) !== 1)) {
            return $this->responses->fail('Search term must be a string of at most 200 characters.');
        }
        if (is_string($search)) {
            $search = trim($search);
            if ($search === '') {
                $search = null;
            }
        }

        return $this->responses->success($this->files->list($userId, $folderId, $search));
    }
}
