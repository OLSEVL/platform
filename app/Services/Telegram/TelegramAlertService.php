<?php

namespace App\Services\Telegram;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Services\Bots\Levels\StrongLevelCardPresenter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка алертов в Telegram Bot API.
 * Token — из config бота; получатели — telegram_chat_id + telegram_subscribers.
 */
class TelegramAlertService
{
    /**
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendMessage(string $token, string $chatId, string $text): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('telegram_send_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? null) : null;

        return [
            'ok' => true,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];
    }

    /**
     * Алерт о сильном тренде по результату стратегии strong_trend_telegram.
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $m1Candles
     * @return array{ok:bool,error?:string,message_id?:int,photo_ok?:bool,photo_error?:string}
     */
    public function sendTrendAlert(Bot $bot, string $instrument, array $decision, ?float $payout, array $m1Candles = []): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (! (bool) ($cfg['telegram_enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }

        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        $recipients = TelegramChatAccess::recipientChatIds($cfg);
        if ($token === '' || $recipients === []) {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        $trend = (string) ($decision['trend'] ?? '');
        if (! in_array($trend, ['up', 'down'], true)) {
            $side = (string) ($decision['side'] ?? '');
            $trend = $side === 'CALL' ? 'up' : ($side === 'PUT' ? 'down' : '');
        }
        if ($trend === '') {
            return ['ok' => false, 'error' => 'invalid_trend'];
        }

        $streakLen = (int) ($decision['features']['streak_len'] ?? ($cfg['streak_len'] ?? 6));
        $strictness = (string) ($decision['features']['candle_strictness'] ?? ($cfg['candle_strictness'] ?? 'strict'));
        $strictnessLabel = $strictness === 'with_doji' ? 'с доджи' : 'строго';

        $icon = $trend === 'up' ? '🔼' : '🔽';
        $trendLabel = $trend === 'up' ? 'восходящий' : 'нисходящий';
        $candleLabel = $trend === 'up' ? 'бычьих' : 'медвежьих';

        $lines = [
            "{$icon} <b>Сильный {$trendLabel} тренд</b>",
            '',
            'Пара: <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            'Таймфрейм: M1',
            "{$streakLen} {$candleLabel} свечей подряд ({$strictnessLabel})",
        ];
        // Если стратегия ждала контр-свечу — явно пишем, что вход в продолжение после отката.
        $waitOpposite = (bool) ($decision['features']['wait_opposite_then_continue']
            ?? ($cfg['wait_opposite_then_continue'] ?? false));
        if ($waitOpposite) {
            $counterLabel = $trend === 'up' ? 'медвежья' : 'бычья';
            $lines[] = "После серии — {$counterLabel} контр-свеча → вход в продолжение";
        }
        if ($payout !== null) {
            $lines[] = 'Payout: '.number_format($payout, 1, '.', '').'%';
        }
        $lines[] = 'Время: '.now()->utc()->format('d.m.Y H:i').' UTC';

        $text = implode("\n", $lines);

        // Как у Strong Level / CRC: сначала старший контекст M5, затем полный M1-график.
        $sendChart = (bool) ($cfg['telegram_send_chart'] ?? true);
        $sendChartM5 = (bool) ($cfg['telegram_send_chart_m5'] ?? true);
        $photoOk = null;
        $photoError = null;
        $m5PngPath = null;
        $m1PngPath = null;
        $captionBase = null;
        if ($sendChart && $m1Candles !== [] && extension_loaded('gd')) {
            $chartLevels = app(StrongTrendTelegramChartBuilder::class)
                ->chartLevelsFromDecision($decision, $m1Candles);
            $side = strtoupper((string) ($decision['side'] ?? ''));
            $captionBase = $icon.' <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
            if ($side !== '') {
                $captionBase .= ' · '.$side;
            }

            // 1) M5 из тех же M1 (5×1m → 5m) — без лишнего запроса к БД/сети.
            $m5Candles = $this->aggregateCandlesToTimeframe($m1Candles, 300);
            if ($sendChartM5 && count($m5Candles) >= 12) {
                $m5PngPath = app(TelegramChartImageRenderer::class)->render($instrument, $m5Candles, $chartLevels, [
                    'width' => 640,
                    'height' => 360,
                    'max_bars' => 60,
                    'margin_left' => 56,
                    'margin_top' => 32,
                    'margin_bottom' => 28,
                    'font_size_title' => 10,
                    'font_size_axis' => 8,
                    'font_size_label' => 8,
                    'timeframe_label' => 'M5',
                ]);
                if ($m5PngPath === null) {
                    $photoOk = false;
                    $photoError = 'm5:render_failed';
                    Log::warning('strong_trend_telegram_photo_m5_skipped', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'reason' => 'render_returned_null',
                    ]);
                }
            } elseif ($sendChartM5) {
                Log::info('strong_trend_telegram_photo_m5_skipped', [
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'reason' => 'not_enough_m1_bars_for_m5',
                    'm1_count' => count($m1Candles),
                ]);
            }

            // 2) Полный M1-график как у Strong Level: 900×520, до 90 свечей (не миниатюра).
            $m1PngPath = app(TelegramChartImageRenderer::class)->render($instrument, $m1Candles, $chartLevels, [
                'timeframe_label' => 'M1',
            ]);
            if ($m1PngPath === null) {
                $photoOk = $photoOk === null ? false : $photoOk;
                $photoError = $photoError !== null ? $photoError.';m1:render_failed' : 'm1:render_failed';
                Log::warning('strong_trend_telegram_photo_skipped', [
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'reason' => 'render_returned_null',
                ]);
            }
        } elseif ($sendChart && ! extension_loaded('gd')) {
            $photoOk = false;
            $photoError = 'gd_extension_missing';
        }

        $llmMarkup = StrongLevelTelegramLlmButton::inlineKeyboardMarkup($cfg, $bot, $instrument);
        // Poll нужен для LLM-кнопок и для /start автоподписки.
        if ($llmMarkup !== null || TelegramChatAccess::autoSubscribeEnabled($cfg)) {
            app(StrongLevelTelegramProcessManager::class)->ensurePollRunning($bot->id);
        }

        $messageResult = $this->broadcastToRecipients($token, $recipients, function (string $chatId) use (
            $token,
            $m5PngPath,
            $m1PngPath,
            $captionBase,
            $text,
            $llmMarkup,
            $bot,
            $instrument,
            &$photoOk,
            &$photoError,
        ): array {
            if ($m5PngPath !== null && $captionBase !== null) {
                $m5PhotoResult = $this->sendPhoto($token, $chatId, $m5PngPath, $captionBase.' · M5');
                $m5Ok = (bool) ($m5PhotoResult['ok'] ?? false);
                $photoOk = $photoOk === null ? $m5Ok : ($photoOk && $m5Ok);
                if (! $m5Ok) {
                    $m5Error = (string) ($m5PhotoResult['error'] ?? 'telegram_photo_failed');
                    $photoError = $photoError !== null ? $photoError.';m5:'.$m5Error : 'm5:'.$m5Error;
                    Log::warning('strong_trend_telegram_photo_m5_failed', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'chat_id' => $chatId,
                        'error' => $m5Error,
                    ]);
                }
            }
            if ($m1PngPath !== null && $captionBase !== null) {
                $photoResult = $this->sendPhoto($token, $chatId, $m1PngPath, $captionBase.' · M1');
                $m1Ok = (bool) ($photoResult['ok'] ?? false);
                $photoOk = $photoOk === null ? $m1Ok : ($photoOk && $m1Ok);
                if (! $m1Ok) {
                    $m1Error = (string) ($photoResult['error'] ?? 'telegram_photo_failed');
                    $photoError = $photoError !== null ? $photoError.';m1:'.$m1Error : 'm1:'.$m1Error;
                    Log::warning('strong_trend_telegram_photo_failed', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'chat_id' => $chatId,
                        'error' => $m1Error,
                    ]);
                }
            }

            return $llmMarkup !== null
                ? $this->sendMessageWithMarkup($token, $chatId, $text, $llmMarkup)
                : $this->sendMessage($token, $chatId, $text);
        });

        if ($m5PngPath !== null) {
            @unlink($m5PngPath);
        }
        if ($m1PngPath !== null) {
            @unlink($m1PngPath);
        }

        if (! ($messageResult['ok'] ?? false)) {
            return array_merge($messageResult, array_filter([
                'photo_ok' => $photoOk,
                'photo_error' => $photoError,
            ]));
        }

        return array_merge($messageResult, array_filter([
            'photo_ok' => $photoOk,
            'photo_error' => $photoError,
        ]));
    }

