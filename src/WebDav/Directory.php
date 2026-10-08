<?php

declare(strict_types=1);

namespace App\WebDav;

use App\Service\FileTooLargeException;
use Sabre\DAV\Collection;
use Sabre\DAV\Exception\Forbidden;

final class Directory extends Collection
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string|null $folderId,
        private readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getChildren(): array
    {
        return $this->filesystem->children($this->folderId);
    }

    public function getChild($name): \Sabre\DAV\INode
    {
        return $this->filesystem->child($this->folderId, $name);
    }

    public function childExists($name): bool
    {
        try {
            $this->getChild($name);
            return true;
        } catch (\Sabre\DAV\Exception\NotFound) {
            return false;
        }
    }

    public function createDirectory($name): void
    {
        $this->filesystem->createDirectory($this->folderId, $name);
    }

    public function createFile($name, $data = null): string|null
    {
        try {
            $key = $this->filesystem->createFile($this->folderId, $name, $data ?? '');
        } catch (FileTooLargeException) {
            throw new \Sabre\DAV\Exception\InsufficientStorage('Maximum file size is 100 MB.');
        }

        $file = $this->filesystem->child($this->folderId, $name);
        return $file instanceof DavFile ? $file->getETag() : null;
    }

    public function delete(): void
    {
        if ($this->folderId === null) {
            throw new Forbidden('The WebDAV root cannot be deleted.');
        }

        $this->filesystem->deleteDirectory($this->folderId);
    }

    public function setName($name): void
    {
        if ($this->folderId === null) {
            throw new Forbidden('The WebDAV root cannot be renamed.');
        }
        $this->filesystem->renameDirectory(
            $this->folderId,
            $this->filesystem->parentId($this->folderId),
            $name,
        );
    }
}
