<?php

namespace App\Services\Telegram;

/**
 * Тексты Telegram-алертов и карточек для ботов отбития от уровня
 * (Strong Level и Candle Reversal + Confirm).
 */
class LevelBounceAlertPresenter
{
    /** @var array<string, string> */
    public const SOURCE_LABELS = [
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

    /**
     * CRC v3 или явный тип бота candle_reversal_confirm.
     *
     * @param  array<string, mixed>  $decision
     */
    public function isCrcDecision(array $decision, ?string $botType = null): bool
    {
        if ($botType === 'candle_reversal_confirm') {
            return true;
        }

        $version = (string) ($decision['decision_version'] ?? '');

        return str_starts_with($version, 'candle_reversal_confirm');
    }

    /**
     * RSI Contrtrend (OsEngine → CALL/PUT).
     *
     * @param  array<string, mixed>  $decision
     */
    public function isRsiContrtrendDecision(array $decision, ?string $botType = null): bool
    {
        if ($botType === 'rsi_contrtrend') {
            return true;
        }

        $version = (string) ($decision['decision_version'] ?? '');

        return str_starts_with($version, 'rsi_contrtrend');
    }

    /**
     * Bollinger Contrtrend (OsEngine StrategyBollinger → CALL/PUT).
     *
     * @param  array<string, mixed>  $decision
     */
    public function isBollingerContrtrendDecision(array $decision, ?string $botType = null): bool
    {
        if ($botType === 'bollinger_contrtrend') {
            return true;
        }

        $version = (string) ($decision['decision_version'] ?? '');

        return str_starts_with($version, 'bollinger_contrtrend');
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public function alertBrandTitle(array $decision, string $sideLabel, ?string $botType = null): string
    {
        if ($botType === 'bollinger_contrtrend' || str_starts_with((string) ($decision['decision_version'] ?? ''), 'bollinger_contrtrend')) {
            return '📊 <b>Bollinger Contrtrend — '.htmlspecialchars($sideLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
        }

        if ($botType === 'rsi_contrtrend' || str_starts_with((string) ($decision['decision_version'] ?? ''), 'rsi_contrtrend')) {
            return '📉 <b>RSI Contrtrend — '.htmlspecialchars($sideLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
        }

        if ($this->isCrcDecision($decision, $botType)) {
            return '🔄 <b>Candle Reversal + Confirm — '.htmlspecialchars($sideLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
        }

        return '🎯 <b>Strong Level — '.htmlspecialchars($sideLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
    }

    public function formatExpiryRu(int $sec): string
    {
        $sec = max(1, $sec);
        if ($sec >= 3600 && $sec % 3600 === 0) {
            $h = (int) ($sec / 3600);

            return $h.' '.($h === 1 ? 'час' : ($h < 5 ? 'часа' : 'часов'));
        }
        if ($sec % 60 === 0) {
            $m = (int) ($sec / 60);

            return $m.' '.($m === 1 ? 'минута' : ($m < 5 ? 'минуты' : 'минут'));
        }

        return $sec.' с';
    }

    public function levelKindRu(string $kind): string
    {
        return match ($kind) {
            'support' => 'поддержка',
            'resistance' => 'сопротивление',
            'sma' => 'SMA',
            'bb_lower' => 'нижняя BB',
            'bb_upper' => 'верхняя BB',
            default => 'уровень',
        };
    }

    public function levelKindRuGenitive(string $kind): string
    {
        return match ($kind) {
            'support' => 'поддержки',
            'resistance' => 'сопротивления',
            'sma' => 'SMA',
            'bb_lower' => 'нижней BB',
            'bb_upper' => 'верхней BB',
            default => 'уровня',
        };
    }

    /**
     * @param  array<string, mixed>  $features
     */
    public function formatSources(array $features): string
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

    /**
     * @param  list<string>  $rawSources
     * @return list<string>
     */
    public function formatSourceList(array $rawSources): array
    {
        $out = [];
        foreach ($rawSources as $source) {
            $key = (string) $source;
            $out[] = self::SOURCE_LABELS[$key] ?? $key;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $features
     */
    public function formatDistanceLine(array $features, ?float $entryPrice = null): ?string
    {
        $distance = isset($features['distance_to_level']) ? (float) $features['distance_to_level'] : null;
        if ($distance === null || $distance <= 0) {
            return null;
        }

        $atrM1 = isset($features['atr_m1']) ? (float) $features['atr_m1'] : 0.0;
        $parts = [sprintf('до уровня: %.5f', $distance)];
        if ($atrM1 > 0) {
            $parts[] = sprintf('%.1f× ATR M1', $distance / $atrM1);
        }
        if ($entryPrice !== null) {
            $parts[] = 'вход '.number_format($entryPrice, 5, '.', '');
        }

        return implode(' · ', $parts);
    }

    /**
     * Блок подтверждения M5 / M1 для brief и detailed.
     *
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     * @return list<string>
     */
    public function formatConfirmLines(array $features, array $reasons): array
    {
        $lines = [];
        $m5Enabled = (bool) ($features['m5_rejection_enabled'] ?? true);
        $m1Enabled = (bool) ($features['m1_reversal_enabled'] ?? true);

        if ($m5Enabled) {
            $wick = isset($features['m5_reversal_wick']) ? (float) $features['m5_reversal_wick'] : null;
            if ($wick !== null && $wick > 0) {
                $lines[] = sprintf('M5: rejection ✓ (тень %.0f%%)', $wick * 100);
            } else {
                foreach ($reasons as $reason) {
                    if (str_contains($reason, 'M5 rejection')) {
                        $lines[] = 'M5: rejection ✓';
                        break;
                    }
                }
                if ($lines === []) {
                    $lines[] = 'M5: rejection ✓';
                }
            }
        } else {
            $lines[] = 'M5: выкл (вход без rejection на M5)';
        }

        if ($m1Enabled) {
            $bars = max(1, min(3, (int) ($features['m1_reversal_confirm_bars'] ?? 1)));
            $side = strtoupper((string) ($features['signal_side'] ?? ''));
            $dir = $side === 'PUT' ? 'вниз' : 'вверх';
            $word = $bars === 1 ? 'свеча' : ($bars < 5 ? 'свечи' : 'свечей');
            $lines[] = sprintf('M1: %d %s в сторону %s', $bars, $word, $dir);
        } else {
            $lines[] = 'M1: выкл (без подтверждения разворотными M1)';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $features
     */
    public function strategyTitle(array $features, ?string $botType = null, array $decision = []): string
    {
        if ($this->isBollingerContrtrendDecision($decision, $botType)) {
            $setup = (string) ($features['setup'] ?? '');

            return match ($setup) {
                'bb_touch_lower' => 'Отскок от нижней полосы Bollinger',
                'bb_touch_upper' => 'Отскок от верхней полосы Bollinger',
                default => 'Bollinger Contrtrend',
            };
        }

        if ($this->isRsiContrtrendDecision($decision, $botType)) {
            $setup = (string) ($features['setup'] ?? '');

            return match ($setup) {
                'rsi_oversold_in_uptrend' => 'RSI перепроданность в аптренде',
                'rsi_overbought_in_downtrend' => 'RSI перекупленность в даунтренде',
                default => 'RSI Contrtrend',
            };
        }

        if ($this->isCrcDecision($decision, $botType)) {
            return 'Отскок от уровня + разворот';
        }

        $regime = (string) ($features['entry_regime'] ?? 'bounce');

        return $regime === 'breakout' ? 'Пробой уровня' : 'Отбой от уровня';
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     */
    public function strategyNarrative(array $features, string $side, ?string $botType = null, array $decision = [], array $reasons = []): string
    {
        if ($this->isBollingerContrtrendDecision($decision, $botType)) {
            return $this->bollingerContrtrendNarrative($features, $side, $reasons);
        }

        if ($this->isRsiContrtrendDecision($decision, $botType)) {
            return $this->rsiContrtrendNarrative($features, $side, $reasons);
        }

        if ($this->isCrcDecision($decision, $botType)) {
            return $this->crcNarrative($features, $side, $reasons);
        }

        return $this->strongLevelNarrative($features, $side);
    }

    /**
     * Абзац для карточки: касание полосы Bollinger.
     *
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     */
    private function bollingerContrtrendNarrative(array $features, string $side, array $reasons): string
    {
        $bbLen = max(1, (int) ($features['bb_length'] ?? 12));
        $bbDev = (float) ($features['bb_deviation'] ?? 2.0);
        $up = isset($features['bb_up']) ? (float) $features['bb_up'] : 0.0;
        $down = isset($features['bb_down']) ? (float) $features['bb_down'] : 0.0;
        $mid = isset($features['bb_mid']) ? (float) $features['bb_mid'] : 0.0;
        $close = isset($features['close']) ? (float) $features['close'] : 0.0;
        $direction = strtoupper($side) === 'CALL' ? 'вверх' : 'вниз';

        if (strtoupper($side) === 'CALL') {
            $core = sprintf(
                'Цена %.5f закрылась ниже нижней полосы BB(%d, %.1fσ)=%.5f (mid %.5f) — перерастяжение вниз.',
                $close,
                $bbLen,
                $bbDev,
                $down,
                $mid
            );
        } else {
            $core = sprintf(
                'Цена %.5f закрылась выше верхней полосы BB(%d, %.1fσ)=%.5f (mid %.5f) — перерастяжение вверх.',
                $close,
                $bbLen,
                $bbDev,
                $up,
                $mid
            );
        }

        $extra = $reasons !== [] ? ' '.($reasons[0] ?? '') : '';

        return $core.' Ждём отскок '.$direction.'.'.$extra;
    }

    /**
     * Абзац для карточки / Telegram card: SMA-фильтр + зона RSI.
     *
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     */
    private function rsiContrtrendNarrative(array $features, string $side, array $reasons): string
    {
        $sma = isset($features['sma']) ? (float) $features['sma'] : (isset($features['level_price']) ? (float) $features['level_price'] : 0.0);
        $rsi = isset($features['rsi']) ? (float) $features['rsi'] : 0.0;
        $smaLen = max(1, (int) ($features['sma_length'] ?? 50));
        $rsiLen = max(1, (int) ($features['rsi_length'] ?? 20));
        $rsiUp = (float) ($features['rsi_up'] ?? 65);
        $rsiDown = (float) ($features['rsi_down'] ?? 35);
        $direction = strtoupper($side) === 'CALL' ? 'вверх' : 'вниз';

        if (strtoupper($side) === 'CALL') {
            $core = sprintf(
                'Цена выше SMA(%d)%s, RSI(%d)=%.1f ниже порога %.0f — перепроданность в аптренде.',
                $smaLen,
                $sma > 0 ? sprintf(' (%.5f)', $sma) : '',
                $rsiLen,
                $rsi,
                $rsiDown
            );
        } else {
            $core = sprintf(
                'Цена ниже SMA(%d)%s, RSI(%d)=%.1f выше порога %.0f — перекупленность в даунтренде.',
                $smaLen,
                $sma > 0 ? sprintf(' (%.5f)', $sma) : '',
                $rsiLen,
                $rsi,
                $rsiUp
            );
        }

        $extra = $reasons !== [] ? ' '.($reasons[0] ?? '') : '';

        return $core.' Ждём отскок '.$direction.'.'.$extra;
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     */
    private function crcNarrative(array $features, string $side, array $reasons): string
    {
        $levelPrice = isset($features['level_price']) ? (float) $features['level_price'] : 0.0;
        $kind = $this->levelKindRuGenitive((string) ($features['level_kind'] ?? 'neutral'));
        $strength = isset($features['level_strength']) ? (float) $features['level_strength'] : 0.0;
        $sources = $this->formatSources($features);
        $h1 = $this->h1BiasRu((string) ($features['h1_bias'] ?? ''));
        $direction = strtoupper($side) === 'CALL' ? 'вверх' : 'вниз';

        $levelPart = $levelPrice > 0
            ? sprintf('%s %.5f', $kind, $levelPrice)
            : $kind;

        $meta = [];
        if ($strength > 0) {
            $meta[] = sprintf('сила %.0f', $strength);
        }
        if ($sources !== '') {
            $meta[] = 'источники '.$sources;
        }
        $metaSuffix = $meta !== [] ? ' ('.implode(', ', $meta).')' : '';

        $confirmParts = [];
        if ((bool) ($features['m5_rejection_enabled'] ?? true)) {
            $wick = isset($features['m5_reversal_wick']) ? (float) $features['m5_reversal_wick'] : null;
            $confirmParts[] = $wick !== null && $wick > 0
                ? sprintf('M5 показала rejection (тень %.0f%%)', $wick * 100)
                : 'M5 показала rejection у уровня';
        }
        if ((bool) ($features['m1_reversal_enabled'] ?? true)) {
            $bars = max(1, (int) ($features['m1_reversal_confirm_bars'] ?? 1));
            $confirmParts[] = $bars === 1
                ? 'M1 подтвердила начало разворота'
                : sprintf('M1: %d свечи подтверждают разворот', $bars);
        }
        $confirmSentence = $confirmParts !== []
            ? implode(', ', $confirmParts).'.'
            : 'Подтверждение по настройкам бота.';

        $h1Part = $h1 !== '' ? ' Контекст H1: '.$h1.'.' : '';

        return sprintf(
            'Цена отбилась от %s%s. %s Ждём продолжение движения %s.%s',
            $levelPart,
            $metaSuffix,
            $confirmSentence,
            $direction,
            $h1Part
        );
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function strongLevelNarrative(array $features, string $side): string
    {
        $regime = (string) ($features['entry_regime'] ?? 'bounce');
        $levelPrice = isset($features['level_price']) ? (float) $features['level_price'] : 0.0;
        $strength = isset($features['level_strength']) ? (float) $features['level_strength'] : 0.0;
        $touches = max(0, (int) ($features['level_touches'] ?? 0));
        $kind = $this->levelKindRuGenitive((string) ($features['level_kind'] ?? 'neutral'));
        $sources = $this->formatSources($features);
        $h1 = $this->h1BiasRu((string) ($features['h1_bias'] ?? ''));
        $setup = (string) ($features['setup_type'] ?? '');

        $levelPart = $levelPrice > 0
            ? sprintf('сильному уровню %s %.5f', $kind, $levelPrice)
            : sprintf('сильному уровню %s', $kind);

        $metaPart = [];
        if ($strength > 0) {
            $metaPart[] = sprintf('сила %.0f', $strength);
        }
        if ($touches > 0) {
            $metaPart[] = $this->touchesLabel($touches);
        }
        if ($sources !== '') {
            $metaPart[] = 'источники: '.$sources;
        }
        $metaSuffix = $metaPart !== [] ? ' ('.implode(', ', $metaPart).')' : '';

        $direction = strtoupper($side) === 'CALL' ? 'вверх' : 'вниз';

        if ($regime === 'breakout') {
            $retest = str_contains(mb_strtolower($setup), 'retest') ? ' с ретестом' : '';
            $h1Part = $h1 !== '' ? ' Контекст H1: '.$h1.'.' : '';

            return sprintf(
                'Цена пробила %s%s%s. После закрепления за уровнем ожидаем продолжение движения %s.%s',
                $levelPart,
                $metaSuffix,
                $retest,
                $direction,
                $h1Part
            );
        }

        $h1Part = $h1 !== '' ? ' Тренд H1: '.$h1.' — движение согласовано с контекстом.' : '';

        return sprintf(
            'Цена подошла к %s%s и оттолкнулась. На таком уровне скапливаются встречные заявки — ждём продолжение отскока %s.%s',
            $levelPart,
            $metaSuffix,
            $direction,
            $h1Part
        );
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $reasons
     */
    public function technicalDetails(array $features, array $reasons, ?string $botType = null, array $decision = []): string
    {
        if ($this->isBollingerContrtrendDecision($decision, $botType)) {
            $parts = [];
            $bbLen = (int) ($features['bb_length'] ?? 0);
            $bbDev = (float) ($features['bb_deviation'] ?? 0);
            $up = isset($features['bb_up']) ? (float) $features['bb_up'] : 0.0;
            $mid = isset($features['bb_mid']) ? (float) $features['bb_mid'] : 0.0;
            $down = isset($features['bb_down']) ? (float) $features['bb_down'] : 0.0;
            $setup = (string) ($features['setup'] ?? '');

            if ($bbLen > 0) {
                $parts[] = sprintf('BB(%d, %.1fσ)', $bbLen, $bbDev);
            }
            if ($up > 0 && $mid > 0 && $down > 0) {
                $parts[] = sprintf(
                    'up=%s mid=%s down=%s',
                    number_format($up, 5, '.', ''),
                    number_format($mid, 5, '.', ''),
                    number_format($down, 5, '.', '')
                );
            }
            if ($setup !== '') {
                $parts[] = $setup;
            }
            $parts[] = 'режим: Bollinger Contrtrend';
            if ($reasons !== []) {
                $parts[] = implode(' · ', array_slice($reasons, 0, 2));
            }

            return implode(' · ', $parts);
        }

        if ($this->isRsiContrtrendDecision($decision, $botType)) {
            $parts = [];
            $sma = isset($features['sma']) ? (float) $features['sma'] : (isset($features['level_price']) ? (float) $features['level_price'] : 0.0);
            $rsi = isset($features['rsi']) ? (float) $features['rsi'] : 0.0;
            $smaLen = (int) ($features['sma_length'] ?? 0);
            $rsiLen = (int) ($features['rsi_length'] ?? 0);
            $setup = (string) ($features['setup'] ?? '');

            if ($smaLen > 0 && $sma > 0) {
                $parts[] = sprintf('SMA(%d)=%s', $smaLen, number_format($sma, 5, '.', ''));
            }
            if ($rsiLen > 0) {
                $parts[] = sprintf('RSI(%d)=%s', $rsiLen, number_format($rsi, 2, '.', ''));
            }
            if ($setup !== '') {
                $parts[] = $setup;
            }
            $parts[] = 'режим: RSI Contrtrend';
            if ($reasons !== []) {
                $parts[] = implode(' · ', array_slice($reasons, 0, 2));
            }

            return implode(' · ', $parts);
        }

        $parts = [];
        $levelPrice = isset($features['level_price']) ? (float) $features['level_price'] : 0.0;
        $kindLabel = $this->levelKindRu((string) ($features['level_kind'] ?? 'neutral'));
        $touches = max(0, (int) ($features['level_touches'] ?? 0));
        $regime = (string) ($features['entry_regime'] ?? 'bounce');

        if ($levelPrice > 0) {
            $touchText = $touches > 0 ? ' ('.$this->touchesLabel($touches).')' : '';
            $parts[] = $kindLabel.' '.number_format($levelPrice, 5, '.', '').$touchText;
        }

        $distanceLine = $this->formatDistanceLine($features);
        if ($distanceLine !== null) {
            $parts[] = $distanceLine;
        }

        $sources = $this->formatSources($features);
        if ($sources !== '') {
            $parts[] = 'источники: '.$sources;
        }

        $parts[] = $regime === 'breakout' ? 'режим: пробой' : 'режим: отскок';

        if ($this->isCrcDecision($decision, $botType)) {
            foreach ($this->formatConfirmLines($features, $reasons) as $line) {
                $parts[] = $line;
            }
        } else {
            $setup = (string) ($features['setup_type'] ?? '');
            if ($setup !== '' && $setup !== 'level_bounce_reversal') {
                $parts[] = $setup;
            }
        }

        if ($reasons !== []) {
            $parts[] = implode(' · ', array_slice($reasons, 0, 3));
        }

        return implode(' · ', $parts);
    }

    public function h1BiasRu(string $bias): string
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
}
