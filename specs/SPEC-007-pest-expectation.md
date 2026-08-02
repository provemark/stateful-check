# SPEC-007: Optional Pest expectation

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved — **not scheduled for v0.1**             |
| Author     | maurice                                           |
| Approved   | maurice, 2026-08-02                               |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

> **Convenience only.** The package is already fully usable from Pest without
> this (the two `examples/` suites prove it): `check()` returns a `PropertyResult`
> and the caller asserts on it directly. This spec removes one recurring footgun,
> nothing more. If it does not clearly earn its place, close it `superseded`.

## Problem

Asserting a passing property in Pest today reads:

```php
expect($result->passed)->toBeTrue($result->counterexampleAsString());
```

The assertion carries a footgun that defeats the package's own reproducibility
guarantee. `counterexampleAsString()` is the reproduction artefact — it renders
`seed=…`, the `initial`, the counterexample commands and any "not a confirmed
minimum" qualification (SPEC-005). It is passed as Pest's *optional* failure
message. Omit it — the obvious thing to do, since `expect($x)->toBeTrue()` reads
complete — and a red test prints no seed, so the failure cannot be reproduced.
The one piece of output that makes a stateful failure actionable is exactly the
piece the ergonomics invite you to drop.

A one-line custom expectation, `expect($result)->toPass()`, binds the two
together: it cannot assert the pass without also rendering the reproduction
artefact on failure. That is the whole of this spec.

This is test-time sugar over `PropertyResult`, not an engine abstraction, so
CLAUDE.md §4's "must be needed by the two dogfood suites" gate does not apply the
same way — but its spirit does: the surface is kept to the single expectation the
footgun justifies, and richer helpers are pushed to open questions.

## Scope

**In scope**

- A single Pest expectation `toPass()` on a `PropertyResult`: passes silently when
  the result passed, fails with `counterexampleAsString()` as the message when it
  did not (a real counterexample, a vacuous run, or an abandoned/budget-limited
  shrink — all are "did not pass").
- Registration by including one provided file from the consumer's `tests/Pest.php`.
  Pest declared under `require-dev` (the package's own suite already uses Pest) and
  `suggest`, never `require`.
- Graceful absence: nothing autoloaded at runtime references Pest; a PHPUnit-only
  consumer, and the package's own non-Pest code, load and run without
  `pestphp/pest`.

**Out of scope** (each needs its own spec before it may be built)

- A `statefulProperty(...)` builder helper to shorten the constructor wiring
  (ROADMAP §5's duplicated-argument-generator and constant-`preCondition`
  findings). That is engine-ergonomics over `StatefulProperty`, a separate concern
  from the assertion, and it is where speculative generality would creep in.
- A negative expectation (`toHaveCounterexample()` / `toFailWith(...)`) for suites
  that assert a bug *is* found. The meta-suite (`tests/Meta/`) already asserts exact
  minimal sequences against `PropertyResult` fields directly (R8) and needs no
  helper; nothing in scope requires the negative form. Recorded as an open question,
  not built on spec.
- Auto-registration as a `Pest\Plugin`. More magic, more surface; the manual
  include is R7-safe and explicit.

## Behavior

- **AC1 — a passing property asserts cleanly**
  - Given a `PropertyResult` with `passed = true`
  - When `expect($result)->toPass()` is evaluated
  - Then the expectation succeeds and returns the expectation for chaining, with no
    output.

- **AC2 — a failing property renders the reproduction artefact automatically**
  - Given a `PropertyResult` that did not pass (a counterexample, a vacuous run, or
    an abandoned/budget-limited shrink)
  - When `expect($result)->toPass()` is evaluated
  - Then the expectation fails and its failure message is exactly
    `$result->counterexampleAsString()` — so `seed`, `initial`, the counterexample
    and any qualification appear without the caller wiring the message by hand.

- **AC3 — a non-result target fails loudly** *(required: error / malformed input)*
  - Given `expect($x)` where `$x` is not a `PropertyResult`
  - When `toPass()` is evaluated
  - Then it throws with a message naming the actual type received, rather than a
    `TypeError` or a confusing failure raised deep inside `counterexampleAsString()`.

- **AC4 — the package works without Pest installed**
  - Given a consumer that requires the package but not `pestphp/pest`
  - When that consumer's code and the package's own autoloaded runtime run
  - Then nothing fails to load: the expectation lives in a file included only from a
    Pest bootstrap, and no autoloaded class in `src/` references Pest's API.

## API sketch

Illustrative only. A plain PHP file — not a PSR-4 autoloaded class, so the runtime
autoload path never touches Pest (AC4). The consumer includes it once:

```php
// vendor/provemark/stateful-check/pest/expectations.php  (name TBD)
// declare(strict_types=1);

use Provemark\StatefulCheck\PropertyResult;

expect()->extend('toPass', function () {
    $result = $this->value;

    if (! $result instanceof PropertyResult) {
        throw new \InvalidArgumentException(sprintf(
            'toPass() expects a %s, got %s.',
            PropertyResult::class,
            get_debug_type($result),
        ));
    }

    // Pest's own assertion carries the rendered artefact as its message (AC2).
    \PHPUnit\Framework\Assert::assertTrue($result->passed, $result->counterexampleAsString());

    return $this;
});
```

```php
// consumer tests/Pest.php
require_once __DIR__.'/../vendor/provemark/stateful-check/pest/expectations.php';

// then, in any test:
expect($property->check())->toPass();
```

## Open questions

- **Negative expectation — open, non-blocker.** Whether `toHaveCounterexample()` /
  `toFailWith(...)` is worth adding for bug-finding suites. Default: no, until a
  concrete suite needs it; the meta-suite asserts on fields directly.
- **Auto-registration — open, non-blocker.** Manual `require_once` vs a
  `Pest\Plugin`. Default to the manual include (explicit, R7-safe).
- **Minimum Pest version — open, non-blocker.** Pin the lowest version actually
  exercised, not the latest by default. (Same discipline as SPEC-004.)

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |