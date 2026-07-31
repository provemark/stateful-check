<?php

declare(strict_types=1);

namespace Provemark\StatefulCheck\Shrinking;

use LogicException;
use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Failure;
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
     * @param  list<Command<TModel, TSut, mixed>>  $failing
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     * @return ShrinkResult<TModel, TSut>
     */
    public function shrink(array $failing, RunResult $original, callable $freshSut, mixed $initialModel): ShrinkResult
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

        // Non-determinism guard (AC8, R4). Replay the whole failing sequence once and compare it to
        // the recorded run: if the execution PATH or the VERDICT diverges, the system is unstable, so
        // any counterexample derived from it would rest on a run that does not reproduce. Abort — hand
        // back the original sequence unshrunk and *unfiltered* (filtering trusts $original->executed,
        // the very record just shown unreliable) and flag it. This one replay is not a candidate
        // execution (D007): it does not count toward the budget nor toward `executions`; it is a fixed
        // one-run overhead. `passed` is compared to `passed` directly (not the derived
        // `failure === null`), then the failure kind — the failure-null check also guards sameKindAs.
        $replay = $this->replay($failing, $freshSut, $initialModel);
        if ($replay->executed !== $original->executed
            || $replay->passed !== $original->passed
            || $replay->failure === null
            || ! $replay->failure->sameKindAs($baseline)) {
            return new ShrinkResult(
                $failing,
                count($failing),
                0,
                false,
                abandonedNonDeterministic: true,
            );
        }

        // A counter, not a constant: it increments per candidate run below (AC2). The filter runs
        // nothing, so on a sequence with no structural reduction it stays zero (AC3).
        $executions = 0;

        // Drop the commands that did not execute (AC3). The filter is a separate, capability-free
        // step (see executedSubset): it reads the record and cannot run anything.
        $current = $this->executedSubset($failing, $original);

        // Reduce to a local minimum (AC2). On each accepted candidate, restart generation from the
        // reduced sequence. The restart is load-bearing (AC7 proves it): the family drops one
        // contiguous chunk per candidate, so a minimum needing two non-contiguous drops — leading and
        // middle junk around the failing commands — is only reached across successive passes. A single
        // pass stalls one drop short. Termination rests on every accepted candidate being *strictly
        // shorter* than its predecessor — a property of the structural family, not of this loop — so
        // `$current` shrinks on every restart and the loop cannot run forever. A length-preserving
        // family (the argument family) would break that; it may be added only once AC9's budget
        // provides the safety net.
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
            $current,
            count($failing),
            $executions,
            $budgetExhausted,
        );
    }

    /**
     * The commands that actually executed (SPEC-002 AC3): drop every position marked `false` in the
     * original run's `executed` record — skipped by a false precondition, or never reached after the
     * failure (RunResult documents that it does not distinguish the two; the shrinker does not need
     * to). This reads the record and nothing else: it takes no system and no `freshSut`, so it
     * *cannot* discover the drop by trying candidates — the trial-and-error alternative is absent by
     * construction, not merely unused. Public and pure, like `candidateReductions`, so the filter can
     * be tested in isolation from the shrink loop and from AC8's replay.
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Command<TModel, TSut, mixed>>  $failing
     * @return list<Command<TModel, TSut, mixed>>
     */
    public function executedSubset(array $failing, RunResult $original): array
    {
        $subset = [];
        foreach ($failing as $i => $command) {
            if ($original->executed[$i]) {
                $subset[] = $command;
            }
        }

        return $subset;
    }

    /**
     * Runs a candidate against a fresh system and reports whether it still fails the *same* way as
     * the original (AC1's identity, D020): a different-kind failure is not a reproduction, so the
     * loop never drifts toward a bug we were not shrinking. Each command is shallow-cloned before the
     * run (R9b).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Command<TModel, TSut, mixed>>  $candidate
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     */
    private function stillFails(array $candidate, Failure $baseline, callable $freshSut, mixed $initialModel): bool
    {
        $result = $this->replay($candidate, $freshSut, $initialModel);

        return ! $result->passed && $result->failure !== null && $result->failure->sameKindAs($baseline);
    }

    /**
     * Runs a sequence against a fresh system, shallow-cloning each command first (R9b), and returns
     * the raw result. Shared by the candidate loop (via `stillFails`) and by the non-determinism
     * guard, so both drive a sequence through the exact same path.
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Command<TModel, TSut, mixed>>  $sequence
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     */
    private function replay(array $sequence, callable $freshSut, mixed $initialModel): RunResult
    {
        $commands = array_map(static fn (Command $command): Command => clone $command, $sequence);

        return $this->runner->run($commands, $freshSut, $initialModel);
    }

    /**
     * Structural reduction candidates (SPEC-002 AC4): hold a prefix `[0, k)` and a retained suffix
     * `[k + s, length)` that always ends at the last command, dropping the middle chunk `[k, k + s)`
     * of length `s`. The last command caused the failure, so removing it is never a useful reduction
     * and no candidate ever does (the suffix always includes it). A sequence of length 0 or 1 has no
     * reduction and yields nothing; the full sequence is never yielded (`s >= 1` always drops at
     * least one). v0.1 has this structural family only; argument shrinking is out of scope until a
     * later spec (the deleted `$alphabet` param and the `GeneratedValue` wrapper it needed went with
     * it — see the SPEC-002 amendment retracting them).
     *
     * This is the full family of the spec — "hold a prefix, shrink the length of the retained
     * suffix" — not just the `s = length - 1` slice (suffix fixed at the last command). Reaching a
     * minimum like `[A, B]` from `[junk, A, junk, B]` needs to drop the *leading* junk, which only a
     * variable-length suffix can do; the middle junk then drops on a later pass (AC7 proves both).
     *
     * Order is **largest drop first** (`s` descending), matching fast-check's length-shrinking: the
     * accept-loop takes the first still-failing candidate, so this order is the greedy path, and a big
     * reduction accepted early reaches a small minimum in fewer passes. Within one `s`, the drop moves
     * left to right (`k` ascending). The candidate count is quadratic in `length` (`length·(length−1)/2`
     * per pass), where the old fixed-suffix family was linear — which is what makes AC9's budget a real
     * bound on long sequences, not a formality (D007).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Command<TModel, TSut, mixed>>  $sequence
     * @return iterable<list<Command<TModel, TSut, mixed>>>
     */
    public function candidateReductions(array $sequence): iterable
    {
        $length = count($sequence);
        if ($length <= 1) {
            return;
        }

        for ($s = $length - 1; $s >= 1; $s--) {
            for ($k = 0; $k <= $length - 1 - $s; $k++) {
                yield [...array_slice($sequence, 0, $k), ...array_slice($sequence, $k + $s)];
            }
        }
    }
}
