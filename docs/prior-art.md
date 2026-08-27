# Prior art

Required reading before designing anything (CLAUDE.md §8). This records what the
mature implementations actually do, where they disagree, and what the PHP
attempts tell us. Cite it in specs. Add findings as you verify them — mark
anything unverified as such rather than repeating a claim.

## The shape everyone converged on

Four parts, stable across every implementation since Quviq QuickCheck (Erlang,
2006):

1. **Command alphabet** — the operations, with generated arguments.
2. **Shadow model** — a simple, obviously-correct account of expected state.
3. **Transition function** — how each command advances the model.
4. **Postcondition** — after each command, does the system agree with the model?

Generation in PropEr and stateful-check runs in two phases: an abstract phase
that walks the model building a sequence (checking preconditions symbolically,
never touching the system), then a real phase that executes it.

We do **not** do this. An abstract replay was specified initially to serve the
shrinker, and turned out to be unnecessary — see the fast-check section below and
R1. Our runner has a single phase; commands whose precondition fails are simply
skipped (SPEC-001 AC3).

## The stateless primitive underneath — `forAll`

The command-sequence machinery above is not the base layer; it sits on an older,
simpler one. Every property tool since QuickCheck (Haskell, Claessen & Hughes,
2000) is built on `forAll(generator, predicate)`: draw a value, check a predicate,
and on failure shrink the value toward a minimum. The stateful property is a
*special case* of it — the generator produces a sequence of commands and the
predicate runs them against the model. fast-check makes this literal:
`fc.commands(...)` is just an `Arbitrary`, and a stateful run is
`fc.assert(fc.property(fc.commands(...), setup => fc.modelRun(setup, cmds)))` — a
`forAll` over a commands arbitrary. Eris exposes `forAll(...)->then(...)`;
Hypothesis has `@given(...)` beside its `RuleBasedStateMachine`.

We started at the stateful layer and never exposed the primitive beneath it, so a
user with an ordinary property has no entry point (SPEC-008 fixes this). The
mechanism is not new: the value shrink is the generator's own origin-ward `shrink()`
(SPEC-003/006) — no executed-subset filtering (R1), no model, just reduce the value.
Shrinking stays *external* to the generator here too, the same deliberate divergence
from fast-check's integrated model recorded for sequences (D003).

One place we go slightly beyond the mature tools: they reproduce a counterexample
from a seed and otherwise **trust the predicate to be pure** — a non-deterministic
predicate is treated as user error, not detected. SPEC-008 adds a single re-check of
the reported counterexample (D026) as R4's stateless analogue, since the stateless
runner has no replay-path detector (SPEC-002 AC8) to lean on. A modest strengthening,
not something QuickCheck, fast-check or Eris do.

## fast-check (TypeScript) — the blueprint

`fc.commands` / `fc.modelRun`. Actively maintained, and it genuinely shrinks
command sequences. Read `CommandWrapper` first, then `CommandsArbitrary`.

**Verified 2026-07-29 by reading the source.**

- `ICommand` has `check(model)` (precondition) and `run(model, real)` which both
  mutates the model and asserts against the real system — a merged transition and
  postcondition. We deliberately keep them split (PropEr/stateful-check style);
  our pure `nextState` is what lets the model stay immutable.
- `CommandWrapper` adds a `hasRan` flag, set only in `run()`. Shrinking filters
  to commands where it is true. Because fast-check cannot replay abstractly
  (there is no pure transition), this flag is how it learns which commands
  executed. We get the same information from `RunResult::$executed`.
- **Shortened sequences cannot be ill-formed.** A command whose `check()` fails
  is skipped, so removing a command it depended on merely makes it skip. This is
  why no candidate re-validation exists anywhere in `CommandsArbitrary`, and it
  holds only because commands never consume each other's results. It is the
  origin of our R1 and R9a.
- Candidates, in order: the **empty sequence** exactly once (guarded by a
  `shrunkOnce` flag — it asks whether the commands caused the failure at all);
  then **hold a prefix, shrink the retained suffix length**, always keeping the
  last executed command because execution stopped there; then **per-command
  argument shrinking** via each item's stored context.
- Sequence **length is its own arbitrary** (`lengthArb`), so length shrinking is
  integer shrinking. Not a bespoke "remove blocks" routine.
- Each item is a `Value` = generated value + shrink context. Per-command argument
  shrinking is only possible because that context survives generation. This
  fixed the shape of our generation core (SPEC-003).
