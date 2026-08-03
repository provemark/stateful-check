# SPEC-009: Edge-biased generation

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | maurice                                           |
| Approved   | maurice, 2026-08-03                               |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

> **A v0.2 spec.** Recorded as a known limitation since Step 13 (NOTES): uniform
> generation over a wide range almost never draws the values where bugs cluster —
> `0`, `min`, `max`, and their neighbours. Step 13 deferred the fix to "its own
> spec, when a real suite draws a command argument over a wide range and relies on
> hitting an edge to trigger a bug." That consumer is now named: testing
> AI-generated code, whose characteristic bugs live exactly at those edges.

## Problem

The generation core draws uniformly (`IntegersGenerator`, SPEC-003). Over a wide
range this almost never produces the boundary values — `0`, `min`, `max`, and their
immediate neighbours — which is precisely where off-by-one, empty-collection,
overflow and degenerate-case bugs cluster. Step 13 recorded this as a deliberate,
known limitation, deferred until a consumer needed it.

That consumer is the reason the package exists: testing AI-generated code
(CLAUDE.md — the separate verification package builds on this engine). AI writes
code and its tests together, so they share blind spots; the happy path passes and
the failure is at an edge the author never considered. A property tester earns its
place only by drawing what the author did not — and uniform sampling, over any
non-trivial range, does not draw the edges. Richer *types* (floats, strings) do not
fix this: a uniform `floats()` misses `NaN`/`INF`/`0` just as uniform `integers()`
misses `0`/`min`/`max`. The leverage is *where you sample*, not *what you sample*.

fast-check and every QuickCheck descendant bias toward small values and boundaries
for this reason (`docs/prior-art.md`). This spec adds that bias to `integers()` — the
leaf every other generator composes on — so the boundary values appear with a
controlled, deterministic frequency, while everything else about generation and
shrinking is unchanged.

Governing rules: R4 (determinism — the bias must be seeded and reproducible), R7 (no
runtime dependencies — the bias draws from the owned `Source`), R8 (the behaviour
needs a planted-bug meta-test — here a bug that fires *only* at an edge), CLAUDE.md
§4 (build only what a consumer needs; measure defaults, do not guess them).

## Scope

**In scope**

- Edge-biasing for `integers($min, $max, $origin)`: with a configured frequency, a
  draw returns a value from a small curated **edge-set** instead of a uniform value.
  The edge-set is drawn from the same seeded `Source`, so the whole thing stays
  deterministic (R4).
- The edge-set is derived from the range the generator already knows: `origin`,
  `min`, `max`, and their in-range neighbours (`origin±1`, `min+1`, `max-1`),
  clamped into `[min, max]` and de-duplicated. No user-supplied edges in v1 (§4).
- **Shrinking is unchanged.** `IntegersGenerator::shrink` reads only the value and
  the origin, never how the value was drawn (integer context is `null`). An
  edge-drawn `max` shrinks toward the origin exactly as a uniform-drawn value would.
  This spec adds nothing to `shrink()`; the invariant to preserve is that an
  edge-biased `generate()` stores the *same* context a uniform draw of that value
  would (trivially `null` for integers).
- Bias **propagates through the combinators for free**: `map`, `associative` draw
  their inner values through the (now edge-aware) `integers`, so a composite over a
  biased integer inherits the bias with no change to those combinators.
- A **planted-bug meta-test** (R8): a property that fails only at an edge, which the
  biased generator finds within budget and uniform generation does not.

**Out of scope** (each needs its own spec before it may be built)

- **Edge-sets for other types** — a nasty-string set (empty, whitespace, control,
  multibyte, very long) or a nasty-float set (`NaN`, `INF`, `±0`, subnormal). Those
  belong with the `strings()`/`floats()` generators (D024), each with its own
  origin-ward shrink, not here.
- **Adaptive / size-dependent bias schedules** (fast-check varies the frequency by
  run index). v1 is a fixed frequency; an adaptive schedule is a later refinement if
  measured to help.
- **User-supplied custom edges** — deferred until a suite needs a domain-specific
  boundary the range cannot derive (§4).
- **Targeted or coverage-guided generation** — steering by observed coverage is a
  different mechanism, explicitly out of v0.1/v0.2 scope (CLAUDE.md §4).

## Behavior

Acceptance criteria as Given/When/Then, each covered by a Pest test tagged
`->group('SPEC-009')`. The edge-only planted bug is additionally a meta-test (R8).

- **AC1 — the edge-set appears at the configured frequency, deterministically**
  - Given `integers(0, 1_000_000)` edge-biased at a fixed frequency and a fixed seed
  - When a fixed number of values is drawn
  - Then the sequence contains edge values (`0`, `1_000_000`, …) — which uniform
    generation over this range essentially never produces — and the exact sequence
    is pinned to the seed (so the test is deterministic, not statistical).

- **AC2 — biasing is reproducible from a seed** *(R4)*
  - Given the same generator and seed
  - When drawn twice, then once with a different seed
  - Then the same seed yields the identical sequence (edge draws included); a
    different seed varies it. No non-deterministic source enters the decision.

