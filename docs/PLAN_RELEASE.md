# Implementation plan — Pocket Options bots platform

Historical design notes for candle storage, instruments admin, and proxy ingest. For current install steps see [DEPLOYMENT.md](./DEPLOYMENT.md).

## 1. Goals

- **Candles in DB:** proxy writes **M1 only**; M5 and M15 are aggregated in **PHP** on read.  
- **Bots read from DB:** use the candle service when data is present; fall back to the proxy API if missing/stale.  
- **Lower ban risk:** one persistent WebSocket per asset; no frequent instrument switching on a connection.  
- **Pairs in admin UI:** instruments in MySQL (`display_name`, priority, per-pair auth, `is_active`); empty auth → global settings.

---

## 2. Database

### 2.1. Instruments table

**Table:** `pocket_instruments`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint, PK | — |
| instrument | string, unique | Asset code (e.g. `EURUSD_otc`) |
| display_name | string, nullable | UI label |
| priority | integer, default 0 | Higher = listed first |
| session_token | text, nullable | Per-pair auth for proxy (encrypted) |
| uid | string, nullable | UID for this pair |
| is_demo | boolean, default false | Demo flag |
| is_active | boolean, default true | Included in streaming |
| created_at / updated_at | timestamp | — |

- Empty `session_token` / `uid` → proxy uses global settings.  
- Runtime list: `is_active = true`, order `priority DESC`, then `instrument`.

### 2.2. Candles table (M1 only)

**Table:** `candles`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint, PK | — |
| instrument | string | Asset code |
| timeframe | smallint | Always `1` (minute) |
| time | bigint | Unix start of minute |
| open / high / low / close / volume | decimal | OHLC + volume |
| created_at / updated_at | timestamp | — |

- Unique: `(instrument, timeframe, time)`.  
- Query index: `(instrument, timeframe, time DESC)`.

### 2.3. Laravel models

- **PocketInstrument** — encrypt cast on `session_token`, fillable, scopes as needed.  
- **Candle** — scopes by instrument, timeframe, time.

---

## 3. Admin UI

### 3.1. Instruments page

**Route:** `/instruments` (auth group).

- List: instrument, display_name, priority, is_active, “custom auth” yes/no; sort priority DESC.  
- Form: instrument (required, unique), display_name, priority, optional auth block (`session_token`, `uid`, `is_demo`), `is_active`.  
- Validation: if `session_token` set, `uid` is required.

### 3.2. Navigation

Link “Instruments” → `route('instruments.index')` in the app layout.

---

## 4. Platform API

### 4.1. Active instruments for the proxy

**Endpoint:** `GET /api/proxy/instruments`

- Network-restricted (not a public browser API); no separate token in the original design.  
- Rows: `is_active = true`, sort `priority DESC`, `instrument`.  
- JSON array: instrument, display_name, priority, session_token (decrypted or null), uid, is_demo.

### 4.2. Candle ingest

**Endpoint:** `POST /api/ingest-candles`

- Body: `instrument`, `timeframe` (expect `1`), `candles: [{ time, open, high, low, close, volume }, ...]`.  
- Upsert into `candles` on `(instrument, timeframe, time)`.

---

## 5. Candle read service

**Class:** `App\Services\CandleService`  
**Method:** `getCandles(string $instrument, int $timeframe, int $count): array`

- Return shape compatible with existing clients: `['candles' => [...], 'instrument' => ..., 'timeframe' => ...]`.  
- **timeframe = 1:** query DB M1, newest first, limit `$count`, reverse to oldest→newest if needed.  
- **timeframe = 5 or 15:** load M1 for `$count * $timeframe` minutes; group with  
  `period = floor(time / (timeframe * 60)) * (timeframe * 60)`;  
  open = first, high = max, low = min, close = last, volume = sum; trim to `$count`.

---

## 6. RunBotJob integration

Before building the bot payload: call `CandleService::getCandles`; if empty/stale, fall back to `$proxy->getCandles(...)` with the user’s session/uid. Put the resulting array in `$payload['candles']`.

---

## 7. Proxy (outside Laravel)

### 7.1. Config

- Env: `PLATFORM_URL`.  
- Instrument list only from `GET /api/proxy/instruments` (not a static file). Auth fields come from `pocket_instruments`.

### 7.2. Background loop

- On start (and on a timer): fetch instruments.  
- Per active instrument: use API `session_token` / `uid`; skip if missing token.  
- One persistent WS client per instrument (cap `MAX_CONNECTIONS`, respect priority).  
- Loop: subscribe to that instrument’s M1 only; post to `POST /api/ingest-candles`.  
- Never switch instrument on a live connection; reconnect to the same instrument on drop.

---

## 8. Platform config (`config/services.php`)

```php
'url' => env('POCKET_OPTIONS_PROXY_URL', 'http://127.0.0.1:3000'),
```

---

## 9. Implementation stages (original order)

| # | Stage | Notes |
|---|--------|-------|
| 1 | Migrations & models | `pocket_instruments`, `candles` |
| 2 | Instruments admin | controller, routes, views, nav link |
| 3 | Proxy API | `GET /api/proxy/instruments`, `POST /api/ingest-candles` |
| 4 | CandleService | M1 from DB; M5/M15 PHP aggregate |
| 5 | RunBotJob | DB first, proxy fallback |
| 6 | Proxy process | API list, persistent sockets, M1 ingest |
| 7 | Testing | M1 write; M1/M5/M15 read; bot on DB + fallback |

---

## 10. Data-flow summary

- **Write:** Pocket Options → proxy (N persistent sockets, M1 only) → `POST /api/ingest-candles` → `candles`.  
- **Read (bots):** RunBotJob → CandleService (DB / PHP aggregate) → optional proxy fallback → bot payload.  
- **Pairs:** admin → `pocket_instruments`; proxy refreshes via `GET /api/proxy/instruments`.  
