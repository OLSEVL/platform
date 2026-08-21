<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
     * PNG-график для Telegram: свечи + уровни/зоны/метки индикаторов (PHP GD).
     * Текст — через TrueType (DejaVu/Liberation): встроенный imagestring не рисует кириллицу.
     */
class TelegramChartImageRenderer
{
    private const WIDTH = 900;

    private const HEIGHT = 520;

    private const MARGIN_LEFT = 80;

    private const MARGIN_RIGHT = 96;

    private const MARGIN_TOP = 52;

    private const MARGIN_BOTTOM = 44;

    private const FONT_SIZE_TITLE = 11;

    private const FONT_SIZE_AXIS = 9;

    private const FONT_SIZE_LABEL = 9;

    /** @var list<string> */
    private const FONT_CANDIDATES = [
        'resources/fonts/DejaVuSans.ttf',
        'storage/app/fonts/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];

    /**
     * @param  list<array<string,mixed>>  $candles
     * @param  array{
     *     levels?:list<array<string,mixed>>,
     *     zones?:list<array<string,mixed>>,
     *     entry?:?array<string,mixed>,
     *     oscillator?:?array<string,mixed>,
     *     oscillator_guides?:list<array<string,mixed>>,
     *     segments?:list<array<string,mixed>>,
     *     markers?:list<array<string,mixed>>,
     *     subtitle?:string,
     *     legend?:string
     * }  $chartLevels
     * @param  array{width?:int,height?:int,margin_left?:int,margin_right?:int,margin_top?:int,margin_bottom?:int,max_bars?:int,timeframe_label?:string,font_size_title?:float,font_size_axis?:float,font_size_label?:float}  $options
     */
    public function render(string $instrument, array $candles, array $chartLevels, array $options = []): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $width = max(160, (int) ($options['width'] ?? self::WIDTH));
        $height = max(120, (int) ($options['height'] ?? self::HEIGHT));
        // С панелью DeMarker чуть выше, чтобы свечи не сжимались.
        if (! isset($options['height']) && is_array($chartLevels['oscillator'] ?? null)) {
            $height = 620;
        }
        $subtitle = trim((string) ($chartLevels['subtitle'] ?? ''));
        $legend = trim((string) ($chartLevels['legend'] ?? ''));
        $marginLeft = max(8, (int) ($options['margin_left'] ?? self::MARGIN_LEFT));
        $marginRight = max(4, (int) ($options['margin_right'] ?? self::MARGIN_RIGHT));
        // Подзаголовок, легенда и метки у последней свечи не должны упираться в рамку PNG.
        $marginTop = max(8, (int) ($options['margin_top'] ?? ($subtitle !== '' ? 72 : self::MARGIN_TOP)));
        $marginBottom = max(8, (int) ($options['margin_bottom'] ?? ($legend !== '' ? 62 : self::MARGIN_BOTTOM)));
        $maxBars = max(10, (int) ($options['max_bars'] ?? 90));
        $fontTitle = (float) ($options['font_size_title'] ?? self::FONT_SIZE_TITLE);
        $fontAxis = (float) ($options['font_size_axis'] ?? self::FONT_SIZE_AXIS);
        $fontLabel = (float) ($options['font_size_label'] ?? self::FONT_SIZE_LABEL);
        $timeframeLabel = (string) ($options['timeframe_label'] ?? 'M1');

        $fontPath = $this->resolveFontPath();

        $series = $this->normalizeCandles($candles);
        if ($series === []) {
            return null;
        }

        $oscillator = is_array($chartLevels['oscillator'] ?? null) ? $chartLevels['oscillator'] : null;
        $oscValues = is_array($oscillator['values'] ?? null) ? $oscillator['values'] : [];

        if (count($series) > $maxBars) {
            $series = array_slice($series, -$maxBars);
            if ($oscValues !== []) {
                $oscValues = array_slice($oscValues, -$maxBars);
            }
        }

        $levels = is_array($chartLevels['levels'] ?? null) ? $chartLevels['levels'] : [];
        $zones = is_array($chartLevels['zones'] ?? null) ? $chartLevels['zones'] : [];
        $entry = is_array($chartLevels['entry'] ?? null) ? $chartLevels['entry'] : null;
        $oscillatorGuides = is_array($chartLevels['oscillator_guides'] ?? null) ? $chartLevels['oscillator_guides'] : [];
        $segments = is_array($chartLevels['segments'] ?? null) ? $chartLevels['segments'] : [];
        $markers = is_array($chartLevels['markers'] ?? null) ? $chartLevels['markers'] : [];

        $priceMin = min(array_column($series, 'low'));
        $priceMax = max(array_column($series, 'high'));
        foreach ($levels as $lvl) {
            if (isset($lvl['price']) && is_numeric($lvl['price'])) {
                $p = (float) $lvl['price'];
                $priceMin = min($priceMin, $p);
                $priceMax = max($priceMax, $p);
            }
        }
        foreach ($zones as $zone) {
            foreach (['price_low', 'price_high', 'low', 'high'] as $k) {
                if (isset($zone[$k]) && is_numeric($zone[$k])) {
                    $p = (float) $zone[$k];
                    $priceMin = min($priceMin, $p);
                    $priceMax = max($priceMax, $p);
                }
            }
        }
        foreach ($segments as $segment) {
            if (isset($segment['price']) && is_numeric($segment['price'])) {
                $p = (float) $segment['price'];
                $priceMin = min($priceMin, $p);
                $priceMax = max($priceMax, $p);
            }
        }
        foreach ($markers as $marker) {
            if (isset($marker['price']) && is_numeric($marker['price'])) {
                $p = (float) $marker['price'];
                $priceMin = min($priceMin, $p);
                $priceMax = max($priceMax, $p);
            }
        }
        if ($entry && isset($entry['price']) && is_numeric($entry['price'])) {
            $p = (float) $entry['price'];
            $priceMin = min($priceMin, $p);
            $priceMax = max($priceMax, $p);
        }

        // Шире кадр, когда на графике метки входа — иначе подписи упираются в правый край PNG.
        if (! isset($options['width']) && ($markers !== [] || $entry !== null || $subtitle !== '')) {
            $width = max($width, 980);
        }

        // Запас по цене, чтобы треугольники FH/SWEEP над хаем не обрезались верхним краем плота.
        $padRatio = ($markers !== [] || $entry !== null) ? 0.14 : 0.08;
        $pad = max(1e-8, ($priceMax - $priceMin) * $padRatio);
        $priceMin -= $pad;
        $priceMax += $pad;

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return null;
        }

