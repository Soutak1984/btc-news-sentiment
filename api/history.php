<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database.php';

try {
    $pdo = db();

    $hours = min(168, max(1, intval($_GET['hours'] ?? 24)));

    $since = date(
        'Y-m-d H:i:s',
        time() - ($hours * 3600)
    );

    $stmt = $pdo->prepare("
        SELECT
            recorded_at,
            score,
            label,
            news_count,
            btc_price
        FROM sentiment_history
        WHERE recorded_at >= ?
        ORDER BY recorded_at ASC
    ");

    $stmt->execute([$since]);

    echo json_encode([
        'ok' => true,
        'items' => $stmt->fetchAll()
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>
