<?php

declare(strict_types=1);

namespace App\Service;

use App\Storage\LocalFileStorage;
use PDO;

use function bin2hex;
use function hash;
use function random_bytes;
use function rtrim;
use function strtr;
use function substr;

final readonly class ShareService
{
    public function __construct(
        private PDO $database,
        private LocalFileStorage $storage,
    ) {}

    public function createFileShare(string $userId, string $fileId, string|null $expiresAt): array|null
    {
        $check = $this->database->prepare(
            'SELECT 1 FROM files WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1',
        );
        $check->execute(['id' => $fileId, 'user_id' => $userId]);
        if ($check->fetchColumn() === false) {
            return null;
        }

        return $this->create($userId, $fileId, null, $expiresAt);
    }

    public function createFolderShare(string $userId, string $folderId, string|null $expiresAt): array|null
    {
        $check = $this->database->prepare('SELECT 1 FROM folders WHERE id = :id AND user_id = :user_id LIMIT 1');
        $check->execute(['id' => $folderId, 'user_id' => $userId]);
        if ($check->fetchColumn() === false) {
            return null;
        }

        return $this->create($userId, null, $folderId, $expiresAt);
    }

    public function list(string $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT shares.id, shares.file_id AS fileId, shares.folder_id AS folderId,
                    COALESCE(files.name, folders.name) AS name, shares.expires_at AS expiresAt,
                    shares.created_at AS createdAt
             FROM shares
             LEFT JOIN files ON files.id = shares.file_id
             LEFT JOIN folders ON folders.id = shares.folder_id
             WHERE shares.user_id = :user_id
               AND (shares.file_id IS NULL OR files.deleted_at IS NULL)
             ORDER BY shares.created_at DESC',
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(string $userId, string $shareId): bool
    {
        $statement = $this->database->prepare('DELETE FROM shares WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $shareId, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    public function getPublicShare(string $token): array|null
    {
        if (preg_match('/\A[A-Za-z0-9_-]{40,50}\z/', $token) !== 1) {
            return null;
        }

        $statement = $this->database->prepare(
            'SELECT id, file_id AS fileId, folder_id AS folderId, expires_at AS expiresAt
             FROM shares WHERE token_hash = :token_hash
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1',
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $share = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($share) ? $share : null;
    }

    public function publicDetails(array $share): array|null
    {
        if ($share['fileId'] !== null) {
            $file = $this->getFile($share['fileId']);
            if ($file === null) {
                return null;
            }

            return [
                'type' => 'file',
                'name' => $file['name'],
                'size' => $file['size'],
                'mimeType' => $file['mimeType'],
                'files' => [$this->publicFile($file)],
            ];
        }

        $folder = $this->getFolder($share['folderId']);
        if ($folder === null) {
            return null;
        }

        $statement = $this->database->prepare(
            'WITH RECURSIVE descendants AS (
                SELECT id FROM folders WHERE id = :folder_id
                UNION ALL
                SELECT folders.id FROM folders INNER JOIN descendants ON folders.parent_id = descendants.id
             )
             SELECT files.id, files.name, files.size, files.mime_type AS mimeType
             FROM files WHERE files.folder_id IN (SELECT id FROM descendants)
               AND files.deleted_at IS NULL
             ORDER BY files.name',
        );
        $statement->execute(['folder_id' => $folder['id']]);
        $files = array_map(fn(array $file): array => $this->publicFile($file), $statement->fetchAll(PDO::FETCH_ASSOC));

        return [
            'type' => 'folder',
            'name' => $folder['name'],
            'files' => $files,
        ];
    }

    public function publicDownload(array $share, string $fileId): array|null
    {
        $file = $this->getFile($fileId);
        if ($file === null) {
            return null;
        }

        if ($share['fileId'] !== null) {
            return $share['fileId'] === $fileId ? $file : null;
        }

        $statement = $this->database->prepare(
            'WITH RECURSIVE descendants AS (
                SELECT id FROM folders WHERE id = :folder_id
                UNION ALL
                SELECT folders.id FROM folders INNER JOIN descendants ON folders.parent_id = descendants.id
             )
             SELECT 1 FROM files
             WHERE files.id = :file_id AND files.deleted_at IS NULL
               AND files.folder_id IN (SELECT id FROM descendants) LIMIT 1',
        );
        $statement->execute(['folder_id' => $share['folderId'], 'file_id' => $fileId]);

        return $statement->fetchColumn() === false ? null : $file;
    }

    private function create(
        string $userId,
        string|null $fileId,
        string|null $folderId,
        string|null $expiresAt,
    ): array {
        $id = self::uuid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $statement = $this->database->prepare(
            'INSERT INTO shares (id, token_hash, user_id, file_id, folder_id, expires_at, created_at)
             VALUES (:id, :token_hash, :user_id, :file_id, :folder_id, :expires_at, UTC_TIMESTAMP())',
        );
        $statement->execute([
            'id' => $id,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'file_id' => $fileId,
            'folder_id' => $folderId,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => $id,
            'token' => $token,
            'fileId' => $fileId,
            'folderId' => $folderId,
            'expiresAt' => $expiresAt,
        ];
    }

    private function getFile(string $id): array|null
    {
        $statement = $this->database->prepare(
            'SELECT id, name, storage_key AS storageKey, size, mime_type AS mimeType
             FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($file)) {
            return null;
        }

        $file['size'] = (int) $file['size'];
        $file['path'] = $this->storage->pathFor($file['storageKey']);

        return $file;
    }

    private function getFolder(string $id): array|null
    {
        $statement = $this->database->prepare('SELECT id, name FROM folders WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $folder = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($folder) ? $folder : null;
    }

    private function publicFile(array $file): array
    {
        return [
            'id' => $file['id'],
            'name' => $file['name'],
            'size' => (int) $file['size'],
            'mimeType' => $file['mimeType'],
        ];
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
