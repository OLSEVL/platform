<?php

declare(strict_types=1);

/**
 * Лёгкий ingest partial-свечи без bootstrap Laravel.
 *
 * Вызывается из public/ingest-current.php (nginx location для /api/ingest-current-candle).
 * Контракт Redis и JSON ответа совпадает с ProxyInstrumentController::ingestCurrentCandle()
 * и CurrentCandleCacheService::putPartial().
 */

/** S5-бакет partial в ingest_stream.py (BASE_BAR_PERIOD_SEC). */
const INGEST_CURRENT_LIGHT_S5_BAR_PERIOD_SEC = 5;

/** Префикс ключа Redis — как CurrentCandleCacheService::KEY_PREFIX. */
const INGEST_CURRENT_LIGHT_KEY_PREFIX = 'current_candle:';

/** Pub/sub канал S5 partial — как App\Support\ChartLiveConfig::REDIS_CHANNEL (Laravel добавит Redis prefix). */
const INGEST_CURRENT_LIGHT_PUB_CHANNEL = 'chart:s5';

/**
 * @return array{
 *     status: int,
 *     body: array<string, mixed>,
 * }
 */
function ingest_current_light_handle_request(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return ingest_current_light_error(405, 'method_not_allowed', 'Only POST is allowed.');
    }

    $rawBody = file_get_contents('php://input');
    if (! is_string($rawBody) || $rawBody === '') {
        return ingest_current_light_error(422, 'invalid_json', 'Request body is empty.');
    }

    $decoded = json_decode($rawBody, true);
    if (! is_array($decoded)) {
        return ingest_current_light_error(422, 'invalid_json', 'Request body must be valid JSON.');
    }

    $validationError = ingest_current_light_validate($decoded);
    if ($validationError !== null) {
        return ingest_current_light_error(422, 'validation_error', $validationError);
    }

    $env = ingest_current_light_load_env(dirname(__DIR__).'/.env');

    $instrument = (string) $decoded['instrument'];
    $ttlSec = (int) ($decoded['ttl_sec'] ?? 180);
    $ttlSec = max(30, min(600, $ttlSec));

    $data = [
        'instrument' => $instrument,
        'time' => max(1, (int) $decoded['time']),
        'raw_feed_time' => max(0, (int) ($decoded['raw_feed_time'] ?? 0)),
        'effective_feed_time' => max(1, (int) ($decoded['effective_feed_time'] ?? $decoded['time'])),
        'open' => (float) $decoded['open'],
        'high' => (float) $decoded['high'],
        'low' => (float) $decoded['low'],
        'close' => (float) $decoded['close'],
        'volume' => (float) ($decoded['volume'] ?? 0),
        'updated_at' => max(1, (int) ($decoded['updated_at'] ?? time())),
        'ingest_received_at' => time(),
    ];

    // Та же коррекция оси времени, что FeedTimeNormalizer::normalizePartial() в Laravel.
    $correctionSec = (int) ($env['FEED_TIME_CORRECTION_SEC'] ?? 0);
    $data = ingest_current_light_normalize_partial($data, $correctionSec);

    ingest_current_light_put_partial($env, $instrument, $data, $ttlSec);

    return [
        'status' => 200,
        'body' => [
            'ok' => true,
            'instrument' => $instrument,
            'time' => (int) $data['time'],
            'raw_feed_time' => (int) $data['raw_feed_time'],
            'effective_feed_time' => (int) $data['effective_feed_time'],
            'is_partial' => true,
        ],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function ingest_current_light_validate(array $payload): ?string
{
    if (! isset($payload['instrument']) || ! is_string($payload['instrument']) || $payload['instrument'] === '') {
        return 'instrument is required.';
    }
    if (strlen($payload['instrument']) > 63) {
        return 'instrument must be at most 63 characters.';
    }
    if (! isset($payload['time']) || ! is_numeric($payload['time']) || (int) $payload['time'] < 1) {
        return 'time must be a positive integer.';
    }
    foreach (['open', 'high', 'low', 'close'] as $field) {
        if (! isset($payload[$field]) || ! is_numeric($payload[$field])) {
            return "{$field} must be numeric.";
        }
    }
    if (isset($payload['ttl_sec']) && (! is_numeric($payload['ttl_sec']) || (int) $payload['ttl_sec'] < 30 || (int) $payload['ttl_sec'] > 600)) {
        return 'ttl_sec must be between 30 and 600.';
    }

    return null;
}

/**
 * Минимальный парсер .env для Redis и FEED_TIME_CORRECTION_SEC.
 *
 * @return array<string, string>
 */
function ingest_current_light_load_env(string $envPath): array
{
    $values = [
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => '6379',
        'REDIS_PASSWORD' => '',
        'REDIS_PREFIX' => 'laravel-database-',
        'FEED_TIME_CORRECTION_SEC' => '0',
    ];

    if (! is_readable($envPath)) {
        return $values;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (array_key_exists($key, $values)) {
            $values[$key] = $value;
        }
    }

    if ($values['REDIS_PASSWORD'] === 'null') {
        $values['REDIS_PASSWORD'] = '';
    }

    if ($values['REDIS_PREFIX'] === '') {
        $values['REDIS_PREFIX'] = 'laravel-database-';
    }

    return $values;
}

/**
 * Дублирует App\Support\FeedTimeNormalizer::normalizePartial() без Laravel config().
 *
 * @param  array<string, mixed>  $partial
 * @return array<string, mixed>
 */
function ingest_current_light_normalize_partial(array $partial, int $correctionSec, int $barPeriodSec = INGEST_CURRENT_LIGHT_S5_BAR_PERIOD_SEC): array
{
    if ($correctionSec === 0) {
        return $partial;
    }

    $raw = (int) ($partial['raw_feed_time'] ?? 0);
    $effective = (int) ($partial['effective_feed_time'] ?? ($partial['time'] ?? 0));

    if ($raw <= 0) {
        return $partial;
    }

    $corrected = max(1, $raw + $correctionSec);
    $bucketed = (int) (floor($corrected / $barPeriodSec) * $barPeriodSec);

    // Ingest уже применил ту же коррекцию — не дублируем.
    if (abs($effective - $bucketed) <= $barPeriodSec * 2) {
        return $partial;
    }

    $partial['effective_feed_time'] = $bucketed;
    $partial['time'] = $bucketed;

    return $partial;
}

/**
 * @param  array<string, string>  $env
 * @param  array<string, mixed>  $candle
 */
function ingest_current_light_put_partial(array $env, string $instrument, array $candle, int $ttlSec): void
{
    $payload = [
        'instrument' => $instrument,
        'time' => (int) ($candle['time'] ?? 0),
        'raw_feed_time' => (int) ($candle['raw_feed_time'] ?? 0),
        'effective_feed_time' => (int) ($candle['effective_feed_time'] ?? ($candle['time'] ?? 0)),
        'open' => (float) ($candle['open'] ?? 0),
        'high' => (float) ($candle['high'] ?? 0),
        'low' => (float) ($candle['low'] ?? 0),
        'close' => (float) ($candle['close'] ?? 0),
        'volume' => (float) ($candle['volume'] ?? 0),
        'is_partial' => true,
        'updated_at' => (int) ($candle['updated_at'] ?? time()),
        'ingest_received_at' => (int) ($candle['ingest_received_at'] ?? time()),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    $redisKey = ($env['REDIS_PREFIX'] ?? 'laravel-database-').INGEST_CURRENT_LIGHT_KEY_PREFIX.$instrument;

    try {
        if (! class_exists(Redis::class)) {
            return;
        }

        $redis = new Redis;
        $host = $env['REDIS_HOST'] ?? '127.0.0.1';
        $port = (int) ($env['REDIS_PORT'] ?? 6379);
        if (! $redis->connect($host, $port, 1.0)) {
            return;
        }

        $password = $env['REDIS_PASSWORD'] ?? '';
        if ($password !== '') {
            $redis->auth($password);
        }

        $redis->setex($redisKey, $ttlSec, $json);
        // PR2: тот же payload в pub/sub (префикс Laravel Redis options.prefix).
        $channel = ($env['REDIS_PREFIX'] ?? 'laravel-database-').INGEST_CURRENT_LIGHT_PUB_CHANNEL;
        $redis->publish($channel, $json);
    } catch (Throwable) {
        // Как CurrentCandleCacheService — Redis может быть временно недоступен.
    }
}

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function ingest_current_light_error(int $status, string $code, string $message): array
{
    return [
        'status' => $status,
        'body' => [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ],
    ];
}

/**
 * @param  array{status: int, body: array<string, mixed>}  $result
 */
function ingest_current_light_send_response(array $result): void
{
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
}
