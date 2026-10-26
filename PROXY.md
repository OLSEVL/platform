# Pocket Options candle / order proxy (Python)

The Laravel platform does **not** open Pocket Options WebSockets itself. Candles, assets, charts, and place-order traffic go through a **separate Python proxy** under `bot/`.

## What it is

- **Entry script:** `bot/pocket-options-proxy.py` (FastAPI, port **3000**).  
- **Role:** one (or many) WebSocket sessions to Pocket Options (`PocketOptionAPI-main`), exposed as **HTTP/WS APIs** so Laravel and tooling never hold raw browser tokens on the public site.  
- **Production unit:** `deploy/platform-pocket-proxy.service` (see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)).

## Main HTTP endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/candles?instrument=&timeframe=&sessionToken=&uid=` | Candles for instrument/timeframe |
| GET | `/api/assets` | Assets / categories |
| GET | `/api/chart?…` | Chart as PNG |
| POST | `/api/chart` | Chart PNG from supplied data |
| POST | `/api/place-order` | Place order (used by platform trading) |
| GET | `/health` | Liveness |

Tokens (`sessionToken`, `uid`) are passed in the request; Laravel loads them from the DB when calling the proxy. Platform `.env`:

```env
POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000
```

## Manual start

```bash
cd /home/o/platform/bot
python3 -m venv .venv          # once
.venv/bin/pip install -r requirements.txt
.venv/bin/pip install -r python/PocketOptionAPI-main/requirements.txt
.venv/bin/python pocket-options-proxy.py
```

Listens on **http://0.0.0.0:3000**.

With continuous ingest enabled, also set `PLATFORM_URL` (and optional vars in `bot/proxy.env`) — details in [docs/PROXY_INGEST.md](docs/PROXY_INGEST.md).

## Dependencies

- Python 3, FastAPI, uvicorn, and the tree `bot/python/PocketOptionAPI-main/`  
- Redis when using Redis-direct current-candle / `chart:s5` publish  

## Related

- Continuous ingest + systemd: [docs/PROXY_INGEST.md](docs/PROXY_INGEST.md)  
- Full host deployment: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)  
