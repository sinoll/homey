<?php

declare(strict_types=1);

use App\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env for non-Docker/non-container environments.
// Existing process environment variables take precedence (Docker, CI, server config).
// phpdotenv is a dev dependency — not available in production (composer install --no-dev).
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$installationConfig = dirname(__DIR__) . '/runtime/installation.php';
if (is_file($installationConfig)) {
    $settings = require $installationConfig;
    if (!is_array($settings)) {
        throw new RuntimeException('The installation configuration is invalid.');
    }

    foreach ($settings as $key => $value) {
        if (
            !is_string($key)
            || !is_string($value)
            || getenv($key) !== false
            || isset($_ENV[$key])
        ) {
            continue;
        }
        $_ENV[$key] = $value;
    }
}

Environment::prepare();
