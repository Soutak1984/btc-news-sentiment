<?php
require_once __DIR__ . '/news_fetcher.php';

const TELEGRAM_EXIT_CONFIG = 78;

function log_line(string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function env_value(string $key): string {
    $value = getenv($key);
    if ($value === false) {
        return '';
    }

    return trim($value);
}

function load_env_file(string $path): void {
    if (!is_file($path)) {
        return;
    }

    if (DIRECTORY_SEPARATOR === '/' ) {
        $perms = fileperms($path) & 0777;
        if ($perms & 0077) {
            log_line('Warning: telegram.env is readable by other users. Run: chmod 600 telegram.env');
        }
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
            continue;
        }

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            }
        }

        $current = getenv($key);
        if ($current === false || $current === '') {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function bot_token(): string {
    $token = env_value('TELEGRAM_BOT_TOKEN');

    if (
        $token === ''
        || str_contains($token, 'paste')
        || str_contains($token, 'replace')
        || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)
    ) {
        log_line('Set TELEGRAM_BOT_TOKEN in telegram.env. Copy telegram.env.example and paste the token from @BotFather.');
        exit(TELEGRAM_EXIT_CONFIG);
    }

    return $token;
}

function collect_interval_minutes(): int {
    $raw = env_value('COLLECT_INTERVAL_MINUTES');
    if ($raw === '') {
        return 15;
    }

    return max(0, (int)$raw);
}

function allowed_ids(): array {
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $ids = [];
    foreach (preg_split('/[\s,]+/', env_value('TELEGRAM_ALLOWED_USER_IDS'), -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (preg_match('/^-?\d+$/', $part)) {
            $ids[$part] = true;
        }
    }

    return $ids;
}

function html_escape(string $value): string {
    return htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8');
}

function html_attr(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function mood_icon(string $label): string {
    if ($label === 'Bullish') {
        return '🟢';
    }

    if ($label === 'Bearish') {
        return '🔴';
    }

    return '🟡';
}

function format_score($score): string {
    return sprintf('%+.2f', (float)$score);
}

function format_price($price): string {
    if ($price === null || $price === '') {
        return 'unavailable';
    }

    return '$' . number_format((float)$price, 2);
}

function short_text(string $text, int $max = 240): string {
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $max - 1)) . '…';
}

function safe_link(string $url, string $label): string {
    $label = html_escape($label);
    if (!preg_match('#^https?://#i', $url)) {
        return $label;
    }

    return '<a href="' . html_attr($url) . '">' . $label . '</a>';
}

function data_path(string $name): string {
    return dirname(DB_FILE) . DIRECTORY_SEPARATOR . $name;
}

function tg(string $method, array $params, int $timeout = 30): array {
    $url = 'https://api.telegram.org/bot' . bot_token() . '/' . $method;
    $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Could not encode a Telegram request.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new RuntimeException('Telegram network error: ' . $error);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Telegram returned a non-JSON response.');
    }

    if (!($json['ok'] ?? false) && (int)($json['error_code'] ?? 0) === 401) {
        log_line('Telegram rejected the bot token.');
        exit(TELEGRAM_EXIT_CONFIG);
    }

    return $json;
}

function reply_keyboard(): array {
    return [
        'keyboard' => [
            [['text' => 'Sentiment'], ['text' => 'Price']],
            [['text' => 'News'], ['text' => 'Bullish'], ['text' => 'Bearish']],
            [['text' => 'History'], ['text' => 'Status']],
            [['text' => 'Refresh'], ['text' => 'Help']]
        ],
        'resize_keyboard' => true
    ];
}

function split_message(string $html, int $limit = 3500): array {
    if (mb_strlen($html) <= $limit) {
        return [$html];
    }

    $parts = [];
    $current = '';

    foreach (preg_split("/\n/u", $html) as $line) {
        $candidate = $current === '' ? $line : $current . "\n" . $line;

        if (mb_strlen($candidate) <= $limit) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $parts[] = $current;
            $current = '';
        }

        while (mb_strlen($line) > $limit) {
            $parts[] = mb_substr($line, 0, $limit);
            $line = mb_substr($line, $limit);
        }

        $current = $line;
    }

    if ($current !== '') {
        $parts[] = $current;
    }

    return $parts;
}

