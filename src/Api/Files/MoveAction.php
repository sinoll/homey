<?php

declare(strict_types=1);

namespace App\Api\Files;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FileService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class MoveAction
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

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('folderId', $body)) {
            return $this->responses->fail('A destination folderId (or null for root) is required.');
        }
        $folderId = $body['folderId'];
        if ($folderId !== null && !is_string($folderId)) {
            return $this->responses->fail('Invalid destination folder identifier.');
        }
        if (!$this->files->move($userId, $id, $folderId)) {
            return $this->responses->notFound('File or destination folder not found.');
        }

        return $this->responses->success(['moved' => true]);
    }
}
