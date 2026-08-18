<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * PNG-график для Telegram: свечи M1 + уровни/зоны из chart_levels (PHP GD).
 * Текст — через TrueType (DejaVu/Liberation): встроенный imagestring не рисует кириллицу.
 */
class TelegramChartImageRenderer
{
    private const WIDTH = 900;

    private const HEIGHT = 520;

    private const MARGIN_LEFT = 72;

    private const MARGIN_RIGHT = 16;

    private const MARGIN_TOP = 44;

    private const MARGIN_BOTTOM = 36;

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
     * @param  array{levels?:list<array<string,mixed>>,zones?:list<array<string,mixed>>,entry?:?array<string,mixed>,oscillator?:?array<string,mixed>}  $chartLevels
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
        $marginLeft = max(8, (int) ($options['margin_left'] ?? self::MARGIN_LEFT));
        $marginRight = max(4, (int) ($options['margin_right'] ?? self::MARGIN_RIGHT));
        $marginTop = max(8, (int) ($options['margin_top'] ?? self::MARGIN_TOP));
        $marginBottom = max(8, (int) ($options['margin_bottom'] ?? self::MARGIN_BOTTOM));
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
        if ($entry && isset($entry['price']) && is_numeric($entry['price'])) {
            $p = (float) $entry['price'];
            $priceMin = min($priceMin, $p);
            $priceMax = max($priceMax, $p);
        }

        $pad = max(1e-8, ($priceMax - $priceMin) * 0.06);
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
        $this->drawText($img, $fontPath, $fontTitle, $plotX0, max(14, (int) round($marginTop * 0.5)), $text, $title);

        for ($i = 0; $i <= 5; $i++) {
            $ratio = $i / 5;
            $price = $priceMax - ($priceMax - $priceMin) * $ratio;
            $y = (int) round($plotY0 + $candleH * $ratio);
            imageline($img, $plotX0, $y, $width - $marginRight, $y, $grid);
            $this->drawText($img, $fontPath, $fontAxis, 4, $y + 4, $text, $this->formatPrice($price));
        }

        $barCount = count($series);
        $slotW = $plotW / max(1, $barCount);

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

        foreach ($series as $idx => $bar) {
            $cx = (int) round($plotX0 + ($idx + 0.5) * $slotW);
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

        if ($entry && isset($entry['price']) && is_numeric($entry['price'])) {
            $price = (float) $entry['price'];
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $candleH);
            $side = strtoupper((string) ($entry['side'] ?? ''));
            $entryColor = $side === 'PUT' ? $bear : $bull;
            $xMid = (int) round($plotX0 + $plotW * 0.85);
            imagefilledellipse($img, $xMid, $y, 10, 10, $entryColor);
            $entryLabel = trim((string) ($entry['label'] ?? ''));
            if ($entryLabel === '') {
                $entryLabel = $side !== '' ? 'Вход '.$side : 'Вход';
            }
            $this->drawText($img, $fontPath, $fontLabel, $xMid + 12, $y + 4, $entryColor, mb_substr($entryLabel, 0, 40));
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
    ): void {
        $oscMin = (float) ($oscillator['min'] ?? 0.0);
        $oscMax = (float) ($oscillator['max'] ?? 1.0);
        if ($oscMax <= $oscMin) {
            $oscMax = $oscMin + 1.0;
        }
        $oversold = (float) ($oscillator['oversold'] ?? 0.3);
        $overbought = (float) ($oscillator['overbought'] ?? 0.7);
        $label = mb_substr((string) ($oscillator['label'] ?? 'Osc'), 0, 24);
        $barCount = count($values);
        $slotW = $plotW / max(1, $barCount);
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
            $x = (int) round($plotX0 + ($idx + 0.5) * $slotW);
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
        $top = max(2, min($imageHeight - 14, $y - 6));
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
}
