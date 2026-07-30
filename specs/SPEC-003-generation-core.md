# SPEC-003: Generation core

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-30                               |
| Amended    | maurice, 2026-07-30 — combinator scope narrowed after the dogfood-example audit (`bool`, `oneOf`, `filter`, `tuple`, `vector` removed) |
| Supersedes | — (replaces the earlier draft "Generator port and Eris adapter") |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The package needs three things: random values for command arguments, a length
for each generated sequence, and value-level shrinking with enough context that
a single command's arguments can be reduced later (SPEC-002, third candidate
family).

The earlier draft obtained these from Eris behind a port. That draft carried two
blockers: whether Eris exposes a threadable seed at all, and whether it can
shrink a previously generated value outside its own `forAll` loop. If either
answer is no, the adapter cannot be built and the design collapses late.

Owning generation removes both. **PHP 8.2's Random extension provides the hard
part**: `Random\Randomizer` over a `Random\Engine\Mt19937($seed)` is seeded,
isolated per instance, cloneable and serialisable. That is exactly the
determinism guarantee R4, SPEC-005 AC3 and SPEC-002 AC8 depend on, and it is
strictly better than a global `mt_srand()` because two generators cannot
interfere.

What remains to build is smaller than it looks. One integer generator with sound
shrinking, plus a few combinators. That is the classic QuickCheck observation: good
integer shrinking composes into almost everything else. `elements` is a shrinking
index into an array — so it shrinks toward the first element for free. `map` passes
shrinking through to its part; `associative` shrinks a keyed bundle of generators.

The combinator set was audited against the two dogfood examples once they existed
(2026-07-30) and cut to exactly what they use: `constant`, `elements`, `map`,
`associative`. Removed as unused (§4): `bool`, `oneOf`, `filter`, `tuple`, `vector`.
Two corrections to the earlier assumptions came out of that audit and are worth
recording: the suites do **not** build strings from fragments with `map` and
`vector` — they draw whole names with `elements`, so `vector` never appears; and
`oneOf` as used was always `oneOf(elements(...), ...)`, which flattens to a single
`elements`. No primitive string generator is required either. Do not build any of
these back until a real suite needs one.

Governing rules: R4 (determinism), R7 (no runtime dependencies), and CLAUDE.md §4
(build only what the dogfood suites need).

## Scope

**In scope**

- A seeded source wrapping `Random\Randomizer` + `Random\Engine\Mt19937`.
- `GeneratedValue` — a value plus the opaque context its generator needs in order
  to shrink it later. Modelled on fast-check's `Value`.
- A bounded integer generator with shrinking toward an origin.
- Exactly these combinators, because the two dogfood suites use exactly these:
  `constant`, `elements`, `map`, `associative`. (Audited 2026-07-30;
  `bool`, `oneOf`, `filter`, `tuple`, `vector` were removed as unused, §4.)
- A sequence-length generator, so that shrinking a sequence's length is integer
  shrinking (SPEC-002, second candidate family).
- A command-alphabet generator: uniform choice across the alphabet, no bias.
  **Built after SPEC-001**, since it produces `Command` instances and that type
  does not exist until then. See `ROADMAP.md`.

**Out of scope** (each needs its own spec before it may be built)

- A general-purpose generator library: primitive strings, floats, dates,
  regex-driven values, collections. Not needed; do not anticipate.
- The combinators removed by the 2026-07-30 audit — `bool`, `oneOf`, `filter`,
  `tuple`, `vector`. Neither dogfood suite uses them (§4). `filter` in particular
  carried the re-check-while-shrinking footgun and two of its own acceptance
  criteria; it returns only when a real suite needs it, in its own amendment.
- Biased, weighted, size-scaled, targeted or coverage-guided generation.
  fast-check ignores bias for commands too; uniform is enough.
- Shrink trees. A finite, ordered iterable of candidates is sufficient for
  SPEC-002's greedy loop.
- An Eris adapter (SPEC-004, optional and unscheduled).
- Forkable or branching sources. Removed from scope: nothing in the package needs
  an independent sub-stream, and keeping it would oblige us to verify
  `Randomizer` clone semantics for no gain.

## Behavior

- **AC1 — the same seed reproduces the same values**
  - Given a seed
  - When the same generator produces a value twice from sources seeded
    identically
  - Then the values are identical, and remain so across separate processes.
  - **The engine mode is passed explicitly** as `MT_RAND_MT19937`, never left to
    the default. Verified 2026-07-29: `MT_RAND_PHP` produces a different stream
    for the same seed, so relying on the default would let a future change to it
    invalidate every recorded seed silently.
  - *Verified on PHP 8.3.6 / Linux / 64-bit — see `docs/verification/mt19937.php`
    and `docs/prior-art.md`. End-to-end reproducibility is SPEC-005 AC3; this is
    the primitive it rests on.*

