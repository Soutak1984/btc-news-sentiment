#!/usr/bin/env bash
# Run the collector and Telegram bot in the foreground.
# The web dashboard stays off unless SERVE_WEB=1 in telegram.env.
set -euo pipefail

cd "$(dirname "$0")"

if [[ ! -f telegram.env ]]; then
  echo "telegram.env is missing."
  echo "Copy telegram.env.example to telegram.env and set TELEGRAM_BOT_TOKEN."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP CLI was not found."
  echo "Debian/Ubuntu: sudo apt install php-cli php-sqlite3 php-curl php-xml php-mbstring"
  exit 1
fi

WEB_PID=""

cleanup() {
  if [[ -n "${WEB_PID}" ]]; then
    kill "${WEB_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

read_env() {
  local key="$1"
  local default="$2"
  local line value q
  q="'"
  line="$(grep -E "^${key}=" telegram.env | head -n 1 || true)"
  value="${line#*=}"
  value="${value//$'\r'/}"
  value="${value#\"}"
  value="${value%\"}"
  value="${value#$q}"
  value="${value%$q}"
  if [[ -z "${value}" ]]; then
    printf '%s' "${default}"
  else
    printf '%s' "${value}"
  fi
}

php -r 'foreach (["pdo_sqlite","curl","simplexml","mbstring"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Missing PHP extension: $e\n"); exit(1); } }'

mkdir -p data

SERVE_WEB="$(read_env SERVE_WEB 0)"
WEB_HOST="$(read_env WEB_HOST 127.0.0.1)"
WEB_PORT="$(read_env WEB_PORT 8080)"

if [[ "${SERVE_WEB}" == "1" ]]; then
  HOST="${WEB_HOST}"
  PORT="${WEB_PORT}"
  if [[ ! "${HOST}" =~ ^[A-Za-z0-9.:-]+$ || ! "${PORT}" =~ ^[0-9]+$ ]]; then
    echo "WEB_HOST or WEB_PORT is invalid."
    exit 1
  fi
  echo "Dashboard: http://${HOST}:${PORT}/"
  php -S "${HOST}:${PORT}" -t "$PWD" >data/web-server.log 2>&1 &
  WEB_PID=$!
  sleep 0.3
  if ! kill -0 "${WEB_PID}" 2>/dev/null; then
    echo "The dashboard failed to start. See data/web-server.log"
    exit 1
  fi
fi

echo "Telegram bot is running. Press Ctrl+C to stop."
php telegram_bot.php
