# SPEC-003: Generation core

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | maurice                                           |
| Approved   | — (draft)                                         |
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
determinism guarantee R4, SPEC-001 AC5 and SPEC-002 AC8 depend on, and it is
strictly better than a global `mt_srand()` because two generators cannot
interfere.

What remains to build is smaller than it looks. One integer generator with sound
shrinking, plus combinators. That is the classic QuickCheck observation: good
integer shrinking composes into almost everything else. `elements` is a shrinking
index into an array — so it shrinks toward the first element for free. `bool` is
`choose(0, 1)`. `oneOf` picks a branch and delegates. `map` and `tuple` pass
shrinking through to their parts. `vector` is a length plus elements.

Notably, **no primitive string generator is required**: both dogfood suites build
their strings from `elements` over fixed fragments composed with `map` and
`vector`. Do not build one until something needs it.

Governing rules: R4 (determinism), R7 (no runtime dependencies), and CLAUDE.md §3
(build only what the dogfood suites need).

## Scope

**In scope**

- A seeded source wrapping `Random\Randomizer` + `Random\Engine\Mt19937`,
  supporting fork (clone with independent state) and state capture.
- `GeneratedValue` — a value plus the opaque context its generator needs in order
  to shrink it later. Modelled on fast-check's `Value`.
- A bounded integer generator with shrinking toward an origin.
- Exactly these combinators, because the dogfood suites use exactly these:
  `constant`, `elements`, `bool`, `oneOf`, `map`, `filter`, `tuple`,
  `associative`, `vector`.
- A sequence-length generator, so that shrinking a sequence's length is integer
  shrinking (SPEC-002, second candidate family).
- A command-alphabet generator: uniform choice across the alphabet, no bias.

**Out of scope** (each needs its own spec before it may be built)

- A general-purpose generator library: primitive strings, floats, dates,
  regex-driven values, collections beyond `vector`. Not needed; do not
  anticipate.
- Biased, weighted, size-scaled, targeted or coverage-guided generation.
  fast-check ignores bias for commands too; uniform is enough.
- Shrink trees. A finite, ordered iterable of candidates is sufficient for
  SPEC-002's greedy loop.
- An Eris adapter (SPEC-004, optional and unscheduled).

## Behavior

- **AC1 — the same seed reproduces the same values**
  - Given a seed
  - When the same generator produces a value twice from sources seeded
    identically
  - Then the values are identical, and remain so across separate processes.

- **AC2 — sources fork independently**
  - Given a source that has produced some values
  - When it is forked
  - Then the fork continues from the same state, and consuming from either does
    not affect the other.

- **AC3 — integer shrinking terminates and reaches the origin**
  - Given any generated integer within bounds
  - When it is shrunk repeatedly, always taking the first candidate
  - Then the sequence of candidates strictly approaches the origin, reaches it in
    a number of steps logarithmic in the distance, and terminates.

- **AC4 — a filtered generator re-checks its predicate while shrinking**
  - Given a generator filtered by a predicate
  - When any of its values is shrunk
  - Then every candidate returned satisfies the predicate.
  - *This is the classic footgun: shrinking through a filter without re-checking
    produces values the filter would never have generated, and the resulting
    counterexample is invalid.*

- **AC5 — combinators delegate shrinking to their parts**
  - Given a value from `map`, `tuple`, `associative` or `vector`
  - When it is shrunk
  - Then the candidates are derived by shrinking the underlying parts, and each
    candidate carries a context that permits further shrinking.

- **AC6 — sequence length is generated and shrinkable**
  - Given a maximum length *n*
  - When a sequence is generated
  - Then it contains at most *n* elements, and shrinking the length produces
    shorter sequences independently of the elements' own shrinking.

- **AC7 — an unsatisfiable filter fails loudly** *(required: error path)*
  - Given a filtered generator whose predicate rejects every value produced
  - When generation is attempted
  - Then it throws after a bounded number of attempts, naming the generator and
    the attempt count, rather than looping indefinitely.

## API sketch

Illustrative only.

```php
// namespace Provemark\StatefulCheck\Generation;

/** Seeded, isolated, forkable source of randomness (PHP 8.2 Random extension). */
final class Source
{
    public function __construct(private Randomizer $randomizer) {}

    public static function seeded(int $seed): self
    {
        return new self(new Randomizer(new Mt19937($seed)));
    }

    /** Independent continuation; consuming from either does not affect the other. */
    public function fork(): self;

    public function nextInt(int $min, int $max): int;
}

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
 * integers(int $min, int $max, int $origin = 0): Generator<int>
 * constant(mixed $value):                        Generator<mixed>
 * elements(array $choices):                      Generator<mixed>   // shrinks toward the first
 * bool():                                        Generator<bool>
 * oneOf(Generator ...$generators):               Generator<mixed>
 * map(callable $fn, Generator $g):               Generator<mixed>
 * filter(callable $predicate, Generator $g):     Generator<mixed>   // see AC4, AC7
 * tuple(Generator ...$generators):               Generator<array>
 * associative(array $generatorsByKey):           Generator<array>
 * vector(Generator $length, Generator $element): Generator<array>
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
- **Shrink origin — open, non-blocker.** Shrink integers toward zero, or toward a
  configurable origin? A configurable origin is more useful (shrinking a
  timestamp toward zero is meaningless) and costs little. Recommend configurable
  with zero as the default; confirm before writing `integers()`.
- **Shrink breadth — open, non-blocker.** How many candidates per value? Binary
  reduction plus the origin is the usual answer. More candidates means better
  minima and slower shrinking, and SPEC-002 already has a budget. Measure against
  the meta-suite rather than guessing.
- **Integrated versus external shrinking — open, architectural, worth deciding
  before SPEC-002 is approved.** Now that generation is owned, fast-check's
  integrated model (one object both generates and shrinks a command sequence) has
  become available; SPEC-002's external shrinker was originally chosen because
  Eris was a black box. External remains simpler to test in isolation and the
  SPEC-002 draft is coherent as written. Recommendation: keep external, and
  record it in `docs/prior-art.md` as a deliberate divergence rather than an
  inherited constraint.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