- **AC2 — integer shrinking terminates and reaches the origin**
  - Given any generated integer within bounds
  - When it is shrunk repeatedly, always taking the first candidate
  - Then the sequence of candidates strictly approaches the origin, reaches it in
    a number of steps logarithmic in the distance, and terminates.

- **AC3 — combinators delegate shrinking to their parts**
  - Given a value from `map` or `associative`
  - When it is shrunk
  - Then the candidates are derived by shrinking the underlying parts, and each
    candidate carries a context that permits further shrinking.

- **AC4 — sequence length is generated and shrinkable**
  - Given a maximum length *n*
  - When a sequence is generated
  - Then it contains at most *n* elements, and shrinking the length produces
    shorter sequences independently of the elements' own shrinking.

## API sketch

Illustrative only.

```php
// namespace Provemark\StatefulCheck\Generation;

/** Seeded, isolated, sequential source of randomness (PHP 8.2 Random extension). */
final class Source
{
    public function __construct(private Randomizer $randomizer) {}

    public static function seeded(int $seed): self
    {
        // Mode pinned explicitly (AC1): the default is not part of our contract.
        return new self(new Randomizer(new Mt19937($seed, MT_RAND_MT19937)));
    }

    public function nextInt(int $min, int $max): int;
}

// No fork(). Generation is strictly sequential from a single source, and
// shrinking reuses captured contexts rather than regenerating, so nothing needs
// an independent sub-stream (D010). Verified 2026-07-29: Randomizer cannot be
// cloned at all — it throws. Had fork() stayed, it would have had to be built on
// the engine, which IS cloneable.

/**
 * A generated value plus whatever its generator needs to shrink it later.
 * Opaque to everything except the generator that produced it.
 *
 * @template T
 */
final readonly class GeneratedValue
{
    /** @param T $value */
    public function __construct(public mixed $value, public mixed $context = null) {}
}

/** @template T */
interface Generator
{
    /** @return GeneratedValue<T> */
    public function generate(Source $source): GeneratedValue;

    /**
     * Smaller alternatives, closest-to-origin first, finite.
     *
     * @param  GeneratedValue<T>  $value
     * @return iterable<GeneratedValue<T>>
     */
    public function shrink(GeneratedValue $value): iterable;
}

/**
 * The whole library, as far as generation goes. Everything else composes.
 *
 * Exposed as static methods on a `Gen` facade (`Gen::elements(...)`), which is
 * what the dogfood examples and SPEC-005 use. Free functions were the
 * alternative; a facade keeps the names out of the global namespace and reads
 * better at a call site that already imports little else.
 *
 * integers(int $min, int $max, int $origin = 0): Generator<int>   // the foundation; not user-facing here
 * constant(mixed $value):                        Generator<mixed>
 * elements(array $choices):                      Generator<mixed>   // shrinks toward the first
 * map(callable $fn, Generator $g):               Generator<mixed>
 * associative(array $generatorsByKey):           Generator<array>
 */
```

## Open questions

- ~~**Flat enumeration versus shrink tree.**~~ **Resolved: flat, ordered,
  finite iterable, with a context per value.** SPEC-002's greedy loop never needs
  to descend into a branch.
- ~~**Seed threading.**~~ **Resolved:** owned via the Random extension. The
  blocker is gone.
- ~~**Can the backend shrink a previously generated value?**~~ **Resolved:** we
  define the interface, so yes by construction. Blocker gone.
- ~~**Shrink origin — toward zero or configurable?**~~ **Resolved (D004):**
  configurable origin, default 0. Shrinking a timestamp toward zero is
  meaningless, and configurability costs little.
- ~~**Shrink breadth — how many candidates per value?**~~ **Resolved (D005):**
  minimal — binary reduction toward the origin plus the origin itself, nothing
  more. Measuring against the meta-suite is cheaper than guessing a wider strategy
  up front.
- ~~**Integrated versus external shrinking.**~~ **Resolved (D003):** external.
  Simpler to test in isolation, SPEC-002 is coherent as written, and the advantage
  of integrated shrinking weighs lightly because our sequence structure dominates,
  not the values. Recorded in `docs/prior-art.md` as a deliberate divergence from
  fast-check.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | `tests/Unit/Generation/SourceTest.php` (group `SPEC-003`) | `src/Generation/Source.php` :: `Source` |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
