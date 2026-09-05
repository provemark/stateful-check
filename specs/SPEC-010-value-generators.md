# SPEC-010: Value generators — strings, floats, lists, and optional record keys

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | maurice                                           |
| Approved   | maurice, 2026-08-27                               |
| Supersedes | — but it amends SPEC-003's out-of-scope list: `oneOf` returns to the user-facing surface, on the terms that list itself sets ("it returns when a real suite needs one, in its own amendment"). `bool`, `filter`, `tuple` and `vector` stay out. |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

D024 refused a string generator and named the condition under which it would
return:

> Revisit if: a concrete case appears with a **free text field as a command
> argument** — a parser command, a name/input field, or the separate
> AI-generated-code verification package that will build on this engine. Then
> add `strings()` **with** its origin-ward `shrink()`, via a spec, with that
> case as the named consumer.

That condition has now occurred. **The named consumer is
`provemark/stateful-check-mcp`** — the MCP adapter that will build on this
engine — and specifically its SPEC-004, which derives generators from a tool's
JSON Schema. An MCP server advertises each tool's arguments as a schema:
`{"type":"string","minLength":3}`, `{"type":"array","items":…,"minItems":1}`,
an object with required and optional properties, a number with bounds. Every
one of those is a command argument.

The gap is smaller and sharper than "a general-purpose generator library",
which §4 rightly refuses. Reading the current set against the consumer's needs,
it is **one missing idea plus two small ones**:

- **Variable arity.** Everything the engine generates today has a shape fixed
  at construction: a record with known keys, a choice among known values, a
  bounded integer. A string is a sequence of *n* characters, a list a sequence
  of *n* items, and *n* is drawn. Nothing in the engine draws a size. Strings,
  lists and optional record keys are all this one idea, which is why they
  belong in one spec rather than three. Prior art agrees that they are one
  mechanism: fast-check's `fc.string` is an array of characters, and
  Hypothesis's `TextStrategy` extends `ListStrategy[str]` outright.
- **Floats** — real work, but small and modelled directly on `integers`.
- **`oneOf()`** — already implemented, as the *inside* of `AlphabetGenerator`,
  whose `@template` parameters are the only thing tying it to `Command`. The
  2026-07-30 audit removed it from the user-facing surface, not from the code.

**D024's second half is the binding constraint on all of it.** A generator
without its origin-ward shrink is worse than no generator: it drops an
un-shrinkable value into a counterexample and silently degrades the guarantee
SPEC-006 exists to give. So every generator here is specified *with* its
shrink, and no acceptance criterion may be split into "generate now, shrink
later". For the sequence generators the shrink has a specific shape that both
mature implementations agree on and that this spec commits to: **remove
elements before simplifying them**, never below the declared minimum.

Governing rules: R7 (owned generation, no runtime dependencies — these are
built on `Source` and `integers`, nothing else), R3 (documented local minimum:
`associative`'s one-component-at-a-time reduction is inherited here, and the
new deletion family has a limit of its own that stays stated rather than
fixed — see AC6), R4 (determinism), R8 (a shrinking behaviour is not proven by
"it does not crash" — the argument family gets a planted-bug meta-test), R10
(the heterogeneous-generic gate, run in Open question 9), R11 (each AC checked
fulfillable at approval — AC2's note is one such check), §4 (this set earns
its place against a named consumer's acceptance criteria, not against a feature
checklist — see the necessity audit below).

Prior art: `docs/prior-art.md` §"Value generation — how lists and strings are
drawn and shrunk", added 2026-08-19 by reading fast-check's `ArrayArbitrary`
and Hypothesis's shrinker and collection strategies. That section was the §8
pre-approval task for this spec; what it changed is recorded in Open question
10.

## Necessity audit (§4)

§4 says an abstraction goes in only if it is needed to express a real
consumer's requirements, and D024's note records that `bool`, `oneOf`,
`filter`, `tuple` and `vector` were **removed** by the 2026-07-30 audit rather
than never built. Re-adding any of them therefore needs more than "the consumer
generates that type". This table is the audit, run per candidate against the
consumer's acceptance criteria (`stateful-check-mcp` SPEC-004), and it cut one
of the seven candidates and changed the argument for two more.

