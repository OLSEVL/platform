#!/bin/bash
# Запуск всей платформы: MySQL, Nginx, PHP-FPM, очередь; опционально — прокси свечей (Python/WebSocket)
# Запуск: ./start-all.sh
# С прокси:  ./start-all.sh --with-proxy

set -e
WITH_PROXY=false
RESTART_PROXY=false
for arg in "$@"; do
  case "$arg" in
    --with-proxy) WITH_PROXY=true ;;
    --restart-proxy) RESTART_PROXY=true; WITH_PROXY=true ;;
  esac
done

cd "$(dirname "$0")"

proxy_has_place_order() {
  curl -sf http://127.0.0.1:3000/ 2>/dev/null | grep -q 'place-order'
}

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
# Важно: только ОДИН процесс на :3000. Если включён systemd — never nohup (иначе EADDRINUSE и crash-loop).
if $WITH_PROXY; then
  PROXY_DIR="/home/o/platform/bot"
  if [[ ! -f "$PROXY_DIR/pocket-options-proxy.py" ]]; then
    echo "[--] Прокси не найден в $PROXY_DIR, пропуск"
  elif systemctl is-enabled platform-pocket-proxy.service >/dev/null 2>&1; then
    if $RESTART_PROXY; then
      echo "[...] Перезапуск platform-pocket-proxy (systemd, --restart-proxy)..."
      sudo systemctl restart platform-pocket-proxy.service 2>/dev/null || systemctl --user restart platform-pocket-proxy.service 2>/dev/null || true
    elif systemctl is-active --quiet platform-pocket-proxy.service 2>/dev/null; then
      if proxy_has_place_order; then
        echo "[OK] platform-pocket-proxy.service уже active (systemd, place-order есть)"
      else
        echo "[!] Прокси без place-order — перезапуск systemd..."
        sudo systemctl restart platform-pocket-proxy.service 2>/dev/null || systemctl --user restart platform-pocket-proxy.service 2>/dev/null || true
      fi
    else
      echo "[...] Запуск platform-pocket-proxy (systemd)..."
      sudo systemctl start platform-pocket-proxy.service 2>/dev/null || systemctl --user start platform-pocket-proxy.service 2>/dev/null || true
    fi
    sleep 2
    echo "[OK] Прокси через systemd (логи: storage/logs/proxy.log, status: systemctl status platform-pocket-proxy)"
  else
    NEED_PROXY_START=false
    if $RESTART_PROXY && pgrep -f "pocket-options-proxy.py" >/dev/null 2>&1; then
      echo "[...] Перезапуск прокси (--restart-proxy, nohup)..."
      pkill -f "pocket-options-proxy.py" 2>/dev/null || sudo pkill -f "pocket-options-proxy.py" 2>/dev/null || true
      sleep 2
      NEED_PROXY_START=true
    elif pgrep -f "pocket-options-proxy.py" >/dev/null 2>&1; then
      if proxy_has_place_order; then
        echo "[OK] Прокси свечей уже запущен (nohup, есть /api/place-order)"
      else
        echo "[!] Прокси устарел (нет place-order), перезапуск..."
        pkill -f "pocket-options-proxy.py" 2>/dev/null || sudo pkill -f "pocket-options-proxy.py" 2>/dev/null || true
        sleep 2
        NEED_PROXY_START=true
      fi
    else
      NEED_PROXY_START=true
    fi
    if $NEED_PROXY_START; then
      echo "[...] Запуск прокси свечей (порт 3000, nohup)..."
      mkdir -p storage/logs
      PROXY_PY="$PROXY_DIR/.venv/bin/python3"
      [[ -x "$PROXY_PY" ]] || PROXY_PY="python3"
      (cd "$PROXY_DIR" && nohup $PROXY_PY pocket-options-proxy.py >> /home/o/platform/storage/logs/proxy.log 2>&1 &)
      sleep 2
      echo "[OK] Прокси (логи: storage/logs/proxy.log)"
    fi
  fi
fi

ROOT_DIR="$(pwd)"
echo ""
echo "Платформа запущена."
echo "  Сайт:    http://bot13123.online:8080  (или http://localhost:8080)"
echo "  Очередь: в фоне (логи: storage/logs/queue.log)"
$WITH_PROXY && echo "  Прокси:  http://127.0.0.1:3000 (свечи/активы через WebSocket)"
echo ""
echo "Планировщик Laravel (боты, свечи): без него bots:scan не пойдёт по времени."
echo "  Cron (рекомендуется на сервере):"
echo "    * * * * * cd ${ROOT_DIR} && php artisan schedule:run >> ${ROOT_DIR}/storage/logs/scheduler.log 2>&1"
echo "  Или в отдельном терминале: php artisan schedule:work"
echo "  Пример файла: crontab.example"
echo ""
echo "Остановить очередь: kill \$(cat storage/app/queue.pid 2>/dev/null) 2>/dev/null"
echo ""
if ! $WITH_PROXY; then
  echo "Запуск с прокси свечей: ./start-all.sh --with-proxy"
  echo "  Перезапуск прокси (после обновления кода): ./start-all.sh --restart-proxy"
  echo "  (прокси = Python, чтение свечей по WebSocket Pocket Options, см. PROXY.md)"
fi
echo ""
