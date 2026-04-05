# План реализации платформы ботов под Pocket Options

## 1. Цели

- **Свечи в БД:** прокси пишет только **M1**; M5 и M15 считаются в **PHP** при чтении.
- **Боты читают из БД:** при наличии данных — из сервиса; при отсутствии/устаревании — fallback на прокси (API).
- **Снижение риска бана:** по одному постоянному WebSocket на актив, без частой смены инструмента.
- **Управление парами в админке:** список инструментов в MySQL (display_name, приоритет, свои auth, is_active); при пустых auth — глобальные настройки.

---

## 2. База данных

### 2.1. Таблица инструментов (пары)

**Таблица:** `pocket_instruments`

| Поле           | Тип              | Описание |
|----------------|-------------------|----------|
| id             | bigint, PK        | —        |
| instrument     | string, unique    | Код актива (EURUSD_otc и т.п.) |
| display_name   | string, nullable  | Название для UI |
| priority       | integer, default 0| Приоритет (больше = выше в списке) |
| session_token  | text, nullable    | Свои auth для прокси (encrypted) |
| uid            | string, nullable  | UID для этой пары |
| is_demo        | boolean, default false | Демо для пары |
| is_active      | boolean, default true  | Участвует в стриминге |
| created_at     | timestamp         | —        |
| updated_at     | timestamp         | —        |

- Если `session_token`/`uid` пустые — прокси использует глобальные настройки.
- В работе только записи с `is_active = true`, сортировка по `priority DESC`, затем по `instrument`.

### 2.2. Таблица свечей (только M1)

**Таблица:** `candles` (или `instrument_candles`)

| Поле       | Тип        | Описание |
|------------|------------|----------|
| id         | bigint, PK | —        |
| instrument | string     | Код актива |
| timeframe  | smallint   | Всегда 1 (минута) |
| time       | bigint     | Unix, начало минуты |
| open       | decimal    | —        |
| high       | decimal    | —        |
| low        | decimal    | —        |
| close      | decimal    | —        |
| volume     | decimal    | —        |
| created_at | timestamp  | —        |
| updated_at | timestamp  | —        |

- Уникальный индекс: `(instrument, timeframe, time)`.
- Индекс для выборки: `(instrument, timeframe, time DESC)`.

### 2.3. Модели Laravel

- **PocketInstrument** — cast `session_token` => encrypted, fillable, scopes при необходимости.
- **InstrumentCandle** (или **Candle**) — scope по instrument, timeframe, time.

---

## 3. Админка (веб-интерфейс)

### 3.1. Страница управления парами

**Маршрут:** `/instruments` (в группе `auth`).

- **Список:**
  - Колонки: instrument, display_name, priority, is_active, «свои auth» (да/нет).
  - Сортировка: по priority DESC, затем по instrument.
  - Действия: создать, редактировать, включить/выключить (is_active).

- **Форма создания/редактирования:**
  - instrument (обязательно, уникально),
  - display_name (опционально),
  - priority (число, по умолчанию 0),
  - Блок «Авторизационные данные для этой пары (опционально)»: session_token, uid, is_demo,
  - is_active (чекбокс).

- Валидация: при заполненном session_token — uid обязателен.

### 3.2. Навигация

- В `layouts/app.blade.php` добавить ссылку «Инструменты» (или «Пары») на `route('instruments.index')`.

---

## 4. API платформы

### 4.1. Список активных инструментов для прокси

**Endpoint:** `GET /api/proxy/instruments`

- Доступ ограничивается сетью (не публичный endpoint); отдельный токен не используется.
- Выборка: только `is_active = true`, сортировка `priority DESC`, затем `instrument`.
- Ответ — JSON-массив объектов: instrument, display_name, priority, session_token (расшифрованный или null), uid, is_demo.

### 4.2. Приём свечей от прокси

**Endpoint:** `POST /api/ingest-candles`

- Доступ ограничивается сетью; отдельный токен не используется.
- Тело: `instrument`, `timeframe` (ожидается 1), `candles: [{ time, open, high, low, close, volume }, ...]`.
- Действие: upsert в таблицу `candles` по ключу `(instrument, timeframe, time)`.

---

## 5. Сервис чтения свечей (платформа)