- Commands are **cloned** into every candidate, producing fresh wrappers with
  `hasRan = false`. Origin of R9b. fast-check clones only when the command
  supports it; we clone every command unconditionally (D006), because an opt-in
  clone fails silently — a deliberate divergence, not an inherited detail.
- `replayPath` is a boolean array — literally "which commands ran" — serialised
  into the failure output. `filterOnReplay` throws `Mismatch between replayPath
  and real execution` when a replay diverges. That is non-determinism detection
  for free, and is cheaper than re-running sequences and comparing verdicts.
  Origin of SPEC-002 AC8.
- Generation is **uniform**: `// For the moment, we fully ignore the bias on
  commands`. Encouraging — no clever generation strategy is needed to be useful.
- `canShrinkWithoutContext` returns hard `false`. Shrinking is impossible without
  the context; there is no reconstruction path.
- Shrinking is **arbitrary-integrated**, not external: the same object generates
  and shrinks. Ours is external — a shrinker over a finished sequence.

  This began as a constraint (Eris was a black box) and is now a choice: since
  SPEC-003 owns generation, the integrated model became available and was not
  taken. External keeps the shrinker testable in isolation and keeps SPEC-002
  coherent as written; the advantage of integrated shrinking weighs lightly here
  because our sequence structure dominates, not the values. Decided in D003 and
  recorded here as a deliberate divergence rather than an inherited limitation,
  per CLAUDE.md §8.

## stateful-check (Clojure)

Built *on top of* test.check rather than inside it: the stateful layer is a
separate library from the generator library. We considered the same split with
Eris and ultimately went further — SPEC-003 owns generation outright, so there is
no underlying library to sit on. Worth knowing that the layered approach is
viable if the decision is ever revisited.

Notable: it does run commands in parallel to detect races, and shrinks those too.
We do not (R5); do not let its feature list leak into our description.

`system.check` was a second Clojure attempt whose README notes the good ideas
across these projects should be consolidated. It has no releases. Worth reading
as a record of what a second-mover thought was missing.

## PropEr (Erlang) and Quviq QuickCheck

The origin. `proper_statem` for sequential, `proper_fsm` for state-machine-shaped
systems. The distinction is worth noting: some systems are better described as a
finite state machine with named states and allowed transitions than as a free
sequence of commands over a model. We are building the latter. If the FSM shape
turns out to be what people want, that is a separate spec, not a retrofit.

Erlang's concurrency is why the parallel feature exists there and why the
technique has the reputation it does. Ours is a deliberately narrower tool.

## Hypothesis (Python) — `RuleBasedStateMachine`

The best-documented explanation of the technique for people who have not seen it
before; useful as a model for our own README. Uses decorators (`@rule`,
`@invariant`, `@precondition`) to declare the alphabet, which is more ergonomic
than our class-per-command but less amenable to static analysis in PHP.

Its shrinking is integrated into the generation source rather than operating on
a finished sequence — a fundamentally different strategy (internal shrinking)
from the external, candidate-generation strategy in SPEC-002. Worth
understanding before committing, but internal shrinking is not compatible with
consuming Eris as a black box.

## Value generation — how lists and strings are drawn and shrunk

Everything above is about command sequences. SPEC-010 needed the layer below it —
strings, lists, uniqueness — and this section did not exist when that spec was
drafted, which is why writing it was a pre-approval task (§8). It changed three
things in the spec, listed at the end.

**Verified 2026-08-19 by reading the source:** fast-check
`packages/fast-check/src/arbitrary/_internals/ArrayArbitrary.ts` and
`arbitrary/string.ts`; Hypothesis
`hypothesis/src/hypothesis/internal/conjecture/shrinker.py` and
`strategies/_internal/{strings,collections}.py`.