function send_html($chatId, string $html, bool $withKeyboard = true): void {
    $keyboard = $withKeyboard ? reply_keyboard() : null;

    foreach (split_message($html) as $index => $part) {
        if (trim($part) === '') {
            continue;
        }

        $params = [
            'chat_id' => (string)$chatId,
            'text' => $part,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];

        if ($index === 0 && $keyboard !== null) {
            $params['reply_markup'] = $keyboard;
        }

        $response = tg('sendMessage', $params, 30);
        if ($response['ok'] ?? false) {
            continue;
        }

        $description = (string)($response['description'] ?? 'send failed');
        log_line('sendMessage failed: ' . $description);

        if ((int)($response['error_code'] ?? 0) !== 400) {
            continue;
        }

        $params['text'] = html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        unset($params['parse_mode']);
        $retry = tg('sendMessage', $params, 30);

        if (!($retry['ok'] ?? false)) {
            log_line('sendMessage plain retry failed: ' . (string)($retry['description'] ?? ''));
        }
    }
}

function is_allowed(array $message): bool {
    $allowed = allowed_ids();
    if (!$allowed) {
        return false;
    }

    $chatType = (string)($message['chat']['type'] ?? '');
    $userId = isset($message['from']['id']) ? (string)$message['from']['id'] : '';
    $chatId = isset($message['chat']['id']) ? (string)$message['chat']['id'] : '';

    if ($chatType === 'private') {
        return $userId !== '' && isset($allowed[$userId]);
    }

    return $chatId !== '' && isset($allowed[$chatId]);
}

function deny_text(array $message): string {
    $userId = (string)($message['from']['id'] ?? 'unknown');
    $chatId = (string)($message['chat']['id'] ?? 'unknown');

    return "This bot is private.\n\n"
        . "Your Telegram user id: <code>" . html_escape($userId) . "</code>\n"
        . "This chat id: <code>" . html_escape($chatId) . "</code>\n\n"
        . "On the server, add the user id to <code>TELEGRAM_ALLOWED_USER_IDS</code> in <code>telegram.env</code>, then restart the bot.";
}

function whoami_text(array $message): string {
    $lines = [
        '<b>Telegram identity</b>',
        'User id: <code>' . html_escape((string)($message['from']['id'] ?? 'unknown')) . '</code>',
        'Chat id: <code>' . html_escape((string)($message['chat']['id'] ?? 'unknown')) . '</code>',
        'Chat type: ' . html_escape((string)($message['chat']['type'] ?? 'unknown'))
    ];

    $username = (string)($message['from']['username'] ?? '');
    if ($username !== '') {
        $lines[] = 'Username: @' . html_escape($username);
    }

    $lines[] = '';
    $lines[] = is_allowed($message)
        ? 'This chat is allowed to query results.'
        : 'This id is not on the allowlist yet. Add the user id to TELEGRAM_ALLOWED_USER_IDS and restart the bot.';

    return implode("\n", $lines);
}

function help_text(): string {
    return <<<'HTML'
<b>BTC News Sentiment</b>
Query the server from this chat. The buttons send the same words.

<b>Sentiment</b> — 1h, 6h, and 24h scores
<b>Price</b> — live BTC/USDT ticker
<b>News</b> — latest headlines
<b>News 24 5 bullish</b> — 24h window, 5 articles, bullish only
<b>Bullish</b> / <b>Bearish</b> — filtered headlines
<b>etf inflow</b> — search stored titles
<b>History 48</b> — snapshots from the last 48 hours
<b>Status</b> — database and collector health
<b>Refresh</b> — fetch feeds now

Commands: /sentiment /price /news /search /history /status /refresh /whoami /help

Plain-language search works in a private chat. In a group, Telegram only delivers commands unless BotFather privacy mode is disabled.
HTML;
}

function canonical_command(string $command): string {
    $map = [
        'score' => 'sentiment',
        'btc' => 'price',
        'headlines' => 'news',
        'articles' => 'news',
        'find' => 'news',
        'search' => 'news',
        'collect' => 'refresh',
        'update' => 'refresh'
    ];

    return $map[$command] ?? $command;
}

