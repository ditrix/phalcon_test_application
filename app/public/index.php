<?php

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Kyiv');

use App\Services\ImportService;
use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;

$db = new \Phalcon\Db\Adapter\Pdo\Mysql([
    'host' => getenv('DB_HOST') ?: 'db',
    'port' => getenv('DB_PORT') ?: '3306',
    'dbname' => getenv('DB_NAME') ?: 'phalcon_app',
    'username' => getenv('DB_USER') ?: 'phalcon',
    'password' => getenv('DB_PASS') ?: 'secret',
    'charset' => 'utf8mb4',
]);

$initSql = file_get_contents(__DIR__ . '/../migrations/001_init.sql');
if ($initSql !== false) {
    foreach (explode(';', $initSql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $db->execute($statement);
        }
    }
}

$di = new FactoryDefault();
$di->setShared('db', function () use ($db) {
    return $db;
});

$app = new Micro();
$app->setDI($di);

$app->get('/', function () {
    echo file_get_contents(__DIR__ . '/../views/index.php');
});

$app->post('/import/upload', function () use ($app) {
    $response = new Response();
    $response->setContentType('application/json', 'UTF-8');

    $files = $app->request->getUploadedFiles();
    if (empty($files)) {
        $response->setJsonContent(array('ok' => false, 'error' => 'No file uploaded'));
        return $response;
    }

    try {
        $service = new ImportService($app->getDI()->getShared('db'));
        $file = $files[0];
        $result = $service->uploadFile($file);
        $response->setJsonContent($result);
        return $response;
    } catch (\Throwable $e) {
        $response->setJsonContent(array('ok' => false, 'error' => $e->getMessage()));
        return $response;
    }
});

$app->get('/requests/rows', function () use ($app) {
    $response = new Response();
    $response->setContentType('application/json', 'UTF-8');

    try {
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $service = new ImportService($app->getDI()->getShared('db'));
        $response->setJsonContent($service->getRows($page));
    } catch (\Throwable $e) {
        $response->setJsonContent(array('error' => $e->getMessage()));
    }

    return $response;
});

$requestUri = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
$app->handle($requestUri ?: '/');
