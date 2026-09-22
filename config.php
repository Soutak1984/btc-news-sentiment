<?php
// SQLite edition: no MySQL configuration is required.
define('APP_TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set(APP_TIMEZONE);

define('DB_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'btc_sentiment.sqlite');

define('NEWS_LOOKBACK_HOURS', 72);
define('MAX_ITEMS_PER_FEED', 40);

define('BTC_PRICE_URL', 'https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT');

define('NEWS_FEEDS', [
    'Google News Bitcoin' =>
        'https://news.google.com/rss/search?q=Bitcoin&hl=en-US&gl=US&ceid=US:en',
    'Google News BTC ETF' =>
        'https://news.google.com/rss/search?q=Bitcoin+ETF&hl=en-US&gl=US&ceid=US:en',
    'Google News Bitcoin Regulation' =>
        'https://news.google.com/rss/search?q=Bitcoin+regulation&hl=en-US&gl=US&ceid=US:en',
    'Google News Bitcoin Institutional' =>
        'https://news.google.com/rss/search?q=Bitcoin+institutional&hl=en-US&gl=US&ceid=US:en',
    'Google News Bitcoin Whale' =>
        'https://news.google.com/rss/search?q=Bitcoin+whale&hl=en-US&gl=US&ceid=US:en'
]);
?>
