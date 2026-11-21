<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    throw new RuntimeException('Preparación disponible solo en local.');
}
$connection = config('database.connections.mysql');
try {
    $pdo = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `school_tutoring_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo 'Base aislada school_tutoring_test preparada; no se modificó la base principal.'.PHP_EOL;
} catch (Throwable $exception) {
    echo 'No se pudo preparar la base de pruebas. Código: '.$exception->getCode().PHP_EOL;
    exit(1);
}
