# SPEC-007: Optional typed pass-assertion helper

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | maurice                                           |
| Approved   | maurice, 2026-08-02 (original + amendment)        |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

> **Convenience only.** The package is already fully usable from Pest without
> this (the two `examples/` suites prove it): `check()` returns a `PropertyResult`
> and the caller asserts on it directly. This spec removes one recurring footgun,
> nothing more. If it does not clearly earn its place, close it `superseded`.

## Amendment history

- **2026-08-02 — mechanism changed from a Pest `expect()->toPass()` expectation to
  a framework-agnostic typed helper.** The original sketch registered a custom Pest
  expectation via `expect()->extend('toPass', …)`. Implementing AC1 proved it cannot
  pass the project's own gates: Pest v3's shipped PHPStan support (`extension.neon`)
  only marks `Pest\Expectation` a universal-object-crate — it registers no custom
  method and does not bind `$this` inside the extend closure, so `expect($r)->toPass()`
  fails PHPStan max with `method.notFound`, and the closure body fails with
  `Undefined variable: $this`. CLAUDE.md §6 bans every escape (`@phpstan-ignore`,
  baseline, `assert()`/inline `@var`). A typed free function sidesteps all of it,
  removes the same footgun, and needs no Pest at all — so it is also R7-cleaner. This
  is the pre-approval R11 check (is the AC fulfillable?) arriving late: the original
  AC1 had a channel in principle but not one that clears this repo's PHPStan rule.

## Problem

Asserting a passing property in Pest today reads:

```php
expect($result->passed)->toBeTrue($result->counterexampleAsString());
```

The assertion carries a footgun that defeats the package's own reproducibility
guarantee. `counterexampleAsString()` is the reproduction artefact — it renders
`seed=…`, the `initial`, the counterexample commands and any "not a confirmed
minimum" qualification (SPEC-005). It is passed as the *optional* failure message.
Omit it — the obvious thing to do, since `expect($x)->toBeTrue()` reads complete —
and a red test prints no seed, so the failure cannot be reproduced. The one piece
of output that makes a stateful failure actionable is exactly the piece the
ergonomics invite you to drop.

A single typed helper, `assertPropertyPassed($result)`, binds the two together: it
cannot assert the pass without also rendering the reproduction artefact on failure.
That is the whole of this spec.

This is test-time sugar over `PropertyResult`, not an engine abstraction, so
CLAUDE.md §4's "must be needed by the two dogfood suites" gate does not apply the
same way — but its spirit does: the surface is kept to the single helper the
footgun justifies, and richer helpers are pushed to open questions.

## Scope

**In scope**

- A typed helper `assertPropertyPassed(PropertyResult $result): void` that asserts
  the result passed and, on failure, raises a PHPUnit assertion failure whose
  message is `counterexampleAsString()`.
- Framework-agnostic: it uses `PHPUnit\Framework\Assert` only — no Pest — so it
  works identically in a Pest test and a plain PHPUnit `TestCase`.
- Shipped in a file **outside `src/`** and registered by one `require_once` from the
  consumer's test bootstrap (`tests/Pest.php` or `bootstrap.php`). Kept out of the
  PSR-4 autoload path so the package's autoloaded runtime never references a
  test-only class (R7 — `require` stays PHP-only; PHPUnit stays `require-dev`).

**Out of scope** (each needs its own spec before it may be built)

- A fluent `expect($result)->toPass()` Pest expectation. Rejected by the 2026-08-02
  amendment: Pest v3 cannot type a custom expectation under PHPStan max without a
  brittle class-replacing stub, and the project bans suppression. The typed function
  is the honest fit for the toolchain.