| Candidate | Criterion that demands it (all `stateful-check-mcp` SPEC-004) | Can the current set express it? | Verdict |
|-----------|-----------------------------------|----------------------------------|---------|
| `strings()` | AC5 (`minLength`/`maxLength`), AC13 (`default` as the shrink origin) | No. `elements` covers a fixed set of *whole* strings; nothing draws a length or a character. | **in** — but see D032: as first drafted this row cited AC13 for a combinator with no origin parameter, and the audit did not catch it because it only asked what the *existing* set could express |
| `listsOf()` | AC9 (`minItems`/`maxItems` over an item schema), AC11 (arrays of objects) | No. The closest composition — `oneOf` over one `associative` per length — generates 1..*n* items but the branch choice is never shrunk, so the length could never reduce, which is exactly what AC9 requires. | **in** |
| optional keys on `associative()` | AC2 (an optional property present in some runs, absent in others), AC16 (a recursive optional `child`) | Only in a form that breaks the contract. `map` over `associative(['keep' => elements([false, true]), …])`, stripping the key when the flag is false, *does* generate both shapes and shrinks toward absence (flag first, `elements` shrinks to index 0). But once the flag has reached `false`, every further shrink of that key's value maps back to the same record — candidates equal to the value they came from, the one thing the `Generator` contract forbids, and the invariant the greedy loop's termination rests on. | **in** |
| `oneOf()` | AC12 (`{"type":["string","null"]}`, and `{}` with no type), AC14 (`anyOf`) | The mechanism exists inside `AlphabetGenerator`, but its signature is `Generator<Command<TModel, TSut, mixed>>`; a heterogeneous list of plain-value generators does not type-check at PHPStan max, and the consumer's layering has no `Command` at all. | **in**, as an extraction of existing code |
| `floats()` | AC6, second half (`{"type":"number"}` with `minimum`/`maximum`) | Partly, and the substitute is real: `Gen::map(fn (int $i) => (float) ($i / $scale), Gen::integers(…))` satisfies AC6's own example, stays in range and shrinks toward the origin. See below. | **in**, judgement call — D033 |
| `subsetOf()` | AC10 (`uniqueItems`) | No. Deduplicating a generated list changes its length, possibly below `minItems`; retrying until distinct makes generation probabilistic. The prior art confirms there is no free lunch here (below). | **in**, judgement call — D034 |
| `booleans()` | AC7 (`{"type":"boolean"}`) | **Yes, completely.** `Gen::elements([false, true])` yields both values and shrinks `true → false`: `ElementsGenerator` shrinks toward index 0, and its deduplication is strict (`in_array($choice, $unique, true)`), so `false` and `true` stay two choices. | **cut** |

**`booleans()` is cut.** It would be a name for a call that already works, and
the 2026-07-30 audit removed `bool` on precisely that reasoning. The consumer
derives `{"type":"boolean"}` as `Gen::elements([false, true])` and
`{"type":"null"}` as `Gen::constant(null)`, neither of which needs anything
from this spec. If a later spec wants a *named* boolean origin — a promise that
`false` is the interesting-value baseline independent of how `elements` happens
to shrink — that is when to add it, and it will still be three lines.

**Why `floats()` survives the same test.** The scaled-integer substitute is
honest (its values satisfy the schema) but it moves a generation policy into
the consumer: someone has to pick the scale, and that choice silently decides
which values a server never sees. For wide bounds the scaled range overflows a
PHP int and hits D016's guard, so the consumer would also need its own clamping
story on top of the one it already has. "How a double in a range is drawn and
how it shrinks" is generation theory, which is this repository's subject; the
alternative is the same code written once here or once per consumer, with a
free parameter nobody owns. D033 records the cut version and its exact
consequence, so the decision stays available.

**Why `subsetOf()` survives it.** The prior art (2026-08-19) shows both mature
implementations giving up something we cannot give up: fast-check's
`uniqueArray` deduplicates shrink candidates with a `preFilter` that "only
drops items", leaving a candidate shorter than the length the shrinker asked
for and re-checking no minimum; Hypothesis rejects the whole test case
("Aborted test because unable to satisfy …") when the filtered draw cannot
produce a fresh element. For our consumer, a list below `minItems` is an invalid
value presented as valid — the one failure mode SPEC-004 exists to prevent —
and a rejected draw is nondeterminism in a seeded run. A bounded subset of a
*fixed choice set* has neither problem, and its limit is honest and small: it
applies only where the item schema is a finite, enumerable set.

## Scope

**In scope** — each with its shrink, none deferrable:

- **`floats(min, max, origin)`** — bounded, finite, shrinking toward an origin
  exactly as `integers` does, including D015's clamp-implicit / throw-explicit
  rule and D016's overflow guard in its float form.
- **`strings(minLength, maxLength, alphabet, origin)`** — a sequence of
  characters drawn from a supplied alphabet. The alphabet is a **required**
  argument, not a defaulted one (D035). Lengths are counted in
  **characters (code points), not bytes** (D031). `origin` is optional and
  names the string the shrink moves toward, defaulting to `minLength`
  repetitions of the alphabet's first character (D032).
- **`listsOf(item, min, max)`** — a bounded sequence of values from an item
  generator.
- **Optional keys on `associative()`** — a second parameter of generators whose
  keys may be absent, with absence as the shrink target (D037
  explains why this is a parameter rather than a new `record()` combinator).
- **`subsetOf(choices, min, max)`** — a bounded list of *distinct* members of a
  fixed choice set. It makes distinctness expressible where the choices are
  finite and enumerable; it is not a general `uniqueItems` solution and this
  spec does not claim to be one.
- **`oneOf(branches)`** — uniform choice among generators of any type, with the
  chosen branch recorded in the context. `alphabet()` keeps its `Command`-typed
  signature and becomes a thin delegation to it; its behaviour, including the
  documented "the branch choice is never shrunk" gap (SPEC-003 AC5), is
  unchanged and its existing tests must pass **unmodified**.

