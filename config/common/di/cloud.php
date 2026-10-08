<?php

declare(strict_types=1);

use App\Environment;

return [
    PDO::class => static fn(): PDO => new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Environment::databaseHost(),
            Environment::databasePort(),
            Environment::databaseName(),
        ),
        Environment::databaseUser(),
        Environment::databasePassword(),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    ),
    App\Storage\LocalFileStorage::class => [
        '__construct()' => [
            'rootPath' => Environment::appStoragePath(),
        ],
    ],
];
