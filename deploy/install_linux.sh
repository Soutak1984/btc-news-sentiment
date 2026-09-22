#!/usr/bin/env bash
# Install the Telegram bot as a systemd service.
# User service (no root):
#   bash deploy/install_linux.sh
# Boot service:
#   sudo bash deploy/install_linux.sh --system
# Optional:
#   --timer   also collect every 15 minutes outside the bot
#   --web     also serve the dashboard on WEB_HOST:WEB_PORT
#   --user NAME  account for --system (default: sudo user, else directory owner)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODE="user"
ENABLE_TIMER=0
ENABLE_WEB=0
SERVICE_USER=""

usage() {
  sed -n '2,12p' "$0"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --system)
      MODE="system"
      ;;
    --timer)
      ENABLE_TIMER=1
      ;;
    --web)
      ENABLE_WEB=1
      ;;
    --user)
      SERVICE_USER="${2:-}"
      if [[ -z "${SERVICE_USER}" ]]; then
        echo "--user needs an account name."
        exit 1
      fi
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
  shift
done

if ! command -v php >/dev/null 2>&1; then
  echo "PHP CLI was not found."
  echo "Debian/Ubuntu: sudo apt update && sudo apt install -y php-cli php-sqlite3 php-curl php-xml php-mbstring"
  exit 1
fi

PHP_BIN="$(command -v php)"

if ! php -r 'foreach (["pdo_sqlite","curl","simplexml","mbstring"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Missing PHP extension: $e\n"); exit(1); } }'; then
  echo "Install the missing PHP extensions, then run this script again."
  exit 1
fi

mkdir -p "${ROOT}/data"

if [[ ! -f "${ROOT}/telegram.env" ]]; then
  umask 077
  cp "${ROOT}/telegram.env.example" "${ROOT}/telegram.env"
  chmod 600 "${ROOT}/telegram.env"
  echo "Created ${ROOT}/telegram.env"
  echo "1. Paste TELEGRAM_BOT_TOKEN from @BotFather."
  echo "2. Run: bash start_linux.sh"
  echo "3. In Telegram, open your bot and send /start. Copy the user id from the reply."
  echo "4. Put that id in TELEGRAM_ALLOWED_USER_IDS and restart."
  echo "5. Run: bash deploy/install_linux.sh"
  exit 1
fi

chmod 600 "${ROOT}/telegram.env"

if [[ "${MODE}" == "system" ]]; then
  if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run: sudo bash deploy/install_linux.sh --system"
    exit 1
  fi

  if [[ -z "${SERVICE_USER}" ]]; then
    if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
      SERVICE_USER="${SUDO_USER}"
    else
      SERVICE_USER="$(stat -c '%U' "${ROOT}")"
    fi
  fi

  if [[ "${SERVICE_USER}" == "root" ]]; then
    echo "Refusing to run the bot as root. Pass --user your-account."
    exit 1
  fi

  if ! id "${SERVICE_USER}" >/dev/null 2>&1; then
    echo "User ${SERVICE_USER} does not exist."
    exit 1
  fi

  SERVICE_GROUP="$(id -gn "${SERVICE_USER}")"
  UNIT_DIR="/etc/systemd/system"
  SYSTEMCTL=(systemctl)
else
  if [[ "$(id -u)" -eq 0 ]]; then
    echo "You are root. Use --system, or run this script as your normal user."
    exit 1
  fi

  UNIT_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
  SYSTEMCTL=(systemctl --user)
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "systemctl was not found. Start the bot in the foreground instead:"
  echo "  bash start_linux.sh"
  exit 0
fi

cd "${ROOT}"

fix_ownership() {
  if [[ "${MODE}" == "system" ]]; then
    chown "${SERVICE_USER}:${SERVICE_GROUP}" "${ROOT}/telegram.env"
    chown -R "${SERVICE_USER}:${SERVICE_GROUP}" "${ROOT}/data"
  fi
}

if ! php telegram_bot.php --check; then
  fix_ownership
  echo "Fix telegram.env, then run the installer again."
  exit 1
fi

fix_ownership

