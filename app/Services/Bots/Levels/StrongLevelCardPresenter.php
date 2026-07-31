<?php

namespace App\Services\Bots\Levels;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Services\CandleService;
use App\Services\Telegram\LevelBounceAlertPresenter;
use App\Services\Telegram\StrongLevelTelegramChartBuilder;
use App\Services\Telegram\TelegramChartImageRenderer;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Тексты и поля карточек Strong Level для страницы /bots/{bot}/cards.
 * Без LLM: заголовки и абзацы собираются из features_json / reasons_json сигнала.
 */
class StrongLevelCardPresenter
{
    /** Кэш PNG на диске: рендер GD ~3–4 с, без кэша img-запросы падают по таймауту. */
    private const CHART_CACHE_TTL_SEC = 45;

    /** @var array<string, string> */
    private const SOURCE_LABELS = [
        'h1_swing' => 'H1',
        'm15_swing' => 'M15',
        'mxwll_1d' => '1D',
        'mxwll_4h' => '4H',
        'pa_dgt_sr' => 'DGT',
        'vp_poc' => 'POC',
        'vp_vah' => 'VAH',
        'vp_val' => 'VAL',
        'rs_sr' => 'RS',
        'three_br_sr' => '3BR',
    ];

    public function __construct(
        private readonly CandleService $candleService,
        private readonly StrongLevelTelegramChartBuilder $chartBuilder,
        private readonly TelegramChartImageRenderer $chartRenderer,
        private readonly LevelBounceAlertPresenter $alertPresenter,
    ) {}

    /**
     * Поля одной открытой сделки для JSON poll и модалки.
     *
     * @return array<string, mixed>
     */
    public function cardPayload(
        BotTrade $trade,
        ?float $currentPrice,
        ?float $currentPayout,
        Carbon $now,
    ): array {
        $sig = $trade->relationLoaded('signal') ? $trade->signal : $trade->signal()->first();
        $features = is_array($sig?->features_json) ? $sig->features_json : [];
        $reasons = $this->normalizeReasons($sig);
        $bot = $trade->relationLoaded('bot') ? $trade->bot : $trade->bot()->first();
        $botType = $bot !== null ? (string) $bot->type : null;
        $decision = $this->decisionFromSignal($sig);

        $side = strtoupper((string) $trade->side);
        $sideRu = $this->sideLabelRu($side);
        $isUp = $side === 'CALL';

        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        $expirySec = max(1, (int) ($meta['expiry_sec_used'] ?? 180));
        $entryTs = optional($trade->entry_time)?->timestamp;
        $expiryTs = optional($trade->expiry_time)?->timestamp;
        $createdTs = optional($trade->created_at)?->timestamp ?? $entryTs;
        $activeSec = $createdTs !== null ? max(0, $now->timestamp - $createdTs) : 0;
        $expiryLeftSec = $expiryTs !== null ? max(0, $expiryTs - $now->timestamp) : 0;

        $entryPrice = $trade->entry_price !== null ? (float) $trade->entry_price : null;
        $confidence = $sig !== null ? (float) $sig->confidence : null;

        $strategyTitle = $this->strategyTitle($features, $botType, $decision);
        $strategyTeaser = $this->strategyTeaser($features, $reasons);
        $strategyNarrative = $this->strategyNarrative($features, $side, $botType, $decision, $reasons);
        $technicalDetails = $this->technicalDetails($features, $reasons, $botType, $decision);

        $totalSec = ($entryTs !== null && $expiryTs !== null && $expiryTs > $entryTs)
            ? ($expiryTs - $entryTs)
            : $expirySec;
        $elapsedSec = $totalSec > 0 ? min($totalSec, max(0, $totalSec - $expiryLeftSec)) : 0;
        $progressPct = $totalSec > 0 ? (int) round(($elapsedSec / $totalSec) * 100) : 0;

        return [
            'id' => $trade->id,
            'instrument' => $trade->instrument,
            'side' => $side,
            'side_ru' => $sideRu,
            'is_up' => $isUp,
            'timeframe' => '1м',
            'expiry_sec' => $expirySec,
            'expiry_label' => $this->formatDurationRu($expirySec),
            'entry_price' => $entryPrice !== null ? $this->formatPrice($entryPrice) : null,
            'entry_price_raw' => $entryPrice,
            'current_price' => $currentPrice !== null ? $this->formatPrice($currentPrice) : null,
            'current_price_raw' => $currentPrice,
            'payout' => $currentPayout !== null ? round($currentPayout, 1) : null,
            'payout_label' => $currentPayout !== null ? number_format($currentPayout, 0, '.', '').'%' : '—',
            'confidence' => $confidence,
            'confidence_fmt' => $confidence !== null ? number_format($confidence, 2, '.', '') : '—',
            'active_sec' => $activeSec,
            'active_label' => $this->formatSecondsAgo($activeSec),
            'expiry_left_sec' => $expiryLeftSec,
            'expiry_left_label' => $this->formatCountdown($expiryLeftSec),
            'progress_pct' => $progressPct,
            'strategy_title' => $strategyTitle,
            'strategy_teaser' => $strategyTeaser,
            'strategy_narrative' => $strategyNarrative,
            'technical_details' => $technicalDetails,
            'reasons_short' => $reasons,
            'entry_ts' => $entryTs,
            'expiry_ts' => $expiryTs,
            'created_ts' => $createdTs,
            'status' => 'active',
            'status_label' => '✅ Активна',
        ];
    }

