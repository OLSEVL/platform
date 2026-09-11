<?php

namespace App\Support;

/**
 * Clamp экспирации бинарных сделок для SRL / Engulfing.
 *
 * Автор на OTC стримах часто ставит 15с при свечах S15; старый floor 60с
 * ломал EV (replay: WR@15с ≫ WR@60с). Минимум 15 — кратен S5/S15.
 */
final class BinaryExpiry
{
    public const MIN_SEC = 15;

    public const MAX_SEC = 900;

    public static function clamp(int $seconds): int
    {
        return max(self::MIN_SEC, min(self::MAX_SEC, $seconds));
    }
}
