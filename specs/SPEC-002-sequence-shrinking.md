# SPEC-002: Sequence shrinking

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-30                               |
| Amended    | maurice, 2026-07-30 — AC5 (empty-sequence probe) removed, D022. The empty sequence cannot fail in this model — the runner checks nothing at zero commands — so the probe is a guaranteed-useless execution and its "empty counterexample" branch is dead; the question it asked is answered by construction (the shrinker only receives a failing `RunResult`). The candidate families drop from three to two (structural, argument), the meta case is removed, and `shrunkOnce` goes with it (its only purpose was trying the probe once). |
| Amended    | maurice, 2026-07-30 — `SequenceShrinker::shrink()` takes the original failing `RunResult`. It makes the shrinker a consumer of what already happened, not a rediscoverer: `$original->executed` filters non-executed commands with **zero** candidate runs (AC3), the execution path is AC8's replay baseline, and `$original->failure` is the `sameKindAs` baseline for the AC1 invariant. `count($original->executed)` must equal `count($failing)` (the same run) or `shrink()` throws a `LogicException` — a length mismatch would filter wrong positions and silently return a wrong counterexample. |
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

- The shrinker operates on the **generated** sequence — a `list<GeneratedValue<Command>>`,
  each command paired with the shrink context the alphabet generator recorded — **and the
  alphabet generator itself**, which family 3 calls to reduce a command's arguments. `shrink()`
  is a method on the generator (Step 12) and the context is opaque, so reducing command *i*'s
  argument requires `alphabet->shrink(generatedValues[i])`; the generator is therefore a second
  argument of the shrinker, not an implementation detail.
- Filtering that sequence to the commands that actually executed, from the execution record
  (SPEC-001 AC4). Both meanings of a false position — precondition-skipped (AC3) and never-reached
  after the failure — drop out identically; the shrinker does not distinguish them.
- Candidate generation in two families, in this order:
  1. **structural** — hold a prefix of length *k*, shrink the length of the
     retained suffix, always keeping the last executed command;
  2. **per-command argument** — for position *i*, `alphabet->shrink(generatedValues[i])` (the
     generation core, SPEC-003), replacing that one command with each reduced value; sequence
     length unchanged.
  (No empty-sequence probe: it always passes in this model, so trying it is a guaranteed-useless
  execution — removed with AC5, D022.)
- `Failure::sameKindAs()` — the identity comparison SPEC-001 declared and deferred to its only
  consumer. It is built here, with D020's exact-class semantics (a subclass is a different kind).
