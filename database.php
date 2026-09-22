<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dir = dirname(DB_FILE);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        $pdo->exec("PRAGMA journal_mode=WAL");
        $pdo->exec("PRAGMA busy_timeout=5000");

        initialize_database($pdo);
    }

    return $pdo;
}

function initialize_database(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS news (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            source TEXT,
            url TEXT NOT NULL,
            url_hash TEXT NOT NULL UNIQUE,
            published_at TEXT NOT NULL,
            sentiment_score REAL NOT NULL DEFAULT 0,
            sentiment_label TEXT NOT NULL DEFAULT 'Neutral',
            importance REAL NOT NULL DEFAULT 1,
            keywords TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_news_published
        ON news(published_at)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_news_label
        ON news(sentiment_label)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sentiment_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recorded_at TEXT NOT NULL,
            score REAL NOT NULL,
            label TEXT NOT NULL,
            bullish_count INTEGER NOT NULL DEFAULT 0,
            bearish_count INTEGER NOT NULL DEFAULT 0,
            neutral_count INTEGER NOT NULL DEFAULT 0,
            news_count INTEGER NOT NULL DEFAULT 0,
            btc_price REAL,
            sentiment_1h REAL,
            sentiment_6h REAL,
            sentiment_24h REAL
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_history_recorded
        ON sentiment_history(recorded_at)
    ");
}

function db_status(): array {
    try {
        $pdo = db();
        return [
            'ok' => true,
            'database' => 'SQLite',
            'file' => DB_FILE,
            'file_exists' => file_exists(DB_FILE),
            'pdo_sqlite' => true
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'database' => 'SQLite',
            'error' => $e->getMessage(),
            'pdo_sqlite' => extension_loaded('pdo_sqlite')
        ];
    }
}
?>
