<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database.php';

$extensions = [
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'curl' => extension_loaded('curl'),
    'simplexml' => extension_loaded('simplexml'),
    'mbstring' => extension_loaded('mbstring')
];

$db = db_status();

echo json_encode([
    'ok' => $db['ok'] && !in_array(false, $extensions, true),
    'php_version' => PHP_VERSION,
    'database' => $db,
    'extensions' => $extensions,
    'time' => date('c')
], JSON_PRETTY_PRINT);
?>
