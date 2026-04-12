<?php

namespace App\Support;

/**
 * Остаток секунд до закрытия текущей M1-свечи по полю time (открытие бара).
 *
 * Время свечей в БД часто в шкале брокера/ингеста и не совпадает с PHP time().
 * Тогда (lastOpen+60)-time() огромное; берём остаток по модулю 60 — фаза внутри минуты
 * совпадает с истинным остатком, если смещение кратно 60 с (типично для целых часовых поясов).
 */
final class M1CandleCountdown
{
    public static function secondsToClose(int $lastBarOpenUnix, int $serverUnix): int
    {
        $raw = ($lastBarOpenUnix + 60) - $serverUnix;
        if ($raw < 0) {
            return 0;
        }
        if ($raw <= 60) {
            return $raw;
        }

        return (int) ($raw % 60);
    }
}
