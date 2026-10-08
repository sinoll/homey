<?php

declare(strict_types=1);

namespace App\Api\Shares;

use App\Api\Shared\ResponseFactory;
use App\Service\ShareService;
use App\Storage\FileContentType;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final readonly class PublicDownloadAction
{
    public function __construct(
        private ShareService $shares,
        private ResponseFactory $responses,
        private ResponseFactoryInterface $httpResponses,
        private StreamFactoryInterface $streams,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $token,
        #[RouteArgument]
        string $fileId,
    ): ResponseInterface {
        $share = $this->shares->getPublicShare($token);
        $file = $share === null ? null : $this->shares->publicDownload($share, $fileId);
        if ($file === null) {
            return $this->responses->notFound('Shared file not found or share expired.');
        }

        $preview = ($request->getQueryParams()['preview'] ?? null) === '1';
        $contentType = $preview
            ? FileContentType::forPreview($file['name'])
            : FileContentType::forFilename($file['name'], $file['mimeType']);
        if ($contentType === null) {
            return $this->responses->fail('Preview is not supported for this file type.', httpCode: Status::UNSUPPORTED_MEDIA_TYPE);
        }

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
            ->withBody($this->streams->createStreamFromFile($file['path']));
    }
}
