# SPEC-002: Sequence shrinking

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-30                               |
| Amended    | maurice, 2026-07-30 — AC5 (empty-sequence probe) removed, D022. The empty sequence cannot fail in this model — the runner checks nothing at zero commands — so the probe is a guaranteed-useless execution and its "empty counterexample" branch is dead; the question it asked is answered by construction (the shrinker only receives a failing `RunResult`). The candidate families drop from three to two (structural, argument), the meta case is removed, and `shrunkOnce` goes with it (its only purpose was trying the probe once). |
| Amended    | maurice, 2026-07-30 — `SequenceShrinker::shrink()` takes the original failing `RunResult`. It makes the shrinker a consumer of what already happened, not a rediscoverer: `$original->executed` filters non-executed commands with **zero** candidate runs (AC3), the execution path is AC8's replay baseline, and `$original->failure` is the `sameKindAs` baseline for the AC1 invariant. `count($original->executed)` must equal `count($failing)` (the same run) or `shrink()` throws a `LogicException` — a length mismatch would filter wrong positions and silently return a wrong counterexample. |
| Amended    | maurice, 2026-07-31 — AC8 broadened from an execution-path mismatch to **path *or* verdict** divergence. The guard replays the failing sequence once anyway, so also comparing the verdict (does it still fail the same kind?) is free and strictly stronger: it catches a system that reproduces the same path but flips the outcome (e.g. a postcondition that passes on replay). This is the one exception to the AC1 invariant, recorded on AC1's traceability row: an abandoned, non-deterministic result is flagged unreliable and is not asserted to still fail. The replay is not a candidate execution (D007) and does not count toward the budget or `executions`. |
| Amended    | maurice, 2026-07-31 — **retraction: the per-command argument family and the whole layer serving it are removed from v0.1.** The shrinker now takes a bare `list<Command>`; the earlier amendments adding the `$alphabet` generator param and the `GeneratedValue` wrapper input are withdrawn, and D021 (the wrapper's command/context pairing) is retracted. No v0.1 case needs argument shrinking — the AC7 meta-suite plants an argument-free bug — so carrying the layer was speculative generality (§4). The AC7 traceability check surfaced that D021's "new wrapper" was never even implemented (`replay()` runs bare clones). A later spec re-adds argument shrinking with its consumer. Same shape as D010 (`fork()`). |
| Amended    | maurice, 2026-08-01 — **AC3 strengthened, and the argument family re-added by SPEC-006** (which the 2026-07-31 retraction deferred to "a later spec"). AC3's guarantee now covers the *returned* result, not only the up-front filter: the counterexample is the executed subset of the **finally accepted candidate**, enforced by re-filtering every accepted candidate through its own replay's executed set. This closes a gap the "no-op drop" argument missed — a reduction can move the failure earlier and leave the retained last command (AC4) unexecuted. Its trigger proved **unreachable** under the two present choices (structure-first family order; `sameKindAs` matches on command *class*), so the re-filter is **unconditional hardening** and its guard is a **tripwire** (SPEC-006 AC5), not a red-first test here. AC2's "local minimum" is extended by SPEC-006 AC3 to "no single structural *or* argument reduction still fails". Details, and the argument family's own ACs, live in SPEC-006. |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This is the reason the package exists. Everything in SPEC-001 is a fifty-line
loop any project can hand-roll — and two projects already have. What nobody
builds themselves, and what ordinary property-based testing does not provide, is
reducing a failing *sequence* to the minimal sequence that still fails.

Value-level shrinking (SPEC-003) minimises a failing command's arguments. The
sequence itself does not shrink: you get the failing program at full generated length and
reduce it by hand. A twelve-command counterexample tells you almost nothing; the
two-command version tells you the bug.

The strategy is taken from fast-check's `CommandsArbitrary`, which solves exactly
this problem. Two things make it simpler than first assumed. Because a command
whose precondition fails is skipped rather than fatal (SPEC-001 AC3), a shortened
sequence can never be ill-formed, so candidates need no re-validation (R1). And
because execution stops at the failure, the last executed command is by
construction the one that caused it, so it is always retained.

Governing rules: R1 (shrink over executed commands), R2 (never return a passing
sequence), R3 (documented local minimum), R4 (determinism detected via execution
path), R8 (planted-bug meta-tests), R9 (clone between candidates).

## Scope

**In scope**

- The shrinker operates on the failing command sequence — a bare `list<Command>`. (v0.1 does not
  carry the generation context into the shrinker: argument shrinking is out of scope, so there is
  no consumer for it — see "Out of scope" and the retraction amendment. The `$alphabet` generator
  and the `GeneratedValue` wrapper this bullet once described went with it.)
- Filtering that sequence to the commands that actually executed, from the execution record
  (SPEC-001 AC4). Both meanings of a false position — precondition-skipped (AC3) and never-reached
  after the failure — drop out identically; the shrinker does not distinguish them.
- Candidate generation, one family: **structural** — hold a prefix of length *k*, shrink the length
  of the retained suffix, always keeping the last executed command. (No empty-sequence probe: it
  always passes in this model, so trying it is a guaranteed-useless execution — removed with AC5,
  D022. No per-command argument family: deferred out of scope, below.)
- `Failure::sameKindAs()` — the identity comparison SPEC-001 declared and deferred to its only
  consumer. It is built here, with D020's exact-class semantics (a subclass is a different kind).
- Lazy candidate generation: candidates are produced on demand, not materialised.
- Cloning every command before running a candidate (R9b): a candidate is run by shallow-cloning each
  command (plain `clone`) so mutable command state cannot leak between candidates (D006). A command
  holding a mutable object it must not share declares `__clone` (R9b).
- Recording an execution path and detecting divergence on replay as the
  non-determinism signal (R4).
- A budget (maximum candidate executions) so shrinking terminates on expensive
  systems.

**Out of scope** (each needs its own spec before it may be built)

- Re-validating candidates against the model. Not needed — see Problem and R1.
  If symbolic results are ever introduced, this returns and the whole strategy
  must be re-specced.
- Global minimisation, exhaustive search, or any claim beyond a local minimum.
- **Per-command argument shrinking** (the former family 2, retracted 2026-07-31). Reducing a
  command's generated arguments needs the generation context threaded into the shrinker (the
  `GeneratedValue` wrapper and the `$alphabet` generator); no v0.1 case requires it — the meta-suite
  (AC7) plants an argument-free order-dependent bug — so building it now would be speculative
  generality (§4). A later spec adds it together with its consumer: the wrapper input, the generator
  argument, and a fresh matched-pair invariant (the retracted D021).
