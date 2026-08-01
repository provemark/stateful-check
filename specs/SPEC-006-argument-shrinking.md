# SPEC-006: Argument shrinking

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | maurice                                           |
| Approved   | — (draft)                                         |
| Supersedes | — (extends SPEC-002; revisits its shrinker input and its local-minimum guarantee) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The shrinker (SPEC-002) makes a failing *sequence* shorter — it drops commands that
did not matter — but never simplifies the *values inside* a command. A counterexample
keeps `Deposit(9999)` where `Deposit(1)` would have failed just as well, and reports
`initial=949` where a smaller opening balance would do. The tutorial says this in so
many words (`docs/tutorial.md`), and the README lists it first under limitations: it
is the single thing a reader coming from fast-check or Hypothesis expects and does
not find.

SPEC-002 retracted an earlier argument-shrinking layer (2026-07-31) for a stated
reason: "no v0.1 case needs it — building it now would be speculative generality
(§4). A later spec adds it together with its consumer." **This is that spec, and the
consumer now exists**: the two dogfood examples and the tutorial each carry a
counterexample whose argument the tool cannot minimise, which is a visible, honest
gap rather than a hypothetical one.

The expensive half is already built. Per SPEC-003 the generation layer carries
value-level shrinking: `Generator::shrink(GeneratedValue): iterable` yields smaller
alternatives closest-to-origin first, `IntegersGenerator::shrink` does binary
reduction toward the origin, and `AlphabetGenerator::shrink` already delegates a
command's argument shrinking to the branch generator that produced it, using the
opaque `GeneratedValue::$context`. What SPEC-002 removed was only the *consumer* of
that machinery: the `GeneratedValue<Command>` wrapper as the shrinker's input, the
`$alphabet` generator parameter, the per-command family, and the matched-pair
invariant D021 (which, on the record, was never even implemented). This spec restores
the consumer, not the algorithm.

Governing rules: R1 (shrinking operates on the executed subset; sound only while
commands are independent, R9a), R2 (never return a passing sequence), R3 (a documented
local minimum — whose statement this spec *changes*), R8 (every shrinking behaviour
needs a planted-bug meta-test), R9b (commands cloned per candidate), R10 (a
generic-typed contract proven heterogeneous before approval), R11 (an AC proven
fulfillable before approval), §4 (no speculative generality — arrives with its
consumer). Prior art: `docs/prior-art.md` on fast-check's arbitrary-integrated
shrinking and its `canShrinkWithoutContext = false` — argument shrinking is impossible
without the surviving generation context, which is exactly what the external shrinker
gave up and this spec threads back in. D003 (external shrinker) is unchanged: the
shrinker stays external; it just receives the context again.

## Scope

**In scope**

- Threading the generation context back into the shrinker: the failing sequence
  arrives as `list<GeneratedValue<Command>>` (the wrapper restored), and the shrinker
  is constructed with the alphabet `Generator` that produced the commands.
- A second candidate family — the **argument family**: for each executed position *i*,
  ask the alphabet to `shrink()` the `GeneratedValue` that produced `sequence[i]`, and
  yield a candidate with position *i* replaced by each shrunk command, every other
  position unchanged. Length-preserving, closest-to-origin first, finite.
- The matched-pair invariant (a D021 successor): a command and the context that
  produced it travel together; the command is authoritative, and a candidate whose
  shrunk `GeneratedValue` yields a command of a different class than the position it
  replaces is a mismatch and aborts loudly (never a silently-wrong counterexample).
- Restating the local-minimum guarantee (R3) to cover both families: "no single
  structural **or** argument reduction still fails." Propagating that restatement to
  the README, the tutorial, and `ShrinkResult`'s rendering.
- Termination with a length-preserving family present, resting on the existing budget
  (SPEC-002 AC9) and the finiteness of each `Generator::shrink` enumeration.
- A `tests/Meta/` planted-bug test that asserts an exact minimal **argument value**
  (R8) — today's meta-suite (SPEC-002 AC7) plants only an argument-free bug.

**Out of scope** (each needs its own spec before it may be built)