        $bg = imagecolorallocate($img, 15, 17, 23);
        $grid = imagecolorallocate($img, 45, 50, 62);
        $text = imagecolorallocate($img, 180, 186, 198);
        $bull = imagecolorallocate($img, 34, 197, 94);
        $bear = imagecolorallocate($img, 239, 68, 68);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        $plotW = $width - $marginLeft - $marginRight;
        $plotH = $height - $marginTop - $marginBottom;
        $plotX0 = $marginLeft;
        $plotY0 = $marginTop;
        // Нижняя панель осциллятора (DeMarker и т.п.): свечи сверху, линия 0..1 снизу.
        $hasOscillator = $oscillator !== null && $oscValues !== [];
        $oscGap = $hasOscillator ? 14 : 0;
        $oscH = $hasOscillator ? max(72, (int) round($plotH * 0.28)) : 0;
        $candleH = $hasOscillator ? max(80, $plotH - $oscH - $oscGap) : $plotH;
        $oscY0 = $plotY0 + $candleH + $oscGap;

        $title = $instrument.' · '.$timeframeLabel.' · '.now()->utc()->format('d.m.Y H:i').' UTC';
        $this->drawText($img, $fontPath, $fontTitle, $plotX0, 22, $text, $title);
        if ($subtitle !== '') {
            $this->drawText(
                $img,
                $fontPath,
                max(8.0, $fontLabel),
                $plotX0,
                42,
                $text,
                mb_substr($subtitle, 0, 96),
            );
        }

        for ($i = 0; $i <= 5; $i++) {
            $ratio = $i / 5;
            $price = $priceMax - ($priceMax - $priceMin) * $ratio;
            $y = (int) round($plotY0 + $candleH * $ratio);
            imageline($img, $plotX0, $y, $width - $marginRight, $y, $grid);
            $this->drawText($img, $fontPath, $fontAxis, 4, $y + 4, $text, $this->formatPrice($price));
        }

