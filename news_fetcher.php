<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/sentiment.php';

function fetch_url(string $url): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is not enabled.');
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 BTC-News-Sentiment/2.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/rss+xml, application/xml, text/xml, */*'
        ]
    ]);

    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException("HTTP fetch failed: $error");
    }

    if ($http >= 400) {
        throw new RuntimeException("HTTP status $http");
    }

    return $body;
}

function clean_text(?string $text): string {
    $text = html_entity_decode(
        $text ?? '',
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $text = strip_tags($text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function parse_feed(string $xmlText, string $feedName): array {
    libxml_use_internal_errors(true);

    $xml = simplexml_load_string($xmlText);

    if (!$xml) {
        return [];
    }

    $items = [];

    if (!isset($xml->channel->item)) {
        return $items;
    }

    foreach ($xml->channel->item as $item) {
        $title = clean_text((string)$item->title);
        $description = clean_text((string)$item->description);
        $url = trim((string)$item->link);
        $published = trim((string)$item->pubDate);

        if (!$title || !$url) {
            continue;
        }

        $timestamp = $published
            ? strtotime($published)
            : time();

        if ($timestamp === false) {
            $timestamp = time();
        }

        $items[] = [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'source' => $feedName,
            'published_at' => date('Y-m-d H:i:s', $timestamp)
        ];

        if (count($items) >= MAX_ITEMS_PER_FEED) {
            break;
        }
    }

    return $items;
}

function fetch_and_store_news(PDO $pdo): array {
    $total = 0;
    $errors = [];

    $insert = $pdo->prepare("
        INSERT OR IGNORE INTO news
        (
            title,
            description,
            source,
            url,
            url_hash,
            published_at,
            sentiment_score,
            sentiment_label,
            importance,
            keywords
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach (NEWS_FEEDS as $feedName => $feedUrl) {
        try {
            $xml = fetch_url($feedUrl);
            $items = parse_feed($xml, $feedName);

            foreach ($items as $item) {
                $publishedTs = strtotime($item['published_at']);

                if ($publishedTs === false) {
                    continue;
                }

                if ($publishedTs < time() - NEWS_LOOKBACK_HOURS * 3600) {
                    continue;
                }

                $analysis = analyze_sentiment(
                    $item['title'],
                    $item['description']
                );

                $hash = hash('sha256', $item['url']);

                $insert->execute([
                    $item['title'],
                    $item['description'],
                    $item['source'],
                    $item['url'],
                    $hash,
                    $item['published_at'],
                    $analysis['score'],
                    $analysis['label'],
                    $analysis['importance'],
                    $analysis['keywords']
                ]);

                if ($insert->rowCount() > 0) {
                    $total++;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $feedName . ': ' . $e->getMessage();
            error_log('BTC sentiment feed error: ' . end($errors));
        }
    }

    return [
        'stored' => $total,
        'errors' => $errors
    ];
}
?>
