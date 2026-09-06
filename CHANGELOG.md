# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Value generators, each with its own origin-ward shrink (SPEC-010): `Gen::strings()`,
  `Gen::floats()`, `Gen::listsOf()`, `Gen::subsetOf()`, `Gen::oneOf()`, and optional
  keys on `Gen::associative()`. A generator without its shrink drops an unshrinkable
  value into a counterexample, so none of them ships without one.
- `Gen::strings()` counts lengths in **characters (code points), not bytes**, so a
  bound derived from a JSON Schema means what the schema says even for a non-ASCII
  alphabet. No new runtime dependency: generation never measures a string, and
  shrinking splits one with PCRE's Unicode support rather than `ext-mbstring`.
- `Gen::strings()` and `Gen::floats()` take an optional `origin` — the value shrinking
  moves toward — so a counterexample can reduce toward a meaningful value such as a
  schema's `default` instead of the alphabet's first character.

### Changed

- Sequence and string shrinking now share one rule: removals take from the **end**, so
  a shrink candidate is a prefix of the value it came from and a reader can check it by
  eye. This diverges from fast-check, which keeps the suffix.
- `Gen::alphabet()` is a thin delegation to the new `oneOf()`, which is the mechanism it
  already contained. Its signature, values and shrink sequences are unchanged, proven by
  its SPEC-003 tests passing unmodified.

### Limitations worth knowing

- `subsetOf()` covers distinctness over a **finite, enumerable** choice set only. It is
  not a general `uniqueItems`: over an arbitrary item generator, uniqueness needs either
  filtering (which breaks a declared minimum) or rejection (which breaks determinism in
  a seeded run), and choosing between those needs its own spec.
- Edge-biasing (`edgeBias`) is not extended to the new generators. It stays an
  `integers()` parameter, so adding it later cannot change what an already-recorded seed
  reproduces.
- List and record shrinking reduce one element, and one key, at a time — a documented
  local minimum, not a global one.

## [0.2.2] - 2026-08-04

### Added

- A release guard in CI: a tag whose name disagrees with the `version` field in
  `composer.json` now fails the build, rather than publishing a version the package
  is not. Proven to redden on a mismatched tag and on a missing field.

### Changed

- `composer.json` declares an explicit `version`. Composer guesses a `path`
  repository's version from the *branch*, reporting `dev-<branch>` even when HEAD
  sits exactly on a tag, so a downstream package consuming this one as a local
  sibling could not pin a version constraint against it (spec-verify D009).

`src/` is unchanged since 0.2.0; this release is packaging metadata and CI only.

## [0.2.1] - 2026-08-04

### Changed

- Documentation only: the README's bank example points at the tutorial rather than
  `examples/`, which holds other suites.

Cut so downstream packages can depend on a tag instead of tracking `dev-main`.
`src/` is unchanged since 0.2.0.

## [0.2.0] - 2026-08-03

### Added

- SPEC-009 (edge-biased generation) — **implemented** (maurice, 2026-08-03). An opt-in
  `edgeBias` on `integers()` (D029, default off — keeps every existing seed
  reproducible) that draws boundary values (`origin`, `min`, `max`) at a chosen
  frequency, so property testing hits the edges where bugs — especially in
  AI-generated code — cluster. Shrinking is unchanged (value-based); the bias
  propagates through `map`/`associative` via delegation; the recommended frequency is a
  measured 10% (D030). A planted edge-only bug meta-test (R8) pins that biased generation
  finds an edge bug uniform misses. The `edgeBias` percentage is guarded to `[0, 100]`
  at construction.
