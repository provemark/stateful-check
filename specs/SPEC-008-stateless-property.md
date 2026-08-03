# SPEC-008: Stateless property runner

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | maurice                                           |
| Approved   | maurice, 2026-08-03                               |
| Amended    | maurice, 2026-08-03 — the entry point is named `StatelessProperty`, not the sketch's `Property`, for symmetry with `StatefulProperty` and to avoid a bare `Property` reading as ambiguous beside it. Renamed at AC1 (before any tag), so no breaking change. Sketch updated; behaviour unchanged. |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

> **First spec of v0.2.** Recorded as the natural next step in D013: the package
> owns generation (SPEC-003), value shrinking (SPEC-003/006) and a seeded
> reproduction mechanism (SPEC-005), but all three are reachable *only* through the
> stateful entry point. A user with an ordinary property — one generated value, one
> predicate — cannot use the engine at all.

## Problem

The package can express exactly one kind of property: a generated *sequence of
commands* checked against a model (SPEC-005). It cannot express the other kind — a
predicate that must hold for every generated *value* — even though everything
needed to run one already exists and is tested:

- seeded, reproducible generation (`Gen::*`, SPEC-003),
- value-level shrinking toward an origin (SPEC-003 `Generator::shrink()`, exercised
  as the argument family in SPEC-006),
- a seed/reproduction mechanism and a report object (SPEC-005).

These are wired together only for command sequences. A user who wants to assert a
plain property — `decode(encode($x)) === $x`, `f(f($x)) === f($x)`, commutativity,
an invariant — must reach for a second library (Eris), which is exactly what R7 and
the README position the package to avoid needing.

