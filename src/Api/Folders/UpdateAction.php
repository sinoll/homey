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

final readonly class UpdateAction
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responses->fail('A JSON request body is required.');
        }

        if (array_key_exists('name', $body) && (!is_string($body['name']) || !$this->folders->rename($userId, $id, $body['name']))) {
            return $this->responses->fail('Folder could not be renamed.');
        }
        if (array_key_exists('parentId', $body)) {
            $parentId = $body['parentId'];
            if (($parentId !== null && !is_string($parentId)) || !$this->folders->move($userId, $id, $parentId)) {
                return $this->responses->fail('Folder could not be moved.');
            }
        }

        return $this->responses->success($this->folders->find($userId, $id));
    }
}
