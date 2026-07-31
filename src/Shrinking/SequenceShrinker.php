<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Shrinking;

use LogicException;
use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Failure;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\RunResult;
use Provemark\StatefulCheck\SequenceRunner;

/**
 * Shrinks a failing command sequence toward a local minimum (SPEC-002).
 *
 * It first drops the commands that did not execute by reading the original run's `executed` record —
 * no candidate is run to discover them (R1, AC3) — then reduces the rest with candidate families,
 * running each against a fresh system and keeping any that still fails the same way (AC2). The
 * result is a local minimum relative to those families (R3): AC2 cannot detect a family that is too
 * weak, only AC7 (a planted bug with a known minimum) can.
 *
 * The command types are method-level templates, as on `SequenceRunner` — the shrinker holds only a
 * (non-generic) runner, so there is nothing to bind at construction.
 */
final class SequenceShrinker
{
    public function __construct(
        private readonly SequenceRunner $runner,
        private readonly int $budget = 100,
    ) {}

    /**
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $failing
     * @param  Generator<Command<TModel, TSut, mixed>>  $alphabet  the generator, for the per-command
     *                                                             argument family (amendment A). It has no consumer yet: that family is deferred until a
     *                                                             planted case (AC7) needs it, and it may be added only once AC9's budget exists, because it
     *                                                             preserves length and so escapes the structural termination argument below. This is the
     *                                                             `Outcome::$value` class of unused-but-planned, not the `reason` class: mechanism and
     *                                                             consumer both exist, only the wiring is deferred.
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

        // The shrinker minimises a *failing* run; the AC1 invariant compares each candidate against
        // this baseline failure, so it must exist.
        $baseline = $original->failure ?? throw new LogicException('SequenceShrinker::shrink(): the original run did not fail.');

        // A counter, not a constant: it increments per candidate run below (AC2). The filter runs
        // nothing, so on a sequence with no structural reduction it stays zero (AC3).
        $executions = 0;

        // Drop the commands that did not execute (AC3): skipped by a false precondition, or never
        // reached after the failure. Both are `false` in `executed`; the shrinker does not
        // distinguish them (RunResult documents the merge). Keep the GeneratedValues — the loop
        // works on them and unwraps to bare commands only for the result.
        $current = [];
        foreach ($failing as $i => $value) {
            if ($original->executed[$i]) {
                $current[] = $value;
            }
        }

        // Reduce to a local minimum (AC2). On each accepted candidate, restart generation from the
        // reduced sequence. Termination rests on every accepted candidate being *strictly shorter*
        // than its predecessor — a property of the structural family, not of this loop — so `$current`
        // shrinks on every restart and the loop cannot run forever. A length-preserving family (the
        // argument family, AC7) would break that; it may be added only once AC9's budget provides the
        // safety net.
        $budgetExhausted = false;
        do {
            $reduced = false;
            foreach ($this->candidateReductions($current) as $candidate) {
                // Checked before running, so a run that confirms the local minimum on its last allowed
                // execution exits the pass naturally below (no next candidate to trip this) and is NOT
                // budget-limited; the budget only fires when it interrupts a pass mid-search (AC9).
                if ($executions >= $this->budget) {
                    $budgetExhausted = true;
                    break 2;
                }
                $executions++;
                if ($this->stillFails($candidate, $baseline, $freshSut, $initialModel)) {
                    $current = $candidate;
                    $reduced = true;
                    break;
                }
            }
        } while ($reduced);

        return new ShrinkResult(
            array_map(static fn (GeneratedValue $value): Command => $value->value, $current),
            count($failing),
            $executions,
            $budgetExhausted,
        );
    }

    /**
     * Runs a candidate against a fresh system and reports whether it still fails the *same* way as
     * the original (AC1's identity, D020): a different-kind failure is not a reproduction, so the
     * loop never drifts toward a bug we were not shrinking. Each command is shallow-cloned out of its
     * wrapper before the run (R9b, D021).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $candidate
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     */
    private function stillFails(array $candidate, Failure $baseline, callable $freshSut, mixed $initialModel): bool
    {
        $commands = array_map(static fn (GeneratedValue $value): Command => clone $value->value, $candidate);
        $result = $this->runner->run($commands, $freshSut, $initialModel);

        return ! $result->passed && $result->failure !== null && $result->failure->sameKindAs($baseline);
    }

    /**
     * Structural reduction candidates (SPEC-002 AC4): hold a prefix of length k and always keep
     * the last command, dropping the middle. The last command caused the failure, so removing it
     * is never a useful reduction and no candidate ever does. k runs up to `count - 2`, so the
     * full sequence (no reduction) is never yielded; a sequence of length 0 or 1 has no structural
     * reduction and yields nothing. The per-command argument family is deferred (see `shrink`).
     *
     * @template TModel
     * @template TSut
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
