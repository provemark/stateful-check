# SPEC-004: Optional Eris adapter

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft — **not scheduled for v0.1**                |
| Author     | maurice                                           |
| Approved   | — (draft)                                         |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

> **Do not build this before v0.1 ships.** It exists so the possibility is
> recorded and the decision is not silently reversed. The package must be fully
> usable, and fully tested, without it.

## Problem

Some projects already have Eris generators written. If the `Generator` interface
from SPEC-003 can be satisfied by wrapping an Eris generator, those projects can
reuse what they have rather than rewriting their alphabets.

That is a convenience, not a requirement, and it must never become one. The
package owns generation (SPEC-003) precisely so that it has no runtime
dependencies and cannot be stranded by an upstream that stops moving — Eris'
last releases are old, and the one comparable PHP effort (`steos/php-quickcheck`)
was abandoned in 2022 with two dependents.

## Scope

**In scope**

- An `ErisGenerator` implementing `Provemark\StatefulCheck\Generation\Generator`
  by delegating to an Eris generator.
- Eris declared under `suggest` in `composer.json`, never `require`.
- Graceful absence: if Eris is not installed, nothing in the package fails to
  load and no test errors — the adapter's tests skip.

**Out of scope** (each needs its own spec before it may be built)

- Any use of the adapter inside the package's own suites, examples or docs
  beyond the adapter's own tests. The dogfood suites use the native core.
- Adapters for other property-testing libraries. If a second one is ever wanted,
  the pattern is established but the decision is separate.

## Behavior

- **AC1 — an Eris generator satisfies the port**
  - Given an Eris generator
  - When it is wrapped and used to generate a value
  - Then a `GeneratedValue` is produced that the runner and shrinker accept.

- **AC2 — the package works without Eris installed**
  - Given a project that does not require Eris
  - When the full suite runs
  - Then everything passes and the adapter's tests skip rather than error.

- **AC3 — an incompatible Eris fails loudly at construction** *(required: error
  path)*
  - Given an Eris version whose surface does not match what the adapter expects
  - When the adapter is constructed
  - Then it throws, naming the missing symbol and the installed Eris version,
    rather than failing later inside a shrink loop.

## Open questions

- **Can Eris shrink a previously generated value at all? — open, and this spec's
  existence depends on it.** If Eris' shrinking is only reachable from inside its
  own `forAll` loop and not as an operation on a value, the adapter can implement
  `generate()` but not `shrink()`. A half-adapter that silently returns no shrink
  candidates is worse than none: it would produce unshrunk counterexamples with
  no indication why. If that is the answer, close this spec as `superseded` with
  the finding recorded, rather than shipping a degraded adapter.
- **Minimum Eris version — open, non-blocker.** Pin the lowest version actually
  exercised, not the latest by default.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
