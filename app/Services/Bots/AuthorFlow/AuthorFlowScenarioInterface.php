<?php

namespace App\Services\Bots\AuthorFlow;

/**
 * Сценарий AuthorFlow: собирает кандидатов одного setup без записи в рынок.
 */
interface AuthorFlowScenarioInterface
{
    /** Имя setup (engulf_D, breakout_E, …). */
    public function setupId(): string;

    /** Базовый priority (константа сценария; меньше = важнее). */
    public function basePriority(): int;

    /**
     * @param  list<array<string,mixed>>  $closed
     * @param  array<string,mixed>  $context  // structure, levels, config
     * @return list<array<string,mixed>>  AuthorFlowCandidate shapes
     */
    public function collect(array $closed, array $context): array;
}
