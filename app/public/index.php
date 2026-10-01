<?php

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Micro;

$di = new FactoryDefault();

$di->setShared('db', function () {
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME') ?: 'phalcon_app';
    $user = getenv('DB_USER') ?: 'phalcon';
    $pass = getenv('DB_PASS') ?: 'secret';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

    return new \Phalcon\Db\Adapter\Pdo\Mysql([
        'host' => $host,
        'port' => $port,
        'dbname' => $dbname,
        'username' => $user,
        'password' => $pass,
        'charset' => 'utf8mb4',
    ]);
});

$app = new Micro();
$app->setDI($di);

$app->get('/', function () use ($app) {
    $phalconVersion = \Phalcon\Version::get();
    $phpVersion = PHP_VERSION;
    $dbStatus = 'DB connection failed';

    try {
        $result = $app->getDI()->getShared('db')->fetchOne('SELECT 1');
        if ($result !== null) {
            $dbStatus = 'DB connection OK';
        }
    } catch (\Throwable $e) {
        $dbStatus = 'DB connection failed: ' . $e->getMessage();
    }

    echo "Phalcon version: {$phalconVersion}<br>";
    echo "PHP version: {$phpVersion}<br>";
    echo "DB status: {$dbStatus}<br>";
});

$app->handle($_SERVER['REQUEST_URI'] ?? '/');