- Shrinking across parallel or scheduled interleavings (R5).
- Re-ordering commands as a reduction step. Deletion only; re-ordering changes semantics in ways
  the model may not catch.
- Simplifying the branch **choice** — replacing a command with a different, simpler alphabet
  entry. The choice is shrunk by no layer: this is SPEC-003 AC5's documented coverage gap, and
  SPEC-002 is where a reader looks for it. A counterexample may keep a more complex command where
  a simpler alphabet entry would also have failed; shortening by deletion is almost always more
  useful than replacement.
- Caching or memoising system-under-test executions between candidates.

## Behavior

- **AC1 — the returned sequence still fails** *(R2, the central postcondition)*
  - Given a failing sequence
  - When it is shrunk
  - Then the returned sequence, executed against a fresh system, still fails, and
    its `Failure` satisfies `sameKindAs()` against the original: same
    `FailureKind`, same failing command class, same exception class if any — compared
    by **exact class** (D020), so a subclass is a different kind. Messages are explicitly
    not compared — a shrunk sequence legitimately produces different numbers in them.

- **AC2 — the returned sequence is a local minimum** *(R3)*
  - Given a shrunk sequence
  - When any single further reduction candidate is generated from it
  - Then every such candidate passes. No single additional reduction step still
    fails.
  - *Extended by SPEC-006 AC3 (2026-08-01):* once the argument family is present, "local
    minimum" means "no single structural **or** argument reduction still fails". This
    structural-only statement is the v0.1 base SPEC-006 AC3 builds on; the two move together.