    /**
     * Уведомление о результате paper-сделки после settlement (WIN / LOSS / DRAW).
     * Включается флагом config.telegram_notify_result (по умолчанию true при telegram_enabled).
     *
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendTradeResultAlert(BotTrade $trade): array
    {
        $bot = $trade->bot;
        if ($bot === null) {
            return ['ok' => false, 'error' => 'bot_missing'];
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (! (bool) ($cfg['telegram_enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }
        // Отдельный тумблер результата; по умолчанию вкл., если Telegram уже включён.
        if (! (bool) ($cfg['telegram_notify_result'] ?? true)) {
            return ['ok' => false, 'error' => 'telegram_result_notify_disabled'];
        }
        // Strong Level и др.: если «не уведомлять» вообще — не шлём и результат.
        $notifyOn = (string) ($cfg['telegram_notify_on'] ?? 'signal_trade');
        if ($notifyOn === 'off') {
            return ['ok' => false, 'error' => 'telegram_notify_disabled'];
        }

        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        // Антидубль: settlement может ретраиться / вызываться повторно.
        if (! empty($meta['telegram_result_notified_at'])) {
            return ['ok' => false, 'error' => 'already_notified'];
        }

        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        $recipients = TelegramChatAccess::recipientChatIds($cfg);
        if ($token === '' || $recipients === []) {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        $result = strtolower((string) $trade->result);
        [$icon, $label] = match ($result) {
            BotTrade::RESULT_WIN => ['✅', 'WIN'],
            BotTrade::RESULT_LOSS => ['❌', 'LOSS'],
            BotTrade::RESULT_DRAW => ['➖', 'DRAW'],
            default => ['ℹ️', strtoupper($result !== '' ? $result : 'PENDING')],
        };

        $side = strtoupper((string) $trade->side);
        $instrument = (string) $trade->instrument;
        $entry = (float) $trade->entry_price;
        $expiry = $trade->expiry_price !== null ? (float) $trade->expiry_price : null;
        $pnl = $trade->pnl !== null ? (float) $trade->pnl : null;
        $payout = $trade->payout_snapshot !== null ? (float) $trade->payout_snapshot : null;

        $lines = [
            "{$icon} <b>{$label}</b> · ".htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')." · {$side}",
            '',
            'Бот: <b>'.htmlspecialchars((string) $bot->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            'Вход: '.$this->formatResultPrice($entry)
                .($expiry !== null ? ' → экспирация: '.$this->formatResultPrice($expiry) : ''),
        ];
        if ($pnl !== null) {
            $pnlText = ($pnl > 0 ? '+' : '').rtrim(rtrim(number_format($pnl, 4, '.', ''), '0'), '.');
            $lines[] = 'PnL (paper): <b>'.$pnlText.'</b>'
                .($payout !== null && $result === BotTrade::RESULT_WIN
                    ? ' (payout '.number_format($payout, 1, '.', '').'%)'
                    : '');
        }
        // Если зеркало на PO отдало свой итог — показываем рядом с paper.
        $poResult = isset($meta['mirror_po_result']) ? strtolower((string) $meta['mirror_po_result']) : '';
        if ($poResult !== '' && in_array($poResult, [BotTrade::RESULT_WIN, BotTrade::RESULT_LOSS, BotTrade::RESULT_DRAW], true)) {
            $poLabel = strtoupper($poResult);
            $lines[] = 'PO mirror: <b>'.$poLabel.'</b>';
        }
        if ($trade->expiry_time !== null) {
            $lines[] = 'Экспирация: '.$trade->expiry_time->utc()->format('d.m.Y H:i:s').' UTC';
        }
        $lines[] = 'Время: '.now()->utc()->format('d.m.Y H:i').' UTC';

        $text = implode("\n", $lines);

        $messageResult = $this->broadcastToRecipients($token, $recipients, function (string $chatId) use ($token, $text): array {
            return $this->sendMessage($token, $chatId, $text);
        });

        if ($messageResult['ok'] ?? false) {
            $meta['telegram_result_notified_at'] = now()->utc()->toIso8601String();
            $trade->update(['meta_json' => $meta]);
        }

        return $messageResult;
    }

    /**
     * Универсальный текстовый алерт для стратегий без «сильного уровня».
     * Его используют Sweep / Raid и Strong Trend + DeMarker: данные их сетапов
     * различаются, поэтому не подставляем в сообщение несуществующий level_price.
     * Для DeMarker при наличии свечей дополнительно шлётся PNG со свечами и панелью осциллятора.
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles
     * @return array{ok:bool,error?:string,message_id?:int,photo_ok?:bool,photo_error?:string}
     */
    public function sendStrategyAlert(
        Bot $bot,
        string $instrument,
        array $decision,
        ?float $payout,
        int $expirySec,
        ?float $entryPrice = null,
        array $closedCandles = [],
    ): array {
        /** @var array<string, mixed> $config */
        $config = is_array($bot->config) ? $bot->config : [];
        if (! (bool) ($config['telegram_enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }
        if ((string) ($config['telegram_notify_on'] ?? 'signal_trade') !== 'signal_trade') {
            return ['ok' => false, 'error' => 'telegram_notify_disabled'];
        }

        $token = trim((string) ($config['telegram_bot_token'] ?? ''));
        $recipients = TelegramChatAccess::recipientChatIds($config);
        if ($token === '' || $recipients === []) {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        // Poll getUpdates нужен, чтобы /start новых чатов попадал в telegram_subscribers.
        if (TelegramChatAccess::autoSubscribeEnabled($config)) {
            app(StrongLevelTelegramProcessManager::class)->ensurePollRunning($bot->id);
        }

        $side = strtoupper((string) ($decision['side'] ?? ''));
        $sideLabel = $side === 'CALL' ? 'CALL ▲' : ($side === 'PUT' ? 'PUT ▼' : $side);
        $reasons = $this->normalizeAlertReasons($decision['reasons_short'] ?? []);
        $timeframeLabel = (int) ($config['timeframe'] ?? 1) === 4030 ? 'S30' : 'M1';
        $lines = [
            '📣 <b>'.htmlspecialchars((string) $bot->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b> · '.$sideLabel,
            '',
            'Пара: <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            'Таймфрейм: '.$timeframeLabel.' · экспирация '.$expirySec.' сек',
        ];
        if ($entryPrice !== null) {
            $lines[] = 'Вход: '.$this->formatResultPrice($entryPrice);
        }
        if ($payout !== null) {
            $lines[] = 'Payout: '.number_format($payout, 1, '.', '').'%';
        }
        if ($reasons !== []) {
            $lines[] = '';
            $lines[] = '<b>Условия:</b>';
            foreach ($reasons as $reason) {
                $lines[] = '• '.htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        $lines[] = 'Время: '.now()->utc()->format('d.m.Y H:i').' UTC';

        $pngPath = null;
        $caption = null;
        $photoOk = null;
        $photoError = null;
        $sendChart = (bool) ($config['telegram_send_chart'] ?? true);
        if ($sendChart && $bot->type === 'strong_trend_demarker' && $closedCandles !== [] && extension_loaded('gd')) {
            $chartLevels = app(StrongTrendDemarkerChartBuilder::class)
                ->chartLevelsFromDecision($decision, $closedCandles);
            $pngPath = app(TelegramChartImageRenderer::class)->render($instrument, $closedCandles, $chartLevels, [
                'timeframe_label' => $timeframeLabel,
            ]);
            if ($pngPath === null) {
                $photoOk = false;
                $photoError = 'render_failed';
                Log::warning('strong_trend_demarker_photo_skipped', [
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'reason' => 'render_returned_null',
                ]);
            } else {
                $caption = '📣 <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
                if ($side !== '') {
                    $caption .= ' · '.$side;
                }
                $caption .= ' · '.$timeframeLabel;
            }
        } elseif ($sendChart && $bot->type === 'strong_trend_demarker' && ! extension_loaded('gd')) {
            $photoOk = false;
            $photoError = 'gd_extension_missing';
        }

        $messageResult = $this->broadcastToRecipients($token, $recipients, function (string $chatId) use (
            $token,
            $pngPath,
            $caption,
            $lines,
            $bot,
            $instrument,
            &$photoOk,
            &$photoError,
        ): array {
            if ($pngPath !== null && $caption !== null) {
                $photoResult = $this->sendPhoto($token, $chatId, $pngPath, $caption);
                $ok = (bool) ($photoResult['ok'] ?? false);
                $photoOk = $photoOk === null ? $ok : ($photoOk && $ok);
                if (! $ok) {
                    $err = (string) ($photoResult['error'] ?? 'telegram_photo_failed');
                    $photoError = $photoError !== null ? $photoError.';'.$err : $err;
                    Log::warning('strong_trend_demarker_photo_failed', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'chat_id' => $chatId,
                        'error' => $err,
                    ]);
                }
            }

            return $this->sendMessage($token, $chatId, implode("\n", $lines));
        });

        if ($pngPath !== null) {
            @unlink($pngPath);
        }

        if (! ($messageResult['ok'] ?? false)) {
            return array_merge($messageResult, array_filter([
                'photo_ok' => $photoOk,
                'photo_error' => $photoError,
            ]));
        }

        return array_merge($messageResult, array_filter([
            'photo_ok' => $photoOk,
            'photo_error' => $photoError,
        ]));
    }

    /** Цена для текста результата: до 5 знаков без лишних нулей. */
    private function formatResultPrice(float $price): string
    {
        return rtrim(rtrim(number_format($price, 5, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Алерт Strong Level: новый сигнал + paper-сделка у сильного уровня.
     *
     * @param  array<string, mixed>  $decision
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendStrongLevelAlert(
        Bot $bot,
        string $instrument,
        array $decision,
        ?float $payout,
        int $expirySec,
        ?float $entryPrice = null,
        array $m1Candles = [],
    ): array {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (! (bool) ($cfg['telegram_enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'telegram_disabled'];
        }

        // По умолчанию — уведомление при создании сигнала и сделки (не «только сигнал в БД»).
        $notifyOn = (string) ($cfg['telegram_notify_on'] ?? 'signal_trade');
        if ($notifyOn !== 'signal_trade') {
            return ['ok' => false, 'error' => 'telegram_notify_disabled'];
        }

        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        $recipients = TelegramChatAccess::recipientChatIds($cfg);
        if ($token === '' || $recipients === []) {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        $format = (string) ($cfg['telegram_alert_format'] ?? 'brief');
        if (! in_array($format, ['brief', 'detailed', 'card'], true)) {
            $format = 'brief';
        }

        $sendChart = (bool) ($cfg['telegram_send_chart'] ?? true);
        // Отдельный флаг для второго изображения M5 в Strong Level алерте.
        // Держим дефолт true, чтобы новое поведение включалось автоматически после релиза.
        $sendChartM5 = (bool) ($cfg['telegram_send_chart_m5'] ?? true);
        $photoOk = null;
        $photoError = null;
        $m5PngPath = null;
        $m1PngPath = null;
        $captionBase = null;

        // PNG: M1 + линия уровня + зона касания + маркер входа (как у Chart Analyst).
        // Рендерим один раз, затем шлём всем recipients.
        if ($sendChart && $m1Candles !== [] && extension_loaded('gd')) {
            $chartLevels = app(StrongLevelTelegramChartBuilder::class)
                ->chartLevelsFromDecision($decision, $entryPrice);
            $side = strtoupper((string) ($decision['side'] ?? ''));
            $captionBase = '📊 <b>'.htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
            if ($side !== '') {
                $captionBase .= ' · '.$side;
            }

            $m5Candles = $this->aggregateCandlesToTimeframe($m1Candles, 300);
            if ($sendChartM5 && count($m5Candles) >= 12) {
                $m5PngPath = app(TelegramChartImageRenderer::class)->render($instrument, $m5Candles, $chartLevels, [
                    'width' => 640,
                    'height' => 360,
                    'max_bars' => 60,
                    'margin_left' => 56,
                    'margin_top' => 32,
                    'margin_bottom' => 28,
                    'font_size_title' => 10,
                    'font_size_axis' => 8,
                    'font_size_label' => 8,
                    'timeframe_label' => 'M5',
                ]);
                if ($m5PngPath === null) {
                    $photoOk = $photoOk === null ? false : $photoOk;
                    $photoError = $photoError !== null ? $photoError.';m5:render_failed' : 'm5:render_failed';
                    Log::warning('strong_level_telegram_photo_m5_skipped', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'reason' => 'render_returned_null',
                    ]);
                }
            } elseif ($sendChartM5) {
                Log::info('strong_level_telegram_photo_m5_skipped', [
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'reason' => 'not_enough_m1_bars_for_m5',
                    'm1_count' => count($m1Candles),
                ]);
            }

            $m1PngPath = app(TelegramChartImageRenderer::class)->render($instrument, $m1Candles, $chartLevels, [
                'timeframe_label' => 'M1',
            ]);
            if ($m1PngPath === null) {
                $photoOk = $photoOk === null ? false : $photoOk;
                $photoError = $photoError !== null ? $photoError.';m1:render_failed' : 'm1:render_failed';
                Log::warning('strong_level_telegram_photo_m1_skipped', [
                    'bot_id' => $bot->id,
                    'instrument' => $instrument,
                    'reason' => 'render_returned_null',
                ]);
            }
        } elseif ($sendChart && ! extension_loaded('gd')) {
            $photoOk = false;
            $photoError = 'gd_extension_missing';
        }

        $text = $this->formatStrongLevelAlertText(
            $instrument,
            $decision,
            $payout,
            $expirySec,
            $entryPrice,
            $format,
            (string) $bot->type,
        );

        $llmMarkup = StrongLevelTelegramLlmButton::inlineKeyboardMarkup($cfg, $bot, $instrument);
        if ($llmMarkup !== null || TelegramChatAccess::autoSubscribeEnabled($cfg)) {
            app(StrongLevelTelegramProcessManager::class)->ensurePollRunning($bot->id);
        }

        $messageResult = $this->broadcastToRecipients($token, $recipients, function (string $chatId) use (
            $token,
            $m5PngPath,
            $m1PngPath,
            $captionBase,
            $text,
            $llmMarkup,
            $bot,
            $instrument,
            &$photoOk,
            &$photoError,
        ): array {
            if ($m5PngPath !== null && $captionBase !== null) {
                $m5PhotoResult = $this->sendPhoto($token, $chatId, $m5PngPath, $captionBase.' · M5');
                $m5Ok = (bool) ($m5PhotoResult['ok'] ?? false);
                $photoOk = $photoOk === null ? $m5Ok : ($photoOk && $m5Ok);
                if (! $m5Ok) {
                    $m5Error = (string) ($m5PhotoResult['error'] ?? 'telegram_photo_failed');
                    $photoError = $photoError !== null ? $photoError.';m5:'.$m5Error : 'm5:'.$m5Error;
                    Log::warning('strong_level_telegram_photo_m5_failed', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'chat_id' => $chatId,
                        'error' => $m5Error,
                    ]);
                }
            }
            if ($m1PngPath !== null && $captionBase !== null) {
                $photoResult = $this->sendPhoto($token, $chatId, $m1PngPath, $captionBase.' · M1');
                $m1Ok = (bool) ($photoResult['ok'] ?? false);
                $photoOk = $photoOk === null ? $m1Ok : ($photoOk && $m1Ok);
                if (! $m1Ok) {
                    $m1Error = (string) ($photoResult['error'] ?? 'telegram_photo_failed');
                    $photoError = $photoError !== null ? $photoError.';m1:'.$m1Error : 'm1:'.$m1Error;
                    Log::warning('strong_level_telegram_photo_m1_failed', [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'chat_id' => $chatId,
                        'error' => $m1Error,
                    ]);
                }
            }

            return $llmMarkup !== null
                ? $this->sendMessageWithMarkup($token, $chatId, $text, $llmMarkup)
                : $this->sendMessage($token, $chatId, $text);
        });

        if ($m5PngPath !== null) {
            @unlink($m5PngPath);
        }
        if ($m1PngPath !== null) {
            @unlink($m1PngPath);
        }

        if (! ($messageResult['ok'] ?? false)) {
            return array_merge($messageResult, array_filter([
                'photo_ok' => $photoOk,
                'photo_error' => $photoError,
            ]));
        }

        return array_merge($messageResult, array_filter([
            'photo_ok' => $photoOk,
            'photo_error' => $photoError,
        ]));
    }


    /**
     * HTML-текст алерта Strong Level / Candle Reversal (краткий, развёрнутый или карточка).
     *
     * @param  array<string, mixed>  $decision
     */
    public function formatStrongLevelAlertText(
        string $instrument,
        array $decision,
        ?float $payout,
        int $expirySec,
        ?float $entryPrice,
        string $format = 'brief',
        ?string $botType = null,
    ): string {
        if ($format === 'card') {
            return app(StrongLevelCardPresenter::class)->formatTelegramCardAlert(
                $instrument,
                $decision,
                $payout,
                $expirySec,
                $entryPrice,
                $botType,
            );
        }

        $presenter = app(LevelBounceAlertPresenter::class);
        $side = (string) ($decision['side'] ?? '');
        $sideLabel = $side === 'CALL' ? 'CALL ▲' : ($side === 'PUT' ? 'PUT ▼' : $side);
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $levelPrice = isset($features['level_price']) ? (float) $features['level_price'] : 0.0;
        $strength = isset($features['level_strength']) ? (float) $features['level_strength'] : 0.0;
        $regime = (string) ($features['entry_regime'] ?? 'bounce');
        $regimeLabel = $regime === 'breakout' ? 'пробой' : 'отскок';
        $confidence = isset($decision['confidence']) ? (float) $decision['confidence'] : 0.0;
        $h1Bias = (string) ($features['h1_bias'] ?? '');
        $reasons = $this->normalizeAlertReasons($decision['reasons_short'] ?? []);
        $isCrc = $presenter->isCrcDecision($decision, $botType);

        $instrumentEsc = htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $kindLabel = $presenter->levelKindRu((string) ($features['level_kind'] ?? 'neutral'));
        $expiryRu = $presenter->formatExpiryRu(max(1, $expirySec));

        $lines = [
            $presenter->alertBrandTitle($decision, $sideLabel, $botType),
            '',
            'Пара: <b>'.$instrumentEsc.'</b>',
        ];

        $levelLine = sprintf(
            'Уровень: <b>%.5f</b> (%s) | сила %.0f',
            $levelPrice,
            htmlspecialchars($kindLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $strength
        );
        $distanceLine = $presenter->formatDistanceLine($features, $entryPrice);
        if ($distanceLine !== null) {
            $levelLine .= ' · '.htmlspecialchars($distanceLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $lines[] = $levelLine;
        $lines[] = sprintf('Режим: %s | экспирация %s', $regimeLabel, $expiryRu);

        if ($payout !== null) {
            $lines[] = 'Payout: '.number_format($payout, 1, '.', '').'%';
        }

        if ($isCrc) {
            $lines[] = '';
            $lines[] = '<b>Подтверждение:</b>';
            foreach ($presenter->formatConfirmLines($features, $reasons) as $confirmLine) {
                $lines[] = '• '.htmlspecialchars($confirmLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        if ($format === 'detailed') {
            $lines[] = sprintf('Confidence: %.2f', $confidence);
            if ($entryPrice !== null) {
                $lines[] = sprintf('Вход: %.5f', $entryPrice);
            }
            if ($h1Bias !== '') {
                $h1Ru = $presenter->h1BiasRu($h1Bias);
                $lines[] = 'H1: '.($h1Ru !== '' ? $h1Ru : $h1Bias);
            }
            $sourcesHuman = $presenter->formatSources($features);
            if ($sourcesHuman !== '') {
                $lines[] = 'Источники: '.htmlspecialchars($sourcesHuman, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            if ($isCrc) {
                $narrative = $presenter->strategyNarrative($features, $side, $botType, $decision, $reasons);
                $lines[] = '';
                $lines[] = htmlspecialchars($narrative, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            if ($reasons !== []) {
                $lines[] = '';
                $lines[] = '<b>Причины:</b>';
                foreach ($reasons as $reason) {
                    $lines[] = '• '.htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
        }

        $lines[] = 'Время: '.now()->utc()->format('d.m.Y H:i').' UTC';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function normalizeAlertReasons(mixed $reasons): array
    {
        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($x) => is_string($x) ? $x : (is_scalar($x) ? (string) $x : ''),
            $reasons
        ), static fn ($s) => $s !== ''));
    }

    /**
     * Тестовое сообщение из настроек бота — всем recipients (primary + subscribers).
     */
    public function sendTestMessage(Bot $bot): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
        $recipients = TelegramChatAccess::recipientChatIds($cfg);

        $text = "✅ <b>Тест Telegram</b>\n\nБот: ".htmlspecialchars($bot->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ."\nПолучателей: ".count($recipients)
            ."\nВремя: ".now()->utc()->format('d.m.Y H:i').' UTC';

        return $this->broadcastToRecipients($token, $recipients, function (string $chatId) use ($token, $text): array {
            return $this->sendMessage($token, $chatId, $text);
        });
    }

    /**
     * Рассылка одного и того же действия по списку chat_id.
     * Успех, если хотя бы один получатель принял сообщение.
     *
     * @param  list<string>  $recipients
     * @param  callable(string):array{ok:bool,error?:string,message_id?:int}  $sendOne
     * @return array{ok:bool,error?:string,message_id?:int,recipients_count?:int,ok_count?:int}
     */
    public function broadcastToRecipients(string $token, array $recipients, callable $sendOne): array
    {
        $token = trim($token);
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $recipients
        ), static fn (string $id): bool => $id !== '')));

        if ($token === '' || $recipients === []) {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        $okCount = 0;
        $lastOk = ['ok' => false, 'error' => 'telegram_broadcast_all_failed'];
        foreach ($recipients as $chatId) {
            $result = $sendOne($chatId);
            if ($result['ok'] ?? false) {
                $okCount++;
                $lastOk = $result;
            } else {
                Log::warning('telegram_broadcast_recipient_failed', [
                    'chat_id' => $chatId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        }

        if ($okCount === 0) {
            return array_merge($lastOk, [
                'recipients_count' => count($recipients),
                'ok_count' => 0,
            ]);
        }

        return array_merge($lastOk, [
            'ok' => true,
            'recipients_count' => count($recipients),
            'ok_count' => $okCount,
        ]);
    }

    /**
     * Читает getUpdates и возвращает уникальные chat_id пользователей, написавших боту.
     * Перед запросом снимает webhook. При $waitSec > 0 ждёт новое сообщение (long poll).
     *
     * @return array{ok:bool,error?:string,chats?:list<array{id:string,title?:string,username?:string,type?:string,first_name?:string}>}
     */
    public function discoverChatIds(string $token, int $waitSec = 0): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        // Webhook блокирует getUpdates; poll-процессы тоже забирают очередь — вызывающий код должен их остановить.
        $this->deleteWebhook($token);

        $deadline = $waitSec > 0 ? time() + max(1, min(30, $waitSec)) : time();
        $chats = [];

        do {
            $timeout = $waitSec > 0 ? min(10, max(1, $deadline - time())) : 0;
            try {
                $response = Http::timeout(max(15, $timeout + 5))->get("https://api.telegram.org/bot{$token}/getUpdates", array_filter([
                    'limit' => 100,
                    'timeout' => $timeout,
                ]));
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }

            if (! $response->successful()) {
                $body = $response->json();
                $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

                return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
            }

            $payload = $response->json();
            $result = is_array($payload) ? ($payload['result'] ?? []) : [];
            if (! is_array($result)) {
                break;
            }

            foreach ($result as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $chat = $update['message']['chat']
                    ?? $update['edited_message']['chat']
                    ?? $update['callback_query']['message']['chat']
                    ?? null;
                if (! is_array($chat) || ! isset($chat['id'])) {
                    continue;
                }
                $id = (string) $chat['id'];
                $chats[$id] = [
                    'id' => $id,
                    'type' => isset($chat['type']) ? (string) $chat['type'] : null,
                    'title' => isset($chat['title']) ? (string) $chat['title'] : null,
                    'username' => isset($chat['username']) ? (string) $chat['username'] : null,
                    'first_name' => isset($chat['first_name']) ? (string) $chat['first_name'] : null,
                ];
            }

            if ($chats !== [] || $waitSec <= 0) {
                break;
            }
        } while (time() < $deadline);

        return ['ok' => true, 'chats' => array_values($chats)];
    }

    /**
     * Long polling: getUpdates с timeout (Telegram держит соединение до N секунд).
     *
     * @return array{ok:bool,error?:string,updates?:list<array<string,mixed>>}
     */
    public function getUpdates(string $token, int $offset, int $timeoutSec = 30): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        try {
            $response = Http::timeout(max(35, $timeoutSec + 5))->get("https://api.telegram.org/bot{$token}/getUpdates", [
                'offset' => max(0, $offset),
                'timeout' => max(0, min(50, $timeoutSec)),
                'allowed_updates' => json_encode(['message', 'callback_query']),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $result = is_array($payload) ? ($payload['result'] ?? []) : [];

        return [
            'ok' => true,
            'updates' => is_array($result) ? $result : [],
        ];
    }

    /**
     * Перед long polling нужно снять webhook, иначе getUpdates не вернёт апдейты.
     *
     * @return array{ok:bool,error?:string}
     */
    public function deleteWebhook(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/deleteWebhook");
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $replyMarkup  reply_markup (inline_keyboard и т.д.)
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendMessageWithMarkup(string $token, string $chatId, string $text, array $replyMarkup): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['ok' => false, 'error' => 'telegram_token_or_chat_id_missing'];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode($replyMarkup),
            ]);
        } catch (\Throwable $e) {
            Log::warning('telegram_send_markup_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? null) : null;

        return [
            'ok' => true,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];
    }

    /**
     * @return array{ok:bool,error?:string,message_id?:int}
     */
    public function sendPhoto(string $token, string $chatId, string $filePath, ?string $caption = null): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '' || ! is_readable($filePath)) {
            return ['ok' => false, 'error' => 'telegram_photo_invalid'];
        }

        try {
            $response = Http::timeout(30)
                ->attach('photo', fopen($filePath, 'r'), basename($filePath))
                ->post("https://api.telegram.org/bot{$token}/sendPhoto", array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => $caption !== null ? 'HTML' : null,
                ]));
        } catch (\Throwable $e) {
            Log::warning('telegram_send_photo_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        $payload = $response->json();
        $messageId = is_array($payload) ? ($payload['result']['message_id'] ?? null) : null;

        return [
            'ok' => true,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ];
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function answerCallbackQuery(string $token, string $callbackQueryId, ?string $text = null): array
    {
        $token = trim($token);
        if ($token === '' || $callbackQueryId === '') {
            return ['ok' => false, 'error' => 'invalid_callback'];
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
                'show_alert' => false,
            ]));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $desc = is_array($body) ? (string) ($body['description'] ?? $response->body()) : $response->body();

            return ['ok' => false, 'error' => $desc !== '' ? $desc : 'telegram_http_'.$response->status()];
        }

        return ['ok' => true];
    }

    /**
     * Telegram лимит 4096 символов — режем длинный анализ на несколько сообщений.
     *
     * @return list<array{ok:bool,error?:string}>
     */
    public function sendLongMessage(string $token, string $chatId, string $text): array
    {
        $chunks = $this->splitTelegramText($text, 3900);
        if ($chunks === []) {
            return [['ok' => false, 'error' => 'empty_text']];
        }

        $results = [];
        foreach ($chunks as $chunk) {
            $results[] = $this->sendMessage($token, $chatId, $chunk);
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function splitTelegramText(string $text, int $maxLen = 3900): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $maxLen) {
            return [$text];
        }

        $parts = [];
        $remaining = $text;
        while (mb_strlen($remaining) > $maxLen) {
            $slice = mb_substr($remaining, 0, $maxLen);
            $breakAt = mb_strrpos($slice, "\n\n");
            if ($breakAt === false || $breakAt < (int) ($maxLen * 0.5)) {
                $breakAt = mb_strrpos($slice, "\n");
            }
            if ($breakAt === false || $breakAt < (int) ($maxLen * 0.5)) {
                $breakAt = $maxLen;
            }
            $parts[] = trim(mb_substr($remaining, 0, $breakAt));
            $remaining = trim(mb_substr($remaining, $breakAt));
        }
        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }

    /**
     * Агрегирует свечи младшего ТФ в старший.
     *
     * Пример: 1m + periodSec=300 -> 5m свечи.
     * Ожидается формат входа как у CandleService/renderer:
     * ['time','open','high','low','close','volume'].
     *
     * @param  list<array<string,mixed>>  $candles
     * @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function aggregateCandlesToTimeframe(array $candles, int $periodSec): array
    {
        if ($candles === [] || $periodSec <= 1) {
            return [];
        }

        $buckets = [];
        foreach ($candles as $row) {
            if (! is_array($row) || ! isset($row['time'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                continue;
            }
            $time = (int) $row['time'];
            if ($time <= 0) {
                continue;
            }
            $bucketTs = intdiv($time, $periodSec) * $periodSec;
            $open = (float) $row['open'];
            $high = (float) $row['high'];
            $low = (float) $row['low'];
            $close = (float) $row['close'];
            $volume = isset($row['volume']) ? (float) $row['volume'] : 0.0;

            if (! isset($buckets[$bucketTs])) {
                $buckets[$bucketTs] = [
                    'time' => $bucketTs,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => $volume,
                ];
                continue;
            }

            $buckets[$bucketTs]['high'] = max((float) $buckets[$bucketTs]['high'], $high);
            $buckets[$bucketTs]['low'] = min((float) $buckets[$bucketTs]['low'], $low);
            // Последний close в пределах бакета становится close агрегированной свечи.
            $buckets[$bucketTs]['close'] = $close;
            $buckets[$bucketTs]['volume'] = (float) $buckets[$bucketTs]['volume'] + $volume;
        }

        if ($buckets === []) {
            return [];
        }

        ksort($buckets);

        /** @var list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> $out */
        $out = array_values($buckets);

        return $out;
    }

    /**
     * Текстовый список уровней для сообщения после анализа.
     *
     * @param  array{levels?:list<array<string,mixed>>,zones?:list<array<string,mixed>>,entry?:?array<string,mixed>}  $chartLevels
     */
    public function formatChartLevelsText(array $chartLevels): string
    {
        $lines = ['📍 <b>Уровни</b>'];
        $levels = is_array($chartLevels['levels'] ?? null) ? $chartLevels['levels'] : [];
        foreach ($levels as $lvl) {
            if (! is_array($lvl) || ! isset($lvl['price'])) {
                continue;
            }
            $label = htmlspecialchars((string) ($lvl['label'] ?? $lvl['kind'] ?? 'level'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tf = isset($lvl['timeframe']) ? (string) $lvl['timeframe'].' ' : '';
            $lines[] = '• '.$tf.$label.': <code>'.htmlspecialchars((string) $lvl['price'], ENT_QUOTES).'</code>';
        }
        $zones = is_array($chartLevels['zones'] ?? null) ? $chartLevels['zones'] : [];
        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $low = $zone['price_low'] ?? $zone['low'] ?? null;
            $high = $zone['price_high'] ?? $zone['high'] ?? null;
            if ($low === null || $high === null) {
                continue;
            }
            $label = htmlspecialchars((string) ($zone['label'] ?? 'zone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = '• '.$label.': <code>'.$low.' – '.$high.'</code>';
        }
        $entry = is_array($chartLevels['entry'] ?? null) ? $chartLevels['entry'] : null;
        if ($entry && isset($entry['price'])) {
            $side = htmlspecialchars((string) ($entry['side'] ?? 'ENTRY'), ENT_QUOTES);
            $lines[] = '• Entry '.$side.': <code>'.htmlspecialchars((string) $entry['price'], ENT_QUOTES).'</code>';
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Token/chat_id из config бота-аналитика с Telegram.
     *
     * @return array{token:string,chat_id:string,enabled:bool}
     */
    public function analystTelegramCredentials(Bot $bot): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];

        return [
            'token' => trim((string) ($cfg['telegram_bot_token'] ?? '')),
            'chat_id' => trim((string) ($cfg['telegram_chat_id'] ?? '')),
            'enabled' => (bool) ($cfg['telegram_enabled'] ?? false),
        ];
    }
}