**Класс:** `App\Services\CandleService` (или `InstrumentCandleService`).

**Метод:** `getCandles(string $instrument, int $timeframe, int $count): array`

- Возврат в формате, совместимом с текущим контрактом: `['candles' => [...], 'instrument' => ..., 'timeframe' => ...]`.

**Логика:**

- **timeframe = 1:** выборка из `candles` по instrument и timeframe = 1, ORDER BY time DESC, limit $count; при необходимости развернуть в «от старых к новым».
- **timeframe = 5 или 15:**
  - из БД читаются M1 за период (`$count * $timeframe` минут);
  - в PHP группировка по периоду: `period = floor(time / (timeframe * 60)) * (timeframe * 60)`;
  - для каждой группы: open первой M1, high = max, low = min, close последней, volume = sum;
  - результат сортируется по time и обрезается до $count баров.

---

## 6. Интеграция в RunBotJob

- В `handle()` перед формированием payload:
  - Внедрить `CandleService` (через конструктор или метод).
  - Вызвать `$candleService->getCandles($instrument, $timeframe, $count)`.
  - Если данных нет или они устарели (критерий на выбор: например, нет записей за последние N минут) — fallback на `$proxy->getCandles(...)` с session/uid из аккаунта пользователя.
- В `$payload['candles']` подставлять массив из сервиса или прокси. Остальная логика бота не меняется.

---

## 7. Прокси (вне платформы)

### 7.1. Конфигурация

- Глобальные (env): URL платформы (`PLATFORM_URL` у прокси).
- Список инструментов — только из `GET /api/proxy/instruments` (не из файла). В ответе по каждому инструменту приходят session_token, uid и прочие параметры из БД (`pocket_instruments`).

### 7.2. Фоновый цикл

- При старте (и по таймеру) запрос `GET /api/proxy/instruments`.
- Для каждого активного инструмента из ответа: использовать session_token и uid из ответа API (из БД) для SSID; при отсутствии session_token — пропуск инструмента.
- Создать по одному постоянному WebSocket-клиенту на инструмент (с учётом MAX_CONNECTIONS, приоритет по полю priority).
- В цикле по каждому соединению: подписка на свой instrument, только M1; получение свечей; отправка на `POST /api/ingest-candles`.
- Без смены инструмента на соединении; при обрыве — переподключение к тому же инструменту.

---

## 8. Конфигурация платформы (config/services.php)

Добавить в `pocket_options_proxy`:

```php
'url' => env('POCKET_OPTIONS_PROXY_URL', 'http://127.0.0.1:3000'),
```

---

## 9. Этапы реализации (порядок работ)

| № | Этап | Описание |
|---|------|----------|
| 1 | Миграции и модели | Создать миграции `pocket_instruments`, `candles`; модели PocketInstrument, InstrumentCandle. |
| 2 | Админка инструментов | Контроллер InstrumentController (web), маршруты, представления (index, create, edit), ссылка в навигации. |
| 3 | API для прокси | GET /api/proxy/instruments, POST /api/ingest-candles; маршруты за middleware-заглушкой (доступ по сети). |
| 4 | Сервис свечей | CandleService::getCandles (M1 из БД, M5/M15 — агрегация в PHP). |
| 5 | Интеграция RunBotJob | Подключить CandleService, приоритет чтения из БД, fallback на PocketOptionsProxyClient. |
| 6 | Прокси (отдельно) | Загрузка списка из API, постоянные соединения, отправка M1 на платформу. |
| 7 | Тестирование | Проверка записи M1, чтения M1/M5/M15, работы бота на данных из БД и через fallback. |

---

## 10. Сводка потоков данных

- **Запись свечей:** Pocket Option → прокси (N постоянных соединений, по одному на актив, только M1) → платформа (`POST /api/ingest-candles`) → таблица `candles`.
- **Чтение для ботов:** RunBotJob → CandleService::getCandles (БД: M1 или агрегат M5/M15 в PHP) → при отсутствии/устаревании — прокси → payload бота.
- **Управление парами:** админка → таблица `pocket_instruments`; прокси получает список через `GET /api/proxy/instruments` и использует приоритет и auth по паре или глобальные настройки.