    /**
     * PNG-график сделки (уровень + вход) для карточек и модалки.
     *
     * @param  1|5|15  $timeframeMinutes
     * @param  'full'|'thumb'  $size
     */
    public function renderChartPng(BotTrade $trade, int $timeframeMinutes = 1, string $size = 'full'): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        if (! in_array($timeframeMinutes, [1, 15], true)) {
            $timeframeMinutes = 1;
        }
        if (! in_array($size, ['full', 'thumb'], true)) {
            $size = 'full';
        }

        $sig = $trade->relationLoaded('signal') ? $trade->signal : $trade->signal()->first();
        if ($sig === null) {
            return null;
        }

        $decision = $this->decisionFromSignal($sig);
        $entryPrice = $trade->entry_price !== null ? (float) $trade->entry_price : null;

        $candleLimit = $timeframeMinutes === 15 ? 80 : 120;
        $series = $this->candleService->getCandles((string) $trade->instrument, $timeframeMinutes, $candleLimit);
        $candles = is_array($series['candles'] ?? null) ? $series['candles'] : [];
        if ($candles === []) {
            return null;
        }

        $chartLevels = $this->chartBuilder->chartLevelsFromDecision($decision, $entryPrice);
        $tfLabel = $timeframeMinutes === 1 ? 'M1' : 'M15';

        $options = $size === 'thumb'
            ? [
                'width' => 420,
                'height' => 230,
                'margin_left' => 48,
                'margin_right' => 8,
                'margin_top' => 26,
                'margin_bottom' => 18,
                'max_bars' => 55,
                'font_size_title' => 8,
                'font_size_axis' => 7,
                'font_size_label' => 7,
                'timeframe_label' => $tfLabel,
            ]
            : [
                'max_bars' => $timeframeMinutes === 15 ? 70 : 90,
                'timeframe_label' => $tfLabel,
            ];