**Out of scope** (each needs its own spec before it may be built)

- **`booleans()`** — `Gen::elements([false, true])` already is it, values and
  origin-ward shrink included. See the necessity audit; the 2026-07-30 removal
  of `bool` stands.
- **Regex-driven strings.** Generating from a pattern is a subsystem (parse,
  then generate from the automaton), and the named consumer has already decided
  to refuse `pattern` rather than approximate it.
- **Semantic string formats** — dates, e-mail addresses, URIs, UUIDs. The
  consumer builds those from `elements` and `map` over this spec's primitives;
  they carry domain knowledge the engine has no business holding.
- **A general `uniqueItems`/set combinator** over an arbitrary item generator.
  `subsetOf` covers finite choice sets only; anything else needs either
  filtering (which breaks the minimum) or rejection (which breaks determinism),
  and choosing between those is its own spec.
- **Weighted, size-scaled or targeted distributions.** Uniform is enough, as it
  is for commands. Edge-biasing (SPEC-009) is deliberately not extended to
  these generators here — D039.
- **Dictionaries with *generated* keys**, generated key *names*, or recursive
  self-referential generators. The consumer bounds its own recursion.
- **`filter` and `tuple`.** Still audited out (SPEC-003 scope). `filter` in
  particular keeps its re-check-while-shrinking footgun; nothing here needs it.
- **Adaptive interval deletion** — Hypothesis-style deletion of arbitrary
  contiguous runs of elements. Our deletion family removes from one end only
  (AC6); the stronger family is a possible later improvement, not a silent
  omission.
- **Shrink trees.** A finite ordered iterable of candidates remains sufficient.
- **Byte-oriented or binary strings.** Characters, not bytes (D031). A string
  that is not valid UTF-8 is not a value this spec generates, and not one it
  accepts as an alphabet entry or an origin (AC10).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will
be covered by a Pest test tagged `->group('SPEC-010')`.

Two invariants from the `Generator` contract apply to every criterion below and
are asserted collectively in AC11, not repeated in each: a shrink sequence is
finite, and it never yields the value it was given.

- **AC1 — `oneOf()` records its branch, and `alphabet()` is unchanged**
  - Given a list of generators of *different* value types
  - When `Gen::oneOf()` generates a value
  - Then the branch is selected by the source (the same seed selects the same
    branch), the context records which branch was chosen plus that branch's own
    context, shrinking delegates to that branch and never replaces it with
    another.
  - And `Gen::alphabet()` produces the same values and the same shrink sequence
    for the same seed as before this spec, proven by SPEC-003's AC5 tests
    passing **without modification**.

