<?php

declare(strict_types=1);

namespace App\Storage;

final class StoragePath
{
    public static function isAbsolute(string $path): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        $path = str_replace('/', '\\', $path);
        if (
            strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && $path[2] === '\\'
        ) {
            return true;
        }

        if (!str_starts_with($path, '\\\\')) {
            return false;
        }

        $segments = explode('\\', substr($path, 2));

        return isset($segments[1]) && $segments[0] !== '' && $segments[1] !== '';
    }

    public static function isWithin(string $path, string $directory): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = str_replace('/', '\\', $path);
            $directory = str_replace('/', '\\', $directory);
        }

        $pathPrefix = rtrim($path, '\\/') . DIRECTORY_SEPARATOR;
        $directoryPrefix = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR;

        if (DIRECTORY_SEPARATOR === '\\') {
            return strncasecmp($pathPrefix, $directoryPrefix, strlen($directoryPrefix)) === 0;
        }

        return str_starts_with($pathPrefix, $directoryPrefix);
    }
}