        return $this->chartRenderer->render((string) $trade->instrument, $candles, $chartLevels, $options);
    }

    /**
     * Бинарный PNG с файловым кэшем (для endpoint и data URI в JSON).
     */
    public function chartPngContents(BotTrade $trade, int $timeframeMinutes = 1, string $size = 'full'): ?string
    {
        if (! in_array($timeframeMinutes, [1, 15], true)) {
            $timeframeMinutes = 1;
        }
        if (! in_array($size, ['full', 'thumb'], true)) {
            $size = 'full';
        }

        $cachePath = $this->chartCachePath($trade->id, $timeframeMinutes, $size);
        if (is_readable($cachePath) && (time() - (int) filemtime($cachePath)) < self::CHART_CACHE_TTL_SEC) {
            $cached = file_get_contents($cachePath);

            return $cached !== false && $cached !== '' ? $cached : null;
        }

        $tmpPath = $this->renderChartPng($trade, $timeframeMinutes, $size);
        if ($tmpPath === null || ! is_readable($tmpPath)) {
            return null;
        }

        $contents = file_get_contents($tmpPath);
        @unlink($tmpPath);
        if ($contents === false || $contents === '') {
            return null;
        }

        File::ensureDirectoryExists(dirname($cachePath));
        if (! is_writable(dirname($cachePath))) {
            @chmod(dirname($cachePath), 0777);
        }
        if (@file_put_contents($cachePath, $contents) === false) {
            // Кэш недоступен (права) — отдаём PNG без записи на диск.
            return $contents;
        }

        return $contents;
    }

    /**
     * Миниатюра M1 как data URI — встраивается в JSON poll, без отдельного img HTTP-запроса.
     */
    public function chartThumbDataUri(BotTrade $trade): ?string
    {
        $bytes = $this->chartPngContents($trade, 1, 'thumb');
        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function chartCachePath(int $tradeId, int $timeframeMinutes, string $size): string
    {
        return storage_path(sprintf('app/card-charts/%d_%d_%s.png', $tradeId, $timeframeMinutes, $size));
    }

    /**
     * URL-ы PNG-графиков для карточки (миниатюра M1 + полные M1/M15 в модалке).
     *
     * @return array{chart_thumb_url:string,chart_m1_url:string,chart_m15_url:string,chart_url:string}
     */
    public function chartUrlsForTrade(Bot $bot, BotTrade $trade): array
    {
        // Относительные пути: img-теги должны идти на тот же origin, что и страница (иначе нет cookie сессии).
        $base = route('bots.cards.chart', ['bot' => $bot->id, 'trade' => $trade->id], absolute: false);

        return [
            'chart_thumb_url' => $base.'?tf=1&size=thumb',
            'chart_m1_url' => $base.'?tf=1',
            'chart_m15_url' => $base.'?tf=15',
            'chart_url' => $base.'?tf=1',
        ];
    }

    /**
     * Последняя цена по M1 (close последней свечи).
     */
    public function currentPriceForInstrument(string $instrument): ?float
    {
        $series = $this->candleService->getCandles($instrument, 1, 3);
        $candles = is_array($series['candles'] ?? null) ? $series['candles'] : [];
        if ($candles === []) {
            return null;
        }
        $last = end($candles);
        if (! is_array($last)) {
            return null;
        }
        $close = $last['close'] ?? null;

        return is_numeric($close) ? (float) $close : null;
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $decision
     */
    public function strategyTitle(array $features, ?string $botType = null, array $decision = []): string
    {
        return $this->alertPresenter->strategyTitle($features, $botType, $decision);
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     */
    public function strategyTeaser(array $features, array $reasons): string
    {
        if ($reasons !== []) {
            return implode(' · ', array_slice($reasons, 0, 2));
        }

        $levelPrice = isset($features['level_price']) ? (float) $features['level_price'] : 0.0;
        $kind = $this->levelKindRu((string) ($features['level_kind'] ?? 'neutral'));

        if ($levelPrice > 0) {
            return sprintf('%s %.5f', $kind, $levelPrice);
        }

        return $this->strategyTitle($features, null, []);
    }

    /**
     * Связный абзац для модалки (шаблон без LLM).
     *
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $decision
     */
    public function strategyNarrative(
        array $features,
        string $side,
        ?string $botType = null,
        array $decision = [],
        array $reasons = [],
    ): string {
        return $this->alertPresenter->strategyNarrative($features, $side, $botType, $decision, $reasons);
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $decision
     */
    public function technicalDetails(
        array $features,
        array $reasons,
        ?string $botType = null,
        array $decision = [],
    ): string {
        return $this->alertPresenter->technicalDetails($features, $reasons, $botType, $decision);
    }

    /**
     * HTML-текст Telegram в формате карточки (тот же шаблон анализа, что на /bots/{bot}/cards).
     *
     * @param  array<string, mixed>  $decision
     */
    public function formatTelegramCardAlert(
        string $instrument,
        array $decision,
        ?float $payout,
        int $expirySec,
        ?float $entryPrice,
        ?string $botType = null,
    ): string {
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $sideRu = $this->sideLabelRu($side);
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $reasons = $this->normalizeReasonsList($decision['reasons_short'] ?? []);
        $confidence = isset($decision['confidence']) ? (float) $decision['confidence'] : null;
        $isCrc = $this->alertPresenter->isCrcDecision($decision, $botType);
        $isRsi = $this->alertPresenter->isRsiContrtrendDecision($decision, $botType);
        $isBb = $this->alertPresenter->isBollingerContrtrendDecision($decision, $botType);

        $instrumentEsc = htmlspecialchars($instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sideEsc = htmlspecialchars($sideRu, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiryLabel = htmlspecialchars($this->alertPresenter->formatExpiryRu(max(1, $expirySec)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $strategyTitle = htmlspecialchars($this->strategyTitle($features, $botType, $decision), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $narrative = htmlspecialchars($this->strategyNarrative($features, $side, $botType, $decision, $reasons), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $technical = htmlspecialchars($this->technicalDetails($features, $reasons, $botType, $decision), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $headerEmoji = $isBb ? '📊' : ($isRsi ? '📉' : ($isCrc ? '🔄' : '🎯'));

        $lines = [
            $headerEmoji.' <b>'.$instrumentEsc.' · '.$sideEsc.' · '.$expiryLabel.' · 1м</b>',
            '',
            '<b>'.$strategyTitle.'</b>',
            $narrative,
        ];

        if ($isCrc) {
            $lines[] = '';
            $lines[] = '<b>Подтверждение</b>';
            foreach ($this->alertPresenter->formatConfirmLines($features, $reasons) as $confirmLine) {
                $lines[] = '• '.htmlspecialchars($confirmLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $lines[] = '';
        $lines[] = '<b>Статистика сигнала</b>';
        $lines[] = 'Выплата: <b>'.($payout !== null ? htmlspecialchars(number_format($payout, 0, '.', '').'%', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—').'</b>';
        $lines[] = 'Уверенность: <b>'.($confidence !== null ? htmlspecialchars(number_format($confidence, 2, '.', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—').'</b>';

        if ($entryPrice !== null) {
            $lines[] = 'Вход: <b>'.htmlspecialchars($this->formatPrice($entryPrice), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
        }

        $lines[] = 'Таймфрейм: <b>1м</b>';
        $lines[] = '';
        $lines[] = '<i>'.$technical.'</i>';
        $lines[] = '';
        $lines[] = 'Время: '.now()->utc()->format('d.m.Y H:i').' UTC';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function normalizeReasonsList(mixed $reasons): array
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
     * @return list<string>
     */
    private function normalizeReasons(?BotSignal $sig): array
    {
        return $this->normalizeReasonsList($sig?->reasons_json);
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionFromSignal(?BotSignal $sig): array
    {
        if ($sig === null) {
            return [];
        }

        return [
            'side' => $sig->side,
            'confidence' => $sig->confidence,
            'reasons_short' => is_array($sig->reasons_json) ? $sig->reasons_json : [],
            'features' => is_array($sig->features_json) ? $sig->features_json : [],
            'decision_version' => (string) ($sig->decision_version ?? ''),
        ];
    }

    private function sideLabelRu(string $side): string
    {
        return match (strtoupper($side)) {
            'CALL' => 'ВВЕРХ',
            'PUT' => 'ВНИЗ',
            default => $side !== '' ? $side : '—',
        };
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function formatSources(array $features): string
    {
        $sources = is_array($features['level_sources'] ?? null) ? $features['level_sources'] : [];
        $labels = [];
        foreach ($sources as $source) {
            $key = (string) $source;
            $labels[] = self::SOURCE_LABELS[$key] ?? $key;
        }
        $labels = array_values(array_unique($labels));

        return implode('+', array_slice($labels, 0, 5));
    }

    private function levelKindRu(string $kind): string
    {
        return match ($kind) {
            'support' => 'поддержки',
            'resistance' => 'сопротивления',
            default => 'уровня',
        };
    }

    private function h1BiasRu(string $bias): string
    {
        return match ($bias) {
            'bull', 'up', 'long' => 'восходящий',
            'bear', 'down', 'short' => 'нисходящий',
            'flat', 'range' => 'боковой',
            default => $bias !== '' ? $bias : '',
        };
    }

    private function touchesLabel(int $touches): string
    {
        $n = abs($touches) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $touches.' касаний';
        }
        if ($n1 === 1) {
            return $touches.' касание';
        }
        if ($n1 >= 2 && $n1 <= 4) {
            return $touches.' касания';
        }

        return $touches.' касаний';
    }

    private function formatDurationRu(int $sec): string
    {
        if ($sec >= 3600 && $sec % 3600 === 0) {
            return (int) ($sec / 3600).'ч';
        }
        if ($sec >= 60) {
            $m = (int) round($sec / 60);

            return $m.'м';
        }

        return $sec.'с';
    }

    private function formatSecondsAgo(int $sec): string
    {
        if ($sec < 60) {
            return $sec.'с назад';
        }
        $m = (int) floor($sec / 60);
        if ($m < 60) {
            return $m.'м назад';
        }

        return (int) floor($m / 60).'ч назад';
    }

    private function formatCountdown(int $sec): string
    {
        $sec = max(0, $sec);
        $m = (int) floor($sec / 60);
        $s = $sec % 60;

        return sprintf('%02d:%02d', $m, $s);
    }

    private function formatPrice(float $price): string
    {
        if ($price >= 1000) {
            return number_format($price, 2, '.', '');
        }
        if ($price >= 10) {
            return number_format($price, 4, '.', '');
        }

        return number_format($price, 5, '.', '');
    }
}
