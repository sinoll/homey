<?php

declare(strict_types=1);

use Yiisoft\Db\Mysql\Dsn;
use App\Environment;

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/db-mysql' => [
        'dsn' => new Dsn(
            'mysql',
            Environment::databaseHost(),
            Environment::databaseName(),
            (string) Environment::databasePort(),
            ['charset' => 'utf8mb4'],
        ),
        'username' => Environment::databaseUser(),
        'password' => Environment::databasePassword(),
    ],

    'fileStorage' => [
        'path' => Environment::appStoragePath(),
    ],
];