- **AC2 — `floats()` stays in range and shrinks toward its origin**
  - Given `Gen::floats(-1.5, 1.5)` and `Gen::floats(2.0, 8.0, origin: 2.0)`
  - When each is run across a fixed set of seeds and every value is shrunk,
    always taking the first candidate
  - Then every generated value lies within the bounds and is finite (never
    `NAN`, never `INF`); the first shrink candidate is the origin itself; each
    following candidate lies strictly between its predecessor and the generated
    value, so every candidate stays strictly nearer the origin than the value it
    was shrunk from while its distance to the origin grows monotonically; and
    the sequence terminates in a bounded number of steps.
  - *The origin-first candidate is what makes termination trivial and mirrors
    `integers`. Halving alone cannot reach an arbitrary float in finite steps —
    stating it as "reaches the origin exactly" would be an AC nothing can
    fulfil (R11).*
  - *The ordering after the origin is the greedy loop's retry-less-aggressively
    order, which is what `integers()` already produces: `integers(0, 9)` shrinks
    8 to `[0, 4, 6, 7]`, receding from the origin rather than approaching it.
    Once the first candidate is the origin there is nothing left to approach, so
    the earlier wording ("the following candidates approach the origin
    monotonically") was unfulfillable after its own first clause — the R11-(a)
    failure AC4 had, in the AC that mirrors `integers` by name (D038).*
  - *The bound on the step count is a requirement here, not an implementation
    detail: unlike `intdiv`, halving a float reaches zero only through the
    denormals, after roughly a thousand steps, and rounding can make the next
    candidate equal the value it came from — which the `Generator` contract
    forbids (AC11). `floats()` therefore stops at a fixed step count and skips a
    candidate that is not strictly between its predecessor and the value.*

- **AC3 — `strings()` respects its length bounds and its alphabet**
  - Given `Gen::strings(3, 6, ['a', 'b', 'c'])` and
    `Gen::strings(0, 4, ['a', 'é', '😀'])`
  - When each is run across a fixed set of seeds
  - Then every value consists only of characters from the alphabet it was
    given, its length **in characters** lies within the bounds, and both bounds
    occur at least once across the seeds. The multi-byte and astral characters
    are in the criterion deliberately: a length counted with `strlen` passes
    the first case and fails the second.

- **AC4 — string shrinking shortens before it simplifies**
  - Given a generated string of length *n* > `minLength` over an alphabet whose
    first character is `'a'`, and no explicit origin — so the origin is
    `minLength` repetitions of `'a'`
  - When it is shrunk
  - Then shorter strings are offered before same-length simplifications, no
    candidate is shorter than the origin's length, character simplification
    moves each position toward the corresponding character of the origin, and
    following the first candidate repeatedly terminates at the origin itself: a
    string of length `minLength` consisting of `'a'`.
  - And given a generated string **at least as long as the origin**, over an
    alphabet that also contains `'d'`, `'m'`, `'i'` and `'n'`, constructed with
    an explicit `origin: 'admin'`, following the first candidate repeatedly
    terminates at `'admin'` instead — the shortening-first ordering unchanged,
    and the deletion family bounded below by the origin's length (five) rather
    than by `minLength`.
  - *A string shorter than the origin can be drawn whenever the origin is longer
    than `minLength`, which D032 permits: the consumer aims at a schema's
    `default`, whose length is not tied to `minLength`. Such a value terminates
    at the origin's prefix of its own length — `'bcab'` at `minLength` 3 shrinks
    to `'admi'`, not to `'admin'` — because shrinking never grows a value, and a
    candidate longer than the value it came from would not be a reduction at
    all. The Given above is therefore a length condition and not a formality;
    without it the criterion would promise a terminus no implementation can
    reach.*
  - *The lower bound on deletion is `max(minLength, origin length)`, and the two
    clauses above are one rule stated twice: with the default origin the two are
    equal, which is why the first reads as `minLength` and as the alphabet's
    first character. They had to be separated because an explicit origin longer
    than `minLength` is unreachable for a family that keeps deleting down to
    `minLength`, and because simplification must aim at the origin's characters
    rather than at `alphabet[0]` — D040's rule that an explicit origin is drawn
    from the alphabet is precisely what makes aiming there reachable. The second
    case's alphabet must therefore contain the origin's characters, or
    construction throws (AC10).*
  - *This is the criterion the necessity audit cited when it
    admitted `strings()`: a consumer deriving from JSON Schema aims the shrink
    at the schema's `default`, because the server author's own statement of
    the ordinary case is what a minimal reproducer wants to show
    (`stateful-check-mcp` SPEC-004 AC13). Without the parameter that citation
    was unsupported — see D032.*
  - *Shortening first is the whole value of the shrink for a report: a reader
    learns far more from "it fails at any 4-character name" than from a
    64-character string with simpler letters. Both fast-check and Hypothesis
    order it this way (prior art, 2026-08-19).*

- **AC5 — `listsOf()` respects its length bounds and its item generator**
  - Given `Gen::listsOf(Gen::integers(0, 100), 1, 4)`
  - When it is run across a fixed set of seeds
  - Then every value is a `list` of 1–4 integers within the item bounds, and
    both length bounds occur at least once.

- **AC6 — list shrinking removes elements before shrinking them**
  - Given a generated list longer than its minimum
  - When it is shrunk
  - Then candidates with fewer elements come before candidates that shrink an
    element, every removal takes elements from the end (D036), no
    candidate is shorter than the declared minimum, element shrinking delegates
    to the item generator and carries a context that permits further shrinking,
    and following the first candidate repeatedly terminates at a list of `min`
    elements each at the item generator's origin.
  - *The stated limit (R3): removing from one end only is a weaker deletion
    family than deleting an arbitrary interior run. A bug that needs the first
    and last element but not the middle one reduces to a local minimum that
    still contains the middle. Documented, not fixed.*

- **AC7 — optional keys are sometimes present and sometimes absent**
  - Given `Gen::associative(['a' => …], optional: ['b' => …])`
  - When it is run across a fixed set of seeds
  - Then `a` is present in every value; `b` is present in at least one and
    absent from at least one; and key order in the produced array is
    deterministic and identical for a given seed across processes (R4).

- **AC8 — a record shrinks an optional key to absent, and never drops a
  required one**
  - Given a value with both keys present
  - When it is shrunk
  - Then a candidate omitting `b` is offered before candidates that shrink
    `b`'s value; once `b` is absent no further candidate re-adds it or repeats
    the same record; no candidate omits `a`; and the remaining reduction is
    component-by-component in declaration order, inheriting `associative`'s
    documented local minimum (R3) — coupled keys are still not reduced
    together.
  - *The "no repeats once absent" clause is what the composed substitute in the
    necessity audit cannot promise, and it is why this is a parameter on the
    generator rather than an idiom in the consumer.*

- **AC9 — `subsetOf()` never repeats a choice**
  - Given `Gen::subsetOf(['a', 'b', 'c'], 1, 3)`
  - When it is run across a fixed set of seeds
  - Then every value is a list of distinct members of the choice set, sizes
    1–3 occur, and shrinking removes elements before moving the remaining ones
    toward earlier choices, never falling below the minimum size and never
    producing a duplicate at any point in the shrink sequence.

- **AC10 — invalid construction arguments throw** *(required: error path)*
  - Given each of: `strings(-1, 4, ['a'])`; `strings(5, 2, ['a'])`;
    `strings(1, 2, [])`; an alphabet entry that is not a single character
    (`['ab']`, and `['']`); `listsOf($g, -1, 3)`; `listsOf($g, 5, 2)`;
    `floats(1.0, 0.0)`; `floats(NAN, 1.0)`; `floats(0.0, 1.0, origin: 5.0)`;
    `subsetOf(['a'], 2, 3)` (a minimum larger than the choice set);
    `subsetOf([], 0, 1)`; and
    `associative(['a' => $g], optional: ['a' => $g])` (the same key required and
    optional)
  - And given each invalid `strings()` origin: one shorter than `minLength`
    (`strings(3, 6, ['a','b'], origin: 'ab')`), one longer than `maxLength`,
    and one containing a character outside the alphabet
    (`strings(0, 6, ['a','b'], origin: 'admin')`)
  - And given a string that is **not valid UTF-8** — the byte `"\xC3"` alone —
    supplied either as an alphabet entry or as an origin
  - When the generator is constructed
  - Then it throws `InvalidArgumentException` with a message naming the offending
    argument and its value, at **construction** time — never at generation time,
    where a seeded run would fail halfway through and the seed would be blamed.
  - *The explicit-origin case follows D015: an implicit default origin is
    clamped into range, an explicitly supplied one out of range throws. The
    single-character check is by characters, not bytes, or `'é'` is rejected as
    two.*
  - *The UTF-8 case is not hypothetical and not the caller's fault to catch: the
    named consumer derives its alphabet and its origin from a JSON Schema
    published by the server under test, which it treats as untrusted input by
    policy. Under D031 the character operations are `preg_*` with the `/u`
    modifier, and those return `false` on malformed UTF-8 rather than raising —
    so without this criterion a hostile or merely broken schema yields a
    generator that silently produces nothing instead of a named error. The
    validity check therefore belongs at construction, next to the other
    argument checks, and must distinguish "invalid UTF-8" from "more than one
    character" in its message.*

- **AC11 — every new generator satisfies the `Generator` contract**
  *(required: error path)*
  - Given a table of every generator this spec adds, each with a generated
    value
  - When each is shrunk to exhaustion under a bound
  - Then no candidate equals the value it was shrunk from, every sequence is
    finite, and every candidate carries a context that permits further
    shrinking.
  - And when `shrink()` is handed a `GeneratedValue` whose context is not the
    shape that generator produces, it throws `LogicException` — the SPEC-003
    AC3 pattern: a generator bug fails loudly rather than silently yielding no
    candidates and leaving a counterexample un-shrunk.

- **AC12 — the same seed reproduces the same values and the same shrinks**
  - Given each new generator
  - When it is run twice from identically seeded sources, in separate
    processes, and every value shrunk
  - Then the values and the full shrink sequences are identical. Nothing here
    may depend on hash order, `spl_object_id`, or the clock.

- **AC13 — a planted bug over the new generators shrinks to the exact minimum**
  *(R8: meta-suite)*
  - Given a system under test with a planted bug that fails when a command's
    **string** argument reaches four characters, and a second with a bug that
    fails when a **list** argument reaches two elements
  - When the property runs and shrinks
  - Then the reported counterexample is exactly a four-character string of the
    alphabet's first character, and exactly a two-element list at the item
    origin, respectively.
  - *This is what proves the new generators actually plug into SPEC-006's
    argument family. Without it, the spec would only prove the generators shrink
    in isolation — which is precisely the gap R8 exists to close.*

## API sketch

Illustrative only. `final`, `readonly` where applicable, `strict_types=1`. No
new `@template` type is introduced: every signature below composes the existing
covariant `Generator<T>` (D017).

```php
// namespace Provemark\StatefulCheck\Generation;

final class Gen
{
    /** @return Generator<float> — finite values only; NAN/INF never generated */
    public static function floats(float $min, float $max, ?float $origin = null): Generator;

    /**
     * A string of $minLength..$maxLength characters drawn from $alphabet.
     * Lengths are counted in characters (code points), not bytes (D031).
     *
     * The alphabet is required: the engine has no opinion about which
     * characters are interesting, and a shipped default would be a promise
     * that every recorded seed depends on (D035).
     *
     * $origin is the string shrinking moves toward. It defaults to
     * $minLength repetitions of the alphabet's first character; an explicit
     * one lets a caller aim the shrink at a meaningful value, such as a JSON
     * Schema `default` (D032).
     *
     * @param  list<string>  $alphabet  single characters; shrinks toward the first
     * @return Generator<string>
     */
    public static function strings(int $minLength, int $maxLength, array $alphabet, ?string $origin = null): Generator;

    /**
     * @param  Generator<mixed>  $item
     * @return Generator<list<mixed>>
     */
    public static function listsOf(Generator $item, int $min, int $max): Generator;

    /**
     * Distinct members of a fixed choice set, in generated order. Not a general
     * uniqueness combinator — the choices must be finite and enumerable.
     *
     * @param  list<mixed>  $choices
     * @return Generator<list<mixed>>
     */
    public static function subsetOf(array $choices, int $min, int $max): Generator;

    /**
     * Optional keys are sometimes absent, and shrink to absent before their
     * value is shrunk. The existing single-argument call is unchanged.
     *
     * @param  array<array-key, Generator<mixed>>  $generators  always present
     * @param  array<array-key, Generator<mixed>>  $optional    sometimes present
     * @return Generator<array<array-key, mixed>>
     */
    public static function associative(array $generators, array $optional = []): Generator;

    /**
     * Uniform choice among branches of any type — the mechanism AlphabetGenerator
     * already contains, without its Command typing. The branch choice is not
     * shrunk (the documented gap from SPEC-003 AC5).
     *
     * @param  list<Generator<mixed>>  $branches
     * @return Generator<mixed>
     */
    public static function oneOf(array $branches): Generator;
}
```

New classes, one per generator, each `implements Generator` and each holding
its own shrink: `FloatsGenerator`, `StringsGenerator`, `ListsGenerator`,
`SubsetGenerator`, `OneOfGenerator`. `AssociativeGenerator` gains the optional
set. `AlphabetGenerator` delegates to `OneOfGenerator` and keeps its typed
signature.

## Open questions

1. **`floats()` — DECIDED (2026-08-27): kept. Recorded as D033.** The substitute
   was real — `Gen::map(fn (int $i) => (float) ($i / $scale), Gen::integers($min
   * $scale, $max * $scale, 0))` satisfies the consumer's AC6 example today —
   and it lost on ownership: it leaves the scale unowned, overflows D016's guard
   on wide bounds, and puts "how a double is drawn and shrunk" in the consumer
   instead of in the engine. The cut version was costed before the choice was
   made, which is why this row is short: `stateful-check-mcp` SPEC-004 would
   have gained a scale parameter, `{"type":"number"}` would be generated on a
   fixed grid, and every report would have had to disclose that every
   number-valued argument a server ever saw from us was a multiple of that grid
   step.

2. **`subsetOf()` — DECIDED (2026-08-27): kept. Recorded as D034.** It exists
   for one consumer criterion, JSON Schema's `uniqueItems` (stateful-check-mcp
   SPEC-004 AC10), and it covers it only where the item schema is a finite
   enumerable set. It was kept on evidence rather than taste: the prior-art
   section shows fast-check leaving candidates below the requested length and
   Hypothesis aborting the test case, and neither is available to a consumer
   whose whole promise is "every value we generate satisfies its schema". The
   cut version was costed too — the consumer would refuse every `uniqueItems`
   schema by name, an honest outcome but a visible one, since arrays of distinct
   ids are common in real tool arguments. **The narrowness stands regardless:**
   a non-enumerable item schema (`{"type":"array","items":{"type":"string"},
   "uniqueItems":true}`) is still unsupported, and the consumer refuses or drops
   it under its own AC18. Keeping `subsetOf` did not make `uniqueItems` general.

