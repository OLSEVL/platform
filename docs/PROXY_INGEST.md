# Прокси: постоянные WebSocket по одному на актив (PLAN_RELEASE)

Прокси при запуске может запускать фоновый инжест: загружает список активных инструментов с платформы, для каждого держит **одно постоянное WebSocket-соединение** к Pocket Option, раз в минуту запрашивает M1-свечи и отправляет их на платформу (`POST /api/ingest-candles`).

## Настройка платформы

В `.env` платформы:

```env
POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000
POCKET_OPTIONS_PROXY_INGEST_TOKEN=секретный_токен
```

В разделе **Инструменты** добавьте нужные активы и включите их. Для каждого укажите `session_token` и `uid` — они хранятся в БД (`pocket_instruments`) и передаются в API вместе с остальными параметрами инструмента.

## Запуск прокси с инжестом

Из каталога `bot`:

```bash
export PLATFORM_URL="http://localhost:8000"     # URL платформы
export X_PROXY_TOKEN="секретный_токен"          # тот же, что POCKET_OPTIONS_PROXY_INGEST_TOKEN
export INGEST_MAX_CONNECTIONS=100               # макс. соединений (по умолчанию 100)
export INSTRUMENTS_REFRESH_SEC=300              # обновление списка инструментов (сек)

python pocket-options-proxy.py
```

При наличии `PLATFORM_URL` и `X_PROXY_TOKEN` прокси автоматически запускает фоновую задачу инжеста: загружает инструменты с платформы и для каждого (до `INGEST_MAX_CONNECTIONS`) держит одно постоянное соединение, раз в минуту запрашивает M1 и отправляет свечи на платформу. При обрыве соединения — переподключение к тому же инструменту.

---

## Запуск как systemd-сервис (постоянный сбор)

В каталоге `bot` есть unit-файл и env-файл:

- `bot/pocket-options-proxy.service` — unit для systemd
- `bot/proxy.env` — переменные `PLATFORM_URL` и `X_PROXY_TOKEN` (при необходимости отредактируйте)

Установка и запуск (от root или через sudo):

```bash
sudo cp /root/bot/pocket-options-proxy.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable pocket-options-proxy.service
sudo systemctl start pocket-options-proxy.service
```

Полезные команды:

```bash
sudo systemctl status pocket-options-proxy   # статус
sudo journalctl -u pocket-options-proxy -f   # логи в реальном времени
sudo systemctl restart pocket-options-proxy  # перезапуск
sudo systemctl stop pocket-options-proxy     # остановка
```

После перезагрузки сервера сервис поднимется сам (enabled).

---

## Если данные не попадают в БД

1. **Порт платформы**  
   `PLATFORM_URL` должен указывать на тот же хост и порт, на которых реально отвечает приложение (Laravel). Например, при `php artisan serve` это обычно `http://127.0.0.1:8000`, при nginx на 8080 — `http://127.0.0.1:8080`. Проверка с той же машины, где крутится прокси:
   ```bash
   curl -s -o /dev/null -w "%{http_code}" -H "X-Proxy-Token: ВАШ_ТОКЕН" "http://127.0.0.1:ПОРТ/api/proxy/instruments"
   ```
   Ожидается 200 и JSON-массив (пустой или с инструментами).

2. **Токен**  
   Значение `X_PROXY_TOKEN` при запуске прокси должно совпадать с `POCKET_OPTIONS_PROXY_INGEST_TOKEN` в `.env` платформы. Иначе запросы к `/api/proxy/instruments` и `/api/ingest-candles` вернут 401.

3. **Активные инструменты**  
   В разделе «Инструменты» должны быть включённые инструменты (`is_active = 1`). Иначе API отдаёт пустой список и воркеры не создаются.

4. **Auth для инструментов**  
   У каждого инструмента в БД (`pocket_instruments`) должны быть заполнены `session_token` и `uid` (в форме инструмента). Эти данные отдаются в API вместе с остальными параметрами инструмента. Без них воркеры не стартуют (в логе прокси будет строка вида `[INGEST] skip ...: no session_token`).

5. **Логи прокси**  
   Запускайте прокси в foreground и смотрите вывод:
   - `[INGEST] background task started` — инжест включён;
   - `[INGEST] fetched N instruments` — платформа отдала список;
   - `[INGEST] started worker for X` — воркер по инструменту X запущен;
   - `[INGEST] X: sent N candles` — свечи по X отправлены в платформу;
   - `[INGEST] fetch_instruments HTTP 401` / `post_ingest_candles HTTP 401` — неверный токен;
   - `[INGEST] no instruments` — пустой список (проверить активные инструменты и доступность платформы).

---

## Удаление устаревших свечей (старше 24 часов)

Команда `candles:prune-old` удаляет из таблицы `candles` все записи, у которых `time` (unix-время минуты) старше 24 часов. Она запланирована на **каждый час** в планировщике Laravel.

Запуск вручную:
```bash
cd /path/to/platform
php artisan candles:prune-old
```

Чтобы расписание выполнялось автоматически, в cron должен быть вызов планировщика раз в минуту (для пользователя, под которым крутится приложение):
```bash
* * * * * cd /root/platform && php artisan schedule:run >> /dev/null 2>&1
```
Проверка запланированных задач: `php artisan schedule:list`.