- **AC3 — an edge-drawn value shrinks normally**
  - Given a failing value that was drawn from the edge-set (e.g. `max`)
  - When it is shrunk
  - Then it reduces toward the origin to the minimal value that still fails, exactly
    as a uniform-drawn value would — `shrink()` is oblivious to the draw path.

- **AC4 — bias propagates through the combinators**
  - Given `map(f, integers(…, biased))` (and `associative` over a biased integer)
  - When drawn from a fixed seed
  - Then edge values of the inner integer appear in the composite draws — the bias is
    inherited via delegation, with no change to `map`/`associative`.

- **AC5 — an edge-only planted bug is found (biased) where uniform misses it** *(R8, meta)*
  - Given a property that fails only at an edge (e.g. the predicate holds unless
    `n === max`), over a wide range
  - When run with edge-biasing at a fixed seed
  - Then a counterexample is found within the run budget, and the meta-test documents
    that uniform generation does not find it within the same budget (asserted in
    `tests/Meta/`). This is the criterion that proves the feature earns its place —
    not that generation "is biased", but that it catches an edge bug uniform misses.

- **AC6 — invalid configuration is rejected at construction** *(required: error / malformed input)*
  - Given a bias frequency outside its valid range (e.g. a percentage `< 0` or `> 100`)
  - When the generator is constructed
  - Then it throws `InvalidArgumentException`, naming the offending value, with no
    partial side effects (the SPEC-005/008 guard pattern).

## API sketch

Illustrative only — not binding on the exact name/signature. The shape is decided
(D029): an opt-in parameter on `integers`, defaulting off so existing seeds are
unchanged (R4), that composes because the leaf carries it.

```php
// namespace Provemark\StatefulCheck\Generation;

Gen::integers(int $min, int $max, ?int $origin = null, int $edgeBias = 0);
//                                                     ^ percent 0..100; 0 = today's pure uniform.

// Composes without touching the combinators:
Gen::map(fn (int $n) => new Deposit($n), Gen::integers(0, 1_000_000, edgeBias: 15));
```

The edge-set is internal to `IntegersGenerator`, derived from `[$min, $max]` and
`$origin`. The bias decision and the edge index are drawn from the `Source` passed to
`generate()`, so nothing new about determinism changes.

## Open questions

**Resolved** (recorded in DECISIONS; folded into Scope/API above):

- ~~**OQ1 — on-by-default, opt-in parameter, or wrapper?**~~ **D029:** opt-in parameter
  on `integers()` (`edgeBias`, default `0` = pure uniform). Keeps every existing seed
  reproducible (R4); biasing is a deliberate, composable choice the leaf carries.

**Remaining:**

- **OQ2 (measured at build, not now) — the frequency.** Deferred deliberately: the
  frequency will be **measured during implementation** against the AC5 planted edge-bug
  (the D007/Step 60 precedent — pick the value that finds the planted bug reliably
  without swamping ordinary coverage, not a round number), and recorded then. Whether it
  is configurable or a single measured constant is settled with that measurement. Not a
  blocker for approval: the *approach* is fixed, only the number waits for the build.

- **OQ3 (non-blocker) — the edge-set contents.** `{origin, min, max}` only, or also
  `{origin±1, min+1, max-1}`? Bigger sets hit more boundary bugs but dilute each edge's
  frequency. *Recommendation: start small, measure against AC5, widen only if a planted
  neighbour-bug needs it.*

- **OQ4 (non-blocker) — user-supplied custom edges?** Deferred (§4) until a suite needs
  a domain boundary the range cannot derive. Recorded so it is a conscious omission.

## Pre-approval checks (R10 / R11)

- **R10** — no subject: this spec introduces no new `@template` type (it changes a draw
  distribution, not a contract's generics). Positively dismissed.
- **R11** — checked per AC on both counts (a path exists; the trigger is reachable):
  - AC1/AC2: the biased draw and the bias decision both come from the seeded `Source`, so
    the sequence is deterministic and reproducible (same mechanism proven in SPEC-008 AC3).
  - AC3: `IntegersGenerator::shrink` is value-based (SPEC-008 AC2, proven), so an
    edge-drawn value reduces toward the origin like any other — the path exists.
  - AC4: `map`/`associative` delegate `generate()` to their inner generator, so biasing the
    inner `integers` propagates with no change — the path exists.
  - AC6: the `edgeBias` out-of-range guard is the SPEC-005/008 construction-guard pattern —
    reachable trigger, existing path.
  - **AC5 was verified constructible *and discriminating* against the real `Source`**, not
    reasoned by analogy (the Step 58 lesson): a throwaway simulating the opt-in bias
    (frequency 15%, edge-set `[0, 1000000, 1, 999999]`) over `integers(0, 1_000_000)` with
    the bug "fails iff `n === max`". Across seeds `1, 42, 777, 12345, 2024`, **uniform
    generation found the edge bug in none within 100 runs; biased generation found it in all
    (at runs 8, 34, 29, 31, 22)**. The criterion genuinely discriminates — uniform misses
    the edge, biased hits it — at the same seed and budget. Throwaway run and deleted, not
    committed. The 15% here is only for the R11 demonstration, not the chosen default (OQ2 is
    measured at build).

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
