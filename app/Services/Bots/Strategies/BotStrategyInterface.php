<?php

namespace App\Services\Bots\Strategies;

interface BotStrategyInterface
{
    /**
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial?:bool}>  $closedCandles
     * @param  array<string,mixed>|null  $partialCandle
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array;
}
