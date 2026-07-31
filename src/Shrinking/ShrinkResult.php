<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Shrinking;

use Provemark\StatefulCheck\Command;

/**
 * The result of shrinking a failing sequence (SPEC-002): the counterexample, unwrapped to bare
 * commands for rendering, plus how far it got.
 *
 * The counterexample, the original length, the number of candidate executions it took, and whether
 * the budget stopped it before it confirmed a local minimum (AC9). `$budgetExhausted` means
 * "budget-limited, not minimal": a run that confirmed the minimum within budget leaves it false,
 * even if it used the last allowed execution. The non-determinism flag arrives with AC8.
 *
 * @template TModel
 * @template TSut
 */
final readonly class ShrinkResult
{
    /**
     * @param  list<Command<TModel, TSut, mixed>>  $commands  the counterexample, unwrapped for rendering
     */
    public function __construct(
        public array $commands,
        public int $originalLength,
        public int $executions,
        public bool $budgetExhausted = false,
    ) {}
}