- Lazy candidate generation: candidates are produced on demand, not materialised.
- Cloning every command before running a candidate (R9b, D021). With `GeneratedValue<Command>`, a
  candidate is run by producing, per position, a **new wrapper carrying a shallow clone of the
  command and the same, unchanged context** — the context is shrink data, never executed, so it is
  never cloned. The command and context are one matched pair from a single `generate()`; the
  command is authoritative (it is what runs), and the shrinker never re-pairs a command with a
  foreign context — a mismatch would silently yield candidates that reduce a different command
  (D021's guard, a unit test).
- Recording an execution path and detecting divergence on replay as the
  non-determinism signal (R4).
- A budget (maximum candidate executions) so shrinking terminates on expensive
  systems.

**Out of scope** (each needs its own spec before it may be built)

- Re-validating candidates against the model. Not needed — see Problem and R1.
  If symbolic results are ever introduced, this returns and the whole strategy
  must be re-specced.
- Global minimisation, exhaustive search, or any claim beyond a local minimum.
- Shrinking across parallel or scheduled interleavings (R5).
- Re-ordering commands as a reduction step. Deletion and argument reduction only;
  re-ordering changes semantics in ways the model may not catch.
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

- **AC6 — commands are cloned between candidates** *(R9b, D006, D021)*
  - Given any command — every command is shallow-cloned out of its `GeneratedValue`
    wrapper before a candidate runs (a new wrapper, cloned command, same context, D021); a
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

- **AC8 — an execution-path mismatch aborts shrinking** *(required: error path,
  R4)*
  - Given a recorded execution path and a replay in which a command marked
    not-executed does execute (or the reverse)
  - When the mismatch is detected
  - Then shrinking aborts, the original counterexample is returned unshrunk, and
    the result reports that the system under test is not deterministic — never a
    "minimal" sequence derived from unstable runs.

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
is invariant; this was the SPEC-001 `SequenceRunner` fix). The failing sequence arrives as
`GeneratedValue`s so family 3 can shrink arguments; the runner is handed bare, cloned commands.

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
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $failing   the generated sequence, with contexts
     * @param  RunResult                                           $original  the failing run SPEC-005 already produced;
     *   it supplies three things and makes the shrinker a consumer of what happened rather than a
     *   rediscoverer of it: `executed` filters non-executed commands with no candidate run (AC3),
     *   the execution path is the baseline AC8 replays against, and `Failure` is the `sameKindAs`
     *   baseline the AC1 invariant checks each candidate against. `count($original->executed)`
     *   must equal `count($failing)` — they must be the same run — or `shrink()` throws a
     *   `LogicException`; a length mismatch would filter the wrong positions and silently return a
     *   wrong counterexample (the same silent class as the generators' context guards)
     * @param  Generator<Command<TModel, TSut, mixed>>             $alphabet  the generator, for family-3 argument shrinking
     * @param  callable(): TSut                                    $freshSut
     * @param  TModel                                              $initialModel
     * @return ShrinkResult<TModel, TSut>
     */
    public function shrink(array $failing, RunResult $original, Generator $alphabet, callable $freshSut, mixed $initialModel): ShrinkResult;
}

/**
 * Candidates, lazily, in the order defined in Scope (structural, then per-command argument).
 * Non-executed positions (SPEC-001 AC4) are already gone from $sequence. The argument family calls
 * `$alphabet->shrink()` on the GeneratedValue at each position; candidates keep their contexts, so
 * an accepted one can be re-shrunk (restart). To run a candidate, unwrap each GeneratedValue to a
 * cloned Command for the runner (R9b). No `shrunkOnce` flag: it existed only to try the empty probe
 * once (fast-check), and the probe is gone (D022) — the two families generate identically on the
 * first shrink and every restart.
 *
 * @template TModel
 * @template TSut
 *
 * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence  executed commands only, with contexts
 * @param  Generator<Command<TModel, TSut, mixed>>            $alphabet
 * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
 */
function candidateReductions(array $sequence, Generator $alphabet): iterable;
```

**The three forms at the layer boundary**, made explicit so no one discovers the conversion mid-build:

- **SPEC-005 (draws)** holds `list<GeneratedValue<Command>>` and the alphabet `Generator`, and hands
  both to the shrinker on a failure.
- **SPEC-002 (shrinks)** works on the `GeneratedValue`s (family 3 needs their context and the
  generator) and yields `GeneratedValue` candidates.
- **SPEC-001 (runs)** takes bare `Command`s: each candidate is unwrapped and shallow-cloned (R9b)
  before `SequenceRunner::run`.

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
| AC1                  | cross-cutting invariant (R2) — asserted in every shrinker test and proven by AC7; row lists the covering tests once they exist | the R2 postcondition, not a distinct symbol |
| AC2                  | —                           | —                    |
| AC3                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "drops non-executed commands by reading the record…" + "fails loudly when the executed record does not match…" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::shrink`; `src/Shrinking/ShrinkResult.php` |
| AC4                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` :: "every structural candidate retains the last executed command" (SPEC-002) | `src/Shrinking/SequenceShrinker.php` :: `SequenceShrinker::candidateReductions` |
| AC5                  | removed (D022) — the empty-sequence probe's trigger is unreachable in this model | n/a |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | `tests/Unit/FailureTest.php` (group `SPEC-002`) | `src/Failure.php` :: `Failure::sameKindAs` |
