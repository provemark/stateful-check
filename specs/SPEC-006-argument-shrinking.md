# SPEC-006: Argument shrinking

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | maurice                                           |
| Approved   | maurice, 2026-08-01                               |
| Amended    | maurice, 2026-08-01 — AC6 gains a third case (c): when `AlphabetGenerator::shrink()` throws on a malformed context or an out-of-range branch index, the argument family **lets it propagate** (loud) rather than swallowing it — a generator/usage bug must fail loudly. Decided during the build (the existing behaviour: the family has no `try/catch`); the review flagged it as worth deciding rather than letting happen. Also clarified: case (b)'s length guard is the **existing** SPEC-002 guard on the wrapped list — there is no separate second list, `$failing` is the wrappers. |
| Amended    | maurice, 2026-08-01 — the argument alphabet is a **parameter of `shrink()`**, not a constructor collaborator as the API sketch first drew it. A class-level alphabet cannot share `shrink()`'s method templates: a concretely-typed `Generator<Command<null, null, mixed>>` is not assignable to a class-level `Generator<Command<mixed, mixed, mixed>>` under `Command`'s invariance, so the shrinker's own tests could not construct it (verified at PHPStan max). As a method parameter it binds per call, like `$freshSut`/`$initialModel`, and the alphabet belongs to the sequence being shrunk. `null` is a contract — "shrink structurally only" — not a forgotten value. Illustrative sketch only, but recorded because it corrects the sketch. |
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

