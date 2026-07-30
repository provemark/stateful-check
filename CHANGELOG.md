# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

- `Source::fork()` from SPEC-003. Unused, and it carried an unverified assumption
  about `Randomizer` clone semantics (D010).
- The opt-in `Cloneable` interface from SPEC-001. Every command is now
  shallow-cloned unconditionally before each shrink candidate; a command holding
  an object it must not share implements `__clone` (D006). Opt-in fails silently,
  which is exactly what this package exists to prevent. Diverges from fast-check,
  which clones only when the command supports it.

### Changed

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
