<?php

namespace App\Services\Telegram;

use App\Services\CandleService;
use App\Support\AuthorFlowConfig;
use App\Support\BotInstrumentMarketScope;
use App\Support\TelegramInstrumentLabel;

/**
 * Caption / overlay / маркеры PNG для AuthorFlow — всё на русском.
 * Engulfing caption path (#45) не трогаем.
 */
final class AuthorFlowAlertPresenter
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $config  bots.config (timeframe, market_scope)
     */
    public function formatCaption(
        string $instrument,
        array $decision,
        ?float $payout = null,
        array $config = [],
    ): string {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $display = htmlspecialchars(
            TelegramInstrumentLabel::displayName($instrument),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $payoutBit = $payout !== null ? sprintf(' (%.0f%%)', $payout) : '';
        $sideBit = $this->sideRuShort(strtoupper((string) ($decision['side'] ?? '')));
        $setupRu = $this->setupRu((string) ($features['setup'] ?? ''));
        $reason = (string) ($features['reason_ru'] ?? $setupRu);
        $scanClock = \Illuminate\Support\Carbon::now('Europe/Kyiv')->format('H:i:s');

        return $display.$payoutBit.$sideBit.' '.$setupRu.' · '.$reason.' '.$scanClock;
    }

    /**
     * Текстовые строки на PNG (все 8 блоков информации).
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function overlayLines(
        string $instrument,
        array $decision,
        int $expirySec,
        ?float $payout = null,
        array $config = [],
    ): array {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $setup = (string) ($features['setup'] ?? '');
        $setupRu = $this->setupRu($setup);
        $reason = (string) ($features['reason_ru'] ?? '');
        $payoutBit = $payout !== null ? sprintf(' · %.0f%%', $payout) : '';

        $levelBit = '';
        $levelPrice = (float) ($features['level_price'] ?? 0);
        if ($levelPrice > 0) {
            $levelBit = ' · '.$this->levelTypeRu((string) ($features['level_type'] ?? '')).' '
                .number_format($levelPrice, 5, '.', '');
        }

        $bias = (string) ($features['structure_bias'] ?? 'range');
        $aligned = ! empty($features['structure_aligned']);
        $openLiq = ! empty($features['open_liquidity']);
        $score = (float) ($decision['confidence'] ?? ($features['score'] ?? 0));
        $priority = (int) ($features['priority'] ?? 0);
        $kindBit = $this->engulfKindRu((string) ($features['engulf_kind'] ?? ''));

        $lines = [
            // 1–2: пара / сторона / payout + setup
            TelegramInstrumentLabel::displayName($instrument).' · '.$this->sideRuLong($side).$payoutBit,
            $setupRu.($reason !== '' ? ': '.$reason : ''),
            // 7: ТФ + рынок + экспирация + уровень
            $this->timeframeRu($config).' · '.$this->marketScopeRu($config)
                .' · эксп. '.$expirySec.'с'.$levelBit,
            // 1: структура + 3: открытая ликвидность
            'Структура: '.$this->biasRu($bias)
                .' · '.($aligned ? 'по структуре' : 'против / флэт')
                .' · ликвидность: '.($openLiq ? 'открыта' : 'нет'),
            // 2: score / priority + 8: kind
            sprintf(
                'Оценка %.2f · приоритет %d%s',
                $score,
                $priority,
                $kindBit !== '' ? ' · '.$kindBit : ''
            ),
        ];

        // 6: кто проиграл Ranker
        $suppressed = is_array($features['suppressed'] ?? null) ? $features['suppressed'] : [];
        if ($suppressed !== []) {
            $bits = [];
            foreach (array_slice($suppressed, 0, 3) as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $bits[] = $this->setupRu((string) ($s['setup'] ?? ''))
                    .' '.$this->sideRuShort(strtoupper((string) ($s['side'] ?? '')));
            }
            if ($bits !== []) {
                $lines[] = 'Мимо: '.implode(', ', $bits);
            }
        }

        return $lines;
    }

    /**
     * Маркеры на свечах: ①②③ для D, снятие+откат для raid_pb, вход для sweep/E.
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array<string,mixed>>  $closedCandles
     * @return list<array<string,mixed>>
     */
    public function chartMarkers(array $decision, array $closedCandles): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $setup = (string) ($features['setup'] ?? '');
        $isCall = $side === 'CALL';
        $entryColor = $isCall ? '#22c55e' : '#ef4444';
        $entryPrice = (float) ($features['entry_price'] ?? 0);
        $eventTime = (int) ($features['event_time'] ?? 0);

        $barAt = static function (array $candles, int $time): ?array {
            foreach ($candles as $c) {
                if ((int) ($c['time'] ?? 0) === $time) {
                    return is_array($c) ? $c : null;
                }
            }

            return null;
        };

        $markers = [];

        if ($setup === 'engulf_D') {
            // 4: метки ①②③ как у #45
            $kindRu = $this->engulfKindRu((string) ($features['engulf_kind'] ?? 'classic')) ?: 'классика';
            $s1 = (int) ($features['step1_time'] ?? 0);
            $s2 = (int) ($features['step2_time'] ?? 0);
            $s3 = (int) ($features['step3_time'] ?? 0);
            $gap = max(1, (int) ($features['reaction_gap_bars'] ?? 1));

            if ($s1 > 0) {
                $bar = $barAt($closedCandles, $s1);
                $markers[] = [
                    'time' => $s1,
                    'price' => $bar !== null ? ($isCall ? (float) $bar['low'] : (float) $bar['high']) : 0.0,
                    'text' => '① Поглощение',
                    'lines' => ['① Поглощение', '('.$kindRu.')'],
                    'color' => '#38bdf8',
                    'position' => $isCall ? 'below' : 'above',
                    'arrow' => $isCall ? 'up' : 'down',
                    'size' => 'normal',
                ];
            }
            if ($s2 > 0) {
                $bar = $barAt($closedCandles, $s2);
                $markers[] = [
                    'time' => $s2,
                    'price' => $bar !== null ? ($isCall ? (float) $bar['high'] : (float) $bar['low']) : 0.0,
                    'text' => '② Реакция',
                    'lines' => ['② Реакция', $gap.' бар'.($gap === 1 ? '' : 'а')],
                    'color' => '#fbbf24',
                    'position' => $isCall ? 'above' : 'below',
                    'arrow' => $isCall ? 'down' : 'up',
                    'size' => 'normal',
                ];
            }
            $t3 = $s3 > 0 ? $s3 : $eventTime;
            if ($t3 > 0) {
                $markers[] = [
                    'time' => $t3,
                    'price' => $entryPrice > 0 ? $entryPrice : 0.0,
                    'text' => '③ Вход',
                    'lines' => ['③ Вход', $this->sideRuLong($side), 'на close'],
                    'color' => $entryColor,
                    'position' => $isCall ? 'below' : 'above',
                    'arrow' => $isCall ? 'up' : 'down',
                    'size' => 'entry',
                ];
            }

            return $markers;
        }

        if ($setup === 'raid_pb') {
            // 5: снятие (рейд) + бар отката
            $takedown = (int) ($features['step1_time'] ?? 0);
            if ($takedown > 0) {
                $bar = $barAt($closedCandles, $takedown);
                $markers[] = [
                    'time' => $takedown,
                    'price' => $bar !== null ? (float) $bar['close'] : 0.0,
                    'text' => 'Снятие (рейд)',
                    'lines' => ['Снятие', '(рейд)'],
                    'color' => '#a78bfa',
                    'position' => $isCall ? 'below' : 'above',
                    'arrow' => $isCall ? 'up' : 'down',
                    'size' => 'normal',
                ];
            }
            if ($eventTime > 0) {
                $markers[] = [
                    'time' => $eventTime,
                    'price' => $entryPrice > 0 ? $entryPrice : 0.0,
                    'text' => 'Вход · откат',
                    'lines' => ['Вход', 'первая откатная', $this->sideRuLong($side)],
                    'color' => $entryColor,
                    'position' => $isCall ? 'below' : 'above',
                    'arrow' => $isCall ? 'up' : 'down',
                    'size' => 'entry',
                ];
            }

            return $markers;
        }

        if ($setup === 'sweep') {
            if ($eventTime > 0) {
                $markers[] = [
                    'time' => $eventTime,
                    'price' => $entryPrice > 0 ? $entryPrice : 0.0,
                    'text' => 'Свип · вход',
                    'lines' => ['Свип', 'вход fade', $this->sideRuLong($side)],
                    'color' => $entryColor,
                    'position' => $isCall ? 'below' : 'above',
                    'arrow' => $isCall ? 'up' : 'down',
                    'size' => 'entry',
                ];
            }

            return $markers;
        }

        // breakout_E и прочее
        if ($eventTime > 0) {
            $markers[] = [
                'time' => $eventTime,
                'price' => $entryPrice > 0 ? $entryPrice : 0.0,
                'text' => $this->setupRu($setup),
                'lines' => [$this->setupRu($setup), $this->sideRuLong($side)],
                'color' => $entryColor,
                'position' => $isCall ? 'below' : 'above',
                'arrow' => $isCall ? 'up' : 'down',
                'size' => 'entry',
            ];
        }

        return $markers;
    }

    /**
     * Горизонтали: вход, FH/FL сетапа, пулы ликвидности (свинг high/low).
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $config
     * @return list<array{price:float,label:string,color:string}>
     */
    public function priceLevels(array $decision, array $config = []): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $side = strtoupper((string) ($decision['side'] ?? ''));
        $entryColor = $side === 'CALL' ? '#22c55e' : '#ef4444';
        $levels = [];
        /** @var array<string, true> $used  не дублировать почти одну цену */
        $used = [];

        $addLevel = static function (float $price, string $label, string $color) use (&$levels, &$used): void {
            if ($price <= 0) {
                return;
            }
            $key = number_format($price, 6, '.', '');
            if (isset($used[$key])) {
                return;
            }
            $used[$key] = true;
            $levels[] = [
                'price' => $price,
                'label' => $label,
                'color' => $color,
            ];
        };

        $entryPx = (float) ($features['entry_price'] ?? 0);
        if ($entryPx > 0) {
            $addLevel($entryPx, 'вход '.number_format($entryPx, 5, '.', ''), $entryColor);
        }

        $levelPrice = (float) ($features['level_price'] ?? 0);
        if ($levelPrice > 0 && AuthorFlowConfig::flag($config, 'telegram_show_nearest_level', true)) {
            $addLevel(
                $levelPrice,
                $this->levelTypeRu((string) ($features['level_type'] ?? ''))
                    .' '.number_format($levelPrice, 5, '.', ''),
                '#fbbf24',
            );
        }

        // Пулы ликвидности по последним swing: сверху (для PUT) / снизу (для CALL).
        $liqHigh = (float) ($features['liq_high'] ?? 0);
        $liqLow = (float) ($features['liq_low'] ?? 0);
        $openPut = ! empty($features['open_liquidity_put']);
        $openCall = ! empty($features['open_liquidity_call']);
        if ($liqHigh > 0) {
            $state = $openPut ? 'открыта' : 'снята';
            $addLevel(
                $liqHigh,
                'ликв. сверху ('.$state.') '.number_format($liqHigh, 5, '.', ''),
                $openPut ? '#c084fc' : '#64748b',
            );
        }
        if ($liqLow > 0) {
            $state = $openCall ? 'открыта' : 'снята';
            $addLevel(
                $liqLow,
                'ликв. снизу ('.$state.') '.number_format($liqLow, 5, '.', ''),
                $openCall ? '#67e8f9' : '#64748b',
            );
        }

        return $levels;
    }

    public function setupRu(string $setup): string
    {
        return match ($setup) {
            'breakout_E' => 'Пробой+закреп',
            'engulf_D' => 'Поглощение',
            'raid_pb' => 'Рейд→откат',
            'sweep' => 'Свип',
            'skop_zone' => 'Скоп-зона',
            default => $setup !== '' ? $setup : 'сетап',
        };
    }

    private function biasRu(string $bias): string
    {
        return match ($bias) {
            'up' => 'рост',
            'down' => 'падение',
            'range' => 'флэт',
            default => $bias !== '' ? $bias : 'флэт',
        };
    }

    private function engulfKindRu(string $kind): string
    {
        return match ($kind) {
            'classic' => 'классика',
            'same_color' => 'одного цвета',
            default => '',
        };
    }

    private function levelTypeRu(string $type): string
    {
        return match ($type) {
            'FH' => 'сопротивление (FH)',
            'FL' => 'поддержка (FL)',
            default => $type !== '' ? $type : 'уровень',
        };
    }

    private function sideRuShort(string $side): string
    {
        return match ($side) {
            'CALL' => ' ▲ вверх',
            'PUT' => ' ▼ вниз',
            default => '',
        };
    }

    private function sideRuLong(string $side): string
    {
        return match ($side) {
            'CALL' => 'ВХОД вверх ▲',
            'PUT' => 'ВХОД вниз ▼',
            default => $side !== '' ? $side : '—',
        };
    }

    /** @param  array<string, mixed>  $config */
    private function timeframeRu(array $config): string
    {
        $tf = (int) ($config['timeframe'] ?? CandleService::TF_S15);

        return match ($tf) {
            CandleService::TF_S5 => 'ТФ S5',
            CandleService::TF_S15 => 'ТФ S15',
            CandleService::TF_S30 => 'ТФ S30',
            1 => 'ТФ M1',
            default => 'ТФ '.$tf,
        };
    }

    /** @param  array<string, mixed>  $config */
    private function marketScopeRu(array $config): string
    {
        $scope = BotInstrumentMarketScope::normalize(
            $config['market_scope'] ?? BotInstrumentMarketScope::OTC
        );

        return match ($scope) {
            BotInstrumentMarketScope::OTC => 'только OTC',
            BotInstrumentMarketScope::NON_OTC => 'без OTC',
            BotInstrumentMarketScope::ALL => 'все пары',
            default => 'рынок',
        };
    }
}
