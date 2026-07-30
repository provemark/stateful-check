<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Shrinking;

use LogicException;
use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\RunResult;

/**
 * Shrinks a failing command sequence toward a local minimum (SPEC-002).
 *
 * For AC3 it only filters: the commands that did not execute are dropped by reading the original
 * run's `executed` record — no candidate is run to discover them (R1). The `SequenceRunner` it will
 * run candidates against, and the budget, are constructor state that arrives with AC5 and AC9 —
 * AC3 needs neither, so neither is declared yet.
 *
 * @template TModel
 * @template TSut
 */
final class SequenceShrinker
{
    /**
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $failing
     * @param  Generator<Command<TModel, TSut, mixed>>  $alphabet
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     * @return ShrinkResult<TModel, TSut>
     */
    public function shrink(array $failing, RunResult $original, Generator $alphabet, callable $freshSut, mixed $initialModel): ShrinkResult
    {
        if (count($original->executed) !== count($failing)) {
            // $failing and $original must be the same run: a length mismatch would filter the wrong
            // positions and silently return a wrong counterexample. Fail loudly, as the generators'
            // context guards do.
            throw new LogicException(sprintf(
                'SequenceShrinker::shrink(): executed record has %d entries for a sequence of %d — not the same run.',
                count($original->executed),
                count($failing),
            ));
        }

        // A counter, not a constant: candidate families (AC5 onward) increment it at each run. AC3
        // filters by reading `executed` and runs nothing, so it stays zero — non-vacuously.
        $executions = 0;

        // Drop the commands that did not execute (AC3): skipped by a false precondition, or never
        // reached after the failure. Both are `false` in `executed`; the shrinker does not
        // distinguish them (RunResult documents the merge).
        $representation = [];
        foreach ($failing as $i => $value) {
            if ($original->executed[$i]) {
                $representation[] = $value->value;
            }
        }

        return new ShrinkResult($representation, count($failing), $executions);
    }

    /**
     * Structural reduction candidates (SPEC-002 AC4): hold a prefix of length k and always keep
     * the last command, dropping the middle. The last command caused the failure, so removing it
     * is never a useful reduction and no candidate ever does. k runs up to `count - 2`, so the
     * full sequence (no reduction) is never yielded; a sequence of length 0 or 1 has no structural
     * reduction and yields nothing. The per-command argument family (AC2) is added here later.
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence
     * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
     */
    public function candidateReductions(array $sequence): iterable
    {
        $length = count($sequence);
        if ($length <= 1) {
            return;
        }

        $last = $sequence[$length - 1];

        for ($k = 0; $k < $length - 1; $k++) {
            yield [...array_slice($sequence, 0, $k), $last];
        }
    }
}
