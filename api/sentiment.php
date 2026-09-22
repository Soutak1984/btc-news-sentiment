<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../sentiment.php';

try {
    $pdo = db();

    $s1 = aggregate_sentiment($pdo, 1);
    $s6 = aggregate_sentiment($pdo, 6);
    $s24 = aggregate_sentiment($pdo, 24);

    $previous = $pdo->query("
        SELECT score
        FROM sentiment_history
        ORDER BY recorded_at DESC
        LIMIT 1
    ")->fetchColumn();

    $change = $previous !== false
        ? round($s24['score'] - (float)$previous, 2)
        : 0;

    $price = get_btc_price();

    echo json_encode([
        'ok' => true,
        'score' => $s24['score'],
        'label' => $s24['label'],
        'change' => $change,
        'btc_price' => $price,
        'one_hour' => $s1,
        'six_hour' => $s6,
        'twenty_four_hour' => $s24,
        'updated_at' => date('c')
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>
