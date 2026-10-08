<?php

declare(strict_types=1);

namespace App\WebDav;

use Sabre\DAV\File;

final class DavFile extends File
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly array $record,
    ) {}

    public function getName(): string
    {
        return $this->record['name'];
    }

    public function get()
    {
        $stream = fopen($this->filesystem->contentsPath($this->record['storageKey']), 'rb');
        if ($stream === false) {
            throw new \Sabre\DAV\Exception\NotFound('Stored file content is missing.');
        }

        return $stream;
    }

    public function put($data): string|null
    {
        try {
            $this->filesystem->replaceFile($this->record['id'], $this->record['storageKey'], $data);
        } catch (\App\Service\FileTooLargeException) {
            throw new \Sabre\DAV\Exception\InsufficientStorage('Maximum file size is 100 MB.');
        }
        return $this->getETag();
    }

    public function getSize(): int
    {
        return (int) $this->record['size'];
    }

    public function getETag(): string
    {
        $hash = hash_file('sha256', $this->filesystem->contentsPath($this->record['storageKey']));
        return '"' . ($hash === false ? $this->record['id'] : $hash) . '"';
    }

    public function getContentType(): string
    {
        return 'application/octet-stream';
    }

    public function getLastModified(): int|null
    {
        $timestamp = strtotime($this->record['updatedAt'] ?? $this->record['createdAt']);
        return $timestamp === false ? null : $timestamp;
    }

    public function delete(): void
    {
        $this->filesystem->deleteFile($this->record['id']);
    }

    public function setName($name): void
    {
        $this->filesystem->renameFile($this->record['id'], $this->record['folderId'] ?? null, $name);
    }
}
