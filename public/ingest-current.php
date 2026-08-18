<?php

declare(strict_types=1);

/**
 * Лёгкий endpoint partial-свечи (без Laravel bootstrap).
 *
 * Nginx location = /api/ingest-current-candle направляет сюда вместо public/index.php.
 * Python proxy (ingest_stream.py) продолжает POST на тот же URL — меняется только обработчик.
 */

require dirname(__DIR__).'/bootstrap/ingest-current-light.php';

ingest_current_light_send_response(ingest_current_light_handle_request());