3. **Characters or bytes? — DECIDED (2026-08-23): characters (code points).
   Recorded as D031.** The named consumer implements JSON Schema, where
   `minLength` counts code points, so bytes would make every derived bound
   subtly wrong for non-ASCII alphabets — and in the fatal direction: at
   `minLength: 3` over an alphabet containing `é`, a byte-counting generator
   emits a two-character string that violates the very schema it was derived
   from, which is the one thing this layer may never do. The prior art is
   unanimous: fast-check counts *units* (with an explicit `unit` option because
   the question is ambiguous), Hypothesis counts Python characters, nobody
   counts bytes.

   **The cost is lower than this spec first claimed, and the correction is
   load-bearing.** The draft said the implementation "must use `mb_*`", which
   would have made `ext-mbstring` this package's first runtime dependency — it
   currently requires nothing but `php`. That is not necessary. Generation never
   *measures* a string: a length *n* is drawn, *n* characters are taken from the
   alphabet, and the result is `implode`d, so the length is known because it was
   chosen. Shrinking needs to split a string back into code points, and
   `preg_split('//u', …)` does that with PCRE's Unicode support, which is
   compiled into PHP by default. **So: no new dependency, and `strlen`,
   `substr` and `str_split` are forbidden throughout.** The price of `preg_*`
   with `/u` is that it fails on malformed UTF-8 rather than raising, which is
   why AC10 now has an explicit invalid-UTF-8 criterion.