- SPEC-008 (stateless property runner) — **implemented** (maurice, 2026-08-03). The
  first v0.2 spec (D013): `StatelessProperty`, a `forAll`-style entry point over one
  `Generator<T>` and a predicate, drawing values from a seed, shrinking a failure to a
  minimal counterexample (`PropertyValueResult<T>`), and reporting it — the value-level
  analogue of SPEC-005. Reproducible from a seed (AC3), reports budget-limited (AC5) and
  non-deterministic (AC6) shrinks as qualifications rather than clean minimums, guards an
  empty run count at construction (AC7), and pins its shrink to an exact minimum with a
  planted-bug meta-test (AC8, R8). Decisions D025 (single generator; multi-argument via
  `associative`/`map`), D026 (non-determinism detected by re-checking the reported
  counterexample once), D027 (a separate `PropertyValueResult<T>`, not `PropertyResult`
  reused), D028 (constructor + `check()`, named `StatelessProperty`, no `forAll` facade
  yet). `docs/prior-art.md` gains a "stateless primitive underneath — `forAll`" section.

## [0.1.0] - 2026-08-03

### Added

- Specs SPEC-001 (command contract and runner), SPEC-002 (sequence shrinking),
  SPEC-003 (generation core), SPEC-004 (optional Eris adapter, unscheduled). All
  `draft`.
- `ROADMAP.md` — order of work for v0.1, and the two maintainer decisions that
  must precede any approval.
- `DECISIONS.md` — decision log; all spec open questions tracked as D001–D011.
- CLAUDE.md §2, the working protocol: one acceptance criterion per step, explain
  before writing, prove the test fails first, stop and report after each step.
- SPEC-005 (property entry point) — the object that owns the seed and wires
  generation, execution and shrinking together. It did not exist; SPEC-001's
  seed criterion had no home without it.
- `Outcome`, so a command can expect a failure (SPEC-001 AC5); structured
  `Failure` with `sameKindAs()`, so shrunk failures can be compared without
  comparing messages (SPEC-001 AC2, SPEC-002 AC1); `Ref`, so an immutable system
  under test can be threaded through a sequence (SPEC-001 AC7); read access to
  the system handle from the postcondition (SPEC-001 AC8, D011).
- `docs/verification/mt19937.php` — reproducibility check for the generation
  core. Verified on PHP 8.3.6 / Linux / 64-bit; SPEC-003 AC1 now also pins the
  engine mode explicitly, since `MT_RAND_PHP` yields a different stream.

- `docs/prior-art.md` — fast-check, stateful-check, PropEr, Hypothesis, and the
  two earlier PHP attempts.

### Removed

- `Failure::$reason` and AC2's promise of "an optional human-readable reason". A
  spec defect surfaced by the traceability check: `postCondition` returns a `bool`,
  so no command can ever supply a reason — the AC promised a field the contract
  cannot fill. Never populated, never tested; removed from AC2 and `Failure` until
  a message channel (and its filling mechanism) exists.
- `Source::fork()` from SPEC-003. Unused, and it carried an unverified assumption
  about `Randomizer` clone semantics (D010).
- The opt-in `Cloneable` interface from SPEC-001. Every command is now
  shallow-cloned unconditionally before each shrink candidate; a command holding
  an object it must not share implements `__clone` (D006). Opt-in fails silently,
  which is exactly what this package exists to prevent. Diverges from fast-check,
  which clones only when the command supports it.

### Changed

