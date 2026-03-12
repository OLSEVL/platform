#!/bin/bash
# Запуск платформы на 0.0.0.0 (доступ по внутреннему IP и домену)
# Использование: ./start.sh [порт]
# Либо: php artisan serve --host=0.0.0.0 --port=8000

cd "$(dirname "$0")"
PORT="${1:-8000}"

echo "Platform: http://0.0.0.0:$PORT (и по домену bot13123.online, если DNS указывает на этот сервер)"
echo "Очередь (queue) запустите отдельно: php artisan queue:work"
echo ""

php artisan config:clear
php artisan serve --host=0.0.0.0 --port="$PORT"
