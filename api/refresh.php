<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../news_fetcher.php';

try {
    $pdo = db();

    $result = fetch_and_store_news($pdo);

    echo json_encode([
        'ok' => true,
        'stored' => $result['stored'],
        'errors' => $result['errors'],
        'time' => date('c')
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>