        $barCount = count($series);
        // Пустые слоты слева/справа: свечи и подписи входа не прилипают к рамке картинки.
        $padSlotsLeft = 1.2;
        $padSlotsRight = ($markers !== [] || $entry !== null) ? 5.5 : 1.8;
        $slotW = $plotW / max(1.0, $barCount + $padSlotsLeft + $padSlotsRight);
        $barX0 = $plotX0 + $padSlotsLeft * $slotW;
        $timeIndex = [];
        foreach ($series as $idx => $bar) {
            $t = (int) ($bar['time'] ?? 0);
            if ($t > 0) {
                $timeIndex[$t] = $idx;
            }
        }
        $entryBarIndex = $this->barIndexByTime(
            $timeIndex,
            is_array($entry) ? ($entry['time'] ?? null) : null,
            $barCount,
        );
        // Без time в entry считаем входом последний закрытый бар (как в scan DeMarker).
        if ($entryBarIndex === null && $entry !== null && $barCount > 0) {
            $entryBarIndex = $barCount - 1;
        }

        foreach ($zones as $zone) {
            $low = (float) ($zone['price_low'] ?? $zone['low'] ?? 0);
            $high = (float) ($zone['price_high'] ?? $zone['high'] ?? 0);
            if ($high <= $low) {
                continue;
            }
            $yTop = $this->priceToY($high, $priceMin, $priceMax, $plotY0, $candleH);
            $yBot = $this->priceToY($low, $priceMin, $priceMax, $plotY0, $candleH);
            $zoneColor = imagecolorallocatealpha($img, 59, 130, 246, 100);
            imagefilledrectangle($img, $plotX0, min($yTop, $yBot), $width - $marginRight, max($yTop, $yBot), $zoneColor);
        }

        // Подсветка свечей «подряд»: те же бары, с которых потом пойдут вертикали на DeMarker.
        $streakBarIndexes = [];
        foreach ($oscillatorGuides as $guide) {
            if (! is_array($guide)) {
                continue;
            }
            $idx = $this->barIndexByTime($timeIndex, $guide['time'] ?? null, $barCount);
            if ($idx === null || $idx === $entryBarIndex) {
                continue;
            }
            $streakBarIndexes[] = $idx;
        }
        if ($streakBarIndexes !== []) {
            $streakHighlight = imagecolorallocatealpha($img, 34, 211, 238, 108);
            foreach (array_unique($streakBarIndexes) as $idx) {
                $xLeft = (int) round($barX0 + $idx * $slotW);
                $xRight = (int) round($barX0 + ($idx + 1) * $slotW);
                imagefilledrectangle($img, $xLeft, $plotY0, max($xLeft + 2, $xRight), $plotY0 + $candleH, $streakHighlight);
            }
        }

        // Подсветка свечи входа — чтобы сразу было видно, на каком баре бот открылся.
        if ($entryBarIndex !== null) {
            $highlight = imagecolorallocatealpha($img, 250, 204, 21, 105);
            $xLeft = (int) round($barX0 + $entryBarIndex * $slotW);
            $xRight = (int) round($barX0 + ($entryBarIndex + 1) * $slotW);
            imagefilledrectangle($img, $xLeft, $plotY0, max($xLeft + 2, $xRight), $plotY0 + $candleH, $highlight);
        }

        foreach ($series as $idx => $bar) {
            $cx = (int) round($barX0 + ($idx + 0.5) * $slotW);
            $o = (float) $bar['open'];
            $h = (float) $bar['high'];
            $l = (float) $bar['low'];
            $c = (float) $bar['close'];
            $color = $c >= $o ? $bull : $bear;
            $yO = $this->priceToY($o, $priceMin, $priceMax, $plotY0, $candleH);
            $yC = $this->priceToY($c, $priceMin, $priceMax, $plotY0, $candleH);
            $yH = $this->priceToY($h, $priceMin, $priceMax, $plotY0, $candleH);
            $yL = $this->priceToY($l, $priceMin, $priceMax, $plotY0, $candleH);
            imageline($img, $cx, $yH, $cx, $yL, $color);
            $bodyTop = min($yO, $yC);
            $bodyH = max(1, abs($yC - $yO));
            $bodyW = max(2, (int) floor($slotW * 0.55));
            imagefilledrectangle($img, $cx - (int) floor($bodyW / 2), $bodyTop, $cx + (int) floor($bodyW / 2), $bodyTop + $bodyH, $color);
        }

