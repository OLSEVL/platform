<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * PNG сигнала Impulse LVL как в updateBotM1 render_signal_chart:
 * 960×600, фон #171b2d, ~40 M1-свечей, цветные LVL-зоны по ТФ, золотая рамка на сигнальной свече.
 */
class ImpulseLvl1minTelegramChartRenderer
{
    private const WIDTH = 960;

    private const HEIGHT = 600;

    private const LEFT = 86;

    private const RIGHT = 944;

    private const TOP = 12;

    private const BOTTOM = 588;

    /** Холодные зоны ТФ — палитра оригинального MACD-тёмного графика. */
    private const ZONE_COLORS = [
        60 => [0x1e, 0x3a, 0x5f],
        300 => [0x31, 0x2e, 0x5f],
        900 => [0x1f, 0x4a, 0x46],
        1800 => [0x44, 0x2a, 0x52],
    ];

    /** @var list<string> */
    private const FONT_CANDIDATES = [
        'resources/fonts/DejaVuSans.ttf',
        'storage/app/fonts/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];

    /**
     * Кадр аналитика Impulse LVL: те же зоны, что в snapshot, плюс строки «как сработал фильтр».
     *
     * @param  list<array<string, mixed>>  $candles
     * @param  array{zones?:list<array<string,mixed>>,overlay_lines?:list<string>,price_levels?:list<array<string,mixed>>,signal_time?:int}  $telegram
     */
    public function renderFromTelegramPayload(array $candles, array $telegram): ?string
    {
        $zones = is_array($telegram['zones'] ?? null) ? $telegram['zones'] : [];
        $lines = is_array($telegram['overlay_lines'] ?? null) ? $telegram['overlay_lines'] : [];
        $levels = is_array($telegram['price_levels'] ?? null) ? $telegram['price_levels'] : [];
        $markers = is_array($telegram['markers'] ?? null) ? $telegram['markers'] : [];

        return $this->render($candles, $zones, [
            'max_bars' => 50,
            'signal_time' => (int) ($telegram['signal_time'] ?? 0),
            'outline_last_bar' => true,
            'overlay_lines' => $lines,
            'price_levels' => $levels,
            'markers' => $markers,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $candles  M1 OHLC (time/open/high/low/close)
     * @param  list<array{tf?:string,tf_seconds?:int,zone_min?:float,zone_max?:float,price?:float}>  $zones
     * @param  array{
     *     max_bars?:int,
     *     signal_time?:int,
     *     outline_last_bar?:bool,
     *     markers?:list<array{time?:int,price?:float,text?:string,lines?:list<string>,color?:string,position?:string,arrow?:string,size?:string}>,
     *     overlay_lines?:list<string>,
     *     price_levels?:list<array{price?:float,label?:string,color?:string}>,
     *     right_pad_bars?:int
     * }  $options
     */
    public function render(array $candles, array $zones = [], array $options = []): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        // Дефолт 50 — как TELEGRAM_CHART_CANDLES в updateBotM1_v1_04/config.py.
        $maxBars = max(10, (int) ($options['max_bars'] ?? 50));
        $signalTime = (int) ($options['signal_time'] ?? 0);
        // Engulfing (#45): золотая рамка на последней свече кадра — лучше видно вход.
        $outlineLastBar = (bool) ($options['outline_last_bar'] ?? false);
        // Отступ справа в «свечах»: чтобы подписи у хвоста (③ ВХОД) не упирались в край.
        $rightPadBars = max(0, min(10, (int) ($options['right_pad_bars'] ?? 0)));
        /** @var list<array<string,mixed>> $markers */
        $markers = is_array($options['markers'] ?? null) ? $options['markers'] : [];
        /** @var list<string> $overlayLines */
        $overlayLines = [];
        if (is_array($options['overlay_lines'] ?? null)) {
            foreach ($options['overlay_lines'] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $overlayLines[] = $line;
                }
            }
        }
        /** @var list<array<string,mixed>> $priceLevels */
        $priceLevels = is_array($options['price_levels'] ?? null) ? $options['price_levels'] : [];

        $series = $this->normalizeCandles($candles);
        if (count($series) < 2) {
            return null;
        }
        if (count($series) > $maxBars) {
            $series = array_slice($series, -$maxBars);
        }

        $zoneBounds = [];
        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $lo = (float) ($zone['zone_min'] ?? $zone['price_low'] ?? 0);
            $hi = (float) ($zone['zone_max'] ?? $zone['price_high'] ?? 0);
            if ($hi <= $lo) {
                $price = (float) ($zone['price'] ?? 0);
                if ($price <= 0) {
                    continue;
                }
                $lo = $price;
                $hi = $price;
            }
            $zoneBounds[] = [
                'tf_seconds' => (int) ($zone['tf_seconds'] ?? 0),
                'tf' => (string) ($zone['tf'] ?? ''),
                'low' => $lo,
                'high' => $hi,
                // Касание текущим баром — ярче контур, чтобы видно работу LVL-фильтра.
                'touching' => (bool) ($zone['touching'] ?? false),
            ];
        }

        $lows = array_map(static fn (array $c): float => (float) $c['low'], $series);
        $highs = array_map(static fn (array $c): float => (float) $c['high'], $series);
        foreach ($zoneBounds as $zb) {
            $lows[] = $zb['low'];
            $highs[] = $zb['high'];
        }
        foreach ($priceLevels as $pl) {
            if (! is_array($pl)) {
                continue;
            }
            $p = (float) ($pl['price'] ?? 0);
            if ($p > 0) {
                $lows[] = $p;
                $highs[] = $p;
            }
        }
        $priceLow = min($lows);
        $priceHigh = max($highs);
        // Как в оригинале: без паддинга 10% — движение занимает весь кадр.
        if ($priceHigh <= $priceLow) {
            $span = max(abs($priceHigh), 1.0) * 1e-6;
            $priceLow -= $span / 2;
            $priceHigh += $span / 2;
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            return null;
        }
        imagesavealpha($img, false);

        $bg = imagecolorallocate($img, 0x17, 0x1b, 0x2d);
        $grid = imagecolorallocate($img, 0x25, 0x2b, 0x3d);
        $timeGrid = imagecolorallocate($img, 0x34, 0x3b, 0x4f);
        $priceText = imagecolorallocate($img, 0x8f, 0x9b, 0xb3);
        $zoneLabel = imagecolorallocate($img, 0xcb, 0xd5, 0xe1);
        $bull = imagecolorallocate($img, 0x35, 0xc4, 0x3a);
        $bear = imagecolorallocate($img, 0xf4, 0x43, 0x36);
        $signalOutline = imagecolorallocate($img, 0xd4, 0xc5, 0x6f);
        imagefilledrectangle($img, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $bg);

        $fontPath = $this->resolveFontPath();
        $yFn = static function (float $price) use ($priceLow, $priceHigh): int {
            return (int) (self::BOTTOM - ($price - $priceLow) / ($priceHigh - $priceLow) * (self::BOTTOM - self::TOP));
        };

        // Горизонтальная сетка с «красивым» шагом цены (1/2/5 × 10^n).
        // Подписи цены — imagestring (быстро); TTF только для редких меток зон.
        $targetGridLines = 6;
        $rawStep = ($priceHigh - $priceLow) / $targetGridLines;
        $power = 10 ** (int) floor(log10(max($rawStep, 1e-12)));
        $normalizedStep = $rawStep / $power;
        $nice = $normalizedStep <= 1 ? 1 : ($normalizedStep <= 2 ? 2 : ($normalizedStep <= 5 ? 5 : 10));
        $priceStep = $nice * $power;
        $decimals = max(0, min(8, (int) ceil(-log10($priceStep))));
        $gridPrice = ceil($priceLow / $priceStep) * $priceStep;
        while ($gridPrice <= $priceHigh + $priceStep * 1e-9) {
            $gy = $yFn($gridPrice);
            imageline($img, self::LEFT, $gy, self::RIGHT, $gy, $grid);
            $label = number_format($gridPrice, $decimals, '.', '');
            $w = strlen($label) * imagefontwidth(2);
            imagestring($img, 2, max(0, self::LEFT - 8 - $w), max(0, $gy - 6), $label, $priceText);
            $gridPrice += $priceStep;
        }

        $barCount = count($series);
        // Слоты = свечи + пустой хвост справа (для Engulfing — 3 свечи подписи «ВХОД»).
        $plotSlots = max(1, $barCount + $rightPadBars);
        $step = (self::RIGHT - self::LEFT) / $plotSlots;

        // Вертикали на :00/:05/:10… (границы 5-минутных блоков).
        foreach ($series as $index => $item) {
            if (((int) $item['time']) % 300 === 0) {
                $x = (int) (self::LEFT + $index * $step);
                imageline($img, $x, self::TOP, $x, self::BOTTOM, $timeGrid);
            }
        }

        foreach ($zoneBounds as $zi => $zb) {
            $rgb = self::ZONE_COLORS[$zb['tf_seconds']] ?? [0x94, 0xa3, 0xb8];
            $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
            $y1 = $yFn($zb['high']);
            $y2 = $yFn($zb['low']);
            $top = min($y1, $y2);
            $bot = max($y1, $y2);
            imagefilledrectangle($img, self::LEFT, $top, self::RIGHT, $bot, $color);
            // Касание текущим баром — золотой контур: фильтр LVL пускает только такие зоны.
            if (! empty($zb['touching'])) {
                $touch = imagecolorallocate($img, 0xfb, 0xbf, 0x24);
                imagerectangle($img, self::LEFT, $top, self::RIGHT - 1, max($top + 1, $bot), $touch);
            }
            $tf = $zb['tf'] !== '' ? $zb['tf'] : ('M'.max(1, intdiv(max(1, $zb['tf_seconds']), 60)));
            $this->drawTextLeft(
                $img,
                $fontPath,
                16,
                self::LEFT + 5,
                $top + 3 + $zi * 22,
                $zoneLabel,
                strtoupper($tf).(! empty($zb['touching']) ? ' · касание' : '')
            );
        }

        $bodyWidth = max(2, (int) ($step * 0.78));
        foreach ($series as $index => $item) {
            $x = (int) (self::LEFT + ($index + 0.5) * $step);
            $openY = $yFn((float) $item['open']);
            $closeY = $yFn((float) $item['close']);
            $highY = $yFn((float) $item['high']);
            $lowY = $yFn((float) $item['low']);
            // Доджи (close == open) — красные, как в оригинале.
            $up = (float) $item['close'] > (float) $item['open'];
            $color = $up ? $bull : $bear;
            imageline($img, $x, $highY, $x, $lowY, $color);
            $bodyTop = min($openY, $closeY);
            $bodyBot = max($openY, $closeY);
            if ($bodyBot <= $bodyTop) {
                $bodyBot = $bodyTop + 1;
            }
            imagefilledrectangle(
                $img,
                $x - intdiv($bodyWidth, 2),
                $bodyTop,
                $x + intdiv($bodyWidth, 2),
                $bodyBot,
                $color
            );
            // Золотая контурная рамка на сигнальной свече (как updateBotM1).
            if ($signalTime > 0 && (int) $item['time'] === $signalTime) {
                $this->drawBarOutline($img, $x, $bodyWidth, $signalOutline);
            }
        }

        // Отдельная рамка на последнем баре кадра (если это не та же свеча, что signal_time).
        if ($outlineLastBar && $barCount > 0) {
            $last = $series[$barCount - 1];
            $lastTime = (int) ($last['time'] ?? 0);
            if ($lastTime > 0 && $lastTime !== $signalTime) {
                $x = (int) (self::LEFT + (($barCount - 1) + 0.5) * $step);
                $this->drawBarOutline($img, $x, $bodyWidth, $signalOutline);
            } elseif ($signalTime <= 0) {
                // Нет signal_time — всё равно выделяем хвост серии.
                $x = (int) (self::LEFT + (($barCount - 1) + 0.5) * $step);
                $this->drawBarOutline($img, $x, $bodyWidth, $signalOutline);
            }
        }

        // Горизонталь цены входа (Engulfing) — пунктир + подпись «вход».
        foreach ($priceLevels as $pl) {
            if (! is_array($pl)) {
                continue;
            }
            $p = (float) ($pl['price'] ?? 0);
            if ($p <= 0) {
                continue;
            }
            $rgb = $this->parseHexRgb((string) ($pl['color'] ?? ''), [0xd4, 0xc5, 0x6f]);
            $lvlColor = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
            $lvlTextColor = imagecolorallocate($img, 0xff, 0xff, 0xff);
            $y = $yFn($p);
            for ($xDash = self::LEFT; $xDash < self::RIGHT; $xDash += 8) {
                imageline($img, $xDash, $y, min(self::RIGHT, $xDash + 4), $y, $lvlColor);
            }
            $lvlLabel = trim((string) ($pl['label'] ?? ''));
            if ($lvlLabel !== '') {
                $this->drawTextLeft(
                    $img,
                    $fontPath,
                    11,
                    self::RIGHT - 170,
                    max(self::TOP + 2, $y - 16),
                    $lvlTextColor,
                    $lvlLabel
                );
            }
        }

        // Метки шагов ①②③ со стрелками направления и многострочным русским текстом.
        $timeToIndex = [];
        foreach ($series as $index => $item) {
            $timeToIndex[(int) $item['time']] = $index;
        }
        foreach ($markers as $marker) {
            if (! is_array($marker)) {
                continue;
            }
            $mTime = (int) ($marker['time'] ?? 0);
            if ($mTime <= 0 || ! isset($timeToIndex[$mTime])) {
                continue;
            }
            $idx = $timeToIndex[$mTime];
            $bar = $series[$idx];
            $x = (int) (self::LEFT + ($idx + 0.5) * $step);
            $price = (float) ($marker['price'] ?? 0);
            if ($price <= 0) {
                $price = (float) $bar['close'];
            }
            $pos = strtolower((string) ($marker['position'] ?? 'above'));
            $isEntry = strtolower((string) ($marker['size'] ?? '')) === 'entry';
            $anchorY = $yFn($price);
            $arrow = strtolower((string) ($marker['arrow'] ?? ''));
            $rgb = $this->parseHexRgb((string) ($marker['color'] ?? ''), [0xcb, 0xd5, 0xe1]);
            // Стрелка и стебель — цвет шага; подписи — белые (читаемее на тёмном фоне).
            $arrowColor = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
            $textColor = imagecolorallocate($img, 0xff, 0xff, 0xff);

            /** @var list<string> $lines */
            $lines = [];
            if (is_array($marker['lines'] ?? null)) {
                foreach ($marker['lines'] as $ln) {
                    $ln = trim((string) $ln);
                    if ($ln !== '') {
                        $lines[] = $ln;
                    }
                }
            }
            if ($lines === []) {
                $text = trim((string) ($marker['text'] ?? ''));
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
            if ($lines === []) {
                continue;
            }

            $fontSize = $isEntry ? 13 : 11;
            $lineH = $isEntry ? 15 : 13;
            $blockH = count($lines) * $lineH;
            $arrowSize = $isEntry ? 10 : 7;

            if ($pos === 'below') {
                $arrowTipY = min(self::BOTTOM - 4, $anchorY + 4);
                if ($arrow === 'up' || $arrow === 'down') {
                    $this->drawArrow($img, $x, $arrowTipY, $arrow, $arrowColor, $arrowSize);
                    $stemEnd = $arrowTipY + $arrowSize * 2 + 4;
                    imageline($img, $x, $arrowTipY + ($arrow === 'up' ? $arrowSize * 2 : 0), $x, min(self::BOTTOM - 2, $stemEnd), $arrowColor);
                    $textY = min(self::BOTTOM - $blockH - 2, $stemEnd + 4);
                } else {
                    $textY = min(self::BOTTOM - $blockH - 2, $anchorY + 12);
                }
            } else {
                $arrowTipY = max(self::TOP + 4, $anchorY - 4);
                if ($arrow === 'up' || $arrow === 'down') {
                    $this->drawArrow($img, $x, $arrowTipY, $arrow, $arrowColor, $arrowSize);
                    $stemTop = $arrowTipY - $arrowSize * 2 - 4;
                    imageline($img, $x, max(self::TOP + 2, $stemTop), $x, $arrowTipY - ($arrow === 'down' ? $arrowSize * 2 : 0), $arrowColor);
                    $textY = max(self::TOP + 2, $stemTop - $blockH - 2);
                } else {
                    $textY = max(self::TOP + 2, $anchorY - 18 - $blockH);
                }
            }

            foreach ($lines as $li => $line) {
                $this->drawTextCentered(
                    $img,
                    $fontPath,
                    $fontSize,
                    $x,
                    $textY + $li * $lineH,
                    $textColor,
                    $line
                );
            }
        }

        // Информативные строки на кадре (пара / сторона / цепочка) — без отдельного HTML-сообщения.
        if ($overlayLines !== []) {
            $titleColor = imagecolorallocate($img, 0xe2, 0xe8, 0xf0);
            $panel = imagecolorallocatealpha($img, 0x17, 0x1b, 0x2d, 55);
            $lineH = 17;
            $pad = 6;
            $panelH = count($overlayLines) * $lineH + $pad * 2;
            imagefilledrectangle(
                $img,
                self::LEFT,
                self::TOP,
                min(self::RIGHT, self::LEFT + 580),
                self::TOP + $panelH,
                $panel
            );
            foreach ($overlayLines as $li => $line) {
                $this->drawTextLeft(
                    $img,
                    $fontPath,
                    12,
                    self::LEFT + 8,
                    self::TOP + $pad + $li * $lineH,
                    $titleColor,
                    $line
                );
            }
        }

        $dir = storage_path('app/tmp/telegram-charts');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/impulse_lvl_'.Str::lower(Str::random(16)).'.png';
        $ok = imagepng($img, $path, 3);
        imagedestroy($img);
        if (! $ok || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Треугольник-стрелка: tip в (x, tipY), направление up/down.
     *
     * @param  resource|\GdImage  $img
     */
    private function drawArrow($img, int $x, int $tipY, string $direction, int $color, int $size = 8): void
    {
        $size = max(5, min(14, $size));
        if ($direction === 'up') {
            $points = [
                $x, $tipY,
                $x - $size, $tipY + $size * 2,
                $x + $size, $tipY + $size * 2,
            ];
        } else {
            $points = [
                $x, $tipY,
                $x - $size, $tipY - $size * 2,
                $x + $size, $tipY - $size * 2,
            ];
        }
        imagefilledpolygon($img, $points, $color);
    }

    /** Вертикальная золотая рамка вокруг тела свечи на всю высоту плота. */
    private function drawBarOutline($img, int $x, int $bodyWidth, int $color): void
    {
        $markerLeft = $x - intdiv($bodyWidth, 2) - 2;
        $markerRight = $x + intdiv($bodyWidth, 2) + 2;
        imagerectangle($img, $markerLeft, self::TOP, $markerRight, self::BOTTOM, $color);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function parseHexRgb(string $hex, array $fallback): array
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return $fallback;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @param  resource|\GdImage  $img */
    private function drawTextCentered($img, ?string $fontPath, float $size, int $cx, int $y, int $color, string $text): void
    {
        if ($fontPath !== null && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $fontPath, $text);
            if (is_array($box)) {
                $textW = (int) abs($box[2] - $box[0]);
                imagettftext($img, $size, 0, $cx - intdiv($textW, 2), $y + (int) $size, $color, $fontPath, $text);

                return;
            }
        }
        $w = strlen($text) * imagefontwidth(2);
        imagestring($img, 2, max(0, $cx - intdiv($w, 2)), max(0, $y), $text, $color);
    }

    /**
     * @param  list<array<string, mixed>>  $candles
     * @return list<array{time:int,open:float,high:float,low:float,close:float}>
     */
    private function normalizeCandles(array $candles): array
    {
        $byTime = [];
        foreach ($candles as $row) {
            if (! is_array($row)) {
                continue;
            }
            $time = (int) ($row['time'] ?? $row['timestamp'] ?? 0);
            $open = (float) ($row['open'] ?? 0);
            $high = (float) ($row['high'] ?? 0);
            $low = (float) ($row['low'] ?? 0);
            $close = (float) ($row['close'] ?? 0);
            if ($time <= 0 || $high <= 0 || $low <= 0) {
                continue;
            }
            // Нулевые «дыры» истории не рисуем.
            if ($open == 0.0 && $high == 0.0 && $low == 0.0 && $close == 0.0) {
                continue;
            }
            $byTime[$time] = [
                'time' => $time,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];
        }
        ksort($byTime, SORT_NUMERIC);

        return array_values($byTime);
    }

    private function resolveFontPath(): ?string
    {
        foreach (self::FONT_CANDIDATES as $rel) {
            $path = str_starts_with($rel, '/') ? $rel : base_path($rel);
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @param  resource|\GdImage  $img */
    private function drawTextLeft($img, ?string $fontPath, float $size, int $x, int $y, int $color, string $text): void
    {
        if ($fontPath !== null && function_exists('imagettftext')) {
            imagettftext($img, $size, 0, $x, $y + (int) $size, $color, $fontPath, $text);

            return;
        }
        imagestring($img, 3, $x, max(0, $y), $text, $color);
    }
}
