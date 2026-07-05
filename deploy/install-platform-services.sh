#!/bin/bash
# Установка systemd-юнитов очереди и pocket-options-proxy (нужен sudo).
# Перед стартом сервисов обновляет bot/.venv — иначе proxy падает без fastapi/loguru и ingest молчит.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BOT_DIR="$ROOT/bot"
VENV_DIR="$BOT_DIR/.venv"
REQ_MAIN="$BOT_DIR/requirements.txt"
REQ_PO="$BOT_DIR/python/PocketOptionAPI-main/requirements.txt"

# Зависимости прокси и ingest (platform-pocket-proxy.service → bot/.venv/bin/python).
if [[ ! -x "$VENV_DIR/bin/python" ]]; then
  echo "[...] Создание venv: $VENV_DIR"
  python3 -m venv "$VENV_DIR"
fi
echo "[...] pip install: $REQ_MAIN"
"$VENV_DIR/bin/pip" install -q --upgrade pip
"$VENV_DIR/bin/pip" install -r "$REQ_MAIN"
if [[ -f "$REQ_PO" ]]; then
  echo "[...] pip install: $REQ_PO"
  "$VENV_DIR/bin/pip" install -r "$REQ_PO"
else
  echo "[!] Нет $REQ_PO — пропуск (PocketOptionAPI)"
fi
echo "[OK] bot/.venv готов"

sudo cp "$ROOT/deploy/platform-queue.service" /etc/systemd/system/
sudo cp "$ROOT/deploy/platform-pocket-proxy.service" /etc/systemd/system/
sudo cp "$ROOT/deploy/logrotate-platform-proxy.conf" /etc/logrotate.d/platform-proxy
# Старый root-юнит (pocket-options-proxy.service) конфликтует с platform-pocket-proxy на :3000.
sudo systemctl disable --now pocket-options-proxy.service 2>/dev/null || true
sudo systemctl daemon-reload
sudo systemctl enable platform-pocket-proxy.service platform-queue.service
# Остановить дубликаты от start-all.sh (nohup), иначе порт 3000 занят не тем процессом.
sudo pkill -f 'pocket-options-proxy.py' 2>/dev/null || true
sleep 2
sudo systemctl restart platform-pocket-proxy.service platform-queue.service
echo "OK: platform-pocket-proxy + platform-queue + logrotate (platform-proxy)"
systemctl is-active platform-pocket-proxy.service platform-queue.service
# Проверка синтаксиса (без ротации): sudo logrotate -d /etc/logrotate.d/platform-proxy
