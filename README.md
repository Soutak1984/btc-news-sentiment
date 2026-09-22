# BTC News Sentiment

SQLite edition. It collects Bitcoin headlines, scores them with a fixed word list, and stores the result in `data/btc_sentiment.sqlite`.

On a Linux server the Telegram bot is the remote interface. No inbound port is required. The server only makes outbound HTTPS calls to Telegram, Google News, and Binance.

The sentiment score is a rule-based news indicator. It is not a standalone trading signal.

## Linux server and Telegram

Requirements:

- PHP 8+ CLI
- PHP extensions: `pdo_sqlite`, `curl`, `simplexml`, `mbstring`

Debian/Ubuntu:

```bash
sudo apt update
sudo apt install -y php-cli php-sqlite3 php-curl php-xml php-mbstring
```

1. Copy this folder to the server, for example `/opt/btc-sentiment`.
2. Create a bot with [@BotFather](https://t.me/BotFather) and copy the token.
3. Create the env file and paste the token:

```bash
cp telegram.env.example telegram.env
chmod 600 telegram.env
```

4. Check the token:

```bash
php telegram_bot.php --check
```

5. Start it once in the foreground:

```bash
bash start_linux.sh
```

6. Open the bot in Telegram and send `/start`. The reply includes your numeric user id.
7. Put that id in `telegram.env`:

```text
TELEGRAM_ALLOWED_USER_IDS=123456789
```

8. Stop the foreground process with Ctrl+C, then install the service:

```bash
bash deploy/install_linux.sh
```

The installer enables a user systemd service. After you log out it keeps running only if lingering is on:

```bash
sudo loginctl enable-linger "$USER"
```

Follow the log:

```bash
journalctl --user -u btc-sentiment-bot -f
```

To install a boot service instead, from your own account:

```bash
sudo bash deploy/install_linux.sh --system
journalctl -u btc-sentiment-bot -f
```

The bot refuses data queries until `TELEGRAM_ALLOWED_USER_IDS` contains your user id. `/start` and `/whoami` still reply, so you can read the id. A group is allowed only when its chat id (a negative number) is listed too.

The bot collects news on startup and then every 15 minutes. Change `COLLECT_INTERVAL_MINUTES` in `telegram.env`. `0` turns collection inside the bot off.

Optional extra collector, every 15 minutes, separate from the bot:

```bash
bash deploy/install_linux.sh --timer
```

If you enable that timer, set `COLLECT_INTERVAL_MINUTES=0` so the same feeds are not fetched twice.

## What you can ask in Telegram

Buttons are shown under the message box after `/start`.

| Ask | Result |
| --- | --- |
| `Sentiment` or `/sentiment` | 1h, 6h, and 24h scores, BTC price, and change since the last snapshot |
| `Price` or `/price` | Live BTC/USDT ticker |
| `News` or `/news` | Latest stored headlines |
| `/news 24 5 bullish` | Last 24 hours, 5 articles, bullish only |
| `Bullish` or `Bearish` | Filtered headlines |
| `etf inflow` or `/search etf inflow` | Search stored titles, keywords, and descriptions |
| `History 48` or `/history 48` | Snapshots from that many hours |
| `Status` or `/status` | PHP extensions, article counts, last snapshot |
| `Refresh` or `/refresh` | Fetch the RSS feeds now and store a snapshot |
| `/whoami` | Your Telegram user id and this chat id |

The first number after `News` is the lookback in hours (1–168). The second number is how many headlines to return (1–12). Words `bullish`, `bearish`, and `neutral` filter the list. Any other words are the search text.

In a group, BotFather privacy mode hides plain text from the bot. Use slash commands there, or turn privacy mode off.

## Local dashboard

The one-click launcher is for a desktop. It binds to `127.0.0.1` and opens a browser. It does not start Telegram.

Windows: double-click `start_windows.bat`.

macOS:

```bash
bash start_mac.sh
```

Linux desktop:

```bash
python3 run.py
```

Dashboard URL: `http://127.0.0.1:8080`. If 8080 is busy, the launcher picks the next free port.

To serve that same dashboard on the Linux server, set this in `telegram.env` and restart:

```text
SERVE_WEB=1
WEB_HOST=127.0.0.1
WEB_PORT=8080
```

Or install it with the service:

```bash
bash deploy/install_linux.sh --web
```

The built-in PHP server has no login. Leave it on `127.0.0.1` and open it through an SSH tunnel:

```bash
ssh -L 8080:127.0.0.1:8080 user@your-server
```

Then browse to `http://127.0.0.1:8080/` on your own machine.

`bash start_linux.sh` also honors `SERVE_WEB=1` while the bot runs in the foreground.

## Database

SQLite is created automatically at `data/btc_sentiment.sqlite`. There is no database username or password.

Useful API pages when the dashboard is running:

```text
/api/status.php
/api/refresh.php
/api/news.php
/api/sentiment.php
/api/history.php
```

## News sources

Google News RSS searches, configured in `config.php`:

- Bitcoin
- Bitcoin ETF
- Bitcoin regulation
- Bitcoin institutional
- Bitcoin whale

Lookback is 72 hours. Each feed keeps at most 40 items.

## Files

| File | Role |
| --- | --- |
| `telegram_bot.php` | Long-polling Telegram bot and query interface |
| `telegram.env` | Bot token and allowed user ids (not committed) |
| `start_linux.sh` | Foreground bot process for a server |
| `deploy/install_linux.sh` | systemd install |
| `cron.php` | One collection plus one snapshot, used by the optional timer |
| `run.py` | Local dashboard launcher |

## Troubleshooting

`php telegram_bot.php --check` confirms the token, the PHP extensions, and the database.

If the bot does not answer a data command, send `/whoami` and compare the user id with `TELEGRAM_ALLOWED_USER_IDS`. Restart after editing `telegram.env`:

```bash
systemctl --user restart btc-sentiment-bot
```

A rejected token stops the service on purpose (`exit 78`) so systemd does not restart in a loop. Fix the token and start it again.

Collection needs outbound HTTPS. The machine does not need a public web port for Telegram.
