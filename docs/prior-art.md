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