read_env() {
  local key="$1"
  local default="$2"
  local line value q
  q="'"
  line="$(grep -E "^${key}=" "${ROOT}/telegram.env" | head -n 1 || true)"
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

WEB_HOST="$(read_env WEB_HOST 127.0.0.1)"
WEB_PORT="$(read_env WEB_PORT 8080)"

if [[ "${ENABLE_WEB}" -eq 1 ]]; then
  if [[ ! "${WEB_HOST}" =~ ^[A-Za-z0-9.:-]+$ || ! "${WEB_PORT}" =~ ^[0-9]+$ ]]; then
    echo "WEB_HOST or WEB_PORT in telegram.env is invalid."
    exit 1
  fi
  if [[ "${WEB_HOST}" == "0.0.0.0" || "${WEB_HOST}" == "::" ]]; then
    echo "Warning: the dashboard has no login. Prefer 127.0.0.1 and an SSH tunnel."
  fi
fi

render_unit() {
  local src="$1"
  local dest="$2"
  RENDER_SRC="${src}" \
  RENDER_DEST="${dest}" \
  RENDER_ROOT="${ROOT}" \
  RENDER_PHP="${PHP_BIN}" \
  RENDER_USER="${SERVICE_USER}" \
  RENDER_GROUP="${SERVICE_GROUP:-}" \
  RENDER_WEB_HOST="${WEB_HOST}" \
  RENDER_WEB_PORT="${WEB_PORT}" \
  php -r 'file_put_contents(getenv("RENDER_DEST"), strtr(file_get_contents(getenv("RENDER_SRC")), ["@ROOT@" => getenv("RENDER_ROOT"), "@PHP@" => getenv("RENDER_PHP"), "@USER@" => (string)getenv("RENDER_USER"), "@GROUP@" => (string)getenv("RENDER_GROUP"), "@WEB_HOST@" => getenv("RENDER_WEB_HOST") ?: "127.0.0.1", "@WEB_PORT@" => getenv("RENDER_WEB_PORT") ?: "8080"]));'
}

mkdir -p "${UNIT_DIR}"

enable_unit() {
  local name="$1"
  "${SYSTEMCTL[@]}" enable "${name}"
  if "${SYSTEMCTL[@]}" is-active --quiet "${name}"; then
    "${SYSTEMCTL[@]}" restart "${name}"
  else
    "${SYSTEMCTL[@]}" start "${name}"
  fi
}

if [[ "${MODE}" == "system" ]]; then
  render_unit "${ROOT}/deploy/systemd/btc-sentiment-bot.system.service" "${UNIT_DIR}/btc-sentiment-bot.service"
else
  render_unit "${ROOT}/deploy/systemd/btc-sentiment-bot.user.service" "${UNIT_DIR}/btc-sentiment-bot.service"
fi

if [[ "${ENABLE_TIMER}" -eq 1 ]]; then
  if [[ "${MODE}" == "system" ]]; then
    render_unit "${ROOT}/deploy/systemd/btc-sentiment-collect.system.service" "${UNIT_DIR}/btc-sentiment-collect.service"
  else
    render_unit "${ROOT}/deploy/systemd/btc-sentiment-collect.user.service" "${UNIT_DIR}/btc-sentiment-collect.service"
  fi
  render_unit "${ROOT}/deploy/systemd/btc-sentiment-collect.timer" "${UNIT_DIR}/btc-sentiment-collect.timer"
fi

if [[ "${ENABLE_WEB}" -eq 1 ]]; then
  if [[ "${MODE}" == "system" ]]; then
    render_unit "${ROOT}/deploy/systemd/btc-sentiment-web.system.service" "${UNIT_DIR}/btc-sentiment-web.service"
  else
    render_unit "${ROOT}/deploy/systemd/btc-sentiment-web.user.service" "${UNIT_DIR}/btc-sentiment-web.service"
  fi
fi

"${SYSTEMCTL[@]}" daemon-reload
enable_unit btc-sentiment-bot.service

if [[ "${ENABLE_TIMER}" -eq 1 ]]; then
  "${SYSTEMCTL[@]}" enable --now btc-sentiment-collect.timer
  echo "Timer enabled. Set COLLECT_INTERVAL_MINUTES=0 in telegram.env if the timer should be the only collector, then restart the bot."
fi

if [[ "${ENABLE_WEB}" -eq 1 ]]; then
  enable_unit btc-sentiment-web.service
  echo "Dashboard: http://${WEB_HOST}:${WEB_PORT}/"
fi

echo "Telegram bot service is installed."
if [[ "${MODE}" == "user" ]]; then
  echo "Keep it running after logout: sudo loginctl enable-linger \"${USER}\""
  echo "Logs: journalctl --user -u btc-sentiment-bot -f"
else
  echo "Logs: journalctl -u btc-sentiment-bot -f"
fi
