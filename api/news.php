<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database.php';

try {
    $pdo = db();

    $limit = min(100, max(1, intval($_GET['limit'] ?? 40)));
    $hours = min(168, max(1, intval($_GET['hours'] ?? 72)));

    $since = date(
        'Y-m-d H:i:s',
        time() - ($hours * 3600)
    );

    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            description,
            source,
            url,
            published_at,
            sentiment_score,
            sentiment_label,
            importance,
            keywords
        FROM news
        WHERE published_at >= ?
        ORDER BY published_at DESC
        LIMIT $limit
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
