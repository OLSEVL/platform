# Platform deployment guide

End-to-end guide for installing and running the Pocket Options trading bots platform (Laravel + Python candle proxy + Redis chart live hub) on a Linux host.

Default install path in this repository: `/home/o/platform`.  
Default public URL (example): `http://bot123456789.space:8080`.  
Adjust paths, `User=` / `Group=` in systemd units, and `APP_URL` for your host.

---

## 1. Architecture (what must be running)

| Component | Role | Default port / path |
|-----------|------|---------------------|
| **MySQL / MariaDB** | App DB (bots, candles M1, instruments, queues) | `3306` |
| **Redis** | Current candle cache, `chart:s5` pub/sub, live chart | `6379` |
| **PHP 8.3-FPM** | Laravel HTTP (`www` pool) + dedicated **ingest** pool | sockets under `/run/php/` |
| **Nginx** | Reverse proxy to PHP-FPM and WebSocket hubs | **8080** (or 80) |
| **platform-pocket-proxy** | FastAPI proxy: PO WebSocket → ingest + place-order | **3000** |
| **platform-chart-live** | Redis `chart:s5` → browser WS `/chart-live` | **8765** |
| **platform-queue** | `php artisan queue:work` (backtest, mirror, default, telegram) | — |
| **Cron / schedule** | `bots:scan`, `bots:settle`, `candles:prune-old`, … | every minute |
| **platform-proxy-probe** *(optional)* | Auto-probe dead HTTP proxies for ingest | — |
| **platform-queue-backtest@N** *(optional)* | Extra parallel backtest workers | — |
| **platform-agentmemory** *(optional)* | Local agent memory HTTP service | **3111** |

Data flow (simplified):

```
Pocket Options WS  →  pocket-options-proxy.py (:3000)
                         ├─ POST /api/ingest-candles          → MySQL (closed M1)
                         └─ Redis current_candle + PUBLISH chart:s5
                                                              ↓
                                              chart_live_hub.py (:8765)
                                                              ↓
                                    Nginx /chart-live  →  browser chart
```

Laravel never opens Pocket Options WebSockets itself. Set `POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000`.

---

## 2. Requirements

- **OS:** Ubuntu/Debian (systemd), Linux x86_64
- **PHP 8.2+** (production units use **8.3**): `php8.3-fpm`, `php8.3-cli`, extensions: `mysql`, `redis`/`phpredis`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`
- **Composer** 2.x
- **Node.js** + npm (Vite frontend build)
- **MySQL 8** or **MariaDB**
- **Redis** (`redis-server`)
- **Nginx**
- **Python 3.10+** with `venv` (proxy + chart hub)
- **sudo** for installing packages and systemd units

Optional: outbound HTTP(S) proxies (Webshare etc.) configured in the app for PO connections — exhausted bandwidth (`402` / `bandwidthlimit`) stops candle ingest until the pool is topped up.

---

## 3. First-time application install

```bash
cd /home/o/platform

# Dependencies
composer install --no-dev --optimize-autoloader   # or without --no-dev on a dev box
npm ci && npm run build

# Environment
cp .env.example .env
php artisan key:generate

# Create DB (example credentials match .env.example)
sudo mysql -e "
  CREATE DATABASE IF NOT EXISTS platform;
  CREATE USER IF NOT EXISTS 'platform'@'localhost' IDENTIFIED BY 'platform';
  GRANT ALL ON platform.* TO 'platform'@'localhost';
  FLUSH PRIVILEGES;
"

# Edit .env at least:
#   APP_URL=http://YOUR_HOST:8080
#   APP_ENV=production
#   APP_DEBUG=false
#   DB_* , REDIS_* , POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000

php artisan migrate --force
php artisan storage:link   # if public storage is used

# Writable dirs for the web user / app user
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
# If systemd services run as user `o`, also allow that user to write logs:
sudo usermod -aG www-data o   # optional; or chgrp to a shared group
```

Ensure Redis is running:

```bash
sudo systemctl enable --now redis-server
redis-cli ping   # PONG
```

---

## 4. Nginx + PHP-FPM

### 4.1 Site config

```bash
sudo cp /home/o/platform/nginx-platform.conf /etc/nginx/sites-available/platform
sudo ln -sf /etc/nginx/sites-available/platform /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default   # optional
sudo nginx -t && sudo systemctl reload nginx
```

The shipped config:

- Listens on **8080** by default (uncomment `listen 80` if free).
- `root` → `/home/o/platform/public`
- Proxies **`/chart-live`** and **`/extension-live`** to `127.0.0.1:8765`
- Routes heavy ingest endpoints to a **separate FPM pool** (see below)

Set `APP_URL` to include the port when using 8080, e.g. `APP_URL=http://bot123456789.space:8080`.

