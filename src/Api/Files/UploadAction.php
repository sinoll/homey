<?php

declare(strict_types=1);

namespace App\Api\Files;

use App\Api\Shared\ResponseFactory;
use App\Service\AuthenticationService;
use App\Service\FileTooLargeException;
use App\Service\FileService;
use App\Service\FolderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Http\Status;

final readonly class UploadAction
{
    public function __construct(
        private AuthenticationService $authentication,
        private FileService $files,
        private FolderService $folders,
        private ResponseFactory $responses,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->authentication->authenticate($request);
        if ($userId === null) {
            return $this->responses->fail('Authentication required.', httpCode: Status::UNAUTHORIZED);
        }

        $upload = $request->getUploadedFiles()['file'] ?? null;
        if (!$upload instanceof UploadedFileInterface) {
            return $this->responses->fail('A file field is required.');
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            if ($upload->getError() === UPLOAD_ERR_INI_SIZE || $upload->getError() === UPLOAD_ERR_FORM_SIZE) {
                return $this->responses->fail('Maximum file size is 100 MB.', httpCode: Status::PAYLOAD_TOO_LARGE);
            }
            return $this->responses->fail('The file upload did not complete successfully.');
        }
        if ($upload->getSize() !== null && $upload->getSize() > FileService::MAX_FILE_SIZE) {
            return $this->responses->fail('Maximum file size is 100 MB.', httpCode: Status::PAYLOAD_TOO_LARGE);
        }

        $body = $request->getParsedBody();
        $folderId = is_array($body) ? ($body['folderId'] ?? null) : null;
        if ($folderId !== null && (!is_string($folderId) || !$this->folders->belongsToUser($folderId, $userId))) {
            return $this->responses->notFound('Folder not found.');
        }

        try {
            $file = $this->files->store($userId, $upload, $folderId);
        } catch (FileTooLargeException) {
            return $this->responses->fail('Maximum file size is 100 MB.', httpCode: Status::PAYLOAD_TOO_LARGE);
        }

        return $this->responses->success($file)->withStatus(Status::CREATED);
    }
}
