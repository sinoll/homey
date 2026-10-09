<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class InstallationConfig
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $settings = require $path;
        if (!is_array($settings)) {
            throw new RuntimeException('The installation configuration is invalid.');
        }

        foreach ($settings as $key => $value) {
            if (!is_string($key) || !is_string($value) || getenv($key) !== false) {
                continue;
            }
            $_ENV[$key] = $value;
        }
    }
}
