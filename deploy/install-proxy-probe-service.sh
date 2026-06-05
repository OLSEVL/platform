#!/usr/bin/env bash
# Установка systemd unit для постоянного proxies:probe-loop
set -euo pipefail
UNIT=platform-proxy-probe.service
SRC="/home/o/platform/deploy/${UNIT}"
DEST="/etc/systemd/system/${UNIT}"

if [[ ! -f "$SRC" ]]; then
  echo "Missing ${SRC}" >&2
  exit 1
fi

sudo cp "$SRC" "$DEST"
sudo systemctl daemon-reload
sudo systemctl enable "$UNIT"
sudo systemctl restart "$UNIT"
sudo systemctl status "$UNIT" --no-pager

echo ""
echo "Logs: sudo journalctl -u ${UNIT} -f"
