<?php
require_once __DIR__ . '/config.php';

function sentiment_lexicon(): array {
    return [
        'record inflow' => 5.0,
        'record inflows' => 5.0,
        'institutional buying' => 4.5,
        'institutional adoption' => 4.2,
        'major adoption' => 4.0,
        'etf inflow' => 4.0,
        'etf inflows' => 4.0,
        'accumulation' => 3.5,
        'bitcoin reserve' => 4.0,
        'strategic reserve' => 4.0,
        'all time high' => 3.5,
        'all-time high' => 3.5,
        'breakout' => 2.8,
        'surge' => 2.5,
        'rally' => 2.4,
        'demand rises' => 2.8,
        'demand increases' => 2.8,
        'capital inflow' => 3.0,

        'bullish' => 2.0,
        'buying' => 1.7,
        'adoption' => 1.8,
        'approval' => 1.8,
        'approved' => 1.8,
        'positive' => 1.2,
        'growth' => 1.2,
        'recovery' => 1.5,
        'support' => 0.8,
        'optimism' => 1.5,
        'institutional' => 1.0,

        'record outflow' => -5.0,
        'record outflows' => -5.0,
        'etf outflow' => -4.0,
        'etf outflows' => -4.0,
        'exchange hack' => -5.0,
        'bitcoin hack' => -5.0,
        'major hack' => -5.0,
        'insolvency' => -5.0,
        'bankruptcy' => -4.5,
        'mass liquidation' => -4.5,
        'ban bitcoin' => -4.0,
        'bitcoin ban' => -4.0,
        'capital outflow' => -3.0,

        'bearish' => -2.0,
        'selling' => -1.7,
        'sell-off' => -2.5,
        'selloff' => -2.5,
        'liquidation' => -2.5,
        'liquidations' => -2.5,
        'lawsuit' => -1.8,
        'regulatory pressure' => -2.5,
        'regulation' => -0.8,
        'crackdown' => -2.8,
        'fraud' => -3.5,
        'exploit' => -3.5,
        'hack' => -3.5,
        'decline' => -1.8,
        'drop' => -1.5,
        'plunge' => -3.2,
        'crash' => -4.0,
        'fear' => -1.8,
        'outflow' => -2.0,
        'concern' => -1.2,
        'risk' => -0.7
    ];
}

function analyze_sentiment(string $title, string $description = ''): array {
    $text = mb_strtolower(strip_tags($title . ' ' . $description), 'UTF-8');
    $headline = mb_strtolower($title, 'UTF-8');

    $score = 0.0;
    $matches = [];

    foreach (sentiment_lexicon() as $phrase => $weight) {
        $pattern = '/(?<![\p{L}\p{N}])' .
                   preg_quote($phrase, '/') .
                   '(?![\p{L}\p{N}])/iu';

        $count = preg_match_all($pattern, $text, $m);

        if ($count) {
            $effective = min($count, 3);
            $score += $weight * $effective;
            $matches[] = $phrase . ($count > 1 ? " x$count" : '');
        }
    }

    $headlineBonus = 0.0;

    foreach (sentiment_lexicon() as $phrase => $weight) {
        $pattern = '/(?<![\p{L}\p{N}])' .
                   preg_quote($phrase, '/') .
                   '(?![\p{L}\p{N}])/iu';

        if (preg_match($pattern, $headline)) {
            $headlineBonus += $weight * 0.35;
        }
    }

    $score += $headlineBonus;

    $normalized = max(-100, min(100, $score * 8));

    if ($normalized >= 25) {
        $label = 'Bullish';
    } elseif ($normalized <= -25) {
        $label = 'Bearish';
    } else {
        $label = 'Neutral';
    }

    $importance = 1.0 + min(4.0, abs($normalized) / 25.0);

    if ($headlineBonus != 0) {
        $importance += 0.5;
    }

    return [
        'score' => round($normalized, 2),
        'label' => $label,
        'importance' => round($importance, 2),
        'keywords' => implode(', ', array_slice($matches, 0, 12))
    ];
}

function aggregate_sentiment(PDO $pdo, int $hours = 24): array {
    $since = date('Y-m-d H:i:s', time() - ($hours * 3600));

    $stmt = $pdo->prepare("
        SELECT sentiment_score, sentiment_label, importance
        FROM news
        WHERE published_at >= ?
        ORDER BY published_at DESC
    ");

    $stmt->execute([$since]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [
            'score' => 0,
            'label' => 'Neutral',
            'bullish' => 0,
            'bearish' => 0,
            'neutral' => 0,
            'count' => 0
        ];
    }

    $weighted = 0;
    $totalWeight = 0;

    $bullish = 0;
    $bearish = 0;
    $neutral = 0;

    foreach ($rows as $row) {
        $weight = max(0.5, (float)$row['importance']);

        $weighted += (float)$row['sentiment_score'] * $weight;
        $totalWeight += $weight;

        if ($row['sentiment_label'] === 'Bullish') {
            $bullish++;
        } elseif ($row['sentiment_label'] === 'Bearish') {
            $bearish++;
        } else {
            $neutral++;
        }
    }

    $score = $totalWeight ? $weighted / $totalWeight : 0;

    if ($score >= 25) {
        $label = 'Bullish';
    } elseif ($score <= -25) {
        $label = 'Bearish';
    } else {
        $label = 'Neutral';
    }

    return [
        'score' => round($score, 2),
        'label' => $label,
        'bullish' => $bullish,
        'bearish' => $bearish,
        'neutral' => $neutral,
        'count' => count($rows)
    ];
}

function get_btc_price(): ?float {
    if (!function_exists('curl_init')) {
        return null;
    }

    try {
        $ch = curl_init(BTC_PRICE_URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'BTC-News-Sentiment/2.0'
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return null;
        }

        $json = json_decode($body, true);

        return isset($json['price'])
            ? (float)$json['price']
            : null;
    } catch (Throwable $e) {
        return null;
    }
}

function record_sentiment_snapshot(PDO $pdo): array {
    $oneHour = aggregate_sentiment($pdo, 1);
    $sixHour = aggregate_sentiment($pdo, 6);
    $day = aggregate_sentiment($pdo, 24);
    $price = get_btc_price();
    $recordedAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO sentiment_history
        (
            recorded_at,
            score,
            label,
            bullish_count,
            bearish_count,
            neutral_count,
            news_count,
            btc_price,
            sentiment_1h,
            sentiment_6h,
            sentiment_24h
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $recordedAt,
        $day['score'],
        $day['label'],
        $day['bullish'],
        $day['bearish'],
        $day['neutral'],
        $day['count'],
        $price,
        $oneHour['score'],
        $sixHour['score'],
        $day['score']
    ]);

    return [
        'recorded_at' => $recordedAt,
        'btc_price' => $price,
        'one_hour' => $oneHour,
        'six_hour' => $sixHour,
        'twenty_four_hour' => $day
    ];
}
?>
