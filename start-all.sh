#!/bin/bash
# Запуск всей платформы: MySQL, Nginx, PHP-FPM, очередь; опционально — прокси свечей (Python/WebSocket)
# Запуск: ./start-all.sh
# С прокси:  ./start-all.sh --with-proxy

set -e
WITH_PROXY=false
[[ "$1" == "--with-proxy" ]] && WITH_PROXY=true

cd "$(dirname "$0")"

echo "=== Платформа: полный запуск ==="

# 1. MySQL
if systemctl is-active --quiet mysql 2>/dev/null; then
  echo "[OK] MySQL уже запущен"
else
  echo "[...] Запуск MySQL..."
  sudo systemctl start mysql 2>/dev/null || sudo systemctl start mariadb 2>/dev/null || true
  echo "[OK] MySQL"
fi

# 2. PHP-FPM
echo "[...] Запуск PHP-FPM..."
sudo systemctl start php8.3-fpm
echo "[OK] PHP-FPM"

# 3. Nginx
echo "[...] Запуск Nginx..."
sudo systemctl start nginx
echo "[OK] Nginx"

# 4. Очередь (в фоне)
if pgrep -f "artisan queue:work" >/dev/null 2>&1; then
  echo "[OK] Очередь уже запущена"
else
  echo "[...] Запуск очереди в фоне..."
  mkdir -p storage/app storage/logs
  nohup php artisan queue:work --sleep=3 --tries=3 --max-time=3600 >> storage/logs/queue.log 2>&1 &
  echo $! > storage/app/queue.pid 2>/dev/null || true
  echo "[OK] Очередь (PID: $!)"
fi

# 5. Прокси свечей (Python, WebSocket → Pocket Options), если задан --with-proxy
if $WITH_PROXY; then
  PROXY_DIR="/root/bot"
  if pgrep -f "pocket-options-proxy" >/dev/null 2>&1; then
    echo "[OK] Прокси свечей уже запущен"
  elif [[ -f "$PROXY_DIR/pocket-options-proxy.py" ]]; then
    echo "[...] Запуск прокси свечей (порт 3000) в фоне..."
    mkdir -p storage/logs
    PROXY_PY="$PROXY_DIR/.venv/bin/python3"
    [[ -x "$PROXY_PY" ]] || PROXY_PY="python3"
    (cd "$PROXY_DIR" && nohup $PROXY_PY pocket-options-proxy.py >> /root/platform/storage/logs/proxy.log 2>&1 &)
    echo "[OK] Прокси (логи: storage/logs/proxy.log)"
  else
    echo "[--] Прокси не найден в $PROXY_DIR, пропуск"
  fi
fi

echo ""
echo "Платформа запущена."
echo "  Сайт:    http://bot13123.online:8080  (или http://localhost:8080)"
echo "  Очередь: в фоне (логи: storage/logs/queue.log)"
$WITH_PROXY && echo "  Прокси:  http://127.0.0.1:3000 (свечи/активы через WebSocket)"
echo ""
echo "Остановить очередь: kill \$(cat storage/app/queue.pid 2>/dev/null) 2>/dev/null"
echo ""
if ! $WITH_PROXY; then
  echo "Запуск с прокси свечей: ./start-all.sh --with-proxy"
  echo "  (прокси = Python, чтение свечей по WebSocket Pocket Options, см. PROXY.md)"
fi
echo ""
