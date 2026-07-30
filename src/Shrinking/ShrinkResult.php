<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Shrinking;

use Provemark\StatefulCheck\Command;

/**
 * The result of shrinking a failing sequence (SPEC-002): the counterexample, unwrapped to bare
 * commands for rendering, plus how far it got.
 *
 * Minimal for AC3: the counterexample, the original length, and the number of candidate executions
 * it took. The budget-exhausted and non-deterministic flags arrive with AC9 and AC8.
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
    ) {}
}
