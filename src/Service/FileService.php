<?php

declare(strict_types=1);

namespace App\Service;

use App\Storage\LocalFileStorage;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function bin2hex;
use function random_bytes;
use function substr;

final readonly class FileService
{
    public const MAX_FILE_SIZE = 104857600;

    public function __construct(
        private PDO $database,
        private LocalFileStorage $storage,
    ) {}

    public function list(string $userId, string|null $folderId = null, string|null $search = null): array
    {
        if ($search !== null) {
            $statement = $this->database->prepare(
                'WITH RECURSIVE folder_paths AS (
                    SELECT id, user_id, parent_id, name, CAST(name AS CHAR(4096)) AS folderPath
                    FROM folders
                    WHERE user_id = :root_user_id AND parent_id IS NULL
                    UNION ALL
                    SELECT child.id, child.user_id, child.parent_id, child.name,
                           CONCAT(parent.folderPath, \' / \', child.name)
                    FROM folders AS child
                    INNER JOIN folder_paths AS parent ON child.parent_id = parent.id
                    WHERE child.user_id = :child_user_id
                 )
                 SELECT files.id, files.name, files.size, files.mime_type AS mimeType,
                        files.created_at AS createdAt, files.folder_id AS folderId,
                        COALESCE(folder_paths.folderPath, \'我的文件\') AS folderPath
                 FROM files
                 LEFT JOIN folder_paths ON folder_paths.id = files.folder_id
                 WHERE files.user_id = :user_id AND files.deleted_at IS NULL
                   AND files.name LIKE :search ESCAPE \'=\'
                 ORDER BY files.updated_at DESC, files.name',
            );
            $term = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search);
            $statement->execute([
                'root_user_id' => $userId,
                'child_user_id' => $userId,
                'user_id' => $userId,
                'search' => '%' . $term . '%',
            ]);

            return array_map(
                static fn(array $file): array => [...$file, 'size' => (int) $file['size']],
                $statement->fetchAll(PDO::FETCH_ASSOC),
            );
        }

        $statement = $this->database->prepare(
            'SELECT id, name, size, mime_type AS mimeType, created_at AS createdAt
             FROM files WHERE user_id = :user_id AND deleted_at IS NULL
               AND folder_id <=> :folder_id ORDER BY created_at DESC',
        );
        $statement->execute(['user_id' => $userId, 'folder_id' => $folderId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function store(string $userId, UploadedFileInterface $upload, string|null $folderId = null): array
    {
        if ($folderId !== null && !$this->folderBelongsToUser($folderId, $userId)) {
            throw new \InvalidArgumentException('Destination folder does not belong to the user.');
        }
        $name = self::cleanName($upload->getClientFilename() ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('The uploaded file has no valid filename.');
        }
        $this->assertNameAvailable($userId, $folderId, $name);
        $stored = $this->storage->store($upload);
        if ($stored['size'] > self::MAX_FILE_SIZE) {
            $this->storage->delete($stored['key']);
            throw new FileTooLargeException();
        }
        $id = self::uuid();

        try {
            $statement = $this->database->prepare(
                'INSERT INTO files (id, user_id, folder_id, name, storage_key, size, mime_type, created_at, updated_at)
                 VALUES (:id, :user_id, :folder_id, :name, :storage_key, :size, :mime_type, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            );
            $statement->execute([
                'id' => $id,
                'user_id' => $userId,
                'folder_id' => $folderId,
                'name' => $stored['name'],
                'storage_key' => $stored['key'],
                'size' => $stored['size'],
                'mime_type' => $stored['mimeType'],
            ]);
        } catch (Throwable $exception) {
            $this->storage->delete($stored['key']);
            throw $exception;
        }

        return [
            'id' => $id,
            'name' => $stored['name'],
            'size' => $stored['size'],
            'mimeType' => $stored['mimeType'],
        ];
    }

    /**
     * @return array{name: string, mimeType: string, size: int, path: string}|null
     */
    public function findForDownload(string $userId, string $id): array|null
    {
        $statement = $this->database->prepare(
            'SELECT name, storage_key, mime_type AS mimeType, size
             FROM files WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1',
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($file)) {
            return null;
        }

        return [
            'name' => $file['name'],
            'mimeType' => $file['mimeType'],
            'size' => (int) $file['size'],
            'path' => $this->storage->pathFor($file['storage_key']),
        ];
    }

    public function delete(string $userId, string $id): bool
    {
        $statement = $this->database->prepare(
            'UPDATE files SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    public function listTrash(string $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT files.id, files.name, files.size, files.mime_type AS mimeType,
                    files.created_at AS createdAt, files.deleted_at AS deletedAt,
                    folders.name AS folderName
             FROM files
             LEFT JOIN folders ON folders.id = files.folder_id AND folders.user_id = files.user_id
             WHERE files.user_id = :user_id AND files.deleted_at IS NOT NULL
             ORDER BY files.deleted_at DESC',
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(
            static fn(array $file): array => [...$file, 'size' => (int) $file['size']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function restoreFromTrash(string $userId, string $id): string|null
    {
        $this->database->beginTransaction();
        try {
            $file = $this->database->prepare(
                'SELECT name, folder_id FROM files
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL LIMIT 1',
            );
            $file->execute(['id' => $id, 'user_id' => $userId]);
            $record = $file->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record)) {
                $this->database->commit();
                return null;
            }

            $folderId = is_string($record['folder_id']) ? $record['folder_id'] : null;
            if ($this->nameExists($userId, $folderId, $record['name'])) {
                $this->database->commit();
                return 'conflict';
            }

            $restore = $this->database->prepare(
                'UPDATE files SET deleted_at = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL',
            );
            $restore->execute(['id' => $id, 'user_id' => $userId]);
            $this->database->commit();

            return $restore->rowCount() > 0 ? 'restored' : null;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function permanentlyDelete(string $userId, string $id): bool
    {
        $file = $this->database->prepare(
            'SELECT storage_key FROM files
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL LIMIT 1',
        );
        $file->execute(['id' => $id, 'user_id' => $userId]);
        $storageKey = $file->fetchColumn();
        if (!is_string($storageKey)) {
            return false;
        }

        $this->database->beginTransaction();
        try {
            $delete = $this->database->prepare(
                'DELETE FROM files WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL',
            );
            $delete->execute(['id' => $id, 'user_id' => $userId]);
            if ($delete->rowCount() === 0) {
                $this->database->commit();
                return false;
            }
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        $this->storage->delete($storageKey);

        return true;
    }

    public function move(string $userId, string $id, string|null $folderId): bool
    {
        if ($folderId !== null && !$this->folderBelongsToUser($folderId, $userId)) {
            return false;
        }

        $file = $this->database->prepare(
            'SELECT name, folder_id FROM files
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1',
        );
        $file->execute(['id' => $id, 'user_id' => $userId]);
        $record = $file->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            return false;
        }
        if ($record['folder_id'] === $folderId) {
            return true;
        }
        if ($this->nameExists($userId, $folderId, $record['name'])) {
            return false;
        }

        $statement = $this->database->prepare(
            'UPDATE files SET folder_id = :folder_id, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
               AND NOT (folder_id <=> :same_folder)',
        );
        $statement->execute([
            'folder_id' => $folderId,
            'id' => $id,
            'user_id' => $userId,
            'same_folder' => $folderId,
        ]);

        if ($statement->rowCount() > 0) {
            return true;
        }

        $check = $this->database->prepare(
            'SELECT 1 FROM files
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
               AND folder_id <=> :folder_id LIMIT 1',
        );
        $check->execute(['id' => $id, 'user_id' => $userId, 'folder_id' => $folderId]);

        return $check->fetchColumn() !== false;
    }

    private function folderBelongsToUser(string $folderId, string $userId): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM folders WHERE id = :id AND user_id = :user_id LIMIT 1',
        );
        $statement->execute(['id' => $folderId, 'user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    private function assertNameAvailable(string $userId, string|null $folderId, string $name): void
    {
        if ($this->nameExists($userId, $folderId, $name)) {
            throw new \InvalidArgumentException('A file or folder with this name already exists.');
        }
    }

    private function nameExists(string $userId, string|null $folderId, string $name): bool
    {
        $statement = $this->database->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM files WHERE user_id = :file_user AND folder_id <=> :file_folder
                    AND name = :file_name AND deleted_at IS NULL)
                OR EXISTS(SELECT 1 FROM folders WHERE user_id = :folder_user AND parent_id <=> :folder_parent AND name = :folder_name)',
        );
        $statement->execute([
            'file_user' => $userId,
            'file_folder' => $folderId,
            'file_name' => $name,
            'folder_user' => $userId,
            'folder_parent' => $folderId,
            'folder_name' => $name,
        ]);

        return (int) $statement->fetchColumn() !== 0;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

        return strlen($name) <= 255 ? $name : substr($name, 0, 255);
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
