# SPEC-006: Argument shrinking

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | maurice                                           |
| Approved   | — (draft)                                         |
| Supersedes | —                                                 |
| Amends     | SPEC-002 (AC2's local-minimum wording; AC3 clarified — the *returned* result is executed-only via the reduction loop, not the up-front filter alone) and SPEC-005 (`StatefulProperty` retains `GeneratedValue` wrappers and passes the alphabet to the shrinker). Both are `implemented`, so these are formal amendments approved together with this spec. |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The shrinker (SPEC-002) makes a failing *sequence* shorter — it drops commands that
did not matter — but never simplifies the *values inside* a command. A counterexample
keeps `Deposit(9999)` where `Deposit(1)` would have failed just as well.

The consumer this needs already exists, independently of this spec: the two dogfood
suites and the tutorial each produce a real counterexample whose argument the tool
cannot minimise — `docs/tutorial.md` shows `deposit(63)` un-reduced and says so, and
the README lists it first under limitations. That is the justification, and it is
pre-existing: those counterexamples exist whether or not this spec is written.

Kept deliberately apart from that: the R8 planted-bug meta-test (AC6) is the
feature's *test*, not evidence for it. A bug written specifically to need argument
shrinking would be circular justification against §4 — the feature proving its own
necessity. The need is the real, already-shipped counterexamples above; the meta-test
only verifies the feature once the need has justified building it.

SPEC-002 retracted an earlier argument-shrinking layer (2026-07-31) with a stated
promise: "no v0.1 case needs it … a later spec adds it together with its consumer."
This is that spec.

The expensive half is already built. Per SPEC-003 the generation layer carries
value-level shrinking: `Generator::shrink(GeneratedValue): iterable` yields smaller
alternatives closest-to-origin first, `IntegersGenerator::shrink` does binary
reduction toward the origin, and `AlphabetGenerator::shrink` already delegates a
command's argument shrinking to the branch generator that produced it, via the opaque
`GeneratedValue::$context`. What SPEC-002 removed was only the *consumer* of that
machinery: the `GeneratedValue<Command>` wrapper as the shrinker's input, the
`$alphabet` generator parameter, and the per-command family. This spec restores the
consumer, not the algorithm. D003 (external shrinker) is unchanged — the shrinker
stays external; it just receives the context again.

Governing rules: R1 (shrinking operates on the executed subset; sound only while
commands are independent, R9a), R2 (never return a passing sequence), R3 (a documented
local minimum — whose statement this spec *changes*), R8 (every shrinking behaviour
needs a planted-bug meta-test), R9b (commands cloned per candidate), §4 (no speculative
generality — arrives with its consumer). Prior art: `docs/prior-art.md` on fast-check's
arbitrary-integrated shrinking and its `canShrinkWithoutContext = false` — argument
shrinking is impossible without the surviving generation context, which the external
shrinker gave up and this spec threads back in.

## Scope

**In scope**

- Threading the generation context back into the shrinker: the failing sequence
  arrives as `list<GeneratedValue<Command>>` (the wrapper restored), and the shrinker
  is constructed with the alphabet `Generator` that produced the commands. (The R10
  gate is already met — a heterogeneous `list<GeneratedValue<Command<M, S, mixed>>>`
  type-checks at PHPStan max, verified 2026-08-01.)
- A second candidate family — the **argument family**: for each executed position *i*,
  ask the alphabet to `shrink()` the `GeneratedValue` that produced `sequence[i]`, and
  yield a candidate with position *i* replaced by each shrunk `GeneratedValue`, every
  other position unchanged. Length-preserving, closest-to-origin first, finite.
- **Command/context coupling by construction** (this replaces the retracted D021's
  runtime "matched-pair guard"). Each argument candidate is a *whole* `GeneratedValue`
  taken straight from `alphabet->shrink()`, which produces the command and its context
  together; the shrinker never re-pairs a command with a foreign context. So the
  coupling holds by construction and needs no runtime guard — the honest position,
  recorded so no future reader reintroduces a guard that checks nothing (D021's own
  failure mode).
- A `tests/Meta/` planted-bug test asserting an exact minimal **argument value** (R8) —
  today's meta-suite (SPEC-002 AC7) plants only an argument-free bug.
- **Cross-spec amendments, delivered here:**
  - *SPEC-002.* AC2's local-minimum wording is superseded by AC3 below. AC3 of SPEC-002
    is **fixed**, not merely clarified: the returned counterexample must be the executed
    subset of the *finally accepted* candidate, so it never contains a non-executed
    command. The up-front filter does not guarantee this on its own — the review found a
    gap the earlier "no-op drop" argument missed: a reduction can move the failure earlier
    and leave the retained last command (AC4) non-executed, which no drop can then remove
    (AC5). The fix is to re-filter every accepted candidate through its own replay's
    executed set — a general property of the shrinker's acceptance logic, so the code
    change is owned here in SPEC-002. Its *reachable trigger* is argument-specific
    (verified: structural-only recovers), so the failing test lives in SPEC-006 AC5.
  - *SPEC-005.* `StatefulProperty` stops unwrapping the drawn `GeneratedValue<Command>`
    on failure; it retains the wrappers and hands them, plus the alphabet, to the shrinker.
- **Migrating the existing shrinker tests** to the wrapped input: `SequenceShrinkerTest`
  (11) and `OrderDependentShrinkTest` (1) call `shrink()` with bare command lists, and
  the SPEC-005 `StatefulPropertyTest` cases that build failing sequences shift at the
  layer boundary. This is not mechanical — each sequence must be wrapped in
  `GeneratedValue`s carrying a real (or deliberately empty) context — and is likely the
  single largest piece of work in the spec. Called out in scope so it is estimated, not
  discovered.

**Out of scope** (each needs its own spec before it may be built)

- Simplifying the branch **choice** — replacing a command with a *different* alphabet
  entry. Argument shrinking simplifies values *within* the chosen branch; the choice is
  still shrunk by no layer (SPEC-003 AC5's documented gap; SPEC-002 restates it).
- Shrinking the drawn **initial state**. It is held fixed while shrinking (SPEC-005 AC8),
  so `initial=949` stays as drawn. Minimising it re-runs the whole sequence per candidate
  and is a separate change with its own spec.
- Symbolic results and any re-validation of candidates against the model (R1, R9a).
  Argument shrinking keeps commands independent: it changes a command's own arguments,
  never makes one depend on another's result.
- Re-ordering commands (SPEC-002 keeps deletion-only).

## Design decision — family ordering (structural first)

Resolved now, because AC3's minimum is *defined relative to the order the loop runs the
families*. The two families share SPEC-002's existing restart loop: on every accepted
reduction, generation restarts from the reduced sequence. Structural candidates are
offered first, argument candidates second. With the restart loop this is effectively
interleaving with structural priority, and it is the cheaper order: every accepted
structural drop removes a position, shrinking the set of positions the argument family
must then consider. Reducing values inside a command that is about to be deleted is
wasted work; deleting first avoids it. The alternative (arguments first) would minimise
values on commands the structural family then throws away.

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

- **AC3 — combined local minimum, stated everywhere** *(R3, restated; supersedes SPEC-002
  AC2's wording)*
  - Given a fully shrunk sequence
  - When any single further reduction — structural drop **or** argument reduction — is
    generated from it
  - Then every such candidate passes: "no single structural or argument reduction still
    fails", and "local minimum" means exactly this. The restated guarantee and **every
    place that states it** move together — the README limitations, `docs/tutorial.md`
    (the argument-shrinking limitation and the "held fixed / not minimised" notes), and
    `ShrinkResult` rendering — so no doc claims the old, narrower minimum. (The doc
    propagation is a deliverable of this AC, not a separate un-owned scope item.)

- **AC4 — termination with a length-preserving family** *(the central correctness risk)*
  - Given a sequence in which every executed position offers argument reductions, so the
    argument family never shortens the sequence
  - When shrinking runs
  - Then it terminates, on a **lexicographic** measure that strictly decreases on every
    accepted candidate: first the length (structural drops lower it), then — at equal
    length — the sum over positions of each value's distance to its origin (argument
    reductions lower it, since `shrink()` only yields origin-ward values). The
    non-obvious step the measure needs: **length can never grow.** Non-executed commands
    are filtered out at the start and never reintroduced, and neither family adds a
    position — structural only drops, argument only replaces one position in place. So
    the measure is bounded below and cannot cycle, and termination does not rest on the
    budget alone (the budget is the bound for *expensive* systems, AC9 of SPEC-002, not
    the reason the loop halts).

- **AC5 — an accepted candidate is re-filtered to its own executed subset** *(the point the
  no-op-drop argument misses; fix lives in the shared acceptance logic)*
  - Given an accepted candidate in which the failure moved earlier — an argument reduction
    made a command fail before a later one it had set up, so that later command (kept by
    AC4's last-command rule) did not execute in this candidate's replay
  - When the candidate is accepted
  - Then the retained representation is the executed subset of **that candidate's own
    replay**, not the parent's, so the returned counterexample never contains — and never
    ends on — a command that did not run. (`stillFails` already runs the candidate; it
    returns that `RunResult` instead of a bare bool, and acceptance re-filters through
    `executedSubset`. Cheap and unconditional.)
  - *Reachability and placement, from the review's code investigation.* The current
    structural loop does not exhibit this: largest-drop-first isolates the retained
    command, and same-class structural instances share behaviour, so the isolated command
    still fails and the result stays clean (verified empirically — `[inc,check,dec,check]`
    shrinks to `[check]`). The argument family breaks that recovery, because same-class
    commands with **different arguments** behave differently: `Withdraw(k)` can fail from
    the initial state while `Withdraw(50)` needs the setup it replaced, so the isolated
    trailing command no longer reproduces and cannot be dropped (it is the retained last).
    So the *reachable trigger* is argument-specific, though the *fix* is a general property
    of the shrinker. The code change is a SPEC-002 amendment (the shared acceptance logic,
    and SPEC-002 AC3 strengthened to state the returned result is executed-only via
    re-filtering, not the up-front filter alone); this AC is the argument-triggered test
    that proves it necessary — the failing test can only exist once the argument family
    does. **Open for the maintainer:** confirm this split (fix in the SPEC-002 amendment,
    failing test here) rather than folding the whole fix into SPEC-006.

- **AC6 — a context the alphabet cannot shrink, or a length mismatch, fails safely**
  *(required: error / malformed-input path)*
  - Given either (a) a position whose `GeneratedValue` the alphabet yields no shrinks for
    (a value already at the origin, or a command with no reducible argument), or (b) a
    `list<GeneratedValue>` whose length does not equal the run's executed count
  - When the argument family is applied
  - Then case (a) yields **no candidates** for that position and shrinking proceeds — it
    never throws, never fabricates a command, never leaves the position changed; and case
    (b) throws a `LogicException` before any candidate runs, the same loud-not-silent
    guard SPEC-002 already applies to `count(executed) === count(failing)`. (There is
    deliberately **no** class-mismatch guard: a shrink that changes a command's class
    within one branch — `Gen::map(fn ($n) => $n > 5 ? new Big($n) : new Small($n), …)` —
    is legitimate, so guarding on class would abort valid shrinks while still not
    catching a context desync; coupling is handled by construction instead, see Scope.)

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
    `ShrinkResult::$commands` is still bare, unwrapped commands for rendering. Testable
    only once the migrated SPEC-002 suite (Scope) is green in wrapped form.

- **AC9 — the entry point threads the wrappers** *(the SPEC-005 amendment, pinned)*
  - Given a `StatefulProperty` whose alphabet produces a command with a reducible argument
  - When `check()` finds a failure and shrinks it
  - Then the returned counterexample carries the **reduced** argument — provable only if
    `StatefulProperty` retained the `GeneratedValue` wrappers and passed the alphabet to
    the shrinker. A bare-command hand-off (today's behaviour) loses the context and leaves
    the argument as drawn, so this AC fails; it is the end-to-end pin that AC1 (shrinker
    level) does not give, the same gap the doc propagation had before it was folded into AC3.

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
        private int $budget = 100,   // see Open questions — may need to change once a second family draws on it
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
 * replaced by a whole alphabet->shrink() GeneratedValue (command + context together — coupling by
 * construction). Length-preserving; origin-first; finite.
 *
 * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $sequence  executed positions only
 * @param  Generator<Command<TModel, TSut, mixed>>             $alphabet
 * @return iterable<list<GeneratedValue<Command<TModel, TSut, mixed>>>>
 */
function argumentReductions(array $sequence, Generator $alphabet): iterable;
```

SPEC-001 (the runner) is untouched — candidates are still shallow-cloned bare commands
(R9b) before `SequenceRunner::run`; the wrapper is unwrapped just before the run.

## Open questions

- **Budget default — a decision due before `implemented` (not before approval).** Measuring
  needs the feature (the prototype *is* the argument family), so "measure before approval"
  would be circular; it is one number with no design consequences, so deciding it late costs
  nothing. Decide it once AC7's meta-case runs and a real total can be read. And the earlier
  `L·(L−1)/2 + L·k` figure was a misleading per-pass worst case: the loop rarely enumerates a
  full family, because acceptance restarts on a *shorter* sequence, so most passes stop early.
  The expensive pass is the **last** — the one that confirms the minimum by enumerating
  everything without accepting — and there `L` is already small. So the meaningful number is
  the **total executions over all passes on a realistic case** (the LRU example, or a
  `Deposit(9999)` overflow), not one pass at the initial length. Read it at AC7; then decide
  whether to raise the default, give the argument family its own share, or accept the flag.
- **D021 successor — record in `DECISIONS.md` first (blocker, per §1).** The successor is
  *not* the retracted guard; it is the recorded decision that command/context coupling
  holds **by construction** (each candidate is a whole `GeneratedValue` from `shrink()`),
  so no runtime guard exists and none is needed. Write it as a decision so the retracted
  guard is not reintroduced later as "protection".

### Answered during review (kept for the record, not open)

- **Executed-subset interaction — investigated in code, and it re-opens a narrowed AC5.**
  Finding: the structural loop does **not** re-filter per candidate — it filters once up
  front (`executedSubset`, on `$original->executed`) and an accepted candidate becomes
  `$current` directly. The first review argued this was harmless via a "no-op drop"
  argument (a skipped command's drop reproduces, so it is always droppable). **That argument
  has a gap on the last position**, which AC4's last-command rule never drops — so an
  accepted candidate can end on a command that did not run, and no drop removes it. The
  structural loop does not hit this in practice (verified: `[inc,check,dec,check]` → `[check]`,
  because same-class structural instances share behaviour, so the isolated trailing command
  still fails), but the argument family does (same class, different argument → the trailing
  command needs the setup its predecessor's reduction removed). So AC5 returns, narrowed to
  the real fix — re-filter each accepted candidate to its own executed subset — with the fix
  owned by the SPEC-002 amendment and the failing test owned here (AC5), because the trigger
  is argument-specific.
- **Family ordering — decided** (structural first; see the Design decision above).
- **R10 gate — verified.** Heterogeneous `list<GeneratedValue<Command<M, S, mixed>>>`
  type-checks at PHPStan max (throwaway check, 2026-08-01).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at least
one test; every source file maps back to this spec.

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
| AC9                  | —                           | —                    |
