<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pocket_options_proxy' => [
        'url' => env('POCKET_OPTIONS_PROXY_URL', 'http://127.0.0.1:3000'),
    ],

    // Сдвиг timestamp от PO feed (сек). -7200 если feed стабильно на 2 ч впереди UTC сервера.
    // Дублирует FEED_TIME_CORRECTION_SEC в ingest; PHP применяет, если ingest без env.
    'feed' => [
        'feed_time_correction_sec' => (int) env('FEED_TIME_CORRECTION_SEC', 0),
        'max_skew_sec' => (int) env('FEED_TIME_MAX_SKEW_SEC', 300),
    ],

    // sync = зеркало сразу после paper-сделки; queue = только через Laravel jobs (медленнее).
    'mirror' => [
        'dispatch' => env('MIRROR_DISPATCH', 'sync'),
    ],

    'webshare' => [
        'api_key' => env('WEBSHARE_API_KEY'),
    ],

    // Настройки OpenRouter для LLM-ботов.
    // Ключ хранится только в .env, а в коде читается через config('services.openrouter.*').
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'referer' => env('OPENROUTER_HTTP_REFERER', ''),
        'title' => env('OPENROUTER_APP_TITLE', 'Platform Bot'),
        // Дефолт HTTP-таймаут, если в config бота не задан openrouter_timeout_sec.
        'timeout_sec' => (int) env('OPENROUTER_TIMEOUT_SEC', 120),
        // Верхняя граница таймаута из настроек бота (сек).
        'timeout_sec_max' => (int) env('OPENROUTER_TIMEOUT_SEC_MAX', 600),
    ],

];
