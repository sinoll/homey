<?php

declare(strict_types=1);

namespace App\WebDav;

use App\Service\FileTooLargeException;
use App\Storage\LocalFileStorage;
use PDO;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;

use function bin2hex;
use function random_bytes;
use function substr;

final readonly class Filesystem
{
    public function __construct(
        private PDO $database,
        private LocalFileStorage $storage,
        private BasicAuthBackend $authentication,
    ) {}

    public function root(): Directory
    {
        return new Directory($this, null, '');
    }

    public function directory(string $id, string $name): Directory
    {
        return new Directory($this, $id, $name);
    }

    public function file(array $record): DavFile
    {
        return new DavFile($this, $record);
    }

    public function parentId(string $id): string|null
    {
        $statement = $this->database->prepare(
            'SELECT parent_id FROM folders WHERE id = :id AND user_id = :user_id LIMIT 1',
        );
        $statement->execute(['id' => $id, 'user_id' => $this->userId()]);
        $parentId = $statement->fetchColumn();

        if ($parentId === false) {
            throw new NotFound('Folder not found.');
        }

        return $parentId === null ? null : (string) $parentId;
    }

    public function userId(): string
    {
        $userId = $this->authentication->authenticatedUserId();
        if ($userId === null) {
            throw new Forbidden('Authentication is required.');
        }

        return $userId;
    }

    public function children(string|null $folderId): array
    {
        $userId = $this->userId();
        $folders = $this->database->prepare(
            'SELECT id, name FROM folders
             WHERE user_id = :user_id AND parent_id <=> :parent_id ORDER BY name',
        );
        $folders->execute(['user_id' => $userId, 'parent_id' => $folderId]);
        $nodes = [];
        foreach ($folders->fetchAll(PDO::FETCH_ASSOC) as $folder) {
            $nodes[] = $this->directory($folder['id'], $folder['name']);
        }

        $files = $this->database->prepare(
            'SELECT id, name, folder_id AS folderId, storage_key AS storageKey, size, mime_type AS mimeType,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM files WHERE user_id = :user_id AND folder_id <=> :folder_id
               AND deleted_at IS NULL ORDER BY name',
        );
        $files->execute(['user_id' => $userId, 'folder_id' => $folderId]);
        foreach ($files->fetchAll(PDO::FETCH_ASSOC) as $file) {
            $file['size'] = (int) $file['size'];
            $nodes[] = $this->file($file);
        }

        return $nodes;
    }

    public function child(string|null $folderId, string $name): Directory|DavFile
    {
        $userId = $this->userId();
        $folder = $this->database->prepare(
            'SELECT id, name FROM folders
             WHERE user_id = :user_id AND parent_id <=> :parent_id AND name = :name LIMIT 1',
        );
        $folder->execute(['user_id' => $userId, 'parent_id' => $folderId, 'name' => $name]);
        $record = $folder->fetch(PDO::FETCH_ASSOC);
        if (is_array($record)) {
            return $this->directory($record['id'], $record['name']);
        }

        $file = $this->database->prepare(
            'SELECT id, name, folder_id AS folderId, storage_key AS storageKey, size, mime_type AS mimeType,
                    created_at AS createdAt, updated_at AS updatedAt
             FROM files
             WHERE user_id = :user_id AND folder_id <=> :folder_id
               AND name = :name AND deleted_at IS NULL LIMIT 1',
        );
        $file->execute(['user_id' => $userId, 'folder_id' => $folderId, 'name' => $name]);
        $record = $file->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new NotFound('Node not found.');
        }
        $record['size'] = (int) $record['size'];

        return $this->file($record);
    }

    public function assertAvailable(string|null $parentId, string $name): void
    {
        if (
            $name === ''
            || strlen($name) > 255
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || $name === '.'
            || $name === '..'
        ) {
            throw new Forbidden('Invalid resource name.');
        }

        $check = $this->database->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM folders WHERE user_id = :folder_user AND parent_id <=> :folder_parent AND name = :folder_name)
                OR EXISTS(SELECT 1 FROM files WHERE user_id = :file_user
                    AND folder_id <=> :file_parent AND name = :file_name AND deleted_at IS NULL)',
        );
        $userId = $this->userId();
        $check->execute([
            'folder_user' => $userId,
            'folder_parent' => $parentId,
            'folder_name' => $name,
            'file_user' => $userId,
            'file_parent' => $parentId,
            'file_name' => $name,
        ]);
        if ((int) $check->fetchColumn() !== 0) {
            throw new Forbidden('A file or folder with this name already exists.');
        }
    }

    public function createDirectory(string|null $parentId, string $name): void
    {
        $this->assertAvailable($parentId, $name);
        $statement = $this->database->prepare(
            'INSERT INTO folders (id, user_id, parent_id, name, created_at)
             VALUES (:id, :user_id, :parent_id, :name, UTC_TIMESTAMP())',
        );
        $statement->execute([
            'id' => self::uuid(),
            'user_id' => $this->userId(),
            'parent_id' => $parentId,
            'name' => $name,
        ]);
    }

    public function createFile(string|null $folderId, string $name, mixed $contents): string
    {
        $this->assertAvailable($folderId, $name);
        try {
            $stored = $this->storage->storeContents($name, $contents);
        } catch (FileTooLargeException) {
            throw new \Sabre\DAV\Exception\InsufficientStorage('Maximum file size is 100 MB.');
        }
        try {
            $statement = $this->database->prepare(
                'INSERT INTO files (id, user_id, folder_id, name, storage_key, size, mime_type, created_at, updated_at)
                 VALUES (:id, :user_id, :folder_id, :name, :storage_key, :size, :mime_type, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            );
            $statement->execute([
                'id' => self::uuid(),
                'user_id' => $this->userId(),
                'folder_id' => $folderId,
                'name' => $stored['name'],
                'storage_key' => $stored['key'],
                'size' => $stored['size'],
                'mime_type' => $stored['mimeType'],
            ]);
        } catch (\Throwable $exception) {
            $this->storage->delete($stored['key']);
            throw $exception;
        }

        return $stored['key'];
    }

    public function replaceFile(string $id, string $storageKey, mixed $contents): void
    {
        if (!$this->fileExists($id)) {
            throw new NotFound('File not found.');
        }

        $size = $this->storage->replaceContents($storageKey, $contents);
        $statement = $this->database->prepare(
            'UPDATE files SET size = :size, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );
        $statement->execute(['size' => $size, 'id' => $id, 'user_id' => $this->userId()]);
    }

    public function contentsPath(string $storageKey): string
    {
        return $this->storage->pathFor($storageKey);
    }

    public function deleteFile(string $id): void
    {
        $statement = $this->database->prepare(
            'UPDATE files SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'user_id' => $this->userId()]);
        if ($statement->rowCount() === 0) {
            throw new NotFound('File not found.');
        }
    }

    public function deleteDirectory(string $id): void
    {
        $userId = $this->userId();
        $this->database->beginTransaction();
        try {
            $trash = $this->database->prepare(
                'WITH RECURSIVE descendants AS (
                    SELECT id FROM folders WHERE id = :folder_id AND user_id = :user_id
                    UNION ALL
                    SELECT folders.id FROM folders INNER JOIN descendants ON folders.parent_id = descendants.id
                 )
                 UPDATE files SET folder_id = NULL, deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()),
                     updated_at = UTC_TIMESTAMP()
                 WHERE files.folder_id IN (SELECT id FROM descendants) AND files.user_id = :file_user',
            );
            $trash->execute(['folder_id' => $id, 'user_id' => $userId, 'file_user' => $userId]);

            $delete = $this->database->prepare('DELETE FROM folders WHERE id = :id AND user_id = :user_id');
            $delete->execute(['id' => $id, 'user_id' => $userId]);
            if ($delete->rowCount() === 0) {
                throw new NotFound('Folder not found.');
            }
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function renameFile(string $id, string|null $folderId, string $name): void
    {
        $this->assertAvailableExcludingFile($folderId, $name, $id);
        $statement = $this->database->prepare(
            'UPDATE files SET name = :name, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );
        $statement->execute(['name' => $name, 'id' => $id, 'user_id' => $this->userId()]);
        if ($statement->rowCount() === 0 && !$this->fileExists($id)) {
            throw new NotFound('File not found.');
        }
    }

    public function renameDirectory(string $id, string|null $parentId, string $name): void
    {
        $this->assertAvailableExcludingFolder($parentId, $name, $id);
        $statement = $this->database->prepare(
            'UPDATE folders SET name = :name WHERE id = :id AND user_id = :user_id',
        );
        $statement->execute(['name' => $name, 'id' => $id, 'user_id' => $this->userId()]);
        if ($statement->rowCount() === 0 && !$this->folderExists($id)) {
            throw new NotFound('Folder not found.');
        }
    }

    private function assertAvailableExcludingFile(string|null $folderId, string $name, string $id): void
    {
        $this->assertValidName($name);
        $statement = $this->database->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM folders WHERE user_id = :folder_user AND parent_id <=> :folder_parent AND name = :folder_name)
                OR EXISTS(SELECT 1 FROM files WHERE user_id = :file_user AND folder_id <=> :file_parent
                    AND name = :file_name AND deleted_at IS NULL AND id <> :file_id)',
        );
        $userId = $this->userId();
        $statement->execute([
            'folder_user' => $userId,
            'folder_parent' => $folderId,
            'folder_name' => $name,
            'file_user' => $userId,
            'file_parent' => $folderId,
            'file_name' => $name,
            'file_id' => $id,
        ]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new Forbidden('A file or folder with this name already exists.');
        }
    }

    private function assertAvailableExcludingFolder(string|null $parentId, string $name, string $id): void
    {
        $this->assertValidName($name);
        $statement = $this->database->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM folders WHERE user_id = :folder_user AND parent_id <=> :folder_parent AND name = :folder_name AND id <> :folder_id)
                OR EXISTS(SELECT 1 FROM files WHERE user_id = :file_user AND folder_id <=> :file_parent
                    AND name = :file_name AND deleted_at IS NULL)',
        );
        $userId = $this->userId();
        $statement->execute([
            'folder_user' => $userId,
            'folder_parent' => $parentId,
            'folder_name' => $name,
            'folder_id' => $id,
            'file_user' => $userId,
            'file_parent' => $parentId,
            'file_name' => $name,
        ]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new Forbidden('A file or folder with this name already exists.');
        }
    }

    private function assertValidName(string $name): void
    {
        if (
            $name === ''
            || strlen($name) > 255
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || $name === '.'
            || $name === '..'
        ) {
            throw new Forbidden('Invalid resource name.');
        }
    }

    private function fileExists(string $id): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM files WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'user_id' => $this->userId()]);

        return $statement->fetchColumn() !== false;
    }

    private function folderExists(string $id): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM folders WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $id, 'user_id' => $this->userId()]);

        return $statement->fetchColumn() !== false;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