This is not hypothetical. The real dogfood file this package is meant to replace,
`provemark/content-credentials`'
`tests/Unit/Property/BuilderSequencePropertyTest.php`, has four properties, of
which only **one** is stateful (SPEC-005's `ImmutableBuilderExample`). The other
three — including **commutativity** (order of independent setters is irrelevant to
the result) and **immutability** (an earlier instance is untouched by later
operations) — are *stateless* and cannot be expressed today. Until they can, §5
(dogfooding) is only partly satisfied: the file the package claims to be able to
replace contains properties it cannot carry. D013 recorded this and named the
stateless runner "the natural v0.2 candidate."

This spec defines that runner: a `forAll`-style entry point that draws values from
one generator, checks a predicate against each, and on failure shrinks the value to
a minimal counterexample and reports it — the value-level analogue of SPEC-005.

Governing rules: R2 (never report a passing counterexample — the value returned
must still fail), R3 (a local minimum, not a global one), R4 (determinism), R7 (no
runtime dependencies), R8 (shrinking behaviour needs a planted-bug meta-test),
CLAUDE.md §4 (build only what the dogfood suites need), §5 (express the hand-rolled
suites without extension).

Prior art: this is `forAll` from QuickCheck / fast-check / Eris — the oldest and
most familiar shape in property testing, and the primitive the stateful runner is
itself a special case of. `docs/prior-art.md` now carries a section ("the stateless
primitive underneath — `forAll`") on how the mature tools structure their
single-value driver, including that our one non-determinism re-check (D026) goes
slightly beyond what they do.

## Scope

**In scope**

- A single entry point taking: **one** `Generator<T>`, a **predicate**
  `callable(T): bool`, a number of runs, and a seed (one generator, D025 — the
  multi-argument case is a composed `Gen::associative`/`map`, not a variadic API).
  It draws a value per run, and fails the property at the first value for which the
  predicate returns `false`.
- The entry point is **generic** over the generated type (`@template T`), so the
  predicate and the reported counterexample are typed, not `mixed` — the same
  D017/D019 lesson SPEC-005 applies to `TModel`/`TSut`. Because the entry point
  *holds* the generator, `T` is class-level, not per-method. The **R10 gate does
  not apply**: `T` comes from a single generator, so there is no heterogeneous list
  to compose — the same reason `Ref<T>` (SPEC-005) and `TInitial` never triggered
  R10. Recorded here so the pre-approval check is not skipped silently but
  positively dismissed.
- The draw → test → (on failure) shrink → report loop. On failure the runner
  shrinks the failing value toward its origin via `Generator::shrink()`, keeping
  the smallest value that still fails (R2/R3) — the value-level counterpart of
  SPEC-005's use of SPEC-002/006.
- Deterministic reproduction from a seed, end to end, reusing SPEC-003's `Source`
  over `Mt19937` exactly as SPEC-005 does. Same seed → same drawn values and same
  counterexample; a different seed → different.
- A **non-determinism guard**: after shrinking settles, the runner re-runs the
  predicate **once** on the accepted counterexample; a flipped verdict is reported
  as a qualification, not a clean counterexample (D026). This is R4's stateless
  analogue — the runner has no replay path (SPEC-002 AC8's detector), so
  verdict-stability on the reported value is the available signal.
- A **separate** result object (`PropertyValueResult<T>`, D027 — not SPEC-005's
  `PropertyResult` reused) carrying pass/fail, the seed used, and on failure the
  shrunk counterexample value plus a not-a-confirmed-minimum flag when shrinking was
  budget-limited (D007's count budget applies here too) or the non-determinism guard
  fired.
- Rendering the result as a readable artefact: seed + counterexample value on
  failure, seed + a no-counterexample note on a pass.
- Reporting, not throwing, when shrinking was budget-limited (SPEC-002/006's budget
  — D007) or abandoned for non-determinism (D026) — both are qualifications on the
  result, consistent with SPEC-005 AC5.

**Out of scope** (each needs its own spec before it may be built)

- **A multi-argument / variadic `forAll`** (`forAll($genA, $genB)->then(fn ($a,
  $b) => …)`). Decided out (D025): the multi-argument case is expressed by composing
  one `Gen::associative([...])` or `Gen::map(...)` into a single generator — exactly
  how the stateful side builds a multi-argument command (SPEC-003, Steps 8–9), and
  with the same component-wise shrinking (Step 17), so nothing is lost. A variadic
  API is speculative generality (§4) until a suite needs it, and is additive later
  without a break.
- **A string generator (D024).** Still absent, and this spec does not unblock it.
  Classic round-trips on free text (`decode(encode("…"))`) need `strings()` *with*
  its origin-ward shrink (D024). The first stateless consumers must be the dogfood
  properties over `integers`/`elements`/`associative`; `strings()` arrives only
  with its own spec and a named free-text consumer.
- **A precondition / `filter` on the drawn value.** `filter` was cut in the
  combinator audit (Step 11) for its re-check-while-shrinking footgun, and the
  stateless runner has no skip concept — every drawn value is tested. This means,
  unlike SPEC-005 AC10, there is **no vacuous case** to report: there is no channel
  by which a value is drawn but not tested. Recorded so a reader does not expect a
  vacuous verdict here.
- **Targeted, coverage-guided, or edge-biased generation.** Consistent with
  SPEC-003's deliberate uniform generation; the wide-range-integer edge-bias
  limitation (Step 13) is unchanged and out of scope.
- **Shrinking anything stateful** — the initial state, a command sequence. That is
  SPEC-005's territory; this runner has no model and no sequence.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-008')`. Shrinking behaviour is
additionally covered by a planted-bug meta-test (R8, AC8).

- **AC1 — a passing property reports success**
  - Given a generator, a predicate that returns `true` for every value it draws,
    and a run count *n*
  - When the property is run with a seed
  - Then it draws *n* values from one seeded stream (advancing it, so the draws are
    not all identical), returns `passed: true`, and reports the seed it used.

- **AC2 — a failing property is found and shrunk to a minimal counterexample**
  - Given a predicate that returns `false` for some drawn values
  - When the property is run
  - Then it stops at the first value for which the predicate is `false`, shrinks
    that value toward its origin to a local minimum that **still fails** (R2/R3),
    and the result carries that minimal value as the counterexample. No returned
    counterexample passes the predicate (R2).

- **AC3 — deterministic reproduction from a seed**
  - Given the same generator, predicate, and run count
  - When run twice with the same seed, then once with a different seed
  - Then the same seed yields the same drawn values and the same counterexample;
    the different seed yields different draws. The whole seed→outcome chain stays
    free of non-deterministic sources (SPEC-005 AC3's recorded requirement).

- **AC4 — the result renders as a readable artefact**
  - Given a completed run (pass or fail)
  - When the result is rendered to a string
  - Then a failing result renders the seed and the counterexample value; a passing
    result renders the seed and a no-counterexample note; a counterexample that is
    budget-limited or non-determinism-qualified is marked as not a confirmed
    minimum. Any value renders without a fatal.

- **AC5 — a budget-limited shrink is a qualification, not a clean minimum**
  - Given a failing value whose shrink would exceed the count budget (D007)
  - When the property is run
  - Then the result reports the best value found so far, flagged as not a confirmed
    minimum (consistent with SPEC-005 AC5), and does not throw.

- **AC6 — a non-deterministic predicate is detected on the reported value** *(R4,
  D026)*
  - Given a predicate whose verdict on a given value is not stable (it returns
    `false` when the counterexample is found, `true` when re-run on the same value)
  - When shrinking settles on a counterexample and the runner re-runs the predicate
    once on it
  - Then the flipped verdict is reported as a non-determinism qualification (the
    result is not a clean counterexample and is flagged not-a-confirmed-minimum),
    and the runner does not throw. The check runs exactly once, on the reported
    value only (D026).

- **AC7 — invalid configuration is rejected at construction** *(required: error /
  malformed input)*
  - Given a run count below 1
  - When the entry point is constructed
  - Then it throws `InvalidArgumentException`, naming the offending value, with no
    partial side effects (parallel to SPEC-005 AC6).

- **AC8 — a planted-bug meta-test pins the exact minimal counterexample** *(R8)*
  - Given a property with a deliberately planted bug whose minimal failing value is
    known (e.g. the predicate holds iff `$n < 50`, over `Gen::integers(0, 1000)`,
    origin 0)
  - When the property is run at a fixed seed
  - Then the returned counterexample is exactly that known minimum (here `50`),
    asserted in `tests/Meta/` — a shrinker that merely does not crash is not tested.

## API sketch

Illustrative only — not binding. `final`, `readonly` value objects,
`declare(strict_types=1)`. Two shapes are sketched; choosing between them is an
open question below.

```php
// namespace Provemark\StatefulCheck\;

/**
 * @template T
 */
final class StatelessProperty
{
    /**
     * @param Generator<T>     $generator
     * @param callable(T): bool $predicate
     */
    public function __construct(
        private Generator $generator,
        private mixed $predicate,   // callable(T): bool
        private int $runs = 100,
        private int $shrinkBudget = 100,   // D007
    ) {
        // AC6: throws InvalidArgumentException when $runs < 1
    }

    /** @return PropertyValueResult<T> */
    public function check(?int $seed = null): PropertyValueResult
    {
        // AC1/AC2/AC3/AC5/AC6: draw, test, shrink on failure, re-check, report.
    }
}

/**
 * D028: the entry point is constructor + check(), as above (matching SPEC-005).
 * A fluent `forAll(...)->then(...)` facade is deferred, additive later without a
 * break — not built now (§4). It would read:
 *
 *   forAll(Gen::associative(['name' => $names, 'version' => $versions]))
 *       ->then(fn (array $a): bool => commutes($a['name'], $a['version']))
 *       ->check($seed);
 */

/**
 * A separate value object (D027), not SPEC-005's PropertyResult reused.
 *
 * @template T
 */
final class PropertyValueResult
{
    public bool $passed;
    public int $seed;
    /** @var T|null the shrunk counterexample, when $passed is false */
    public mixed $counterexample;
    public bool $confirmedMinimum;   // false when budget-limited (AC5) or non-determinism-qualified (AC6)
    public bool $nonDeterministic;   // AC6/D026: the reported value's verdict flipped on re-check

    public function asString(): string { /* AC4 */ }
}
```

## Open questions

**Resolved** (recorded in DECISIONS; folded into Scope/Behavior above):

- ~~**OQ1 — single generator, or a variadic/tuple `forAll`?**~~ **D025:** single
  generator; multi-argument via `Gen::associative`/`map`, equivalent shrinking, no
  loss.
- ~~**OQ2 — is non-determinism detected, and how?**~~ **D026:** yes — re-check the
  reported counterexample once; a flipped verdict is a qualification (AC6).
- ~~**OQ3 — a shared or a separate result type?**~~ **D027:** a separate
  `PropertyValueResult<T>`; the SPEC-007 assertion knock-on is a sibling helper, not
  a shared interface, decided at implementation.
- ~~**OQ4 — constructor + `check()`, or a `forAll(...)->then(...)` facade?**~~
  **D028:** constructor + `check()`, matching SPEC-005. A `forAll` sugar can be added
  later without a breaking change.
- ~~**OQ5 — does `docs/prior-art.md` need extending first?**~~ **Done:** the
  "stateless primitive underneath — `forAll`" section was added, covering
  QuickCheck/fast-check/Eris and citing it here.

**Remaining:** none. All open questions are resolved; the spec is ready for the
pre-approval checks below.

## Pre-approval checks (R10 / R11)

- **R10** — no subject: `T` comes from one generator, no heterogeneous composition
  (see Scope). Positively dismissed, not skipped.
- **R11** — checked per AC on both counts (a path exists; the trigger is reachable):
  - AC1–AC5, AC7: paths exist via the owned generation/shrink/seed machinery and the
    `PropertyValueResult` channels above; triggers are reachable (a `false`-returning
    predicate, a below-1 run count).
  - AC6's non-determinism trigger is reachable — a predicate that flips verdict on
    re-run — and the path exists (the single re-check, D026).
  - AC8's planted-bug trigger was shown **constructible against the built generator**,
    not merely reasoned by analogy (the Step 58 lesson): a throwaway greedy shrink
    over `Gen::integers(0, 1000)` with the predicate "fails iff `n >= 50`" settled on
    exactly `50` from every failing start (`937, 500, 51, 999, 50`), and `49` is
    correctly not a failure. Throwaway run and deleted, not committed. This matches
    the existing `amount(50)` result in `ArgumentPlantedBugShrinkTest` (SPEC-006 AC7),
    the argument-shrinking analogue.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

All tests are in `tests/Unit/StatelessPropertyTest.php` (group `SPEC-008`) unless noted.
Source is `src/StatelessProperty.php` and `src/PropertyValueResult.php`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `StatelessPropertyTest` :: "runs n values from a seeded stream and reports success…" + "advances one seeded stream across the runs…" | `StatelessProperty::check()` (seeded loop over `Source`) |
| AC2 | `StatelessPropertyTest` :: "reports a failing value as a counterexample that still fails, before any shrinking" (part 1) + "shrinks the failing value toward the origin to the minimal value that still fails" (part 2) | `StatelessProperty::check()`, `::shrink()`; `PropertyValueResult::$counterexample` |
| AC3 | `StatelessPropertyTest` :: "reproduces the same draws from the same seed, and varies with a different one" + "reproduces the same counterexample from the same seed…" | `StatelessProperty::check()` (`Source::seeded`, one advancing stream) |
| AC4 | `StatelessPropertyTest` :: "renders a failing result…" + "renders a passing result…" + "marks a budget-limited…" + "marks a non-deterministic…" + "renders any value without a fatal" | `PropertyValueResult::counterexampleAsString()` |
| AC5 | `StatelessPropertyTest` :: "reports a budget-limited shrink as not a confirmed minimum" | `StatelessProperty::shrink()` (budget); `PropertyValueResult::$confirmedMinimum` |
| AC6 | `StatelessPropertyTest` :: "re-checks the counterexample once and reports a non-deterministic verdict flip" | `StatelessProperty::check()` (one re-check); `PropertyValueResult::$nonDeterministic` |
| AC7 | `StatelessPropertyTest` :: "throws at construction when the run count is below one" + "constructs without throwing when the configuration is valid" | `StatelessProperty::__construct()` (guard) |
| AC8 | `tests/Meta/StatelessPlantedBugShrinkTest` :: "shrinks a planted stateless bug to its exact minimal counterexample" (group `meta` + `SPEC-008`) | `StatelessProperty::check()`, `::shrink()` (R8) |