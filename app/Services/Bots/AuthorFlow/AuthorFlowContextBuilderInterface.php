<?php

namespace App\Services\Bots\AuthorFlow;

interface AuthorFlowContextBuilderInterface
{
    /**
     * @param  list<array<string,mixed>>  $closed
     * @param  array<string,mixed>  $config
     * @return array{
     *   structure_bias: 'up'|'down'|'range',
     *   last_swing_high: ?float,
     *   last_swing_low: ?float,
     *   levels: list<array{price:float,type:string,time:int}>,
     *   open_liquidity_call: bool,
     *   open_liquidity_put: bool,
     * }
     */
    public function build(array $closed, array $config): array;
}
