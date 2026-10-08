<?php

declare(strict_types=1);

namespace App\Api\Trash;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FileService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class RestoreAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private FileService $files,
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

        $result = $this->files->restoreFromTrash($userId, $id);
        if ($result === null) {
            return $this->responses->notFound('Trashed file not found.');
        }
        if ($result === 'conflict') {
            return $this->responses->fail(
                'A file or folder with this name already exists in the original location.',
                httpCode: Status::CONFLICT,
            );
        }

        return $this->responses->success(['restored' => true]);
    }
}