DNS: point an A record at the host WAN IP; forward TCP 8080 (or 80) on the router/firewall if needed.

### 4.2 Dedicated ingest PHP-FPM pool (required for live charts under load)

Without a separate pool, `pm.max_children` on the default `www` pool gets stuck on `POST /api/ingest-*` and the UI returns HTTP 499 / timeouts.

```bash
sudo cp /home/o/platform/deploy/php8.3-fpm-ingest.conf /etc/php/8.3/fpm/pool.d/ingest.conf
sudo systemctl restart php8.3-fpm
# Socket should exist:
ls -l /run/php/php8.3-fpm-ingest.sock
```

Tune `pm.max_children` in that file if you raise ingest frequency (many instruments × high Hz). Prefer Redis-direct current-candle writes from the Python proxy so PHP is not on the hot path for every tick — see [PROXY_INGEST.md](./PROXY_INGEST.md).

---

## 5. Python venv (proxy + chart hub)

```bash
cd /home/o/platform/bot
python3 -m venv .venv
.venv/bin/pip install -U pip
.venv/bin/pip install -r requirements.txt
.venv/bin/pip install -r python/PocketOptionAPI-main/requirements.txt
```

Configure `bot/proxy.env` (loaded by `platform-pocket-proxy.service`):

```env
PLATFORM_URL=http://127.0.0.1:8080
INGEST_MAX_CONNECTIONS=100
INSTRUMENTS_REFRESH_SEC=300
INGEST_CURRENT_CANDLE_ENABLE=1
INGEST_CURRENT_CANDLE_TTL_SEC=180
INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC=0.25
INGEST_LOOP_INTERVAL_SEC=0.25
INGEST_CURRENT_CANDLE_REDIS_DIRECT=1
```

`PLATFORM_URL` must reach Laravel on the same host (nginx :8080 or `artisan serve` :8000).

---

## 6. Systemd services (production)

### 6.1 Core trio (proxy + queue + chart live)

Installer (creates/updates venv, installs units, enables, restarts):

```bash
cd /home/o/platform
./deploy/install-platform-services.sh
```

Units installed:

| Unit | Binary / command | Logs |
|------|------------------|------|
| `platform-pocket-proxy.service` | `bot/.venv/bin/python bot/pocket-options-proxy.py` | `storage/logs/proxy.log` |
| `platform-queue.service` | `php artisan queue:work database --queue=backtest,mirror,default,telegram-analyst …` | `storage/logs/queue.log` |
| `platform-chart-live.service` | `bot/.venv/bin/python bot/chart_live_hub.py` | `storage/logs/chart-live-hub.log` |

Also installs logrotate: `/etc/logrotate.d/platform-proxy`.

Useful commands:

```bash
sudo systemctl status platform-pocket-proxy platform-queue platform-chart-live
sudo journalctl -u platform-pocket-proxy -f
sudo systemctl restart platform-pocket-proxy
```

**Conflict note:** an older unit `pocket-options-proxy.service` or a leftover `nohup` proxy will hold port **3000**. The installer disables the old unit and kills stray processes. Prefer only `platform-pocket-proxy.service`.

Edit `User=` / `Group=` / absolute paths in `deploy/*.service` before copying if your home directory or username differs from `o`.

### 6.2 Scheduler (required for live bots)

Bots depend on Laravel’s scheduler (`bots:scan`, `bots:settle`, candle prune, etc.).

**Production — cron** (one line; see also `crontab.example`):

```bash
crontab -e
```

```
* * * * * cd /home/o/platform && /usr/bin/php artisan schedule:run >> /home/o/platform/storage/logs/scheduler.log 2>&1
```

**Dev alternative:** `php artisan schedule:work` in a dedicated terminal.

Verify: `php artisan schedule:list`.

### 6.3 Extra backtest workers (optional)

Template unit: `deploy/platform-queue-backtest@.service`.

```bash
sudo cp deploy/platform-queue-backtest@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now platform-queue-backtest@1 platform-queue-backtest@2 platform-queue-backtest@3
```

Or ad-hoc: `./deploy/start-backtest-workers.sh` (nohup workers writing to `/tmp/queue-bt.log`).

Ensure `DB_QUEUE_RETRY_AFTER` in `.env` / `config/queue.php` is **greater than** the worker `--timeout` (3600), or long backtests get stolen by other workers.

