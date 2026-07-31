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
 * even if it used the last allowed execution.
 *
 * `$abandonedNonDeterministic` means the shrinker replayed the failing sequence, found the system
 * unstable (the execution path or the verdict diverged, AC8/R4), and gave up: `$commands` is then the
 * original sequence unshrunk and unfiltered, and the AC1 invariant does NOT hold — the one case where
 * the returned sequence is not guaranteed to still fail the same way, because no stable verdict for it
 * exists. `$executions` is then 0: the single replay is not a candidate execution (D007) and is not
 * counted.
 *
 * A layer-boundary caveat for whoever reads this flag: it reports what the shrinker *observed* — the
 * same sequence produced two different verdicts — not the cause. That has **two** possible causes the
 * shrinker cannot tell apart: a genuinely flaky system under test, or a caller that rebuilt the system
 * from **fresh** state per candidate instead of holding it fixed (e.g. an entry point whose `freshSut`
 * re-draws its initial state each run rather than reusing the one that failed, SPEC-005 AC8). From the
 * shrinker's side the two are indistinguishable, so a caller seeing this flag must rule out its own
 * wiring before blaming the system.
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
        public bool $abandonedNonDeterministic = false,
    ) {}
}