        // Отрезки фрактальных уровней (от пивота до снятия), не на всю ширину графика.
        foreach ($segments as $segment) {
            if (! isset($segment['price']) || ! is_numeric($segment['price'])) {
                continue;
            }
            $price = (float) $segment['price'];
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $candleH);
            $x0 = $this->segmentX((int) ($segment['time_start'] ?? 0), $timeIndex, $barX0, $slotW, $barCount, $plotX0, $width - $marginRight, 'start');
            $x1 = $this->segmentX((int) ($segment['time_end'] ?? 0), $timeIndex, $barX0, $slotW, $barCount, $plotX0, $width - $marginRight, 'end');
            if ($x0 === null || $x1 === null || $x1 <= $x0) {
                continue;
            }
            $lineColor = $this->colorFromHex(
                $img,
                (string) ($segment['color'] ?? ''),
                148,
                163,
                184,
            );
            $style = strtolower((string) ($segment['style'] ?? 'dashed'));
            if ($style === 'dashed') {
                for ($x = $x0; $x < $x1; $x += 8) {
                    imageline($img, $x, $y, min($x + 4, $x1), $y, $lineColor);
                }
            } else {
                imageline($img, $x0, $y, $x1, $y, $lineColor);
            }
        }

        $labelLanes = [];
        foreach ($levels as $lvl) {
            if (! isset($lvl['price']) || ! is_numeric($lvl['price'])) {
                continue;
            }
            $price = (float) $lvl['price'];
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $candleH);
            $lineColor = $this->levelColor($img, (string) ($lvl['timeframe'] ?? ''));
            $style = strtolower((string) ($lvl['style'] ?? 'solid'));
            if ($style === 'dashed') {
                for ($x = $plotX0; $x < $width - $marginRight; $x += 8) {
                    imageline($img, $x, $y, min($x + 4, $width - $marginRight), $y, $lineColor);
                }
            } else {
                imageline($img, $plotX0, $y, $width - $marginRight, $y, $lineColor);
            }
            $label = mb_substr((string) ($lvl['label'] ?? $lvl['kind'] ?? 'level'), 0, 48);
            $labelY = $this->laneY($labelLanes, $y, 14, $height);
            $this->drawTextRight($img, $fontPath, $fontLabel, $width - $marginRight - 4, $labelY + 4, $lineColor, $label);
        }

        $markerLanes = [];
        foreach ($markers as $marker) {
            $markerTime = (int) ($marker['time'] ?? 0);
            $idx = $this->barIndexByTime($timeIndex, $markerTime, $barCount);
            if ($idx === null || ! isset($marker['price']) || ! is_numeric($marker['price'])) {
                continue;
            }
            $price = (float) $marker['price'];
            $cx = (int) round($barX0 + ($idx + 0.5) * $slotW);
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $candleH);
            $position = strtolower((string) ($marker['position'] ?? 'above'));
            $markerColor = $this->colorFromHex(
                $img,
                (string) ($marker['color'] ?? ''),
                245,
                158,
                11,
            );
            $this->drawMarkerTriangle($img, $cx, $y, $position, $markerColor);
            $label = mb_substr((string) ($marker['text'] ?? ''), 0, 36);
            if ($label === '') {
                continue;
            }
            $fontMarker = max(7.5, $fontLabel - 1);
            $labelY = $this->laneY($markerLanes, $position === 'below' ? $y + 16 : $y - 10, 12, $height);
            $labelX = $this->labelXBesideBar($fontPath, $fontMarker, $cx, $label, $width);
            $this->drawText($img, $fontPath, $fontMarker, $labelX, $labelY + 4, $markerColor, $label);
        }

        if ($entry && isset($entry['price']) && is_numeric($entry['price'])) {
            $price = (float) $entry['price'];
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $candleH);
            $side = strtoupper((string) ($entry['side'] ?? ''));
            $entryColor = $side === 'PUT' ? $bear : $bull;
            $xMid = $entryBarIndex !== null
                ? (int) round($barX0 + ($entryBarIndex + 0.5) * $slotW)
                : (int) round($barX0 + $plotW * 0.85);
            imagefilledellipse($img, $xMid, $y, 12, 12, $entryColor);
            $entryLabel = trim((string) ($entry['label'] ?? ''));
            if ($entryLabel === '') {
                $entryLabel = $side !== '' ? 'Вход '.$side : 'Вход';
            }
            $entryLabel = mb_substr($entryLabel, 0, 48);
            $entryLabelX = $this->labelXBesideBar($fontPath, $fontLabel, $xMid, $entryLabel, $width);
            $this->drawText($img, $fontPath, $fontLabel, $entryLabelX, $y - 8, $entryColor, $entryLabel);
        }

        if ($hasOscillator) {
            $this->drawOscillatorPane(
                $img,
                $fontPath,
                $fontAxis,
                $fontLabel,
                $oscillator,
                $oscValues,
                $plotX0,
                $oscY0,
                $plotW,
                $oscH,
                $width,
                $marginRight,
                $text,
                $grid,
                $barX0,
                $slotW,
            );
            $streakGuideColor = imagecolorallocate($img, 34, 211, 238);
            $streakColumn = imagecolorallocatealpha($img, 34, 211, 238, 105);
            // Сначала серия «подряд», затем вход — жёлтая линия входа поверх, если бары совпали.
            foreach ($oscillatorGuides as $guide) {
                if (! is_array($guide) || ! isset($guide['price']) || ! is_numeric($guide['price'])) {
                    continue;
                }
                $idx = $this->barIndexByTime($timeIndex, $guide['time'] ?? null, $barCount);
                if ($idx === null) {
                    continue;
                }
                $this->drawPriceToOscillatorGuide(
                    $img,
                    $fontPath,
                    $fontLabel,
                    (float) $guide['price'],
                    $oscillator,
                    $oscValues,
                    $idx,
                    $barX0,
                    $slotW,
                    $plotY0,
                    $candleH,
                    $oscY0,
                    $oscH,
                    $priceMin,
                    $priceMax,
                    $streakGuideColor,
                    $streakColumn,
                    (string) ($guide['label'] ?? ''),
                    false,
                );
            }
            // Вертикаль: свеча входа → то же время на DeMarker, чтобы не гадать «на какой бар смотреть».
            if ($entryBarIndex !== null && $entry && isset($entry['price']) && is_numeric($entry['price'])) {
                $this->drawPriceToOscillatorGuide(
                    $img,
                    $fontPath,
                    $fontLabel,
                    (float) $entry['price'],
                    $oscillator,
                    $oscValues,
                    $entryBarIndex,
                    $barX0,
                    $slotW,
                    $plotY0,
                    $candleH,
                    $oscY0,
                    $oscH,
                    $priceMin,
                    $priceMax,
                    $entryColor ?? imagecolorallocate($img, 250, 204, 21),
                    imagecolorallocatealpha($img, 250, 204, 21, 105),
                    '',
                    true,
                );
            }
        }

        if ($legend !== '') {
            $this->drawText(
                $img,
                $fontPath,
                max(7.5, $fontAxis),
                $plotX0,
                $height - 18,
                $text,
                mb_substr($legend, 0, 110),
            );
        }

        $dir = storage_path('app/card-charts/tmp');
        File::ensureDirectoryExists($dir);
        if (! is_writable($dir)) {
            @chmod($dir, 0777);
        }
        $path = $dir.'/'.Str::uuid()->toString().'.png';
        try {
            $ok = @imagepng($img, $path);
        } catch (\Throwable) {
            $ok = false;
        }
        imagedestroy($img);

        if (! $ok || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Нижняя панель осциллятора: зоны oversold/overbought и линия значений 0..1.
     *
     * @param  \GdImage  $img
     * @param  array<string, mixed>  $oscillator
     * @param  list<mixed>  $values
     */
    private function drawOscillatorPane(
        $img,
        ?string $fontPath,
        float $fontAxis,
        float $fontLabel,
        array $oscillator,
        array $values,
        int $plotX0,
        int $oscY0,
        int $plotW,
        int $oscH,
        int $width,
        int $marginRight,
        int $textColor,
        int $gridColor,
        float $barX0,
        float $slotW,
    ): void {
        $oscMin = (float) ($oscillator['min'] ?? 0.0);
        $oscMax = (float) ($oscillator['max'] ?? 1.0);
        if ($oscMax <= $oscMin) {
            $oscMax = $oscMin + 1.0;
        }
        $oversold = (float) ($oscillator['oversold'] ?? 0.3);
        $overbought = (float) ($oscillator['overbought'] ?? 0.7);
        $label = mb_substr((string) ($oscillator['label'] ?? 'Osc'), 0, 24);
        $rightX = $width - $marginRight;

        $oversoldFill = imagecolorallocatealpha($img, 34, 197, 94, 110);
        $overboughtFill = imagecolorallocatealpha($img, 239, 68, 68, 110);
        $lineColor = imagecolorallocate($img, 0, 255, 255);
        $lastDot = imagecolorallocate($img, 250, 204, 21);

        $yTop = $this->priceToY($oscMax, $oscMin, $oscMax, $oscY0, $oscH);
        $yBot = $this->priceToY($oscMin, $oscMin, $oscMax, $oscY0, $oscH);
        imageline($img, $plotX0, $yTop, $rightX, $yTop, $gridColor);
        imageline($img, $plotX0, $yBot, $rightX, $yBot, $gridColor);

        $yOs = $this->priceToY($oversold, $oscMin, $oscMax, $oscY0, $oscH);
        $yOb = $this->priceToY($overbought, $oscMin, $oscMax, $oscY0, $oscH);
        imagefilledrectangle($img, $plotX0, $yOs, $rightX, $yBot, $oversoldFill);
        imagefilledrectangle($img, $plotX0, $yTop, $rightX, $yOb, $overboughtFill);
        imageline($img, $plotX0, $yOs, $rightX, $yOs, imagecolorallocate($img, 34, 197, 94));
        imageline($img, $plotX0, $yOb, $rightX, $yOb, imagecolorallocate($img, 239, 68, 68));

        $this->drawText($img, $fontPath, $fontLabel, $plotX0 + 4, $oscY0 + 12, $textColor, $label);
        $this->drawText($img, $fontPath, $fontAxis, 4, $yOb + 4, $textColor, number_format($overbought * 100, 0).'%');
        $this->drawText($img, $fontPath, $fontAxis, 4, $yOs + 4, $textColor, number_format($oversold * 100, 0).'%');

        $prevX = null;
        $prevY = null;
        $lastX = $plotX0;
        $lastY = $yBot;
        foreach ($values as $idx => $raw) {
            $value = is_numeric($raw) ? (float) $raw : 0.0;
            $x = (int) round($barX0 + ($idx + 0.5) * $slotW);
            $y = $this->priceToY($value, $oscMin, $oscMax, $oscY0, $oscH);
            if ($prevX !== null && $prevY !== null) {
                imageline($img, $prevX, $prevY, $x, $y, $lineColor);
            }
            $prevX = $x;
            $prevY = $y;
            $lastX = $x;
            $lastY = $y;
        }
        imagefilledellipse($img, $lastX, $lastY, 8, 8, $lastDot);
    }

    /**
     * Пунктир от цены свечи до значения DeMarker на том же баре.
     *
     * @param  \GdImage  $img
     * @param  array<string, mixed>  $oscillator
     * @param  list<mixed>  $oscValues
     */
    private function drawPriceToOscillatorGuide(
        $img,
        ?string $fontPath,
        float $fontLabel,
        float $price,
        array $oscillator,
        array $oscValues,
        int $barIndex,
        float $barX0,
        float $slotW,
        int $plotY0,
        int $candleH,
        int $oscY0,
        int $oscH,
        float $priceMin,
        float $priceMax,
        int $lineColor,
        int $columnColor,
        string $indexLabel,
        bool $showDemarkerValue,
    ): void {
        $oscMin = (float) ($oscillator['min'] ?? 0.0);
        $oscMax = (float) ($oscillator['max'] ?? 1.0);
        if ($oscMax <= $oscMin) {
            $oscMax = $oscMin + 1.0;
        }
        $raw = $oscValues[$barIndex] ?? null;
        if (! is_numeric($raw)) {
            return;
        }
        $oscValue = (float) $raw;
        $xMid = (int) round($barX0 + ($barIndex + 0.5) * $slotW);
        $yPrice = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $candleH);
        $yOsc = $this->priceToY($oscValue, $oscMin, $oscMax, $oscY0, $oscH);
        // Колонка на DeMarker под той же свечой, затем пунктир поверх (чтобы линия не пряталась).
        $xLeft = (int) round($barX0 + $barIndex * $slotW);
        $xRight = (int) round($barX0 + ($barIndex + 1) * $slotW);
        imagefilledrectangle($img, $xLeft, $oscY0, max($xLeft + 2, $xRight), $oscY0 + $oscH, $columnColor);
        $yTop = min($yPrice, $yOsc);
        $yBot = max($yPrice, $yOsc);
        for ($y = $yTop; $y < $yBot; $y += 7) {
            imageline($img, $xMid, $y, $xMid, min($y + 4, $yBot), $lineColor);
        }
        $dot = $showDemarkerValue ? 10 : 7;
        imagefilledellipse($img, $xMid, $yOsc, $dot, $dot, $lineColor);
        if ($showDemarkerValue) {
            $pct = number_format($oscValue * 100, 1);
            $this->drawText(
                $img,
                $fontPath,
                max(7.5, $fontLabel - 0.5),
                $xMid + 8,
                $yOsc - 4,
                $lineColor,
                'DeM '.$pct,
            );

            return;
        }
        // Номер свечи серии над панелью DeMarker — без процентов, иначе подписи налезут друг на друга.
        if ($indexLabel !== '') {
            $this->drawText(
                $img,
                $fontPath,
                max(7.0, $fontLabel - 1.5),
                $xMid - 3,
                $oscY0 + 12,
                $lineColor,
                $indexLabel,
            );
        }
    }

    /**
     * @param  \GdImage  $img
     */
    private function drawText($img, ?string $fontPath, float $size, int $x, int $yBaseline, int $color, string $text): void
    {
        if ($fontPath !== null && function_exists('imagettftext')) {
            imagettftext($img, $size, 0, $x, $yBaseline, $color, $fontPath, $text);

            return;
        }
        imagestring($img, 2, $x, max(0, $yBaseline - 10), $this->asciiFallback($text), $color);
    }

    /**
     * @param  \GdImage  $img
     */
    private function drawTextRight($img, ?string $fontPath, float $size, int $rightX, int $yBaseline, int $color, string $text): void
    {
        if ($fontPath !== null && function_exists('imagettftext')) {
            $box = imagettfbbox($size, 0, $fontPath, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $width = abs($box[2] - $box[0]);
            imagettftext($img, $size, 0, max(0, $rightX - $width), $yBaseline, $color, $fontPath, $text);

            return;
        }
        $fallback = $this->asciiFallback($text);
        imagestring($img, 2, max(0, $rightX - strlen($fallback) * 6), max(0, $yBaseline - 10), $fallback, $color);
    }

    /** @param  array<int, int>  $lanes  */
    private function laneY(array &$lanes, int $y, int $step, int $imageHeight = self::HEIGHT): int
    {
        $bucket = (int) round($y / $step);
        $top = max(18, min($imageHeight - 22, $y - 6));
        if (isset($lanes[$bucket]) && abs($lanes[$bucket] - $top) < $step - 2) {
            $top = max(2, $top - $step);
        }
        $lanes[$bucket] = $top;

        return $top;
    }

    private function resolveFontPath(): ?string
    {
        foreach (self::FONT_CANDIDATES as $candidate) {
            $path = str_starts_with($candidate, '/')
                ? $candidate
                : base_path($candidate);
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Если TTF недоступен — только латиница и цифры. */
    private function asciiFallback(string $text): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii !== false && $ascii !== '' ? $ascii : preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '?';
    }

    /**
     * @param  list<array<string,mixed>>  $candles
     * @return list<array{open:float,high:float,low:float,close:float}>
     */
    private function normalizeCandles(array $candles): array
    {
        $out = [];
        foreach ($candles as $c) {
            if (! is_array($c)) {
                continue;
            }
            $out[] = [
                'time' => (int) ($c['time'] ?? 0),
                'open' => (float) ($c['open'] ?? 0),
                'high' => (float) ($c['high'] ?? 0),
                'low' => (float) ($c['low'] ?? 0),
                'close' => (float) ($c['close'] ?? 0),
            ];
        }

        return $out;
    }

    private function priceToY(float $price, float $min, float $max, int $plotY0, int $plotH): int
    {
        if ($max <= $min) {
            return $plotY0 + (int) ($plotH / 2);
        }
        $ratio = ($max - $price) / ($max - $min);

        return (int) round($plotY0 + $plotH * $ratio);
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

    /**
     * @param  \GdImage  $img
     */
    private function levelColor($img, string $timeframe): int
    {
        $tf = strtoupper(trim($timeframe));

        return match ($tf) {
            'H1' => imagecolorallocate($img, 168, 85, 247),
            'M15' => imagecolorallocate($img, 59, 130, 246),
            'M5' => imagecolorallocate($img, 34, 197, 94),
            'M1' => imagecolorallocate($img, 245, 158, 11),
            default => imagecolorallocate($img, 148, 163, 184),
        };
    }

    /**
     * Индекс бара только при точном совпадении времени.
     * Не «притягиваем» старые метки к ближайшей свече — иначе вся история
     * склеивается в левый край после обрезки серии до max_bars.
     *
     * @param  array<int, int>  $timeIndex
     */
    private function barIndexByTime(array $timeIndex, mixed $time, int $barCount): ?int
    {
        if ($barCount < 1 || ! is_numeric($time)) {
            return null;
        }
        $t = (int) $time;
        if ($t <= 0 || ! isset($timeIndex[$t])) {
            return null;
        }

        return $timeIndex[$t];
    }

    /**
     * X для отрезка уровня: бар в кадре — центр слота; левее окна — левый край плота.
     *
     * @param  array<int, int>  $timeIndex
     */
    private function segmentX(int $time, array $timeIndex, float $barX0, float $slotW, int $barCount, int $leftX, int $rightX, string $side): ?int
    {
        if ($time <= 0 || $timeIndex === [] || $barCount < 1) {
            return null;
        }
        if (isset($timeIndex[$time])) {
            return (int) round($barX0 + ($timeIndex[$time] + 0.5) * $slotW);
        }
        $times = array_keys($timeIndex);
        $first = min($times);
        $last = max($times);
        if ($time < $first) {
            return $side === 'end' ? null : $leftX;
        }
        if ($time > $last) {
            return $side === 'start' ? null : $rightX;
        }

        $bestIdx = null;
        $bestDelta = PHP_INT_MAX;
        foreach ($timeIndex as $barTime => $idx) {
            $delta = abs($barTime - $time);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $bestIdx = $idx;
            }
        }

        return $bestIdx === null ? null : (int) round($barX0 + ($bestIdx + 0.5) * $slotW);
    }

    /**
     * Подпись у свечи: у правого края — слева от бара, иначе справа, с зазором до рамки.
     */
    private function labelXBesideBar(?string $fontPath, float $fontSize, int $cx, string $label, int $imageWidth): int
    {
        $textW = $this->textWidth($fontPath, $fontSize, $label);
        $gap = 10;
        $edge = 12;
        $preferLeft = $cx > (int) ($imageWidth * 0.62);
        if (! $preferLeft) {
            $rightX = $cx + $gap;
            if ($rightX + $textW <= $imageWidth - $edge) {
                return $rightX;
            }
        }
        $leftX = $cx - $gap - $textW;

        return max($edge, min($leftX, $imageWidth - $edge - $textW));
    }

    private function textWidth(?string $fontPath, float $size, string $text): int
    {
        if ($fontPath !== null && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $fontPath, $text);
            if (is_array($box)) {
                return max(1, abs($box[2] - $box[0]));
            }
        }

        return max(1, strlen($this->asciiFallback($text)) * 6);
    }

    /**
     * Треугольник метки индикатора: «выше свечи» смотрит вниз, «ниже» — вверх.
     *
     * @param  \GdImage  $img
     */
    private function drawMarkerTriangle($img, int $cx, int $y, string $position, int $color): void
    {
        $size = 6;
        if ($position === 'below') {
            $points = [$cx, $y + $size, $cx - $size, $y - 2, $cx + $size, $y - 2];
        } else {
            $points = [$cx, $y - $size, $cx - $size, $y + 2, $cx + $size, $y + 2];
        }
        imagefilledpolygon($img, $points, $color);
    }

    /**
     * Цвет из #RRGGBB; при битой строке — запасной RGB.
     *
     * @param  \GdImage  $img
     */
    private function colorFromHex($img, string $hex, int $fallbackR, int $fallbackG, int $fallbackB): int
    {
        $clean = ltrim(trim($hex), '#');
        if (strlen($clean) === 3 && ctype_xdigit($clean)) {
            $clean = $clean[0].$clean[0].$clean[1].$clean[1].$clean[2].$clean[2];
        }
        if (strlen($clean) !== 6 || ! ctype_xdigit($clean)) {
            return imagecolorallocate($img, $fallbackR, $fallbackG, $fallbackB);
        }

        return imagecolorallocate(
            $img,
            hexdec(substr($clean, 0, 2)),
            hexdec(substr($clean, 2, 2)),
            hexdec(substr($clean, 4, 2)),
        );
    }
}
