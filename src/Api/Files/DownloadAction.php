<?php

declare(strict_types=1);

namespace App\Api\Files;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FileService;
use App\Storage\FileContentType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface as HttpResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class DownloadAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private FileService $files,
        private ResponseFactory $responses,
        private HttpResponseFactoryInterface $httpResponses,
        private StreamFactoryInterface $streams,
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

        $file = $this->files->findForDownload($userId, $id);
        if ($file === null) {
            return $this->responses->notFound('File not found.');
        }

        $preview = ($request->getQueryParams()['preview'] ?? null) === '1';
        $contentType = $preview
            ? FileContentType::forPreview($file['name'])
            : FileContentType::forFilename($file['name'], $file['mimeType']);
        if ($contentType === null) {
            return $this->responses->fail('Preview is not supported for this file type.', httpCode: Status::UNSUPPORTED_MEDIA_TYPE);
        }

        $body = $this->streams->createStreamFromFile($file['path']);
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) ?: 'download';

        return $this->httpResponses->createResponse(Status::OK)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) $file['size'])
            ->withHeader(
                'Content-Disposition',
                ($preview ? 'inline' : 'attachment') . '; filename="' . addcslashes($fallbackName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']),
            )
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($body);
    }
}
