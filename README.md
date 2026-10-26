# Pocket Options bots platform (Laravel)

Trading bots, live charts, candle ingest, and Pocket Options order mirroring — Laravel app plus a Python WebSocket proxy.

**Full production install:** see **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** (English).

---

## Requirements

- PHP 8.2+ (8.3 recommended) with FPM, Composer, Node.js/npm  
- MySQL or MariaDB  
- Redis  
- Nginx (production)  
- Python 3.10+ venv for the candle / order proxy (`bot/`)  

---

## Quick install

```bash
cd /home/o/platform   # or your clone path
cp .env.example .env
php artisan key:generate
# Create DB user/database, set DB_* and POCKET_OPTIONS_PROXY_URL in .env
composer install
npm ci && npm run build
php artisan migrate
```

Example DB bootstrap:

```bash
sudo mysql -e "CREATE DATABASE platform; CREATE USER 'platform'@'localhost' IDENTIFIED BY 'platform'; GRANT ALL ON platform.* TO 'platform'@'localhost'; FLUSH PRIVILEGES;"
```

---

## Running the stack

### Development

1. Set **APP_URL** in `.env` (e.g. `http://127.0.0.1:8000` or your LAN IP).  
2. Start the candle proxy: `cd bot && .venv/bin/python pocket-options-proxy.py`  
3. Queue worker: `php artisan queue:work`  
4. Scheduler (required for bots): either cron (`crontab.example`) or `php artisan schedule:work`  
5. HTTP: `./start.sh` or `php artisan serve --host=0.0.0.0 --port=8000`  

### One-command local/prod-like bring-up

```bash
./start-all.sh              # MySQL, PHP-FPM, Nginx, queue
./start-all.sh --with-proxy # also Python proxy on :3000
```

Default site: **http://bot123456789.space:8080** (or host IP :8080).

### Production (systemd + Nginx)

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for Nginx, the ingest PHP-FPM pool, and:

```bash
./deploy/install-platform-services.sh
# enables: platform-pocket-proxy, platform-queue, platform-chart-live
```

Install the minute cron from `crontab.example` so `schedule:run` drives `bots:scan` / `bots:settle` / candle prune.

---

## Features (high level)

- **Bots:** CRUD (web + API), strategies, live scan, settlement, Telegram alerts  
- **Stats:** Win/Lose over 1h / 3h / day  
- **Charts:** M1 candles in MySQL; higher TFs aggregated in PHP; live S5 via Redis + `/chart-live` WS  
- **Mirror / place-order:** through the Python proxy (tokens stay off the browser)  
- **Schedules:** Laravel scheduler + per-bot cron expressions where configured  

---

## HTTP API (prefix `/api`)

- `GET/POST/PUT/DELETE /api/bots`, `POST /api/bots/{id}/run`, `GET /api/bot-runs/{id}`  
- `GET /api/bot-stats`  
- `GET /api/chart/candles`, `GET /api/assets`  
- `GET/POST/PUT/DELETE /api/chart-markups`  
- `POST /api/bot-results?run_token=…` — bot callback  

Auth: session (web guard). Callbacks use a one-time `run_token`.

Proxy-facing ingest/instruments endpoints are documented in [docs/PROXY_INGEST.md](docs/PROXY_INGEST.md).

---

## Documentation map

| Doc | Contents |
|-----|----------|
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | **Primary deployment guide** (services, Nginx, checklist) |
| [PROXY.md](PROXY.md) | Python proxy HTTP API |
| [docs/PROXY_INGEST.md](docs/PROXY_INGEST.md) | Continuous ingest, latency, troubleshooting |
| [docs/PLAN_RELEASE.md](docs/PLAN_RELEASE.md) | Product/data plan (instruments, M1 storage) |
| `deploy/` | systemd units + install scripts |
| `crontab.example` | Scheduler cron line |

---

## First login

Open `APP_URL`, register, create a bot, and configure Pocket Options instruments / accounts (`session_token`, `uid`, active flags) so ingest workers can connect.