function route_text(string $text): array {
    $raw = trim($text);
    if ($raw === '') {
        return ['help', ''];
    }

    if (preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s+([\s\S]*))?$/u', $raw, $matches)) {
        return [strtolower($matches[1]), trim((string)($matches[2] ?? ''))];
    }

    $low = mb_strtolower($raw);
    $exact = [
        'sentiment' => ['sentiment', ''],
        'score' => ['sentiment', ''],
        'price' => ['price', ''],
        'btc' => ['price', ''],
        'btc price' => ['price', ''],
        'bitcoin price' => ['price', ''],
        'history' => ['history', ''],
        'status' => ['status', ''],
        'refresh' => ['refresh', ''],
        'help' => ['help', ''],
        'start' => ['help', ''],
        'news' => ['news', ''],
        'headlines' => ['news', ''],
        'bullish' => ['news', 'bullish'],
        'bearish' => ['news', 'bearish'],
        'neutral' => ['news', 'neutral']
    ];

    if (isset($exact[$low])) {
        return $exact[$low];
    }

    if (preg_match('/^(?:news|headlines|articles)\b\s*(.*)$/iu', $raw, $matches)) {
        return ['news', trim((string)$matches[1])];
    }

    if (preg_match('/^(?:search|find)\b\s+(.+)$/iu', $raw, $matches)) {
        return ['news', trim((string)$matches[1])];
    }

    if (preg_match('/^(?:history|snapshots?)\b\s*(.*)$/iu', $raw, $matches)) {
        return ['history', trim((string)$matches[1])];
    }

    if (
        preg_match('/\b(sentiment|score)\b/iu', $low)
        && str_word_count($low) <= 6
        && !preg_match('/\b(news|headline|article)\b/iu', $low)
    ) {
        return ['sentiment', ''];
    }

    if (
        preg_match('/\b(price|ticker)\b/iu', $low)
        && str_word_count($low) <= 6
        && !preg_match('/\b(news|headline|article)\b/iu', $low)
    ) {
        return ['price', ''];
    }

    if (preg_match('/\b(history|snapshots?)\b/iu', $low) && str_word_count($low) <= 6) {
        return ['history', $raw];
    }

    if (preg_match('/\b(status|health)\b/iu', $low) && str_word_count($low) <= 3) {
        return ['status', ''];
    }

    if (preg_match('/\b(refresh|collect)\b/iu', $low) && str_word_count($low) <= 3) {
        return ['refresh', ''];
    }

    if (mb_strlen($raw) >= 2) {
        return ['news', $raw];
    }

    return ['help', ''];
}

function parse_news_args(string $args): array {
    $hours = 72;
    $limit = 8;
    $label = null;
    $terms = [];
    $numbers = 0;

    $parts = preg_split('/\s+/u', trim($args), -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        $parts = [];
    }

    foreach ($parts as $part) {
        $low = mb_strtolower($part);
        if (in_array($low, ['bullish', 'bearish', 'neutral'], true)) {
            $label = ucfirst($low);
            continue;
        }

        if (preg_match('/^\d{1,3}$/', $part)) {
            $numbers++;
            $number = (int)$part;
            if ($numbers === 1) {
                $hours = max(1, min(168, $number));
            } else {
                $limit = max(1, min(12, $number));
            }
            continue;
        }

        $terms[] = $part;
    }

    return [
        'hours' => $hours,
        'limit' => $limit,
        'label' => $label,
        'term' => trim(implode(' ', $terms))
    ];
}

function escape_like(string $value): string {
    return str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $value
    );
}

function query_news(PDO $pdo, int $hours, int $limit, ?string $label, string $term): array {
    $hours = max(1, min(168, $hours));
    $limit = max(1, min(12, $limit));
    $since = date('Y-m-d H:i:s', time() - ($hours * 3600));

    $sql = "
        SELECT
            title,
            source,
            url,
            published_at,
            sentiment_score,
            sentiment_label,
            importance,
            keywords
        FROM news
        WHERE published_at >= ?
    ";
    $params = [$since];

    if ($label !== null) {
        $sql .= " AND sentiment_label = ?";
        $params[] = $label;
    }

    if ($term !== '') {
        $like = '%' . escape_like($term) . '%';
        $sql .= "
            AND (
                title LIKE ? ESCAPE '\\'
                OR keywords LIKE ? ESCAPE '\\'
                OR description LIKE ? ESCAPE '\\'
            )
        ";
        array_push($params, $like, $like, $like);
    }

    $sql .= " ORDER BY published_at DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function window_line(string $name, array $row): string {
    return $name . ': ' . mood_icon((string)$row['label'])
        . ' <b>' . format_score($row['score']) . '</b> '
        . html_escape((string)$row['label'])
        . ' · ' . (int)$row['count'] . ' articles'
        . ' (' . (int)$row['bullish'] . ' bull / '
        . (int)$row['neutral'] . ' neu / '
        . (int)$row['bearish'] . ' bear)';
}

