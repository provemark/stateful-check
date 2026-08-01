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
 * The sequence is carried as `GeneratedValue<Command>` wrappers, not bare commands (SPEC-006): each
 * command travels with the opaque generation context that a later family will shrink its arguments
 * from. v0.1's structural family never reads the context — it drops and slices whole wrappers — but
 * threading it keeps the argument family a pure addition (SPEC-006 AC8). Commands are unwrapped only
 * at the moment of running (`replay`) and in the final result (`ShrinkResult` renders bare commands).
 *
 * The command types are method-level templates, as on `SequenceRunner` — the shrinker holds only a
 * (non-generic) runner. The alphabet the argument family shrinks through is a **parameter of `shrink()`**,
 * not a constructor collaborator: it belongs to the sequence being shrunk (the sequence's commands came
 * from it), the same way `$freshSut` and `$initialModel` do, and its command type binds to the same
 * method templates per call. A class-level alphabet cannot: a concretely-typed `Generator<Command<null,
 * null, mixed>>` is not assignable to a class-level `Generator<Command<mixed, mixed, mixed>>` under
 * `Command`'s invariance, so the shrinker's own tests could not construct it (verified at PHPStan max).
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
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     * @param  ?Generator<Command<TModel, TSut, mixed>>  $alphabet  the generator the sequence was drawn
     *                                                              from, for the argument family (SPEC-006): each candidate is a whole `GeneratedValue` from
     *                                                              `$alphabet->shrink()`, so a command and its context stay a matched pair by construction (D023).
     *                                                              **`null` is a contract, not a forgotten value: it means shrink structurally only** — the v0.1
     *                                                              mode — and the argument family is then deliberately, not accidentally, absent. A caller that
     *                                                              wants argument shrinking passes the alphabet; one that does not, does not.
     * @return ShrinkResult<TModel, TSut>
     */
    public function shrink(array $failing, RunResult $original, callable $freshSut, mixed $initialModel, ?Generator $alphabet = null): ShrinkResult
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
                $this->unwrap($failing),
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
        // reduced sequence. The restart is load-bearing (SPEC-002 AC7 proves it): the structural family
        // drops one contiguous chunk per candidate, so a minimum needing two non-contiguous drops —
        // leading and middle junk around the failing commands — is only reached across successive passes.
        // Candidates come structure-first, then argument reductions (SPEC-006). Structural drops are
        // strictly shorter, so on the structural family alone the loop trivially terminates; the argument
        // family is length-preserving, so termination now also rests on the budget and on each
        // `shrink()` being finite and origin-ward (SPEC-006 AC4 formalises the lexicographic measure).
        $budgetExhausted = false;
        do {
            $reduced = false;
            foreach ($this->candidates($current, $alphabet) as $candidate) {
                // Checked before running, so a run that confirms the local minimum on its last allowed
                // execution exits the pass naturally below (no next candidate to trip this) and is NOT
                // budget-limited; the budget only fires when it interrupts a pass mid-search (AC9).
                if ($executions >= $this->budget) {
                    $budgetExhausted = true;
                    break 2;
                }
                $executions++;
                $replay = $this->stillFails($candidate, $baseline, $freshSut, $initialModel);
                if ($replay !== null) {
                    // Re-filter the accepted candidate to what actually ran in ITS OWN replay, so the
                    // returned counterexample is always the executed subset of the sequence returned —
                    // never a command that did not run (SPEC-006 AC5). Unconditional: a reduction that
                    // moves the failure earlier and leaves the retained last command unexecuted is the
                    // case this closes; that its trigger is (currently) unreachable is what makes this
                    // hardening rather than a fix with a red-first test. When every command ran, the
                    // subset is the candidate unchanged.
                    $current = $this->executedSubset($candidate, $replay);
                    $reduced = true;
                    break;
                }
            }
        } while ($reduced);

        return new ShrinkResult(
            $this->unwrap($current),
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
     * be tested in isolation from the shrink loop and from AC8's replay. Operates on the wrappers so a
     * surviving command keeps its context (SPEC-006).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $failing
     * @return list<GeneratedValue<Command<TModel, TSut, mixed>>>
     */
    public function executedSubset(array $failing, RunResult $original): array
    {
        $subset = [];
        foreach ($failing as $i => $wrapper) {
            if ($original->executed[$i]) {
                $subset[] = $wrapper;
            }
        }

        return $subset;
    }

    /**
     * Runs a candidate against a fresh system and returns that run **iff** it still fails the *same*
     * way as the original (AC1's identity, D020): a different-kind failure is not a reproduction, so the
     * loop never drifts toward a bug we were not shrinking. Returns `null` when the candidate does not
     * reproduce. The `RunResult` is returned (not a bare bool) so the caller can re-filter the accepted
     * candidate to its own executed subset (SPEC-006 AC5). Each command is shallow-cloned before the run
     * (R9b).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $candidate
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     */
    private function stillFails(array $candidate, Failure $baseline, callable $freshSut, mixed $initialModel): ?RunResult
    {
        $result = $this->replay($candidate, $freshSut, $initialModel);

        return ! $result->passed && $result->failure !== null && $result->failure->sameKindAs($baseline) ? $result : null;
    }

    /**
     * Runs a sequence against a fresh system, unwrapping each command from its `GeneratedValue` and
     * shallow-cloning it first (R9b), and returns the raw result. Shared by the candidate loop (via
     * `stillFails`) and by the non-determinism guard, so both drive a sequence through the exact same
     * path. The context is never run, so it is never cloned — only the command is (D023).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     */
    private function replay(array $sequence, callable $freshSut, mixed $initialModel): RunResult
    {
        $commands = array_map(static fn (GeneratedValue $wrapper): Command => clone $wrapper->value, $sequence);

        return $this->runner->run($commands, $freshSut, $initialModel);
    }

    /**
     * Structural reduction candidates (SPEC-002 AC4): hold a prefix `[0, k)` and a retained suffix
     * `[k + s, length)` that always ends at the last command, dropping the middle chunk `[k, k + s)`
     * of length `s`. The last command caused the failure, so removing it is never a useful reduction
     * and no candidate ever does (the suffix always includes it). A sequence of length 0 or 1 has no
     * reduction and yields nothing; the full sequence is never yielded (`s >= 1` always drops at
     * least one). This is the structural family; the argument family (SPEC-006) is a separate
     * generator over the same wrappers, added alongside this one.
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
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence
     * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
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

    /**
     * All reduction candidates, structure-first then argument (SPEC-006). The structural family drops
     * or slices whole wrappers (SPEC-002 AC4); the argument family reduces one command's value in place.
     * Structure-first is the cheaper order and the one AC3's minimum is defined against: every accepted
     * drop removes a position the argument family would otherwise have shrunk (SPEC-006 design decision).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence
     * @param  ?Generator<Command<TModel, TSut, mixed>>  $alphabet
     * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
     */
    private function candidates(array $sequence, ?Generator $alphabet): iterable
    {
        yield from $this->candidateReductions($sequence);
        yield from $this->argumentReductions($sequence, $alphabet);
    }

    /**
     * Argument reduction candidates (SPEC-006): for each position, replace just that command with each
     * smaller `GeneratedValue` the alphabet yields from the one that produced it. The candidate is a
     * whole `GeneratedValue` straight from `$alphabet->shrink()`, so its command and context stay a
     * matched pair by construction (D023). Length-preserving, closest-to-origin first, finite
     * (`Generator::shrink`). Yields nothing without an alphabet (structural-only) or for a position the
     * alphabet cannot shrink — a value already at its origin (SPEC-006 AC6, case a).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence
     * @param  ?Generator<Command<TModel, TSut, mixed>>  $alphabet
     * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
     */
    public function argumentReductions(array $sequence, ?Generator $alphabet): iterable
    {
        if ($alphabet === null) {
            return;
        }

        foreach ($sequence as $i => $wrapper) {
            foreach ($alphabet->shrink($wrapper) as $shrunk) {
                $candidate = $sequence;
                $candidate[$i] = $shrunk;
                yield array_values($candidate);
            }
        }
    }

    /**
     * The bare commands of a wrapped sequence, for the result a reader sees (`ShrinkResult` renders
     * bare commands) and for the abort path. The context is shrink data, never part of the rendered
     * counterexample.
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $wrapped
     * @return list<Command<TModel, TSut, mixed>>
     */
    private function unwrap(array $wrapped): array
    {
        return array_map(static fn (GeneratedValue $wrapper): Command => $wrapper->value, $wrapped);
    }
}
