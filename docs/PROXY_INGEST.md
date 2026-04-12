# Прокси: постоянные WebSocket по одному на актив (PLAN_RELEASE)

Прокси при запуске может запускать фоновый инжест: загружает список активных инструментов с платформы, для каждого держит **одно постоянное WebSocket-соединение** к Pocket Option, раз в минуту запрашивает M1-свечи и отправляет их на платформу (`POST /api/ingest-candles`).

## Настройка платформы

В `.env` платформы:

```env
POCKET_OPTIONS_PROXY_URL=http://127.0.0.1:3000
```

В разделе **Инструменты** добавьте нужные активы и включите их. Для каждого укажите `session_token` и `uid` — они хранятся в БД (`pocket_instruments`) и передаются в API вместе с остальными параметрами инструмента.

## Запуск прокси с инжестом

Из каталога `bot`:

```bash
export PLATFORM_URL="http://localhost:8000"     # URL платформы
export INGEST_MAX_CONNECTIONS=100               # макс. соединений (по умолчанию 100)
export INSTRUMENTS_REFRESH_SEC=300              # обновление списка инструментов (сек)

python pocket-options-proxy.py
```

При наличии `PLATFORM_URL` прокси автоматически запускает фоновую задачу инжеста: загружает инструменты с платформы и для каждого (до `INGEST_MAX_CONNECTIONS`) держит одно постоянное соединение, раз в минуту запрашивает M1 и отправляет свечи на платформу. При обрыве соединения — переподключение к тому же инструменту.

---

## Запуск как systemd-сервис (постоянный сбор)

В каталоге `bot` есть unit-файл и env-файл:

- `bot/pocket-options-proxy.service` — unit для systemd
- `bot/proxy.env` — переменная `PLATFORM_URL` (при необходимости отредактируйте)

Установка и запуск (от root или через sudo):

```bash
sudo cp /home/o/platform/bot/pocket-options-proxy.service /etc/systemd/system/
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

## Задержка графика относительно Pocket Options

На стороне Pocket Option график обновляется по **прямому WebSocket** в браузере. На платформе цепочка другая: тики и свечи из потока **агрегируются в воркере** (`bot/ingest_stream.py`), затем **текущая (незакрытая) минута** отправляется на Laravel (`POST /api/ingest-current-candle`, кеш Redis), а дашборд **периодически запрашивает** свечи (`GET /candles` с `include_partial=1`). Поэтому полное совпадение с окном Pocket по времени невозможно; важно не раздувать искусственную задержку на своей стороне.

### Узкое место: интервал главного цикла инжеста

Воркер в цикле делает паузу **`INGEST_LOOP_INTERVAL_SEC`** секунд и только после неё выполняет отправку текущей свечи, сброс завершённых минут в БД и прочие шаги. Если этот интервал большой (раньше в коде было **5 с**), то **даже при малом `INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC`** текущая свеча на платформу уходит **не чаще, чем раз за интервал цикла**. В сочетании с опросом графика это давало ощущаемое отставание порядка **5–10 с** от графика Pocket.

Рекомендация: держать **`INGEST_LOOP_INTERVAL_SEC=1`** (или `2`, если нужно снизить нагрузку на Redis/API). После смены переменных перезапустите прокси (`systemctl restart pocket-options-proxy` или перезапуск процесса с актуальным `proxy.env`).

### Остальные переменные (текущая свеча)

| Переменная | Назначение |
|------------|------------|
| `INGEST_CURRENT_CANDLE_ENABLE` | `1` — слать формирующуюся минуту на платформу; `0` — только закрытые свечи в БД. |
| `INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC` | Минимальный интервал (сек, можно дробное, например `0.5`) между POST одной и той же открытой минуты при обновлении OHLC, чтобы не забивать Redis. |
| `INGEST_CURRENT_CANDLE_TTL_SEC` | TTL ключа текущей свечи в Redis. |
| `INGEST_LOOP_INTERVAL_SEC` | Пауза главного цикла воркера (сек); влияет на максимальную частоту доставки текущей свечи и flush завершённых минут. |

Пример фрагмента `bot/proxy.env`:

```env
INGEST_CURRENT_CANDLE_ENABLE=1
INGEST_CURRENT_CANDLE_TTL_SEC=180
INGEST_CURRENT_CANDLE_POST_DEBOUNCE_SEC=1
INGEST_LOOP_INTERVAL_SEC=1
```

### Дашборд

Страница графиков опрашивает API свечей с интервалом **около 1 с** (см. `resources/views/dashboard.blade.php`, автообновление после выбора инструмента). Это добавляет до ~1 с задержки между обновлением Redis и отображением в браузере.

---

## Если данные не попадают в БД

1. **Порт платформы**  
   `PLATFORM_URL` должен указывать на тот же хост и порт, на которых реально отвечает приложение (Laravel). Например, при `php artisan serve` это обычно `http://127.0.0.1:8000`, при nginx на 8080 — `http://127.0.0.1:8080`. Проверка с той же машины, где крутится прокси:
   ```bash
   curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:ПОРТ/api/proxy/instruments"
   ```
   Ожидается 200 и JSON-массив (пустой или с инструментами).

2. **Активные инструменты**  
   В разделе «Инструменты» должны быть включённые инструменты (`is_active = 1`). Иначе API отдаёт пустой список и воркеры не создаются.

3. **Auth для инструментов**  
   У каждого инструмента в БД (`pocket_instruments`) должны быть заполнены `session_token` и `uid` (в форме инструмента). Эти данные отдаются в API вместе с остальными параметрами инструмента. Без них воркеры не стартуют (в логе прокси будет строка вида `[INGEST] skip ...: no session_token`).

4. **Логи прокси**  
   Запускайте прокси в foreground и смотрите вывод:
   - `[INGEST] background task started` — инжест включён;
   - `[INGEST] fetched N instruments` — платформа отдала список;
   - `[INGEST] started worker for X` — воркер по инструменту X запущен;
   - `[INGEST] X: sent N candles` — свечи по X отправлены в платформу;
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
* * * * * cd /home/o/platform && php artisan schedule:run >> /dev/null 2>&1
```
Проверка запланированных задач: `php artisan schedule:list`.
