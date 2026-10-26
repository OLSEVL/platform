# Proxy: persistent WebSocket per instrument (ingest)

On startup the proxy can run a background ingest loop: it loads active instruments from the platform, keeps **one persistent WebSocket per asset** to Pocket Options, requests M1 candles on a schedule, and posts them to Laravel (`POST /api/ingest-candles`). Current (open) minutes can go to Redis for the live chart.

## Platform configuration

In the platform `.env`:

```env
POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000
```

In **Instruments**, enable the assets you need. Each instrument should have `session_token` and `uid` stored in `pocket_instruments` (returned by the instruments API to the proxy).

## Running the proxy with ingest

From `bot/`:

```bash
export PLATFORM_URL="http://127.0.0.1:8080"   # must match real Laravel URL
export INGEST_MAX_CONNECTIONS=100
export INSTRUMENTS_REFRESH_SEC=300

.venv/bin/python pocket-options-proxy.py
```

When `PLATFORM_URL` is set, ingest starts automatically: fetch instruments, start up to `INGEST_MAX_CONNECTIONS` workers, reconnect on drop.

Prefer systemd in production: `platform-pocket-proxy.service` via `./deploy/install-platform-services.sh` (loads `bot/proxy.env`). See [DEPLOYMENT.md](./DEPLOYMENT.md).

### Legacy unit (avoid on the same host)

Older docs referenced `bot/pocket-options-proxy.service`. That conflicts on port **3000** with `platform-pocket-proxy.service`. Disable the old unit:

```bash
sudo systemctl disable --now pocket-options-proxy.service
```

Useful commands for the current unit:

```bash
sudo systemctl status platform-pocket-proxy
sudo journalctl -u platform-pocket-proxy -f
# or: tail -f storage/logs/proxy.log
sudo systemctl restart platform-pocket-proxy
```

---

## Chart lag vs Pocket Options

Pocket Options updates the browser chart over a **direct WebSocket**. On this platform the path is: ticks aggregated in the ingest worker → open minute to Laravel/Redis → dashboard poll or `/chart-live` WS. Perfect tick-for-tick parity is not the goal; avoid adding avoidable delay.

### Bottleneck: main ingest loop interval

The worker sleeps **`INGEST_LOOP_INTERVAL_SEC`** between cycles (current-candle post, flush closed bars, etc.). A large interval (historically **5 s**) caps updates even if debounce is small — often **5–10 s** perceived lag with polling.

Recommended for snappy S5 live:

```env
INGEST_LOOP_INTERVAL_SEC=0.25
INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC=0.25
INGEST_CURRENT_CANDLE_REDIS_DIRECT=1
```

With a tiny default `www` FPM pool, HTTP `POST /api/ingest-current-candle` cannot sustain 100+ pairs × ~4 Hz (p50 ~1s, 504s). **Redis-direct** writes `SETEX current_candle:*` and `PUBLISH chart:s5` from Python so ticks do not wait on PHP flush of closed bars. Nginx still routes ingest HTTP to the dedicated **ingest** FPM pool (`deploy/php8.3-fpm-ingest.conf`).

### Current-candle environment variables

| Variable | Purpose |
|----------|---------|
| `INGEST_CURRENT_CANDLE_ENABLE` | `1` = push forming minute; `0` = closed candles to DB only |
| `INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC` | Min seconds between posts of the same open minute (float OK) |
| `INGEST_CURRENT_CANDLE_TTL_SEC` | Redis TTL for current candle keys |
| `INGEST_LOOP_INTERVAL_SEC` | Main worker loop sleep (float); caps post/flush rate |
| `INGEST_CURRENT_CANDLE_REDIS_DIRECT` | `1` = write Redis + pub/sub from Python |

Example `bot/proxy.env` fragment:

```env
INGEST_CURRENT_CANDLE_ENABLE=1
INGEST_CURRENT_CANDLE_TTL_SEC=180
INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC=0.25
INGEST_LOOP_INTERVAL_SEC=0.25
INGEST_CURRENT_CANDLE_REDIS_DIRECT=1
```

### Dashboard

Charts also poll candle APIs (~1 s) when not fully on the live hub; that can add up to ~1 s after Redis updates. Prefer `/chart-live` (hub on :8765) for the lowest latency path.

---

## If data never reaches the DB

1. **Platform URL / port**  
   `PLATFORM_URL` must match the host:port that actually serves Laravel (`:8000` for `artisan serve`, `:8080` for nginx). From the proxy host:

   ```bash
   curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:PORT/api/proxy/instruments"
   ```

   Expect HTTP 200 and a JSON array.

2. **Active instruments**  
   At least one instrument with `is_active = 1`. Otherwise the API returns `[]` and no workers start.

3. **Auth on instruments**  
   Each row needs `session_token` and `uid`. Missing auth → log line like `[INGEST] skip …: no session_token`.

4. **Outbound proxies / bandwidth**  
   If the HTTP proxy pool returns `402` / `bandwidthlimit`, PO connections fail and candles stop until bandwidth is restored. Check proxy provider quota and platform proxy settings.

5. **Proxy logs** (foreground or `storage/logs/proxy.log`):  
   - `[INGEST] background task started` — ingest on  
   - `[INGEST] fetched N instruments` — platform list OK  
   - `[INGEST] started worker for X` — worker up  
   - `[INGEST] X: sent N candles` — posted to platform  
   - `[INGEST] no instruments` — empty list or unreachable platform  

---

## Pruning old candles (> 24 hours)

`php artisan candles:prune-old` deletes `candles` rows whose minute `time` is older than 24 hours. It is scheduled **hourly** in Laravel’s scheduler.

Manual run:

```bash
cd /home/o/platform
php artisan candles:prune-old
```

Scheduler must run every minute (cron); see `crontab.example` and [DEPLOYMENT.md](./DEPLOYMENT.md). Check with `php artisan schedule:list`.