- A `statefulProperty(...)` builder helper to shorten the constructor wiring
  (ROADMAP §5's duplicated-argument-generator and constant-`preCondition` findings).
  That is engine-ergonomics over `StatefulProperty`, a separate concern.
- A negative helper (`assertPropertyFailed(...)`) for suites that assert a bug *is*
  found. The meta-suite (`tests/Meta/`) already asserts exact minimal sequences
  against `PropertyResult` fields directly (R8) and needs no helper; nothing in scope
  requires the negative form. Recorded as an open question, not built on spec.

## Behavior

- **AC1 — a passing property asserts cleanly**
  - Given a `PropertyResult` with `passed = true`
  - When `assertPropertyPassed($result)` is called
  - Then it returns without raising and records a passing assertion.

- **AC2 — a failing property fails the test with the reproduction artefact**
  *(required: error / failure path)*
  - Given a `PropertyResult` that did not pass (a counterexample, a vacuous run, or
    an abandoned/budget-limited shrink)
  - When `assertPropertyPassed($result)` is called
  - Then it raises a PHPUnit assertion failure whose message is exactly
    `$result->counterexampleAsString()` — so `seed`, `initial`, the counterexample
    and any qualification appear without the caller wiring the message by hand.

- **AC3 — the helper needs no Pest, and the runtime needs no PHPUnit**
  - Given a consumer that does not require `pestphp/pest`
  - When that consumer calls the helper from a plain PHPUnit test, and separately
    when the package's autoloaded runtime loads
  - Then nothing fails: the helper references no Pest symbol, and it lives in a file
    outside the PSR-4 autoload path, so loading the package never pulls in PHPUnit.

> **Wrong-type safety is now the type system's job, not a runtime guard.** The
> original AC3 was "an incompatible target fails loudly." With a typed parameter,
> passing a non-`PropertyResult` is a PHPStan error statically and a `TypeError` at
> runtime — louder and earlier than any hand-written check, and it needs no code. The
> error-path criterion is therefore AC2: the helper must *fail the test* when the
> property failed, never swallow it.

## API sketch

Illustrative only. A namespaced free function in a shipped file that is **not**
PSR-4 autoloaded, so the runtime autoload path never references PHPUnit (R7 / AC3).

```php
// testing/assertions.php  (path/name TBD)
// declare(strict_types=1);
namespace Provemark\StatefulCheck\Testing;

use PHPUnit\Framework\Assert;
use Provemark\StatefulCheck\PropertyResult;

function assertPropertyPassed(PropertyResult $result): void
{
    // The pass assertion and the rendered reproduction artefact are one call:
    // the seed cannot be dropped on failure (the footgun this spec removes).
    Assert::assertTrue($result->passed, $result->counterexampleAsString());
}
```

```php
// consumer tests/Pest.php  (or any PHPUnit bootstrap)
require_once __DIR__.'/../vendor/provemark/stateful-check/testing/assertions.php';

use function Provemark\StatefulCheck\Testing\assertPropertyPassed;

// then, in any test:
assertPropertyPassed($property->check());
```

## Open questions

- **Negative helper — open, non-blocker.** Whether `assertPropertyFailed(...)` is
  worth adding for bug-finding suites. Default: no, until a concrete suite needs it;
  the meta-suite asserts on fields directly.
- **Function vs. static method — open, non-blocker.** A free function
  (`assertPropertyPassed`) or a static method (`PropertyAssertions::assertPassed`).
  Default to the free function: fewer ceremonies, and `use function` reads cleanly.
- **File path / analysis — open, non-blocker.** Where the shipped file lives and
  whether it is added to PHPStan `paths` (it type-checks cleanly there, being an
  ordinary typed function — unlike the abandoned expectation). Default: add it to
  `paths` so the call sites resolve, and require it from `tests/Pest.php`.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 — passing asserts cleanly | `tests/Unit/Testing/AssertPropertyPassedTest.php` :: "asserts cleanly on a passing property result" (`SPEC-007`) | `testing/assertions.php` :: `assertPropertyPassed()` (pass path) |
| AC2 — failing renders the artefact | `tests/Unit/Testing/AssertPropertyFailedRendersTest.php` :: "fails the test with counterexampleAsString() as the message" (`SPEC-007`) | `testing/assertions.php` :: `assertPropertyPassed()` (`Assert::fail` branch) |
| AC3 — runtime needs no framework | `tests/Unit/Testing/RuntimeIsFrameworkFreeTest.php` :: "imports no PHPUnit or Pest symbol anywhere under src/" (`SPEC-007`, `arch`) | `testing/assertions.php` living outside the `src/` PSR-4 root; `composer.json` autoload (`Provemark\StatefulCheck\` → `src/` only) |