- **AC3 — commands that did not execute are dropped** *(R1)*
  - Given a failing run in which some commands were skipped (precondition false)
    or never reached (after the failure)
  - When shrinking begins
  - Then those commands are absent from the shrink representation and from the
    returned counterexample. The claim is specifically about **the filter**: the drop
    is determined by reading `executed`, running no candidate to discover it — as
    distinct from the later reduction loop (AC2 onward), which does run candidates. The
    two must not be conflated: the drop's zero executions are the filter's, not the
    whole shrink's.
  - *Strengthened by SPEC-006 (2026-08-01): the guarantee holds for the **returned** result,
    not the up-front filter alone.* A reduction can move the failure earlier and leave the
    retained last command (AC4) unexecuted in that candidate's replay — which the up-front
    filter, reading only `$original->executed`, never sees. So every **accepted** candidate is
    re-filtered through its own replay's executed set, and the returned counterexample is the
    executed subset of the sequence actually returned. The trigger for that gap proved
    unreachable under the present family order and class-granular `sameKindAs` (SPEC-006 AC5),
    so the re-filter is unconditional hardening and its guard is a **tripwire** in SPEC-006 AC5
    — this AC's returned-result guarantee is therefore traced to a SPEC-006 test, not to a
    missing row here.

- **AC4 — the last executed command is always retained**
  - Given any structural reduction candidate
  - When it is produced
  - Then it still ends with the last executed command of the sequence it was
    derived from — that command caused the failure, so removing it is never a
    useful reduction.

- **AC5 — removed (D022).** The empty-sequence probe was deleted: in this model the empty
  sequence cannot fail (the runner checks nothing at zero commands), so its trigger is unreachable
  and its "empty counterexample" branch is dead. The question it asked — did the commands cause the
  failure? — is answered by construction: the shrinker only ever receives a failing `RunResult`,
  which can only fail through a command's postcondition or exception. The number is a redirect, not
  renumbered. The probe returns if invariants are ever checked before the first command (D022).

- **AC6 — commands are cloned between candidates** *(R9b, D006)*
  - Given any command — every command is shallow-cloned (plain `clone`) before a candidate runs; a
    command carrying mutable state is what makes the cloning observable
  - When it appears in two successive candidates
  - Then the second candidate receives a fresh clone, and no state from the first
    execution is observable in it.

- **AC7 — a planted bug shrinks to its known minimal sequence** *(R8)*
  - Given a system with a deliberately planted order-dependent bug and a known
    minimal reproducing sequence
  - When a long failing sequence is shrunk
  - Then the result equals that known minimal sequence exactly, compared by its
    string representation.

- **AC8 — a replay that diverges in path *or* verdict aborts shrinking** *(required: error path,
  R4)*
  - Given a recorded run and a single replay of the same failing sequence, in
    which either the execution path diverges (a command marked not-executed does
    execute, or the reverse) *or* the verdict diverges (the replay does not
    reproduce the same-kind failure — it passes, or fails differently) though the
    path is identical
  - When the divergence is detected
  - Then shrinking aborts, the original counterexample is returned unshrunk and
    unfiltered, and the result reports that the system under test is not
    deterministic — never a "minimal" sequence derived from unstable runs. This
    is the one exception to AC1: the returned sequence is *not* guaranteed to
    still fail, because no stable verdict for it exists. The replay is not a
    candidate execution (D007) — it does not count toward the budget or
    `executions`.

- **AC9 — shrinking respects the budget**
  - Given a budget of *n* candidate executions
  - When shrinking a sequence that could be reduced further
  - Then no more than *n* executions occur, the best sequence found so far is
    returned, and the result is flagged as budget-limited rather than minimal.

