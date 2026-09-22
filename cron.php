<?php
require_once __DIR__ . '/news_fetcher.php';

try {
    $pdo = db();
    $fetch = fetch_and_store_news($pdo);
    $snap = record_sentiment_snapshot($pdo);
    $day = $snap['twenty_four_hour'];

    echo "OK\n";
    echo "News stored: {$fetch['stored']}\n";
    echo "Sentiment: {$day['score']} {$day['label']}\n";
    echo "BTC: " . ($snap['btc_price'] ?? 'N/A') . "\n";

    if ($fetch['errors']) {
        echo "Warnings:\n";
        foreach ($fetch['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