- SPEC-005 (property entry point) `implemented` (2026-07-31): all ten ACs built AC by AC — seed
  ownership and auto-generation, the generate → run → shrink → report loop, the one reproduction
  artefact `counterexampleAsString()` (`seed=… · initial=… · cmd,cmd`, with an R3 "not a confirmed
  minimum" marker on a qualified shrink), and the vacuous verdict. At finalisation the three-sided
  traceability check removed a vestigial scope item — the result object's unused "number of runs
  performed", which no AC claimed and no code built, surviving only in the API sketch. Both dogfood
  suites (`examples/`) now pass; `ImmutableBuilder` unmodified from the sketch it was written against
  before any implementation existed.
- SPEC-002 (sequence shrinking) implemented AC by AC; then, at the AC7 traceability check, the
  per-command argument family and the whole layer serving it were **retracted** (2026-07-31): the
  shrinker takes a bare `list<Command>`, the `$alphabet` generator param and the `GeneratedValue`
  wrapper input are gone, and D021 is retracted (it was never even implemented — `replay()` ran bare
  clones). No v0.1 case needs argument shrinking; carrying the layer was speculative generality (§4),
  the same shape as D010 (`fork()`). A later spec re-adds argument shrinking with its consumer.
- SPEC-002 (sequence shrinking) `approved`, then amended: AC5 (the empty-sequence probe) removed as D022 — it cannot fail in this model, so it was a useless execution and a dead branch; `shrunkOnce` removed with it. CLAUDE.md gains R11 (an AC is proven fulfillable before approval), promoted on n=2 with `Failure::$reason`. AC8 later broadened to path-**or**-verdict divergence.
- SPEC-002 (sequence shrinking) originally `approved` (maurice, 2026-07-30) after a review against the
  now-built SPEC-001/SPEC-003. Amended before approval: the shrinker takes the generated sequence
  as `list<GeneratedValue<Command>>` **and** the alphabet generator (family-3 argument shrinking is
  `alphabet->shrink(generatedValues[i])`) — **later retracted, see above**; the generic signature
  replaces the stale bare `list<Command>` (R10); `Failure::sameKindAs()` is now an explicit
  deliverable with its own AC10 (exact-class, D020, subclass ≠ parent); the branch-choice coverage
  gap (SPEC-003 AC5) and the three-form layer boundary (generated / shrink / run) are stated; AC1
  cites D020. D021 records the clone-the-command-keep-the-context rule with the command as the
  authoritative side (retracted 2026-07-31).
- SPEC-003 (generation core) is `implemented`. The last deferred piece, the command-alphabet
  generator (`Gen::alphabet`, AC5), landed: uniform choice over a heterogeneous list of command
  generators, recording the chosen branch so shrinking delegates argument-shrinking to the
  generator that produced the command; the branch choice is shrunk by no layer (a documented
  coverage gap). Its first-of-a-kind composite context has an out-of-range failure mode, handled
  with a loud `LogicException`. SPEC-003's other deferred piece, AC4 (sequence length), was
  removed: it had no SPEC-003 deliverable — the length is `integers(1, n, origin: 1)`, a
  consumer's usage — and moved to SPEC-005 AC9 (D018), where the sequence is drawn.
- SPEC-001 (command contract and runner) is `implemented`: all eight acceptance
  criteria traced to tests over `SequenceRunner`, `Command`, `Outcome`, `Failure`,
  `FailureKind`, `RunResult` and `Ref`. Includes AC5/AC6 (the runner catches
  `Throwable` and classifies a thrown-and-rejected outcome as `UnexpectedException`
  carrying the concrete exception class, D020), AC7 (`Ref` handle threaded, never
  replaced) and AC8 (the postcondition observes the system up to and including the
  command). `composer check` green across the suite.

- Specs revised after reading fast-check's `CommandsArbitrary` source. Candidate
  re-validation dropped (unnecessary: skipped preconditions cannot make a
  shortened sequence ill-formed); shrinking now filters to executed commands.
  Symbolic results excluded from v0.1 by design. Command cloning and execution
  -path-based non-determinism detection added.
- Eris dropped as a runtime dependency. SPEC-003 rewritten from "generator port
  over Eris" to a self-owned generation core on PHP 8.2's Random extension,
  which removes both of that draft's blockers. Eris moved to SPEC-004: optional,
  `suggest`, unscheduled.
- SPEC-001 (command contract and runner) and SPEC-003 (generation core) approved
  (maurice, 2026-07-30). The open questions that gated them are decided in
  `DECISIONS.md`: D001 (generics — later revised to `@template`, see below), D003 (external shrinking),
  D004 (configurable shrink origin, default 0), D005 (minimal shrink breadth),
  D006 (always clone), D007 (count budget, default 100), D008 (keep
  `provemark/stateful-check`), D009 (return a `PropertyResult`). SPEC-002 and
  SPEC-005 stay `draft` until their turn, so they can learn from the layers below.
- Shrink budget default lowered from 200 to 100 and fixed as a count of candidate
  executions, never a time budget (D007) — a time budget would make the shrunk
  counterexample machine-dependent and break deterministic reproduction.
- SPEC-005 amended (still `draft`): the separate `model` and `system` closures
  replaced by a single `setup` closure returning both, fed by an optional `initial`
  generator, after fast-check's ModelRunSetup (D012). Surfaced porting dogfood
  example 1, which could not otherwise thread a generated `MediaType` into model
  and system consistently. `PropertyResult` now carries the drawn initial state and
  `counterexampleAsString()` renders it; shrinking holds the initial state fixed
  (new AC7, AC8); an omitted `initial` defaults to `Gen::constant(null)` for a
  single code path. Not shrinking the initial state is documented as a limitation
  under R3.
- D013 decided (option b): the package is positioned explicitly as the *stateful*
  layer; stateless property testing is out of scope for v0.1, and the README names
  Eris as the natural companion. A minimal stateless `forAll` over the generation
  core is recorded as a v0.2 candidate.
- D014 recorded: the dogfood examples use a self-contained minimal builder as their
  system under test rather than depending on `provemark/content-credentials`, so a
  general test library does not couple its CI to a specific C2PA package.
- D001 reversed: `Command`, `Outcome` and `Ref` now carry PHPStan `@template`
  parameters, so a user binds them once with `@implements Command<…>` instead of
  annotating four methods per class. Porting dogfood example 1 showed the earlier
  no-templates call rested on an inverted premise — templates remove annotation
  noise, they do not add it. `TResult` also ties run()'s return to the `Outcome` the
  postcondition inspects. The example uses `@implements`; the alphabet now builds
  commands from `Gen::associative` (named keys) rather than `Gen::tuple`.
- SPEC-003 amended and re-approved (maurice, 2026-07-30): the combinator set narrowed
  to `constant`, `elements`, `map`, `associative` after auditing it against the two
  dogfood examples. `bool`, `oneOf`, `filter`, `tuple`, `vector` removed as unused
  (§4) — `filter` taking its two acceptance criteria and the re-check-while-shrinking
  footgun with it. The Problem section's claim that the suites build strings with
  `map`/`vector` was corrected; the ports falsified it. Example 1's `oneOf` folded
  into a single `elements`.
- SPEC-003 AC3 broadened (amendment): it now covers `elements`, `map` and
  `associative` — `elements` shrinks its index toward zero, which is delegation to an
  underlying `integers()`, and is the first place a `GeneratedValue` context becomes
  tangible; `constant` is its degenerate edge case. The audit had narrowed AC3 to the
  user-facing `map`/`associative`; the mechanism includes `elements`. Recorded that
  the ordering of `elements` is semantic: it shrinks toward the first element, so the
  simplest value belongs first.
- SPEC-003 AC3 implemented — `constant`, `elements`, `map`, `associative` — built on
  the covariant `Generator` amendment (D017). SPEC-003 AC1–AC3 are done; it stays
  `approved`, not `implemented`, because AC4 (sequence length) and the command-alphabet
  generator are deferred to after SPEC-001 (D018): "shorter sequences" needs sequences,
  which the alphabet builds. AC4 sharpened from "at most n" to "between 1 and n" — the
  length generates in `[1, n]` and never produces the empty sequence, which is SPEC-002's
  own candidate.
- SPEC-001 amended and re-approved (maurice, 2026-07-30): `Command`'s `TResult` made
  `@template-covariant` and `Outcome` made non-generic (D019), so a heterogeneous alphabet
  — dogfood example 2 mixes `sign` (result `null`) with `read` (result a report) — type-checks
  as `list<Command<M, S, mixed>>` under PHPStan max. The postcondition now receives a
  non-generic `Outcome` and narrows the value if it needs the type. Same defect class as
  D017, verified against a throwaway check before the amendment.