function sentiment_report(PDO $pdo): string {
    $oneHour = aggregate_sentiment($pdo, 1);
    $sixHour = aggregate_sentiment($pdo, 6);
    $day = aggregate_sentiment($pdo, 24);
    $price = get_btc_price();

    $previous = $pdo->query("
        SELECT score, recorded_at
        FROM sentiment_history
        ORDER BY recorded_at DESC
        LIMIT 1
    ")->fetch();

    $lines = [
        '<b>BTC news sentiment</b>',
        'BTC: <b>' . format_price($price) . '</b>',
        '',
        window_line('1h', $oneHour),
        window_line('6h', $sixHour),
        window_line('24h', $day)
    ];

    if ($previous) {
        $change = round((float)$day['score'] - (float)$previous['score'], 2);
        $lines[] = 'Change vs snapshot ' . html_escape((string)$previous['recorded_at'])
            . ': <b>' . format_score($change) . '</b>';
    }

    if ((int)$day['count'] === 0) {
        $lines[] = '';
        $lines[] = 'No articles in the last 24 hours yet. Send Refresh to collect news.';
    }

    $lines[] = '';
    $lines[] = 'Updated ' . date('Y-m-d H:i:s T');

    return implode("\n", $lines);
}

function price_report(PDO $pdo): string {
    $live = get_btc_price();
    $last = $pdo->query("
        SELECT recorded_at, btc_price, score, label
        FROM sentiment_history
        WHERE btc_price IS NOT NULL
        ORDER BY recorded_at DESC
        LIMIT 1
    ")->fetch();

    $lines = [
        '<b>BTC / USDT</b>',
        'Binance: <b>' . format_price($live) . '</b>'
    ];

    if ($last) {
        $lines[] = 'Last snapshot: ' . format_price($last['btc_price'])
            . ' at ' . html_escape((string)$last['recorded_at']);
        $lines[] = 'Sentiment then: ' . mood_icon((string)$last['label']) . ' '
            . format_score($last['score']) . ' '
            . html_escape((string)$last['label']);
    }

    if ($live === null) {
        $lines[] = '';
        $lines[] = $last
            ? 'The live ticker did not respond. Showing the last stored price.'
            : 'The live ticker did not respond, and there is no stored price yet.';
    }

    return implode("\n", $lines);
}

function news_report(PDO $pdo, string $args): string {
    $parsed = parse_news_args($args);
    $rows = query_news(
        $pdo,
        $parsed['hours'],
        $parsed['limit'],
        $parsed['label'],
        $parsed['term']
    );

    $meta = $parsed['hours'] . 'h · showing up to ' . $parsed['limit'];
    if ($parsed['label'] !== null) {
        $meta .= ' · ' . $parsed['label'];
    }
    if ($parsed['term'] !== '') {
        $meta .= ' · match ' . $parsed['term'];
    }

    $lines = [
        '<b>Bitcoin news</b>',
        html_escape($meta),
        ''
    ];

    if (!$rows) {
        $lines[] = 'No matching articles are stored for that query.';
        $lines[] = 'Send Refresh to pull the latest feeds.';
        return implode("\n", $lines);
    }

    $number = 1;
    foreach ($rows as $row) {
        $lines[] = $number . '. ' . mood_icon((string)$row['sentiment_label'])
            . ' <b>' . format_score($row['sentiment_score']) . ' '
            . html_escape((string)$row['sentiment_label']) . '</b>';
        $lines[] = safe_link((string)$row['url'], short_text((string)$row['title']));
        $lines[] = html_escape((string)($row['source'] ?? ''))
            . ' · ' . html_escape((string)$row['published_at'])
            . ' · importance ' . number_format((float)$row['importance'], 1);

        if (!empty($row['keywords'])) {
            $lines[] = 'Matched: ' . html_escape((string)$row['keywords']);
        }

        $lines[] = '';
        $number++;
    }

    return rtrim(implode("\n", $lines));
}

function parse_hours(string $args, int $default, int $max = 168): int {
    if (preg_match('/\d{1,4}/', $args, $matches)) {
        return max(1, min($max, (int)$matches[0]));
    }

    return $default;
}

function snapshot_line(array $row): string {
    $price = ($row['btc_price'] === null || $row['btc_price'] === '')
        ? 'n/a'
        : format_price($row['btc_price']);

    return html_escape((string)$row['recorded_at'])
        . '  ' . format_score($row['score'])
        . ' ' . html_escape((string)$row['label'])
        . '  ' . $price
        . '  ' . (int)$row['news_count'] . ' news';
}

function history_report(PDO $pdo, string $args): string {
    $hours = parse_hours($args, 24);
    $since = date('Y-m-d H:i:s', time() - ($hours * 3600));

    $stmt = $pdo->prepare("
        SELECT recorded_at, score, label, news_count, btc_price
        FROM sentiment_history
        WHERE recorded_at >= ?
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return "<b>Sentiment history</b>\n\nNo snapshots in the last {$hours}h. Send Refresh to record one.";
    }

    $high = null;
    $low = null;
    foreach ($rows as $row) {
        $score = (float)$row['score'];
        if ($high === null || $score > $high['score']) {
            $high = ['score' => $score, 'at' => $row['recorded_at']];
        }
        if ($low === null || $score < $low['score']) {
            $low = ['score' => $score, 'at' => $row['recorded_at']];
        }
    }

    $latest = $rows[count($rows) - 1];
    $oldest = $rows[0];
    $recent = array_slice(array_reverse($rows), 0, 8);

    $lines = [
        '<b>Sentiment history</b>',
        'Last ' . $hours . 'h · ' . count($rows) . ' snapshots',
        '',
        'Latest: ' . mood_icon((string)$latest['label']) . ' <b>'
            . format_score($latest['score']) . '</b> '
            . html_escape((string)$latest['label'])
            . ' at ' . html_escape((string)$latest['recorded_at']),
        'High: <b>' . format_score($high['score']) . '</b> at ' . html_escape((string)$high['at']),
        'Low: <b>' . format_score($low['score']) . '</b> at ' . html_escape((string)$low['at']),
        'Oldest in window: ' . format_score($oldest['score'])
            . ' at ' . html_escape((string)$oldest['recorded_at']),
        '',
        '<b>Recent snapshots</b>'
    ];

    foreach ($recent as $row) {
        $lines[] = snapshot_line($row);
    }

    return implode("\n", $lines);
}

function status_report(PDO $pdo): string {
    $db = db_status();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM news')->fetchColumn();

    $since = date('Y-m-d H:i:s', time() - 24 * 3600);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM news WHERE published_at >= ?');
    $stmt->execute([$since]);
    $dayCount = (int)$stmt->fetchColumn();

    $last = $pdo->query("
        SELECT recorded_at, score, label, btc_price, news_count
        FROM sentiment_history
        ORDER BY recorded_at DESC
        LIMIT 1
    ")->fetch();

    $lines = [
        '<b>Collector status</b>',
        'PHP ' . html_escape(PHP_VERSION),
        'Database: ' . ($db['ok'] ? 'SQLite ok' : 'SQLite error')
    ];

    if (!$db['ok'] && isset($db['error'])) {
        $lines[] = html_escape((string)$db['error']);
    }

    foreach (['pdo_sqlite', 'curl', 'simplexml', 'mbstring'] as $extension) {
        $lines[] = $extension . ': ' . (extension_loaded($extension) ? 'ok' : 'MISSING');
    }

    $lines[] = 'Articles stored: ' . $total;
    $lines[] = 'Articles in last 24h: ' . $dayCount;
    $lines[] = 'Feeds configured: ' . count(NEWS_FEEDS);

    $interval = collect_interval_minutes();
    $lines[] = $interval > 0
        ? 'Auto-collect: every ' . $interval . ' min'
        : 'Auto-collect: off (external timer)';

    $lastCollect = last_collect_time();
    if ($lastCollect > 0) {
        $lines[] = 'Last collect: ' . date('Y-m-d H:i:s', $lastCollect);
    }

    if ($last) {
        $lines[] = '';
        $lines[] = 'Last snapshot: ' . html_escape((string)$last['recorded_at']);
        $lines[] = mood_icon((string)$last['label']) . ' '
            . format_score($last['score']) . ' '
            . html_escape((string)$last['label']);
        if ($last['btc_price'] !== null && $last['btc_price'] !== '') {
            $lines[] = 'BTC at snapshot: ' . format_price($last['btc_price']);
        }
    } else {
        $lines[] = '';
        $lines[] = 'No snapshot recorded yet.';
    }

    $lines[] = '';
    $lines[] = 'Server time: ' . date('Y-m-d H:i:s T');

    return implode("\n", $lines);
}

function with_collect_lock(callable $fn): ?array {
    $dir = dirname(DB_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the data directory.');
    }

    $handle = fopen(data_path('collect.lock'), 'c');
    if ($handle === false) {
        throw new RuntimeException('Cannot open the collect lock.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    try {
        return $fn();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function last_collect_time(): int {
    $path = data_path('last_collect.txt');
    if (!is_file($path)) {
        return 0;
    }

    return (int)trim((string)file_get_contents($path));
}

function mark_collected(): void {
    file_put_contents(data_path('last_collect.txt'), (string)time());
}

function collect_news(string $reason): array {
    $result = with_collect_lock(function () {
        $pdo = db();
        $fetch = fetch_and_store_news($pdo);
        $snap = record_sentiment_snapshot($pdo);

        return [
            'busy' => false,
            'stored' => (int)$fetch['stored'],
            'errors' => $fetch['errors'],
            'snapshot' => $snap
        ];
    });

    if ($result === null) {
        log_line('Collect (' . $reason . ') skipped because another collect is running.');
        return ['busy' => true];
    }

    mark_collected();
    $day = $result['snapshot']['twenty_four_hour'];
    log_line(
        'Collect (' . $reason . ') stored ' . $result['stored']
        . ' articles. 24h ' . $day['score'] . ' ' . $day['label'] . '.'
    );

    if ($result['errors']) {
        log_line('Feed warnings: ' . implode(' | ', $result['errors']));
    }

    return $result;
}

function format_collect_result(array $result): string {
    if (!empty($result['busy'])) {
        return 'A collection is already running. Try again in a minute.';
    }

    $snap = $result['snapshot'];
    $day = $snap['twenty_four_hour'];
    $lines = [
        '<b>News collected</b>',
        'New articles stored: ' . (int)$result['stored'],
        '24h sentiment: ' . mood_icon((string)$day['label']) . ' <b>'
            . format_score($day['score']) . ' '
            . html_escape((string)$day['label']) . '</b>',
        'BTC: ' . format_price($snap['btc_price']),
        'Snapshot: ' . html_escape((string)$snap['recorded_at'])
    ];

    if (!empty($result['errors'])) {
        $lines[] = '';
        $lines[] = '<b>Feed warnings</b>';
        foreach (array_slice($result['errors'], 0, 5) as $error) {
            $lines[] = '• ' . html_escape((string)$error);
        }
    }

    return implode("\n", $lines);
}

function refresh_report(): string {
    try {
        return format_collect_result(collect_news('telegram'));
    } catch (Throwable $e) {
        log_line('Telegram refresh failed: ' . $e->getMessage());
        return "Collection failed.\n<code>" . html_escape($e->getMessage()) . "</code>";
    }
}

function maybe_collect(string $reason): void {
    $interval = collect_interval_minutes();
    if ($interval === 0) {
        return;
    }

    $last = last_collect_time();
    if ((time() - $last) < ($interval * 60)) {
        return;
    }

    try {
        collect_news($reason);
    } catch (Throwable $e) {
        log_line('Collection failed: ' . $e->getMessage());
        mark_collected();
    }
}

function handle_message(array $message): void {
    if (!empty($message['from']['is_bot'])) {
        return;
    }

    $chatId = $message['chat']['id'] ?? null;
    if ($chatId === null) {
        return;
    }

    $text = trim((string)($message['text'] ?? ''));
    if ($text === '') {
        return;
    }

    [$command, $args] = route_text($text);
    $command = canonical_command($command);
    $allowed = is_allowed($message);

    log_line(
        'chat=' . $chatId
        . ' user=' . ($message['from']['id'] ?? '?')
        . ' cmd=' . $command
        . ' allowed=' . ($allowed ? 'yes' : 'no')
    );

    $open = ['start' => true, 'help' => true, 'whoami' => true];
    if (!$allowed && !isset($open[$command])) {
        send_html($chatId, deny_text($message), false);
        return;
    }

    if (!$allowed && $command !== 'whoami') {
        send_html($chatId, deny_text($message), false);
        return;
    }

    try {
        $pdo = db();

        switch ($command) {
            case 'whoami':
                send_html($chatId, whoami_text($message), $allowed);
                break;

            case 'start':
            case 'help':
                send_html($chatId, help_text(), true);
                break;

            case 'sentiment':
                send_html($chatId, sentiment_report($pdo), true);
                break;

            case 'price':
                send_html($chatId, price_report($pdo), true);
                break;

            case 'news':
                send_html($chatId, news_report($pdo, $args), true);
                break;

            case 'history':
                send_html($chatId, history_report($pdo, $args), true);
                break;

            case 'status':
                send_html($chatId, status_report($pdo), true);
                break;

            case 'refresh':
                send_html($chatId, 'Collecting Bitcoin news…', false);
                send_html($chatId, refresh_report(), true);
                break;

            default:
                send_html($chatId, "Unknown command.\n\n" . help_text(), true);
        }
    } catch (Throwable $e) {
        log_line('Command failed: ' . $e->getMessage());
        send_html(
            $chatId,
            "That query failed.\n<code>" . html_escape($e->getMessage()) . "</code>",
            $allowed
        );
    }
}

function load_offset(): ?int {
    $path = data_path('telegram_offset.txt');
    if (!is_file($path)) {
        return null;
    }

    $raw = trim((string)file_get_contents($path));
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return null;
    }

    return (int)$raw;
}

function save_offset(int $offset): void {
    $path = data_path('telegram_offset.txt');
    $tmp = $path . '.tmp';

    if (file_put_contents($tmp, (string)$offset) === false) {
        throw new RuntimeException('Could not store the Telegram offset.');
    }

    if (!rename($tmp, $path)) {
        if (is_file($path)) {
            unlink($path);
        }
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Could not store the Telegram offset.');
        }
    }
}

function bootstrap_offset(): int {
    $saved = load_offset();
    if ($saved !== null) {
        return $saved;
    }

    $offset = 0;
    do {
        $response = tg('getUpdates', [
            'timeout' => 0,
            'limit' => 100,
            'offset' => $offset
        ], 20);

        if (!($response['ok'] ?? false)) {
            throw new RuntimeException(
                'Could not read Telegram updates: ' . (string)($response['description'] ?? 'unknown')
            );
        }

        $updates = $response['result'] ?? [];
        if (!$updates) {
            break;
        }

        foreach ($updates as $update) {
            $offset = ((int)$update['update_id']) + 1;
        }
    } while (count($updates) === 100);

    save_offset($offset);
    if ($offset > 0) {
        log_line('Skipped Telegram updates that were already waiting.');
    }

    return $offset;
}

function acquire_instance_lock(): void {
    $dir = dirname(DB_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the data directory.');
    }

    $handle = fopen(data_path('telegram_bot.lock'), 'c');
    if ($handle === false) {
        throw new RuntimeException('Cannot open the bot lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        log_line('Another Telegram bot process is already running.');
        exit(1);
    }

    $GLOBALS['telegram_bot_lock'] = $handle;
}

function register_commands(): void {
    $response = tg('setMyCommands', [
        'commands' => [
            ['command' => 'sentiment', 'description' => '1h, 6h and 24h sentiment'],
            ['command' => 'price', 'description' => 'BTC/USDT price'],
            ['command' => 'news', 'description' => 'Latest headlines'],
            ['command' => 'search', 'description' => 'Search stored headlines'],
            ['command' => 'history', 'description' => 'Recent sentiment snapshots'],
            ['command' => 'status', 'description' => 'Collector and database status'],
            ['command' => 'refresh', 'description' => 'Fetch news now'],
            ['command' => 'whoami', 'description' => 'Show your Telegram user id'],
            ['command' => 'help', 'description' => 'Show query help']
        ]
    ], 20);

    if (!($response['ok'] ?? false)) {
        log_line('setMyCommands failed: ' . (string)($response['description'] ?? 'unknown'));
    }
}

function run_check(): void {
    foreach (['pdo_sqlite', 'curl', 'simplexml', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) {
            log_line('Missing PHP extension: ' . $extension);
            exit(1);
        }
    }

    $pdo = db();
    $count = (int)$pdo->query('SELECT COUNT(*) FROM news')->fetchColumn();
    $me = tg('getMe', [], 20);

    if (!($me['ok'] ?? false)) {
        log_line('Telegram getMe failed: ' . (string)($me['description'] ?? 'unknown'));
        exit(1);
    }

    $username = (string)($me['result']['username'] ?? '');
    log_line('Token ok. Bot @' . $username);
    log_line('Database ok. News rows: ' . $count);

    $ids = array_keys(allowed_ids());
    if (!$ids) {
        log_line('No allowed user ids yet. Send /start to the bot, then add your id to TELEGRAM_ALLOWED_USER_IDS.');
    } else {
        log_line('Allowed ids: ' . implode(', ', $ids));
    }

    exit(0);
}

function assert_same($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}\n");
        fwrite(STDERR, '  expected: ' . var_export($expected, true) . "\n");
        fwrite(STDERR, '  actual:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

function run_self_test(): void {
    assert_same(['sentiment', ''], route_text('/sentiment'), 'cmd sentiment');
    assert_same(['sentiment', ''], route_text('/sentiment@MyBot'), 'cmd with bot');
    assert_same(['news', '24 5 bullish'], route_text('/news 24 5 bullish'), 'news args');
    assert_same(['search', 'etf inflow'], route_text('/search etf inflow'), 'search');
    assert_same('news', canonical_command('search'), 'search alias');
    assert_same(['price', ''], route_text('Price'), 'button');
    assert_same(['news', 'bullish'], route_text('Bullish'), 'bullish button');
    assert_same(['news', 'etf inflow'], route_text('etf inflow'), 'plain search');
    assert_same(['news', '24 bullish'], route_text('News 24 bullish'), 'plain news');
    assert_same(['price', ''], route_text('btc'), 'btc alias');
    assert_same(['sentiment', ''], route_text('what is the sentiment'), 'sentiment phrase');
    assert_same(['price', ''], route_text('show the btc price'), 'price phrase');
    assert_same(['history', 'last 48 hours'], route_text('history last 48 hours'), 'history phrase');

    $parsed = parse_news_args('24 5 bullish etf inflow');
    assert_same(24, $parsed['hours'], 'hours');
    assert_same(5, $parsed['limit'], 'limit');
    assert_same('Bullish', $parsed['label'], 'label');
    assert_same('etf inflow', $parsed['term'], 'term');

    $parsed = parse_news_args('');
    assert_same(72, $parsed['hours'], 'default hours');
    assert_same(8, $parsed['limit'], 'default limit');
    assert_same(null, $parsed['label'], 'default label');
    assert_same('', $parsed['term'], 'default term');

    assert_same('\\%', escape_like('%'), 'like percent');
    assert_same('\\_', escape_like('_'), 'like underscore');
    assert_same('\\\\', escape_like('\\'), 'like backslash');

    $chunks = split_message(str_repeat("a\n", 100), 50);
    if (count($chunks) < 2) {
        fwrite(STDERR, "FAIL split count\n");
        exit(1);
    }
    foreach ($chunks as $chunk) {
        if (mb_strlen($chunk) > 50) {
            fwrite(STDERR, "FAIL chunk length\n");
            exit(1);
        }
    }

    assert_same('&lt;&gt;&amp;"', html_escape('<>&"'), 'html escape');
    assert_same('&lt;&gt;&amp;&quot;', html_attr('<>&"'), 'html attr');

    echo "self-test ok\n";
    exit(0);
}

function run_bot(): void {
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }

    bot_token();
    db();
    acquire_instance_lock();

    $interval = collect_interval_minutes();
    log_line(
        $interval > 0
            ? 'BTC sentiment bot started. Auto-collect every ' . $interval . ' min.'
            : 'BTC sentiment bot started. Auto-collect is off.'
    );

    if (!allowed_ids()) {
        log_line('TELEGRAM_ALLOWED_USER_IDS is empty. Data queries stay refused until you add your Telegram user id.');
    }

    $deleted = tg('deleteWebhook', ['drop_pending_updates' => false], 20);
    if (!($deleted['ok'] ?? false)) {
        log_line('deleteWebhook failed: ' . (string)($deleted['description'] ?? 'unknown'));
    }

    register_commands();
    $offset = bootstrap_offset();

    $last = last_collect_time();
    if ($interval > 0 && $last > 0 && (time() - $last) < ($interval * 60)) {
        $wait = (int)ceil((($interval * 60) - (time() - $last)) / 60);
        log_line('Next collection in ' . max(1, $wait) . ' min.');
    }

    while (true) {
        try {
            maybe_collect('schedule');

            $response = tg('getUpdates', [
                'offset' => $offset,
                'timeout' => 50,
                'allowed_updates' => ['message']
            ], 70);

            if (!($response['ok'] ?? false)) {
                $code = (int)($response['error_code'] ?? 0);
                $description = (string)($response['description'] ?? 'unknown error');

                if ($code === 429) {
                    $wait = (int)($response['parameters']['retry_after'] ?? 5);
                    log_line('Rate limited. Sleeping ' . $wait . 's.');
                    sleep(max(1, $wait));
                    continue;
                }

                log_line('getUpdates failed: ' . $code . ' ' . $description);
                sleep(3);
                continue;
            }

            $updates = $response['result'] ?? [];
            if (!is_array($updates)) {
                $updates = [];
            }

            foreach ($updates as $update) {
                $offset = ((int)$update['update_id']) + 1;
                save_offset($offset);

                if (isset($update['message']) && is_array($update['message'])) {
                    try {
                        handle_message($update['message']);
                    } catch (Throwable $e) {
                        log_line('Message handler error: ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            log_line('Bot loop error: ' . $e->getMessage());
            sleep(5);
        }
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run telegram_bot.php from the command line.\n");
    exit(1);
}

if (in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
    echo "Usage: php telegram_bot.php [--check|--self-test]\n";
    echo "  --check      Validate the token, PHP extensions, and SQLite database.\n";
    echo "  --self-test  Run offline parser checks.\n";
    exit(0);
}

if (in_array('--self-test', $argv ?? [], true)) {
    run_self_test();
}

load_env_file(__DIR__ . DIRECTORY_SEPARATOR . 'telegram.env');

if (in_array('--check', $argv ?? [], true)) {
    run_check();
}

run_bot();
?>
