<?php

declare(strict_types=1);

use App\Environment;
use App\Service\AuthenticationService;
use App\Storage\LocalFileStorage;
use App\WebDav\BasicAuthBackend;
use App\WebDav\Filesystem;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;

$root = dirname(__DIR__);
require_once $root . '/src/bootstrap.php';

$database = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        Environment::databaseHost(),
        Environment::databasePort(),
        Environment::databaseName(),
    ),
    Environment::databaseUser(),
    Environment::databasePassword(),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$authentication = new BasicAuthBackend(new AuthenticationService($database));
$filesystem = new Filesystem($database, new LocalFileStorage(Environment::appStoragePath()), $authentication);
$server = new Server($filesystem->root());
$server->setBaseUri('/dav/');
$server->addPlugin(new AuthPlugin($authentication));
$server->start();
