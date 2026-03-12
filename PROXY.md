# Прокси свечей Pocket Options (Python)

Платформа **не** подключается к Pocket Options напрямую. Все данные свечей и активов идут через **отдельный Python-прокси** в папке `../bot/`.

## Что это

- **Скрипт:** `pocket-options-proxy.py` (FastAPI, порт **3000**).
- **Роль:** подключается к Pocket Options по **WebSocket** (библиотека `PocketOptionAPI-main`), отдаёт данные по **HTTP API**, чтобы Laravel и n8n не работали с WebSocket и токенами напрямую.

## Что умеет прокси

| Метод | URL | Описание |
|-------|-----|----------|
| GET | `/api/candles?instrument=...&timeframe=...&sessionToken=...&uid=...` | Свечи по инструменту и таймфрейму (данные с WebSocket). |
| GET | `/api/assets` | Список активов/категорий. |
| GET | `/api/chart?instrument=...&timeframe=...` | График свечей в виде PNG. |
| POST | `/api/chart` | График PNG по переданным данным. |
| GET | `/health` | Проверка работы прокси. |

Токены (`sessionToken`, `uid`) передаются в запросе к прокси; платформа подставляет их из БД при вызове прокси (см. `.env` → `POCKET_OPTIONS_PROXY_URL`).

## Запуск

Из папки с ботом:

```bash
cd /root/bot
pip install -r requirements.txt   # один раз
python pocket-options-proxy.py
```

Сервер слушает **http://0.0.0.0:3000**. В платформе в `.env` должно быть:

```env
POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000
```

## Зависимости

- Python 3, FastAPI, uvicorn.
- Папка `python/PocketOptionAPI-main/` с библиотекой для WebSocket Pocket Options.

Подробнее: `../bot/README-POCKET-OPTIONS.md`.
