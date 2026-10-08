<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;

use function bin2hex;
use function random_bytes;
use function substr;

final readonly class FolderService
{
    public function __construct(private PDO $database) {}

    public function list(string $userId, string|null $parentId = null): array
    {
        $statement = $this->database->prepare(
            'SELECT id, name, parent_id AS parentId, created_at AS createdAt
             FROM folders WHERE user_id = :user_id AND parent_id <=> :parent_id
             ORDER BY name',
        );
        $statement->execute(['user_id' => $userId, 'parent_id' => $parentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(string $userId, string $name, string|null $parentId = null): array|null
    {
        $name = self::validateName($name);
        if ($name === null || ($parentId !== null && !$this->belongsToUser($parentId, $userId))) {
            return null;
        }

        if ($this->nameExists($userId, $parentId, $name)) {
            return null;
        }

        $id = self::uuid();
        try {
            $statement = $this->database->prepare(
                'INSERT INTO folders (id, user_id, parent_id, name, created_at)
                 VALUES (:id, :user_id, :parent_id, :name, UTC_TIMESTAMP())',
            );
            $statement->execute([
                'id' => $id,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'name' => $name,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return null;
            }
            throw $exception;
        }

        return ['id' => $id, 'name' => $name, 'parentId' => $parentId];
    }

    public function rename(string $userId, string $id, string $name): bool
    {
        $name = self::validateName($name);
        if ($name === null) {
            return false;
        }

        $folder = $this->find($userId, $id);
        if ($folder === null || $this->nameExists($userId, $folder['parent_id'], $name, $id)) {
            return false;
        }
        if ($folder['name'] === $name) {
            return true;
        }

        $statement = $this->database->prepare(
            'UPDATE folders SET name = :name WHERE id = :id AND user_id = :user_id',
        );
        $statement->execute(['name' => $name, 'id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    public function move(string $userId, string $id, string|null $parentId): bool
    {
        $folder = $this->find($userId, $id);
        if (
            $folder === null
            || ($parentId !== null && !$this->belongsToUser($parentId, $userId))
            || $parentId === $id
            || ($parentId !== null && $this->isDescendant($parentId, $id))
            || $this->nameExists($userId, $parentId, $folder['name'], $id)
        ) {
            return false;
        }
        if ($folder['parent_id'] === $parentId) {
            return true;
        }

        $statement = $this->database->prepare(
            'UPDATE folders SET parent_id = :parent_id WHERE id = :id AND user_id = :user_id',
        );
        $statement->execute(['parent_id' => $parentId, 'id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    public function deleteIfEmpty(string $userId, string $id): bool|null
    {
        $folder = $this->find($userId, $id);
        if ($folder === null) {
            return false;
        }

        $check = $this->database->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM folders WHERE user_id = :folder_user AND parent_id = :folder_id)
                OR EXISTS(SELECT 1 FROM files WHERE user_id = :file_user AND folder_id = :file_folder)',
        );
        $check->execute([
            'folder_user' => $userId,
            'folder_id' => $id,
            'file_user' => $userId,
            'file_folder' => $id,
        ]);
        if ((int) $check->fetchColumn() !== 0) {
            return null;
        }

        $delete = $this->database->prepare('DELETE FROM folders WHERE id = :id AND user_id = :user_id');
        $delete->execute(['id' => $id, 'user_id' => $userId]);

        return $delete->rowCount() > 0;
    }

    public function find(string $userId, string $id): array|null
    {
        $statement = $this->database->prepare(
            'SELECT id, name, parent_id FROM folders WHERE id = :id AND user_id = :user_id LIMIT 1',
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $folder = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($folder) ? $folder : null;
    }

    public function belongsToUser(string $id, string $userId): bool
    {
        return $this->find($userId, $id) !== null;
    }

    private function nameExists(
        string $userId,
        string|null $parentId,
        string $name,
        string|null $excludeId = null,
    ): bool {
        $sql = 'SELECT
                    EXISTS(SELECT 1 FROM folders WHERE user_id = :user_id AND parent_id <=> :parent_id AND name = :name'
            . ($excludeId === null ? '' : ' AND id <> :exclude_id')
            . ')
                    OR EXISTS(SELECT 1 FROM files WHERE user_id = :file_user_id
                        AND folder_id <=> :file_parent_id AND name = :file_name AND deleted_at IS NULL)';
        $params = ['user_id' => $userId, 'parent_id' => $parentId, 'name' => $name];
        $params['file_user_id'] = $userId;
        $params['file_parent_id'] = $parentId;
        $params['file_name'] = $name;
        if ($excludeId !== null) {
            $params['exclude_id'] = $excludeId;
        }
        $statement = $this->database->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() !== 0;
    }

    private function isDescendant(string $candidateId, string $ancestorId): bool
    {
        $statement = $this->database->prepare(
            'WITH RECURSIVE descendants AS (
                SELECT id FROM folders WHERE parent_id = :ancestor_id
                UNION ALL
                SELECT folders.id FROM folders
                INNER JOIN descendants ON folders.parent_id = descendants.id
             )
             SELECT 1 FROM descendants WHERE id = :candidate_id LIMIT 1',
        );
        $statement->execute(['ancestor_id' => $ancestorId, 'candidate_id' => $candidateId]);

        return $statement->fetchColumn() !== false;
    }

    private static function validateName(string $name): string|null
    {
        $name = trim($name);
        if (
            $name === ''
            || strlen($name) > 255
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || $name === '.'
            || $name === '..'
        ) {
            return null;
        }

        return $name;
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
