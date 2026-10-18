<?php

namespace App\Services\Bots\AuthorFlow;

interface AuthorFlowRankerInterface
{
    /**
     * Фильтр + сортировка. Возвращает 0 или 1 кандидата (на last closed bar).
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>|null
     */
    public function pick(array $candidates, array $context, array $config): ?array;
}
