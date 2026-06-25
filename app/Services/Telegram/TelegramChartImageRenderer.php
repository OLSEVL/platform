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
     * @param  array{levels?:list<array<string,mixed>>,zones?:list<array<string,mixed>>,entry?:?array<string,mixed>}  $chartLevels
     */
    public function render(string $instrument, array $candles, array $chartLevels): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $fontPath = $this->resolveFontPath();

        $series = $this->normalizeCandles($candles);
        if ($series === []) {
            return null;
        }

        $maxBars = 90;
        if (count($series) > $maxBars) {
            $series = array_slice($series, -$maxBars);
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

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            return null;
        }

        $bg = imagecolorallocate($img, 15, 17, 23);
        $grid = imagecolorallocate($img, 45, 50, 62);
        $text = imagecolorallocate($img, 180, 186, 198);
        $bull = imagecolorallocate($img, 34, 197, 94);
        $bear = imagecolorallocate($img, 239, 68, 68);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        $plotW = self::WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
        $plotH = self::HEIGHT - self::MARGIN_TOP - self::MARGIN_BOTTOM;
        $plotX0 = self::MARGIN_LEFT;
        $plotY0 = self::MARGIN_TOP;

        $title = $instrument.' · M1 · '.now()->utc()->format('d.m.Y H:i').' UTC';
        $this->drawText($img, $fontPath, self::FONT_SIZE_TITLE, $plotX0, 22, $text, $title);

        for ($i = 0; $i <= 5; $i++) {
            $ratio = $i / 5;
            $price = $priceMax - ($priceMax - $priceMin) * $ratio;
            $y = (int) round($plotY0 + $plotH * $ratio);
            imageline($img, $plotX0, $y, self::WIDTH - self::MARGIN_RIGHT, $y, $grid);
            $this->drawText($img, $fontPath, self::FONT_SIZE_AXIS, 4, $y + 4, $text, $this->formatPrice($price));
        }

        $barCount = count($series);
        $slotW = $plotW / max(1, $barCount);

        foreach ($zones as $zone) {
            $low = (float) ($zone['price_low'] ?? $zone['low'] ?? 0);
            $high = (float) ($zone['price_high'] ?? $zone['high'] ?? 0);
            if ($high <= $low) {
                continue;
            }
            $yTop = $this->priceToY($high, $priceMin, $priceMax, $plotY0, $plotH);
            $yBot = $this->priceToY($low, $priceMin, $priceMax, $plotY0, $plotH);
            $zoneColor = imagecolorallocatealpha($img, 59, 130, 246, 100);
            imagefilledrectangle($img, $plotX0, min($yTop, $yBot), self::WIDTH - self::MARGIN_RIGHT, max($yTop, $yBot), $zoneColor);
        }

        foreach ($series as $idx => $bar) {
            $cx = (int) round($plotX0 + ($idx + 0.5) * $slotW);
            $o = (float) $bar['open'];
            $h = (float) $bar['high'];
            $l = (float) $bar['low'];
            $c = (float) $bar['close'];
            $color = $c >= $o ? $bull : $bear;
            $yO = $this->priceToY($o, $priceMin, $priceMax, $plotY0, $plotH);
            $yC = $this->priceToY($c, $priceMin, $priceMax, $plotY0, $plotH);
            $yH = $this->priceToY($h, $priceMin, $priceMax, $plotY0, $plotH);
            $yL = $this->priceToY($l, $priceMin, $priceMax, $plotY0, $plotH);
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
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $plotH);
            $lineColor = $this->levelColor($img, (string) ($lvl['timeframe'] ?? ''));
            $style = strtolower((string) ($lvl['style'] ?? 'solid'));
            if ($style === 'dashed') {
                for ($x = $plotX0; $x < self::WIDTH - self::MARGIN_RIGHT; $x += 8) {
                    imageline($img, $x, $y, min($x + 4, self::WIDTH - self::MARGIN_RIGHT), $y, $lineColor);
                }
            } else {
                imageline($img, $plotX0, $y, self::WIDTH - self::MARGIN_RIGHT, $y, $lineColor);
            }
            $label = mb_substr((string) ($lvl['label'] ?? $lvl['kind'] ?? 'level'), 0, 48);
            $labelY = $this->laneY($labelLanes, $y, 14);
            $this->drawTextRight($img, $fontPath, self::FONT_SIZE_LABEL, self::WIDTH - self::MARGIN_RIGHT - 4, $labelY + 4, $lineColor, $label);
        }

        if ($entry && isset($entry['price']) && is_numeric($entry['price'])) {
            $price = (float) $entry['price'];
            $y = $this->priceToY($price, $priceMin, $priceMax, $plotY0, $plotH);
            $side = strtoupper((string) ($entry['side'] ?? ''));
            $entryColor = $side === 'PUT' ? $bear : $bull;
            $xMid = (int) round($plotX0 + $plotW * 0.85);
            imagefilledellipse($img, $xMid, $y, 10, 10, $entryColor);
            $entryLabel = trim((string) ($entry['label'] ?? ''));
            if ($entryLabel === '') {
                $entryLabel = $side !== '' ? 'Вход '.$side : 'Вход';
            }
            $this->drawText($img, $fontPath, self::FONT_SIZE_LABEL, $xMid + 12, $y + 4, $entryColor, mb_substr($entryLabel, 0, 40));
        }

        $dir = storage_path('app/telegram/charts');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.Str::uuid()->toString().'.png';
        $ok = imagepng($img, $path);
        imagedestroy($img);

        return $ok ? $path : null;
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

    /** Разносим подписи по вертикали, чтобы не накладывались.
     * @param  array<int, int>  $lanes
     */
    private function laneY(array &$lanes, int $y, int $step): int
    {
        $bucket = (int) round($y / $step);
        $top = max(2, min(self::HEIGHT - 14, $y - 6));
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