- **AC10 — `sameKindAs()` compares by exact class, not subtype** *(D020; the identity AC1 rests on)*
  - Given two `Failure`s
  - When compared with `sameKindAs()`
  - Then they are the same kind iff `FailureKind`, failing command class, and exception class are
    equal — the exception class **by exact class**: a subclass of the same parent is **not** the
    same kind, and `index` and any message are ignored. This is the deferred SPEC-001 method built
    here with its consumer; it is asserted directly, not only through AC1, because the
    subclass-is-different property is exactly what a coarser `instanceof` comparison would get
    wrong (D020's revisit-if: dynamically-named or subclass exceptions of one defect).

## API sketch

Illustrative only.

The types carry `@template TModel, TSut` — the sequence is `list<Command<TModel, TSut, mixed>>`,
and a concrete model or system type does not type-check against a bare `list<Command>` (`TModel`
is invariant; this was the SPEC-001 `SequenceRunner` fix). The failing sequence arrives as bare
`Command`s: v0.1 shrinks structurally only, so it needs no generation context (the `GeneratedValue`
wrapper and `$alphabet` generator were retracted, 2026-07-31).

```php
// namespace Provemark\StatefulCheck\Shrinking;

/**
 * @template TModel
 * @template TSut
 */
final readonly class ShrinkResult
{
    /** @param list<Command<TModel, TSut, mixed>> $commands the counterexample, unwrapped for rendering */
    public function __construct(
        public array $commands,
        public int $originalLength,
        public int $executions,
        public bool $budgetExhausted = false,
        public bool $abandonedNonDeterministic = false,
    ) {}

    /** "inc[1],check[1]" — the counterexample as a reader sees it. */
    public function __toString(): string;
}

/**
 * @template TModel
 * @template TSut
 */
final class SequenceShrinker
{
    public function __construct(
        private SequenceRunner $runner,
        private int $budget = 100,
    ) {}

    /**
     * @param  list<Command<TModel, TSut, mixed>>  $failing   the failing sequence
     * @param  RunResult                           $original  the failing run SPEC-005 already produced;
     *   it supplies three things and makes the shrinker a consumer of what happened rather than a
     *   rediscoverer of it: `executed` filters non-executed commands with no candidate run (AC3),
     *   the execution path is the baseline AC8 replays against, and `Failure` is the `sameKindAs`
     *   baseline the AC1 invariant checks each candidate against. `count($original->executed)`
     *   must equal `count($failing)` — they must be the same run — or `shrink()` throws a
     *   `LogicException`; a length mismatch would filter the wrong positions and silently return a
     *   wrong counterexample (the same silent class as the generators' context guards)
     * @param  callable(): TSut                    $freshSut
     * @param  TModel                              $initialModel
     * @return ShrinkResult<TModel, TSut>
     */
    public function shrink(array $failing, RunResult $original, callable $freshSut, mixed $initialModel): ShrinkResult;
}

/**
 * Candidates, lazily, in the structural family's order (Scope). Non-executed positions (SPEC-001
 * AC4) are already gone from $sequence. To run a candidate, each command is shallow-cloned for the
 * runner (R9b). No `shrunkOnce` flag: it existed only to try the empty probe once (fast-check), and
 * the probe is gone (D022).
 *
 * @template TModel
 * @template TSut
 *
 * @param  list<Command<TModel, TSut, mixed>>  $sequence  executed commands only
 * @return iterable<list<Command<TModel, TSut, mixed>>>
 */
function candidateReductions(array $sequence): iterable;
```

**The forms at the layer boundary**, made explicit so no one discovers the conversion mid-build:

- **SPEC-005 (draws)** draws `GeneratedValue<Command>`s from the alphabet. On a failure it unwraps to
  bare `list<Command>` and hands that to the shrinker — v0.1's shrinker has no use for the context.
- **SPEC-002 (shrinks)** works on bare `Command`s and yields bare `Command` candidates.
- **SPEC-001 (runs)** takes bare `Command`s: each candidate is shallow-cloned (R9b) before
  `SequenceRunner::run`.

Counterexample rendering matters more than it looks. fast-check invests real
effort in `toString` delegation, and the reason its shrink tests can assert an
exact string like `inc[1],check[1]` is that the plumbing exists. Budget for it.

## Open questions

- ~~**Restart versus continue after an accepted reduction.**~~
  **Resolved: restart.** Candidate generation begins again from the newly reduced sequence per
  accepted candidate. fast-check also tracks a `shrunkOnce` flag, but only to try its empty probe
  once; with the probe removed (D022) we do not need the flag — the two families generate
  identically on the first shrink and every restart.
- ~~**Flat iterable versus shrink tree for candidates.**~~
  **Resolved: lazy iterable, with per-command context.** Structural candidates
  need no tree; per-command argument shrinking needs the context that the
  generation core carries alongside each value (SPEC-003).
- ~~**"Same reason class" (AC1).**~~ **Resolved (D002):** identity is
  `FailureKind` + failing command class + exception class, compared via
  `Failure::sameKindAs()` (SPEC-001). Messages excluded. This required SPEC-001 to
  carry a structured `Failure` instead of a free-text reason — the two specs were
  amended together.
- ~~**Budget default — count or time?**~~ **Resolved (D007):** a count of
  candidate executions, default 100 — never a time budget, which would break
  determinism (the same seed shrinks less far on a slow machine). Document how to
  lower it for slow systems.
- **Does the retained-suffix strategy need a matching prefix strategy? — open,
  non-blocker.** fast-check holds a prefix and shrinks the suffix length. Whether
  the mirror case (hold a suffix, shrink a prefix) finds anything extra is
  unmeasured. Do not add it speculatively; add it if a meta-test demonstrates a
  minimum it cannot reach.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | cross-cutting invariant (R2). Its two halves are proven separately: the **still-fails** half (`passed === false`) by AC7's meta-case (a shrinker that over-reduced to a passing sequence breaks it) and by every running shrinker test; the **`sameKindAs` identity** half by "does not drift to a candidate that fails for a different reason" (mutant-protected — dropping `sameKindAs` from the accept-condition breaks exactly that test, since `Prime`/`Blow` there can drift to an `UnexpectedException`). AC7's system has one failure kind, so its `sameKindAs` assertion cannot bite there — it documents the invariant, the drift test proves it. **One exception (AC8):** a result flagged `abandonedNonDeterministic` is *not* asserted to still fail — the system is unstable, so no stable verdict exists; the two AC8 tests deliberately omit the invariant | the R2 postcondition, not a distinct symbol |
| AC2                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "shrinks to a local minimum…" (also asserts `ShrinkResult::$originalLength` — the pre-shrink length, distinct from the shrunk `commands`) + "does not drift to a candidate that fails for a different reason…" + "fails loudly when the original run did not fail" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::shrink`, `::stillFails`; `src/Shrinking/ShrinkResult.php` :: `$originalLength` |
| AC3                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "filters the executed subset by reading the record, with no capacity to run a command" (the structural trial-and-error catch) + "drops non-executed commands without running a candidate" (the end-to-end claim, `executions === 0`) + "fails loudly when the executed record does not match…" (SPEC-002). **Returned-result guarantee (2026-08-01 amendment):** `tests/Meta/ArgumentShrinkExecutedSubsetTest.php` (SPEC-006 AC5 tripwire) — traced to SPEC-006, not a missing row, because the re-filter that makes the *returned* result executed-only is hardening whose trigger is unreachable, so it has no red-first test here | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::executedSubset`, `::shrink` (the accept loop re-filters each accepted candidate; `::stillFails` returns the `RunResult`) |
| AC4                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "every structural candidate retains the last executed command" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::candidateReductions` |
| AC5                  | removed (D022) — the empty-sequence probe's trigger is unreachable in this model | n/a |
| AC6                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "clones a command between candidates, so its mutable state does not leak" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::replay` (the shallow clone before each run) |
| AC7                  | `tests/Meta/OrderDependentShrinkTest.php` (groups `meta`, `SPEC-002`) — an order-dependent bug with noise both sides shrinks to exactly `[Prime, Trip]` | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::candidateReductions` (widened to the full retained-suffix family), `::shrink` (the restart loop, proven load-bearing here) |
| AC8                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "aborts shrinking when the replay path diverges — non-determinism" + "aborts when the replay verdict diverges though the path is identical" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::shrink` (replay guard), `::replay`; `src/Shrinking/ShrinkResult.php` :: `$abandonedNonDeterministic` |
| AC9                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "respects the budget: reaching the minimum within it is minimal, one short is budget-limited" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::shrink`; `src/Shrinking/ShrinkResult.php` :: `$budgetExhausted` |
| AC10                 | `tests/Unit/FailureTest.php` (group `SPEC-002`) | `src/Failure.php` :: `Failure::sameKindAs` |
