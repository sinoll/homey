<?php

declare(strict_types=1);

namespace App\Storage;

final class FileContentType
{
    private const TYPES = [
        'aac' => 'audio/aac',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'csv' => 'text/csv; charset=utf-8',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'flac' => 'audio/flac',
        'gif' => 'image/gif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'json' => 'application/json; charset=utf-8',
        'log' => 'text/plain; charset=utf-8',
        'm4a' => 'audio/mp4',
        'm4v' => 'video/mp4',
        'md' => 'text/markdown; charset=utf-8',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'ogv' => 'video/ogg',
        'opus' => 'audio/ogg',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'txt' => 'text/plain; charset=utf-8',
        'wav' => 'audio/wav',
        'webm' => 'video/webm',
        'webp' => 'image/webp',
        'xml' => 'application/xml; charset=utf-8',
        'yaml' => 'text/yaml; charset=utf-8',
        'yml' => 'text/yaml; charset=utf-8',
    ];

    public static function forFilename(string $filename, string $fallback = 'application/octet-stream'): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? $fallback;
    }

    public static function forPreview(string $filename): string|null
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['avif', 'bmp', 'gif', 'jpeg', 'jpg', 'png', 'webp'], true)) {
            return self::TYPES[$extension];
        }
        if (in_array($extension, ['aac', 'flac', 'm4a', 'mp3', 'oga', 'ogg', 'opus', 'wav'], true)) {
            return self::TYPES[$extension];
        }
        if (in_array($extension, ['m4v', 'mp4', 'mov', 'ogv', 'webm'], true)) {
            return self::TYPES[$extension];
        }
        if (in_array($extension, ['csv', 'json', 'log', 'md', 'txt', 'xml', 'yaml', 'yml'], true)) {
            return self::TYPES[$extension];
        }
        if ($extension === 'pdf' || $extension === 'docx') {
            return self::TYPES[$extension];
        }

        return null;
    }
}
