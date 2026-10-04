<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ImportService;
use Phalcon\Db\Adapter\Pdo\Mysql;

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'phalcon_app';
$user = getenv('DB_USER') ?: 'phalcon';
$pass = getenv('DB_PASS') ?: 'secret';

$db = new Mysql([
    'host' => $host,
    'port' => $port,
    'dbname' => $dbname,
    'username' => $user,
    'password' => $pass,
    'charset' => 'utf8mb4',
]);

$sql = file_get_contents(__DIR__ . '/../migrations/001_init.sql');
foreach (explode(';', $sql) as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $db->execute($statement);
    }
}

$path = $argv[1] ?? __DIR__ . '/../docs/База_даних_-_Аркуш1.csv';
$service = new ImportService($db);
$stats = $service->importFile($path);

printf("rows=%d\n", (int) ($stats['total_rows'] ?? 0));
printf("duplicates=%d\n", (int) ($stats['duplicates'] ?? 0));
printf("warnings=%d\n", (int) ($stats['rows_with_warnings'] ?? 0));
printf("elapsed_seconds=%.2f\n", (float) ($stats['elapsed_seconds'] ?? 0));
printf("peak_memory=%s\n", number_format(memory_get_peak_usage(true), 0, '.', ' '));