**A string is a list of characters — in both.** fast-check's `fc.string` is
`array(charArbitrary).map(patternsToStringMapper, unmapper)`; Hypothesis's
`TextStrategy` literally extends `ListStrategy[str]` (it keeps a `draw_string`
fast path for the single-character element strategy, but the shrink model is the
list's). Neither has a bespoke string shrinker. That is the strongest argument
for building `strings()` and `listsOf()` from one mechanism rather than two.

**Length is counted in units, never bytes.** fast-check's `unit` option exists
precisely because "how long is a string" is ambiguous: `'grapheme'`,
`'grapheme-composite'`, `'grapheme-ascii'` (the default — printable ASCII),
`'binary'`, `'binary-ascii'`, or any `Arbitrary<string>`. `minLength`/`maxLength`
count *those units*, not `String.length`. Hypothesis counts Python characters,
i.e. code points, and takes the alphabet as an explicit `IntervalSet` of code
points. Nobody counts bytes.

**Remove before simplify, reached two different ways.**

- fast-check does it by candidate order, in `ArrayArbitrary.shrinkImpl`: first the
  stream from `this.lengthArb.shrink(value.length, …)` — and `this.lengthArb =
  integer({ min: minLength, max: maxGeneratedLength })`, so length shrinking *is*
  integer shrinking and can never go below `minLength`; then `shrinkItemByItem`;
  then a lazy recursion on `safeSlice(value, 1)` re-prefixed with item 0 and
  filtered by `this.minLength <= v[0].length + 1`. Two details worth having read:
  while `value.length > this.minLength` the item-by-item pass is called with
  `endIndex = 1`, i.e. only the *first* item is simplified before the recursion
  takes over; and length candidates are materialised as `const sliceStart =
  value.length - lengthValue.value` followed by `safeSlice(value, sliceStart)` —
  fast-check keeps the **suffix** and drops from the front.
- Hypothesis does it by *ordering the whole choice sequence*, not by ordering
  passes. `sort_key` (verbatim): "We define sort_key so that x is simpler than y
  if x is shorter than y or if they have the same length and
  `map(choice_to_index, x) < map(choice_to_index, y)`" — shortlex. Deleting a
  choice always beats simplifying one, for every strategy at once, because the
  comparison puts length first.

**Hypothesis states our R3 in its own words.** From the `Shrinker` docstring:
"The desired end state of shrinking is to find a value such that no shrink pass
can make progress, i.e. that we are at a local minimum for each shrink pass."
Independent support for refusing to claim minimality beyond a documented local
minimum.

**Character simplification is an explicit order over the alphabet, not code
point order.** Hypothesis shrinks a character by
`IntervalSet.index_from_char_in_shrink_order`, plus `_natural_simpler_chars`,
which offers case-mapped and NFD/NFKD-decomposed replacements "so that e.g. `ß`
can shrink to `s` via casefold", keeping only candidates with a strictly smaller
index in shrink order. SPEC-010's "shrink toward the alphabet's first character"
is the same idea with the ordering handed to the caller: alphabet order *is*
shrink order.

**Length: drawn, or not drawn.** This is where the two disagree outright.
fast-check draws a length from an integer arbitrary. Hypothesis draws no length
at all: `ListStrategy.do_draw` calls `cu.many(data, min_size, max_size,
average_size)` and loops `while elements.more()`, one continuation decision per
element (`average_size = min(max(min_size * 2, min_size + 5), 0.5 * (min_size +
max_size))`). The continuation-probability model only shrinks well *because*
Hypothesis shrinks the underlying choice sequence internally; with an external
shrinker over a finished value there is nothing to delete. So the fast-check
model is the one that fits this codebase — a drawn size is an `integers()` whose
context SPEC-006's argument family already knows how to shrink.

**Uniqueness: neither library guarantees the minimum.** This is the finding that
settled SPEC-010's open question about `subsetOf`.

- fast-check filters. `preFilter` builds a set and `tryAdd`s each item; the
  comment at its other call site is explicit that "`preFilter` only drops items,
  it does not reorder them or add some more". In `shrinkImpl`: "We need to
  explicitly apply filtering on shrink items has they might have duplicates (on
  non shrunk it is not the case by construct)" — so a shrink candidate of a
  `uniqueArray` is the length the shrinker asked for *minus* whatever
  deduplication removed, and nothing re-checks `minLength` afterwards.
- Hypothesis rejects. `UniqueListStrategy.do_draw` draws through a
  `FilteredStrategy` whose predicate is "not yet in the unique list"; when that
  filtered draw fails it calls `elements.reject("Aborted test because unable to
  satisfy …")`, i.e. it throws the whole test case away rather than returning a
  short list. Its closing `assert self.max_size >= len(result) >= self.min_size`
  is upheld by aborting, not by construction.

Both are acceptable there and neither is acceptable here: our consumer's
generated value must satisfy the schema it came from, and a list below `minItems`
does not. Drawing a bounded subset of a *fixed choice set* is a third option that
guarantees distinctness and the minimum without probabilistic retries — at the
price of only applying where the item schema is a finite, enumerable set.

**What this changed in SPEC-010.** Its AC on string/list shrink order was written
before this section existed and survives it: both mature implementations agree on
remove-before-simplify. Three things did change. (a) The removal *direction* was
unstated and is now a recorded decision rather than an implementation accident —
fast-check keeps the suffix, and it would have been easy to write the opposite
without noticing there was a choice. (b) Single-direction removal is a weaker
deletion family than Hypothesis's adaptive interval deletion, and that limit is
now stated under R3 instead of being discovered later. (c) `subsetOf` stopped
being a question of taste: the prior art shows what happens without it.

## The PHP graveyard

Two data points, both worth taking seriously.

**Eris** (`giorgiosironi/eris`) — the working PHP QuickCheck port. Stateless
only. Generators and value shrinking are solid; `Generators::seq()` produces a
sequence of *values*, which is what our hand-rolled tests read as operations.
Lightly maintained: old releases, examples referencing older PHPUnit. Generator
names have moved between versions — pin what we use (SPEC-003).

**steos/php-quickcheck** (later `steos/quickcheck`) — a port of clojure.test.check
0.5.9. Abandoned: last release v2.0.2 in June 2022, still requiring PHP 7.3.
178 stars, ~5000 installs, **2 dependents**.

The lesson is not technical. Neither project hit a wall in PHP; both simply
stopped, and the second had visibility without adoption. Stateful testing was
never even attempted — test.check 0.5.9 predates it, and stateful lives in
separate libraries in Clojure anyway.

Read that as a market signal, not a warning about feasibility: the sequential
part of this is plainly buildable in PHP. Whether anyone else adopts it is a
different question, and the honest answer is that two prior efforts suggest not
many. Build it because it is the tool we need.

## Where PHP may or may not be able to follow

Share-nothing, request-scoped execution means no in-process threads, so the
Erlang-style parallel runner is out for v0.1 (R5).

But note that **JavaScript is single-threaded too**, and fast-check still does
race detection — via `fc.scheduler` and `scheduledModelRun`, which deterministically
permutes the ordering of async operations rather than running anything in
parallel. PHP has Fibers since 8.1 and generators before that. Whether an
analogous deterministic scheduler is buildable in PHP is genuinely open, and
should not be closed off with a blanket "PHP cannot". It is a research question
for after v0.1, and any answer needs its own spec.

Until then the package claims sequential testing only, and says so plainly.

## To verify

Mark each as verified with a date once actually checked, rather than trusting
this document.

- [ ] fast-check's exact shrinking loop: restart semantics, and whether sequence
      length shrinks independently of contents.
- [x] **`Random\Engine\Mt19937` reproducibility — verified 2026-07-29** on PHP
      8.3.6, Linux, 64-bit (`docs/verification/mt19937.php`). Same seed gives an
      identical sequence within a process, between two independent instances, and
      **across separate processes**. It is unaffected by a global `mt_srand()`.
      Both `Randomizer` and the engine serialise and resume correctly.

      Two findings that changed the specs:

      - **The mode argument changes the output.**
        `new Mt19937($seed, MT_RAND_PHP)` yields a different stream from the
        default `MT_RAND_MT19937` (575 vs 506 for the same seed). The mode must
        be passed explicitly, not left to the default, or a future default change
        silently breaks every recorded seed. SPEC-003 AC1 amended.
      - **`Randomizer` cannot be cloned at all** — it throws
        `Error: Trying to clone an uncloneable object`. The *engine* can be, and a
        cloned engine continues the same stream. Independent confirmation that
        dropping `fork()` (D010) was right; had it stayed, it would have had to be
        built at the engine level.

      Still untested, and honestly so: other PHP minor versions, 32-bit builds,
      and non-Linux platforms. Mt19937 is a fixed algorithm and PHP's engine
      objects are documented as reproducible, so divergence is unlikely — but
      "unlikely" is not "verified", and 32-bit is the plausible edge because
      `PHP_INT_SIZE` affects range mapping. Re-run the script if the package ever
      claims support beyond 64-bit Linux.

Only relevant if SPEC-004 (optional Eris adapter) is ever scheduled:

- [ ] Whether Eris can shrink a previously generated value outside its own
      `forAll` loop. If not, the adapter cannot be built and SPEC-004 closes.
