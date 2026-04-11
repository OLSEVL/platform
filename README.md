# Платформа для ботов Pocket Options (Laravel)

Реализация по SPEC_DETAILED.md и PROJECT_PROMPT.md.

## Требования

- PHP 8.2+
- Composer
- MySQL (или MariaDB)
- Прокси свечей Pocket Options (FastAPI на порту 3000), см. `../bot/README-POCKET-OPTIONS.md`

## Установка

```bash
cd platform
cp .env.example .env
php artisan key:generate
# Создайте БД и пользователя (или используйте уже созданные):
#   sudo mysql -e "CREATE DATABASE platform; CREATE USER 'platform'@'localhost' IDENTIFIED BY 'platform'; GRANT ALL ON platform.* TO 'platform'@'localhost'; FLUSH PRIVILEGES;"
# Настройте DB_* и POCKET_OPTIONS_PROXY_URL в .env (по умолчанию DB_USERNAME=platform, DB_PASSWORD=platform)
php artisan migrate
```

## Запуск (внутренний IP или домен bot13123.online)

1. В `.env` задайте **APP_URL** — по какому адресу заходят пользователи:
   - по домену: `APP_URL=http://bot13123.online` (или `https://...` при наличии SSL);
   - по внутреннему IP: `APP_URL=http://192.168.1.100:8000` (подставьте свой IP).
2. Запустите прокси свечей (из папки bot): `python pocket-options-proxy.py`
3. Очередь (в отдельном терминале): `php artisan queue:work`
4. Планировщик Laravel — **обязателен для ботов** (`bots:scan`, `bots:settle`, обрезка свечей). Выберите один вариант:
   - **Cron (продакшен):** строка в crontab пользователя, под которым крутится PHP (часто тот же, что и владелец файлов проекта, или `www-data`):
     ```bash
     crontab -e
     ```
     Добавьте **одну** строку (замените путь на каталог с `artisan`, при необходимости укажите полный путь к `php`, например `/usr/bin/php`):
     ```
     * * * * * cd /home/o/platform && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
     ```
     Путь в `cd` должен быть **абсолютным**. Логи: `storage/logs/scheduler.log` (каталог `storage/logs` должен существовать и быть доступен на запись).
   - **Без cron (разработка / один терминал):** `php artisan schedule:work` — процесс сам раз в минуту вызывает расписание.
   - Пример готовой строки для копирования: см. файл **`crontab.example`** в корне репозитория.
5. Веб-сервер (доступ с любого IP и по домену):
   - **`./start.sh`** или **`./start.sh 8000`** — слушает `0.0.0.0:8000`;
   - либо вручную: `php artisan serve --host=0.0.0.0 --port=8000`

Откройте в браузере APP_URL (например http://bot13123.online или http://ВАШ_IP:8000), зарегистрируйтесь, создайте бота. Для графика и свечей настройте аккаунт Pocket Options (таблица `pocket_options_accounts`).

**Домен bot13123.online:** на сервере должен быть настроен DNS (A-запись на IP этого хоста).

### Запуск через Nginx + PHP-FPM

1. Установлены **nginx** и **php8.3-fpm**, конфиг развёрнут из `nginx-platform.conf`:
   ```bash
   sudo cp /home/o/platform/nginx-platform.conf /etc/nginx/sites-available/platform
   sudo ln -sf /etc/nginx/sites-available/platform /etc/nginx/sites-enabled/
   sudo rm -f /etc/nginx/sites-enabled/default   # опционально
   sudo chown -R www-data:www-data storage bootstrap/cache
   sudo chmod -R 775 storage bootstrap/cache
   sudo nginx -t && sudo systemctl reload nginx
   ```
2. Сайт слушает **порт 8080** (порт 80 был занят; чтобы перейти на 80, освободите его и в конфиге раскомментируйте `listen 80`, закомментируйте `listen 8080`, затем `sudo systemctl reload nginx`).
3. В `.env` укажите **APP_URL** с портом, если используете 8080: `APP_URL=http://bot13123.online:8080` или `APP_URL=http://ВАШ_IP:8080`.
4. Очередь по-прежнему: `php artisan queue:work`, плюс **cron** на `schedule:run` (см. выше) или `php artisan schedule:work`.

Открывайте в браузере: **http://localhost:8080**, **http://ваш-IP:8080** или **http://bot13123.online:8080** (домен на выделенном сервере, без Traefik).

### Запуск всей платформы одной командой

```bash
cd /home/o/platform
./start-all.sh
```

Скрипт поднимает: **MySQL**, **PHP-FPM**, **Nginx**, воркер очереди. Сайт: **http://bot13123.online:8080**. С прокси свечей (Python, WebSocket → Pocket Options): `./start-all.sh --with-proxy`. Подробнее про прокси: **PROXY.md**.

## Основное

- **Боты**: CRUD через веб и API, ручной запуск → Job → callback или stdout.
- **Статистика**: Win/Lose за 1 ч, 3 ч и день (BotStatsService).
- **График**: свечи через API (прокси не светит токены), разметка в `chart_markups`.
- **Расписание**: команда `php artisan bots:dispatch-scheduled` по крону; в таблице `bot_schedules` — cron_expression на бота.

## API (префикс /api)

- `GET /api/bots`, `GET /api/bots/{id}`, `POST /api/bots`, `PUT /api/bots/{id}`, `DELETE /api/bots/{id}`
- `POST /api/bots/{id}/run` — ручной запуск (возвращает run_id)
- `GET /api/bot-runs/{id}` — статус и анализ запуска
- `GET /api/bot-stats` — статистика по периодам
- `GET /api/chart/candles?instrument=&timeframe=`
- `GET /api/assets`
- `GET/POST/PUT/DELETE /api/chart-markups`
- `POST /api/bot-results?run_token=...` — callback от бота (outcome, analysis)

Авторизация: сессия (web guard). Для callback используется одноразовый `run_token`.