### 6.4 Dead-proxy probe loop (optional)

```bash
./deploy/install-proxy-probe-service.sh
# Logs: sudo journalctl -u platform-proxy-probe -f
```

Runs `php artisan proxies:probe-loop`. Requires MySQL and a reachable instruments API from the ingest proxy.

### 6.5 Agent memory (optional, AI tooling)

```bash
./deploy/install-agentmemory-service.sh
curl -s http://localhost:3111/agentmemory/health
```

---

## 7. Quick start without systemd

For a single-machine bring-up (MySQL, PHP-FPM, Nginx, queue worker; optional proxy):

```bash
cd /home/o/platform
./start-all.sh              # core stack
./start-all.sh --with-proxy # also start Python candle proxy
```

Site: `http://bot123456789.space:8080` (or host IP :8080).

Development HTTP only:

```bash
./start.sh 8000   # php artisan serve --host=0.0.0.0 --port=8000
```

Do **not** mix long-lived `nohup` proxy with `platform-pocket-proxy.service` on the same port.

---

## 8. Post-deploy checklist

1. **HTTP:** `curl -sI http://127.0.0.1:8080/` → 200/302  
2. **Proxy health:** `curl -s http://127.0.0.1:3000/health`  
3. **Instruments API:** `curl -s http://127.0.0.1:8080/api/proxy/instruments` → JSON array  
4. **Redis:** `redis-cli ping`  
5. **Services active:**
   ```bash
   systemctl is-active platform-pocket-proxy platform-queue platform-chart-live php8.3-fpm nginx mysql redis-server
   ```
6. **UI:** register a user, add **Instruments** (`is_active`, `session_token`, `uid`), open Dashboard chart  
7. **Ingest logs:** `tail -f storage/logs/proxy.log` — look for `[INGEST] started worker` / `sent N candles`  
8. **Scheduler:** entries in `storage/logs/scheduler.log` once per minute  

If candles stop updating: check outbound proxy bandwidth, SSID/auth on instruments, and proxy journal — see [PROXY_INGEST.md](./PROXY_INGEST.md) troubleshooting.

---

## 9. Frontend / Chrome extension

After UI or chart JS changes:

```bash
cd /home/o/platform
npm run build
# hard-refresh the browser; for the extension, reload the unpacked extension in chrome://extensions
```

Extension sources live under `chrome-extension/`. Point the extension at your `APP_URL` (and WS paths `/extension-live`) in its options.

---

## 10. Log locations

| Source | Path / command |
|--------|----------------|
| Laravel | `storage/logs/laravel.log` |
| Scheduler | `storage/logs/scheduler.log` |
| Queue | `storage/logs/queue.log` |
| Proxy | `storage/logs/proxy.log` |
| Chart live hub | `storage/logs/chart-live-hub.log` |
| Nginx | `/var/log/nginx/error.log`, `access.log` |
| PHP-FPM | `journalctl -u php8.3-fpm` |
| Systemd units | `journalctl -u <unit> -f` |

---

## 11. Operations cheat sheet

```bash
# Restart trading stack
sudo systemctl restart platform-pocket-proxy platform-queue platform-chart-live

# After .env or code deploy
cd /home/o/platform
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm ci && npm run build
sudo systemctl reload php8.3-fpm nginx
sudo systemctl restart platform-queue platform-pocket-proxy platform-chart-live

# Prune old M1 candles manually (also hourly via schedule)
php artisan candles:prune-old
```

---

## 12. Related docs

- [../README.md](../README.md) — project overview and API sketch  
- [../PROXY.md](../PROXY.md) — Python proxy HTTP API overview  
- [PROXY_INGEST.md](./PROXY_INGEST.md) — continuous ingest, latency tuning, troubleshooting  
- [PLAN_RELEASE.md](./PLAN_RELEASE.md) — historical product/data plan (instruments, M1 storage)  
- `crontab.example` — scheduler cron line  
- `deploy/` — systemd units and install scripts  

---

## 13. Security notes (production)

- Set `APP_DEBUG=false`, strong `APP_KEY`, unique DB password.  
- Do not commit `.env`, SSIDs, or proxy credentials.  
- Restrict `/api/proxy/*` and ingest endpoints to localhost / private network where possible (nginx `allow`/`deny` or firewall).  
- Keep `storage/` and `bootstrap/cache` non-world-writable except for the service users that need them.  
- Prefer HTTPS (terminate TLS on nginx or a front proxy) and set `APP_URL` to `https://…`.  