Kept deliberately apart from that: the R8 planted-bug meta-test (AC7) is the
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
    is **strengthened**: the returned counterexample is the executed subset of the *finally
    accepted* candidate, so it never contains a non-executed command — enforced by re-filtering
    every accepted candidate through its own replay's executed set (the shared acceptance logic,
    owned here in SPEC-002). This closes a gap the earlier "no-op drop" argument missed: a
    reduction could move the failure earlier and leave the retained last command (SPEC-002 AC4)
    unexecuted, which no drop then removes. Its *trigger* proved unreachable across three
    attempts (AC5's finding), so the re-filter is **unconditional hardening, not a red-first
    fix**: it removes the property's dependence on the family-order and `sameKindAs` choices
    rather than patching a reproducible bug. The tripwire that would fire if a future change
    makes the trigger reachable lives in SPEC-006 AC5.
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
    fails", and "local minimum" means exactly this.
  - *Self-referential, like SPEC-002 AC2 — its recorded conclusion applies here, not re-derived.*
    The test shows the loop stops where no candidate of **the families it has** still fails; it
    cannot detect a family that is too weak, only that the loop halts where it should. Only AC7 (a
    planted bug with a known minimum) tests the real minimum, and with two families there is *more*
    room for a minimum that sits higher than needed. The test's teeth: a value shrinks to the
    **minimal that still fails, not the origin** — `AtLeast` fails only at n ≥ 50, so it converges to
    50 (49 passes) — which is mutant-provable (a family that stops short misses 50).
  - *Doc propagation — a deliverable of this AC, but **not mutant-provable**, and it lands at SPEC-006
    finalisation, not here.* The restated guarantee and every place that states it — the README
    limitations ("No argument shrinking"), `docs/tutorial.md` (the argument-shrinking limitation and
    the "not minimised" notes), `ShrinkResult` rendering — must move together. But those describe the
    **released** capability, and argument shrinking is not shippable until SPEC-006 completes; moving
    them mid-implementation would claim a feature that is not yet released. So the doc move is deferred
    to finalisation and done there as a **documented manual step** (a grep for the old wording is the
    only non-manual form — the spec-check tool's pattern — but a manual pass is acceptable). Labelled
    here so the traceability is not read as half-proven without the distinction: the behaviour half is
    mutant-proven, the doc half is not testable and is pending finalisation.

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

- **AC5 — no returned counterexample contains a non-executed command** *(a meta-invariant /
  tripwire; the re-filter is unconditional hardening, not a fix with a red-first test)*
  - Given any shrink that runs to a confirmed local minimum
  - When the returned counterexample is re-run against a fresh system
  - Then every command it contains executed — the counterexample is always the executed
    subset of its own replay. The shrinker enforces this **unconditionally**: an accepted
    candidate is re-filtered through `executedSubset` of its own replay (`stillFails` returns
    the `RunResult` instead of a bare bool), so a reduction that moved the failure earlier and
    left the retained last command unexecuted cannot survive into the result.
  - *Reachability finding (R11), established during the build not the review.* The bug this
    guards — a returned counterexample ending on a command that did not run — has a **trigger
    that appears unreachable**, tested across three constructions (a two-mode single class; the
    maintainer's `Bump`/`Trip` with a **system-flag** setup, not an argument; a self-bumping
    variant), all of which the shrinker **recovers** from to a fully-executed counterexample.
    The structural reason: for a same-class-as-tail command before the tail to be non-droppable
    it must contribute setup, but a contributing command is either the tail itself (which then
    self-provides its setup and fails alone once its argument is reduced) or a distinct setup
    command that **structure-first drops as a passing no-op** before the argument family can
    turn it into the failer. Either way the loop reaches a fully-executed counterexample.
  - *This is conditional, not a proof.* It rests on exactly two present choices: the family
    order is **structure-first** (a passing middle command is dropped before it is
    argument-reduced), and **`sameKindAs` matches on command class** (so the moved-forward
    failure must be the same class as the tail). **Revisit if** either changes — arguments-first
    or interleaved family order, or a failure identity finer than command class (by argument or
    message). Either reopens the trigger, and then the re-filter earns the *red-first* test this
    AC's tripwire cannot currently be.
  - *Why a tripwire, not a planted-bug meta-test (R8's honest exception).* The invariant is
    asserted over the closest-to-reachable construction (`Bump`/`Trip`), but **no mutant reddens
    it** — the violation cannot currently be built, so removing the re-filter leaves it green. It
    is therefore not mutant-proven and not a proof of correctness; it is a **tripwire** whose
    value is firing when a future change (above) makes the trigger reachable — the same honest
    labelling as SPEC-005 AC2's wiring-reproduction test. The re-filter lands as **hardening
    without a red-first test**, a marked exception like the `Generator`/`Command` contract
    commits: its gate is this tripwire, not a planted failure. Keeping it is removing an
    assumption (the property is otherwise conditional on the two choices above, adjusted twice
    already), not speculative generality.

- **AC6 — a value that cannot shrink, a length mismatch, and a generator error each behave
  safely** *(required: error / malformed-input path)* *(amended 2026-08-01: case (c) added)*
  - Given (a) a position whose value the alphabet yields no shrinks for (a value at the origin,
    or a command with no reducible argument); (b) a wrapped sequence whose length does not equal
    the run's executed count; or (c) a wrapper whose context the alphabet cannot read (wrong
    shape, or a branch index out of range)
  - When the argument family is applied
  - Then — **and the three cases are different in nature**:
    - (a) is the **normal end of every shrink**, not an error: the family yields **no candidates**
      for that position and simply moves on — no throw, no fabricated command, no change. It is
      green on arrival (AC1 and AC4 reach it inevitably); the pin is that it yields nothing *and*
      the loop continues.
    - (b) is the **existing** SPEC-002 guard, not a new one: since step 0 the shrinker's `$failing`
      **is** the wrapped list (there is no separate second list), so `count(executed) === count($failing)`
      already checks the wrappers, throwing a `LogicException` before any candidate runs.
    - (c) is the **new** decision: `AlphabetGenerator::shrink()` throws on a malformed context or an
      out-of-range index, and the family **lets that propagate** — loud — rather than catching it and
      silently skipping the position. A swallow would be exactly the silent degradation the package
      exists to prevent; a generator/usage bug must fail loudly, as the generators themselves do.
  - (There is deliberately **no** class-mismatch guard: a shrink that changes a command's class
    within one branch — `Gen::map(fn ($n) => $n > 5 ? new Big($n) : new Small($n), …)` — is
    legitimate, so guarding on class would abort valid shrinks while still not catching a context
    desync; coupling is handled by construction instead, see Scope and D023.)

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

// The shrinker stays non-generic (method-level templates), and the alphabet is a parameter of
// shrink(), NOT a constructor collaborator (2026-08-01 amendment): a class-level alphabet cannot share
// shrink()'s method templates, so a concretely-typed Generator<Command<null, null, mixed>> is not
// assignable to a class-level Generator<Command<mixed, mixed, mixed>> under Command's invariance — the
// shrinker's own tests could not construct it. As a method parameter it binds to the same templates per
// call, exactly as $freshSut and $initialModel do; the alphabet belongs to the sequence being shrunk.
final class SequenceShrinker
{
    public function __construct(
        private SequenceRunner $runner,
        private int $budget = 100,   // see Open questions — may need to change once a second family draws on it
    ) {}

    /**
     * @param  list<GeneratedValue<Command<TModel, TSut, mixed>>>  $failing   wrapped, so each command's
     *                                                                        generation context survives
     * @param  RunResult                                            $original
     * @param  callable(): TSut                                     $freshSut
     * @param  TModel                                               $initialModel
     * @param  ?Generator<Command<TModel, TSut, mixed>>             $alphabet  the generator the sequence
     *   was drawn from; **null is a contract meaning "shrink structurally only"**, not a forgotten value
     * @return ShrinkResult<TModel, TSut>   $commands still bare (unwrapped) for rendering
     *
     * @template TModel
     * @template TSut
     */
    public function shrink(array $failing, RunResult $original, callable $freshSut, mixed $initialModel, ?Generator $alphabet = null): ShrinkResult;
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

### Answered during review (kept for the record, not open)

- **Budget default — keep 100** (decided at AC7, measured 2026-08-01). In order of weight:
  1. *D007 first, and strongest.* The budget exists to bound **expensive** systems (a slow
     `run()` — HTTP, a real service), where 100 candidate executions is already a lot. Raising it
     to ~350 (to cover a full maxLength confirming pass) would penalise exactly the cases the
     budget is *for*, to comfort cheap in-memory runs that do not need it.
  2. *The signal stays meaningful.* Measured on the realistic AC7 case: **28** executions — so
     `budgetExhausted` stays false for the short minimums shrinking normally produces, and "not a
     confirmed minimum" keeps its signal. It bites only on long, almost-everything-needed minimums.
  3. *It is configurable.* A user with structurally-long minimums raises it themselves.
- *A measured refutation, recorded so it is not re-learned.* The earlier `L·(L−1)/2 + L·k` figure
  predicted the default would bite *within one pass* and `budgetExhausted` become the normal state.
  It does not — **28 measured against ~115 predicted** — because the loop rarely enumerates a full
  family: each acceptance restarts on a *shorter* sequence, so most passes stop early (exactly the
  objection raised when the estimate was made). Kept not as a correction but so a worst-case formula
  is not mistaken for an expectation next time.
- **Revisit if** a system's minimums are **structurally long** — nearly every command needed and
  nearly every value near-minimal — which makes 100 unusable. Measured: a full confirming pass is
  **218 executions at length 8** and **309 at length 10** (the default maxLength). A tool used mostly
  on such systems should raise the default or make the budget adaptive; 100 rests on the *usage*
  assumption that long-all-needed minimums are the exception, not on a property of the code.

- **D021 successor — recorded as D023.** Command/context coupling holds **by construction**
  (each candidate is a whole `GeneratedValue` from `shrink()`); no runtime *pair* guard, and
  none needed — while AC5's length-mismatch guard (input validation, not pair-guarding) stays.
  The §1 blocker (an open question must be answered in `DECISIONS.md` before approval) is met.

- **Executed-subset interaction — investigated in code; the trigger proved unreachable.**
  Finding: the structural loop does **not** re-filter per candidate — it filters once up
  front (`executedSubset`, on `$original->executed`) and an accepted candidate becomes
  `$current` directly. The "no-op drop" argument that this is harmless **has a gap on the last
  position**, which SPEC-002 AC4's last-command rule never drops — so *in principle* an accepted
  candidate could end on a command that did not run. But the case that would exercise that gap
  proved **not constructible**: three attempts (two-mode single class; `Bump`/`Trip` with a
  system-flag setup; a self-bumping variant) all recover, for the structural reason in AC5
  (structure-first drops a passing setup command before the argument family can make it the
  failer; a self-providing tail fails alone once reduced). So AC5 is a **tripwire + unconditional
  re-filter** (hardening), not a planted-bug meta-test — and the finding is **conditional** on
  the family order and `sameKindAs` granularity (Revisit if, in AC5).
- **Family ordering — decided** (structural first; see the Design decision above).
- **R10 gate — verified.** Heterogeneous `list<GeneratedValue<Command<M, S, mixed>>>`
  type-checks at PHPStan max (throwaway check, 2026-08-01).

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at least
one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | `tests/Unit/Shrinking/ArgumentShrinkTest.php` :: "reduces a command's argument to the smallest that still fails" (SPEC-006) — mutant-proven: dropping the argument family from `candidates()` leaves `overdraw(93)` | `src/Shrinking/SequenceShrinker.php` :: `argumentReductions` (per position, `$alphabet->shrink()` replaces one command), `candidates` (structure-first then argument), `shrink`'s `?Generator $alphabet` param; `src/StatefulProperty.php` :: `check` passes `$commandGenerator` to `shrink()` |
| AC2                  | `tests/Unit/Shrinking/ArgumentShrinkTest.php` :: "yields length-preserving, single-position candidates — the alphabet shrinks, one at a time" (SPEC-006) — a shape-pin, green on arrival; mutant-proven (accumulating changes across positions reddens the single-position assertion) rather than red-first | `src/Shrinking/SequenceShrinker.php` :: `argumentReductions` (one position replaced per candidate by `$alphabet->shrink()`, same length) |
| AC3                  | **Behaviour half (mutant-proven):** `tests/Unit/Shrinking/ArgumentShrinkTest.php` :: "reduces a value to the minimal that still fails, not the origin — a combined local minimum" (SPEC-006) — `atLeast(93)` → `atLeast(50)` (49 passes; self-referential like SPEC-002 AC2, real minimum is AC7); mutant: a family that stops short misses 50. **Doc half (not testable, done at finalisation 2026-08-01):** the "No argument shrinking" limitation is removed from the README and "local minimum" restated as the combined minimum; `docs/tutorial.md`'s bank and LRU examples were **re-run** (`deposit(63)`→`deposit(52)`; LRU put values → 1) so the outputs are real, and the "not reduced" limit removed while "initial held fixed" and the branch-choice gap stay. `prior-art.md` needed no change (it describes fast-check's argument shrinking, claims nothing about ours). A manual step, not a test | `src/Shrinking/SequenceShrinker.php` :: the accept loop over `candidates()` (structural + argument), which halts at the combined local minimum |
| AC4                  | `tests/Unit/Shrinking/ArgumentShrinkTest.php` :: "every argument reduction strictly lowers the (length, distance-to-origin) measure" (the proof-property, directly checkable — a non-decreasing candidate reddens it *without hanging*, mutant-proven: "142 is less than 142") + "the shrink loop terminates on its own, without the budget biting" (`budgetExhausted === false` at a huge budget) (SPEC-006) | `src/Shrinking/SequenceShrinker.php` :: `argumentReductions` (each candidate strictly closer to origin), the accept loop (structural strictly shorter + argument strictly closer → the lexicographic measure falls) |
| AC5                  | `tests/Meta/ArgumentShrinkExecutedSubsetTest.php` :: "never returns a counterexample containing a non-executed command — the argument-family tripwire" (groups `meta`, `SPEC-006`) — a **tripwire**, not mutant-proven (the violation is not currently constructible; see the reachability finding) | `src/Shrinking/SequenceShrinker.php` :: `stillFails` (returns the `RunResult`), the accept loop (re-filters the accepted candidate via `executedSubset`) — unconditional hardening, no red-first test (a marked exception, gated by the tripwire) |
| AC6                  | `tests/Unit/Shrinking/ArgumentShrinkTest.php` :: "yields no argument candidates for a value already at its origin" (case a, green on arrival) + "lets a generator context error propagate, never swallows it" (case c, green on arrival; mutant-proven — a swallowing `try/catch` reddens it); case (b) is the existing SPEC-002 guard, tested by `SequenceShrinkerTest` :: "fails loudly when the executed record does not match…" (migrated to wrapped form in step 0) | `src/Shrinking/SequenceShrinker.php` :: `argumentReductions` (no candidates at origin; no `try/catch`, so `$alphabet->shrink()` throws propagate), `::shrink` (the `count(executed) === count($failing)` guard) |
| AC7                  | `tests/Meta/ArgumentPlantedBugShrinkTest.php` :: "shrinks a planted bug needing both families to its exact minimal sequence and value" (groups `meta`, `SPEC-006`) — the bug fires only with a `prime` (flag) AND `amount >= 50`, so it shrinks to `[prime, amount(50)]` only if the structural family drops the noise/keeps prime AND the argument family lowers the value to the threshold. **Budget:** measured (not a test) — 28 executions here; default kept at 100 (see Open questions, resolved) | `src/Shrinking/SequenceShrinker.php` :: the accept loop over both families (`candidates()`), structure-first; `::$budget` default 100 |
| AC8                  | `tests/Unit/Shrinking/SequenceShrinkerTest.php` (all, migrated to `wrapCommands()`) + `tests/Meta/OrderDependentShrinkTest.php` :: "shrinks an order-dependent bug to its known minimal sequence" (SPEC-002) — the existing structural suite, green-on-arrival under wrapped input | `src/Shrinking/SequenceShrinker.php` :: `shrink`/`executedSubset`/`candidateReductions`/`replay` carry `GeneratedValue<Command>`, `unwrap()` renders bare; `src/StatefulProperty.php` :: `check` retains the wrappers and passes them to the shrinker |
| AC9                  | `tests/Unit/Shrinking/ArgumentShrinkTest.php` :: "threads the wrappers through check() so a counterexample carries the reduced argument" (SPEC-006) — end-to-end via `check()` on a **generated** sequence (not hand-assembled); mutant: omit the alphabet in `check()`'s `shrink()` call → structural still runs but the argument stays unreduced (`overdraw(93)` not `overdraw(1)`), the subtle distinguisher | `src/StatefulProperty.php` :: `check` retains the `GeneratedValue` wrappers (`$wrapped`) and passes `$commandGenerator` as `shrink()`'s alphabet |