- Simplifying the branch **choice** — replacing a command with a *different* alphabet
  entry. Argument shrinking simplifies values *within* the chosen branch; the choice
  is still shrunk by no layer (SPEC-003 AC5's documented gap; SPEC-002 restates it).
- Shrinking the drawn **initial state**. The initial is held fixed while shrinking
  (SPEC-005 AC8), so `initial=949` stays as drawn. Minimising it is a separate change
  with its own risks (it re-runs the whole sequence per candidate) and its own spec.
- Symbolic results and any re-validation of candidates against the model (R1, R9a).
  Argument shrinking keeps commands independent: it changes a command's own arguments,
  never makes one depend on another's result.
- Re-ordering commands (SPEC-002 keeps deletion-only).

## Behavior

- **AC1 — a reducible argument is reduced** *(the capability itself)*
  - Given a counterexample containing a command whose argument can be smaller while the
    sequence still fails (e.g. `Deposit(9999)` where `Deposit(1)` also fails)
  - When the sequence is shrunk
  - Then the returned command at that position carries the smaller value — the smallest
    the argument family reaches that still fails — not the value as drawn.

- **AC2 — the argument family is length-preserving, single-position, origin-first**
  - Given an executed position holding a `GeneratedValue<Command>`
  - When the argument family generates candidates for it
  - Then each candidate has the **same length** as its parent, differs at **exactly that
    one position**, and the replacements are those of `alphabet->shrink(value)` —
    closest-to-origin first and finite. No candidate reduces two positions at once.

- **AC3 — combined local minimum** *(R3, restated; supersedes SPEC-002 AC2's statement)*
  - Given a fully shrunk sequence
  - When any single further reduction — structural drop **or** argument reduction — is
    generated from it
  - Then every such candidate passes. The guarantee is now "no single structural or
    argument reduction still fails"; the words "local minimum" mean this and no more.

- **AC4 — termination with a length-preserving family** *(the central correctness risk)*
  - Given a sequence in which every executed position offers argument reductions, so the
    argument family never shortens the sequence
  - When shrinking runs
  - Then it terminates — bounded by the budget (SPEC-002 AC9) and, within budget, by the
    finiteness of each `Generator::shrink` enumeration and the origin-ward direction of
    reduction (an accepted argument reduction is strictly closer to the origin, so no
    position can be reduced forever). No infinite loop, with or without the budget biting.

- **AC5 — an accepted candidate is re-filtered to what actually ran** *(R1 preserved)*
  - Given an argument reduction that changes a command's value such that a later command's
    precondition flips (a position that ran now skips, or one that skipped now runs)
  - When the candidate is replayed against a fresh system
  - Then the retained representation is the executed subset of **that replay**, not of the
    parent — so the counterexample is always the executed subset of the sequence actually
    returned. (This is how deletion already stays legal, R1; the argument family must obey
    the same rule rather than assume the executed set is fixed.)

- **AC6 — a context the alphabet cannot shrink, or a length mismatch, fails safely**
  *(required: error / malformed-input path)*
  - Given either (a) a position whose `GeneratedValue` the alphabet yields no shrinks for
    (a value already at the origin, or a command with no reducible argument), or (b) a
    `list<GeneratedValue>` whose length does not equal the run's executed count
  - When the argument family is applied
  - Then case (a) yields **no candidates** for that position and shrinking proceeds — it
    never throws, never fabricates a command, never leaves the position changed; and case
    (b) throws a `LogicException` before any candidate runs, the same loud-not-silent guard
    SPEC-002 already applies to `count(executed) === count(failing)`.

- **AC7 — a planted bug shrinks to its exact minimal argument value** *(R8)*
  - Given a system with a bug that fires only for an argument at or above a threshold
    (e.g. a cache that overflows a cap), and a long failing sequence drawn with large
    values
  - When it is shrunk
  - Then the result equals the known minimal sequence **including the minimal argument
    value**, compared by its string representation — the argument-family analogue of
    SPEC-002 AC7's order-dependent planted bug.

- **AC8 — structural shrinking is preserved under the wrapped input**
  - Given the shrinker now receives `list<GeneratedValue<Command>>` and an alphabet
  - When a sequence with no reducible arguments is shrunk
  - Then the structural result is exactly what SPEC-002 AC1–AC4 and AC7 produced from bare
    commands — the plumbing change does not regress structural shrinking, and
    `ShrinkResult::$commands` is still bare, unwrapped commands for rendering.

## API sketch

Illustrative only. The change is a return to the pre-retraction shape, plus one family.

```php
// namespace Provemark\StatefulCheck\Shrinking;

/**
 * @template TModel
 * @template TSut
 */
final class SequenceShrinker
{
    /**
     * @param Generator<Command<TModel, TSut, mixed>> $alphabet  the command generator, for the
     *   argument family (AlphabetGenerator::shrink delegates to the branch that produced each command)
     */
    public function __construct(
        private SequenceRunner $runner,
        private Generator $alphabet,
        private int $budget = 100,
    ) {}

    /**
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $failing   wrapped, so each command's
     *                                                                        generation context survives
     * @param  RunResult                                            $original
     * @param  callable(): TSut                                     $freshSut
     * @param  TModel                                               $initialModel
     * @return ShrinkResult<TModel, TSut>   $commands still bare (unwrapped) for rendering
     */
    public function shrink(array $failing, RunResult $original, callable $freshSut, mixed $initialModel): ShrinkResult;
}

/**
 * The argument family: for each executed position, yield candidates with just that position
 * replaced by an alphabet->shrink() alternative. Length-preserving; origin-first; finite.
 *
 * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence  executed positions only
 * @param  Generator<Command<TModel, TSut, mixed>>             $alphabet
 * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
 */
function argumentReductions(array $sequence, Generator $alphabet): iterable;
```

**The forms at the layer boundary** change on one side only: SPEC-005 (`StatefulProperty`) must
now keep the `GeneratedValue<Command>` wrappers it draws and hand them to the shrinker together
with the alphabet, instead of unwrapping to bare commands on failure. SPEC-001 (the runner) is
untouched — candidates are still shallow-cloned bare commands (R9b) before `SequenceRunner::run`.

## Open questions

- **Family ordering (blocker).** Structure-first-then-arguments, or interleave both families each
  pass (fast-check interleaves)? It changes which combined minimum is reached and the cost. Decide
  and record before approval; the AC3 minimum is defined *relative to the families the loop runs*,
  so the loop's shape is part of the contract.
- **The executed-subset interaction (blocker).** AC5 asserts re-filtering to the replay's executed
  set. Before approval, confirm against the current `SequenceShrinker` whether the structural loop
  already re-derives the executed set per accepted candidate (in which case the argument family
  inherits it) or assumes a fixed set (in which case both families need the change). This is the
  subtle correctness point flagged at scoping; settle it in code-reading, not by assumption.
- **D021 successor (blocker — must be answered in `DECISIONS.md` first, per §1).** The matched-pair
  invariant needs a decision entry: command and context paired, command authoritative, and a
  shrinker-internal guard (AC6) that a shrunk candidate's command class matches the position it
  replaces. The retracted D021 "was never implemented"; its successor must be, and tested.
- **R10 gate (pre-approval).** Verify statically that a *heterogeneous* `list<GeneratedValue<Command<M,
  S, mixed>>>` type-checks at PHPStan max — the same throwaway check that caught D017/D019 — before
  approval, since the wrapper composes commands at differing arguments.
- **Guarantee restatement reach (non-blocker).** AC3 changes what "local minimum" means. Enumerate
  every place that states it — README limitations, `docs/tutorial.md` (both the argument-shrinking
  limitation and the LRU/bank "held fixed / not minimised" notes), and any error/rendering text — so
  the claim moves everywhere at once, not just in code.
- **Budget accounting (non-blocker).** Argument candidates are executions like any other and count
  toward the budget (SPEC-002 D007). Confirm the budget default (100) is still adequate once a second,
  larger family draws from it, or whether the two families should share it differently.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at least one test;
every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