4. **A default alphabet for `strings()` — DECIDED (2026-08-27): no, the
   alphabet is required. Recorded as D035.** The draft had a conservative ASCII
   default. But the engine has no opinion about which characters are
   interesting, its only named consumer passes its own alphabet (quotes,
   backslashes, an accented character, an astral character — that package's
   SPEC-004 open question 7), and neither dogfood suite generates free strings at
   all. The asymmetry decides it: *adding* a default later is backwards
   compatible, while *changing* a shipped one changes what every recorded seed
   in the world reproduces. The cost is one extra argument at every call site,
   including in this spec's own tests.

5. **Which end does a removal take from? — DECIDED (2026-08-27): from the end.
   Recorded as D036.** The prior art made this a visible choice rather than an
   accident: fast-check's array shrinker computes `sliceStart = value.length -
   lengthValue.value` and keeps the **suffix**, dropping from the front. We do
   the opposite deliberately — a candidate is then a *prefix* of the value it
   came from, which is the version a reader of a report can check by eye ("the
   first two items already fail"), and it matches how SPEC-002 shrinks a command
   sequence by holding a prefix. Both directions are legal local minima under
   R3; what mattered is that the choice is recorded and that AC6 asserts it, so
   an implementation cannot quietly do the other. AC6 now cites D036 rather than
   an open question.

6. **A second parameter on `associative()`, or a new `record()`? — DECIDED
   (2026-08-27): the parameter. Recorded as D037.** It adds no new concept,
   leaves every existing call and test untouched, and keeps "a keyed record" one
   thing in the user's head. A separate `record()` would leave two combinators
   that differ only by whether keys may be absent, and D025's reasoning
   (compose, do not multiply entry points) applies unchanged.

7. **Float shrink granularity — DECIDED (2026-08-27): origin first, then
   binary-search halving toward it, stopping at a bounded step count. Recorded
   as D038.** The alternative — simplifying toward "nice" decimals as fast-check
   does — is friendlier to read in a report but needs a definition of *nice*,
   and this engine has no precedent for one. Mirroring `integers` keeps one
   shrink model in the codebase. This question was live only because D033 kept
   `floats()`; cutting it would have made the question moot.

8. **Edge-biasing (SPEC-009, D029/D030) for the new generators — DECIDED
   (2026-08-27): not in this spec. Recorded as D039.** `integers()` takes an
   opt-in `edgeBias`; lengths here are drawn with `integers()`, so the plumbing
   would be nearly free, and the natural edges are obvious
   (`minLength`/`maxLength`, the empty list, the bounds of a float range). It is
   still deferred: D029 made bias opt-in *and measured*, extending it needs its
   own measurement, and it would double this spec's test surface. A named
   follow-up, not a silent omission — and the reason it is safe to defer is that
   adding bias later is opt-in by construction, so no recorded seed changes.

9. **R10 gate — RUN AND PASSED (2026-08-18).** This spec introduces no new
   `@template` type; every signature composes the existing covariant
   `Generator<T>`. The gate therefore reduced to re-confirming D017's covariance
   for a *heterogeneous* branch list. A throwaway file returning
   `list<Generator<mixed>>` built from `Gen::integers()` (`Generator<int>`),
   `Gen::elements()` (`Generator<string>`) and `Gen::associative()`
   (`Generator<array<array-key, mixed>>`) analysed clean under
   `phpstan --level=max`, and was deleted, not committed. Nothing to answer.

10. **§8 pre-approval task: prior art — DONE (2026-08-19).**
    `docs/prior-art.md` gained a section on value generation, written by reading
    fast-check's `ArrayArbitrary`/`string.ts` and Hypothesis's `shrinker.py`,
    `strings.py` and `collections.py`. It **confirmed** AC4/AC6's ordering
    (remove before simplify — fast-check by candidate order, Hypothesis by
    shortlex `sort_key`), confirmed the minimum is respected by making length an
    integer arbitrary bounded below by `minLength`, and independently confirmed
    R3's framing (Hypothesis's own docstring: "we are at a local minimum for
    each shrink pass"). It **changed** three things: the removal direction became
    Open question 5 instead of an implementation detail; the single-direction
    deletion family's limit is now stated in AC6; and question 2 moved from taste
    to evidence. One deliberate divergence: Hypothesis draws no length at all
    (`cu.many` + `while elements.more()`), which only shrinks well with internal
    shrinking over a choice sequence — our external shrinker needs a drawn size,
    so we follow fast-check.

11. **`strings()` gains an `origin` — DECIDED (2026-08-23). Recorded as D032.**
    Found while closing question 3, and it is a contradiction this spec shipped
    with rather than a new idea. The necessity audit admits `strings()` partly
    on the strength of `stateful-check-mcp` SPEC-004 AC13, cited in the table as
    "`default` as the shrink origin" — but the signature had no origin and AC4
    fixed the shrink target at `minLength` repetitions of the alphabet's first
    character. The spec therefore justified a combinator with a criterion it
    could not satisfy. The audit asked "can the *existing* combinators express
    this?" and never asked whether the *proposed* one did, which is the gap that
    let it through; worth remembering the next time the §4 table is filled in.

    The fix mirrors `floats()`: `?string $origin = null`, defaulting to the
    previous behaviour so AC4's first half and every call site in this spec are
    unchanged. Validation follows D015 — an implicit origin is the default, an
    explicit one that is out of length bounds or not drawn from the alphabet
    throws at construction (AC10).

12. **Does an explicit origin force its characters into the alphabet? — DECIDED
    (2026-08-27): yes, strictly; the union is the consumer's job. Recorded as
    D040.** D032 requires an explicit origin to consist of alphabet characters,
    so that character-wise simplification can actually reach it and every shrink
    candidate stays a value the generator could itself have produced. The
    consequence lands on the consumer: `stateful-check-mcp` SPEC-004 picks a
    small alphabet of *interesting* characters (quotes, a backslash, an accented
    character, an astral character — its own open question 7), and `"admin"`
    shares not one character with it. So a derived generator unions the
    `default`'s characters into the alphabet it passes, or AC13's own example
    throws. The alternative — letting the origin sit outside the alphabet and
    offering it as a bare extra candidate — was rejected because it creates
    shrink candidates that generation could never produce, exactly the asymmetry
    the `Generator` contract exists to prevent. The consumer records the union
    in its report, since it changes which characters a server sees.
    **SPEC-004 AC13 carries the matching amendment (its own Step 17).**

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 — `oneOf()` records its branch; `alphabet()` unchanged | `tests/Unit/Generation/OneOfGeneratorTest.php` (group `SPEC-010`); `tests/Unit/Generation/AlphabetGeneratorTest.php` (group `SPEC-003`, **unmodified**) | `src/Generation/OneOfGenerator.php`, `src/Generation/AlphabetGenerator.php` (delegation), `Gen::oneOf` |
| AC2 — `floats()` in range, shrinks to its origin | `tests/Unit/Generation/FloatsGeneratorTest.php` (group `SPEC-010`) | `src/Generation/FloatsGenerator.php`, `Gen::floats` |
| AC3 — `strings()` respects alphabet and code-point bounds | `tests/Unit/Generation/StringsGeneratorTest.php` (group `SPEC-010`) | `src/Generation/StringsGenerator.php`, `Gen::strings` |
| AC4 — string shrinking shortens before it simplifies, toward the origin | `tests/Unit/Generation/StringsGeneratorTest.php` (group `SPEC-010`) | `src/Generation/StringsGenerator.php` :: `shrink` |
| AC5 — `listsOf()` respects its bounds and item generator | `tests/Unit/Generation/ListsGeneratorTest.php` (group `SPEC-010`) | `src/Generation/ListsGenerator.php`, `Gen::listsOf` |
| AC6 — list shrinking removes before it reduces elements | `tests/Unit/Generation/ListsGeneratorTest.php` (group `SPEC-010`) | `src/Generation/ListsGenerator.php` :: `shrink` |
| AC7 — optional keys are sometimes present, sometimes absent | `tests/Unit/Generation/AssociativeGeneratorTest.php` (group `SPEC-010`) | `src/Generation/AssociativeGenerator.php`, `Gen::associative` |
| AC8 — an optional key shrinks to absent; a required one is never dropped | `tests/Unit/Generation/AssociativeGeneratorTest.php` (group `SPEC-010`) | `src/Generation/AssociativeGenerator.php` :: `shrink` |
| AC9 — `subsetOf()` never repeats a choice | `tests/Unit/Generation/SubsetGeneratorTest.php` (group `SPEC-010`) | `src/Generation/SubsetGenerator.php`, `Gen::subsetOf` |
| AC10 — invalid construction arguments throw | `FloatsGeneratorTest.php`, `StringsGeneratorTest.php`, `ListsGeneratorTest.php`, `SubsetGeneratorTest.php`, `AssociativeGeneratorTest.php` (group `SPEC-010`) | the five generators' constructors |
| AC11 — the `Generator` contract holds for every new generator | `tests/Unit/Generation/ValueGeneratorContractTest.php` (group `SPEC-010`) | all six generators :: `shrink` |
| AC12 — the same seed reproduces values and shrinks | `tests/Unit/Generation/ValueGeneratorDeterminismTest.php` (groups `SPEC-010`, `arch`) | all six generators; `src/Generation/Source.php` |
| AC13 — planted string- and list-argument bugs shrink to their exact minima | `tests/Meta/ValueArgumentPlantedBugShrinkTest.php` (groups `meta`, `SPEC-010`) | `StringsGenerator`, `ListsGenerator`, `SequenceShrinker` (SPEC-006 argument family) |

### Three-sided check, run at `implemented`

**Every AC has a test:** the table above, thirteen for thirteen.

**Every deliverable has an AC:** the six in-scope items map to AC1 (`oneOf`), AC2
(`floats`), AC3–AC4 (`strings`), AC5–AC6 (`listsOf`), AC7–AC8 (optional keys) and
AC9 (`subsetOf`); AC10–AC12 are cross-cutting over all six and AC13 proves they
reach SPEC-006's argument family. No class was added that no criterion asks for.

**Every scope item has a deliverable:** `floats` → `FloatsGenerator`, `strings` →
`StringsGenerator`, `listsOf` → `ListsGenerator`, optional keys →
`AssociativeGenerator`'s second parameter, `subsetOf` → `SubsetGenerator`,
`oneOf` → `OneOfGenerator` with `AlphabetGenerator` reduced to a delegation whose
SPEC-003 AC5 tests pass unmodified.

Checked negative as well, since an out-of-scope item that quietly appeared would
be invisible to the three checks above: no `booleans()`, no regex or
format-driven strings, no general `uniqueItems`, no `filter`/`tuple`/`vector`, no
dictionaries with generated keys, and **no `edgeBias` parameter on any of the new
generators** (D039) — the length draws use `integers()` without it, so no
recorded seed can move when bias is extended later.

D035 is enforced by the signature rather than by a check: `strings()` has no
default alphabet to omit.
