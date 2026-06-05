#!/bin/bash
# Установка systemd-юнита agentmemory для автозапуска памяти агента (нужен sudo).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
UNIT=platform-agentmemory.service

sudo cp "$ROOT/deploy/${UNIT}" /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable "$UNIT"
# Остановить ручной nohup/npx, иначе порт 3111 занят не тем процессом.
pkill -f '@agentmemory/agentmemory' 2>/dev/null || true
sleep 2
sudo systemctl restart "$UNIT"
echo "OK: ${UNIT}"
systemctl is-active "$UNIT"
echo ""
echo "Health: curl -s http://localhost:3111/agentmemory/health"
echo "Logs:   tail -f ${ROOT}/storage/logs/agentmemory.log"
echo "        sudo journalctl -u ${UNIT} -f"
