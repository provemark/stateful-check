# ROADMAP — v0.1

Order of work. Dependencies are real and partly circular at the type level, so
the order below interleaves two specs on purpose:

- SPEC-002 needs SPEC-003, because shrinking a command's arguments requires
  generated values that carry a shrink context.
- SPEC-003's *command-alphabet* generator needs SPEC-001's `Command` type, so
  that one piece is built after SPEC-001 rather than with the rest of SPEC-003.
- SPEC-005 wires everything together and owns the seed, so it comes last.

Work one spec at a time, all five workflow steps (CLAUDE.md §1) per spec, before
starting the next. Within a spec, work one acceptance criterion at a time and
stop after each — the working protocol in CLAUDE.md §2 governs how, and it is
deliberately slow.

---

## 0. Decisions before any spec is approved

All open questions are tracked in `DECISIONS.md`. As of 2026-07-30 every one that
gates v0.1 is **decided** — D001–D014 — so the two approved specs below could be
approved. In particular the two former blockers:

- ~~**D001 — generics strategy**~~ **decided (revised):** `@template` on `Command`,
  `Outcome` and `Ref`; one `@implements` line per command class. Reversed from the
  initial no-templates call once porting example 1 showed templates remove
  annotation noise rather than adding it.
- ~~**D009 — throw or return on failure**~~ **decided:** return a `PropertyResult`.

and the rest: ~~D002~~ structured `Failure` identity; ~~D003~~ external shrinking;
~~D004~~ configurable shrink origin (default 0); ~~D005~~ minimal shrink breadth;
~~D006~~ always clone (no opt-in `Cloneable`); ~~D007~~ count budget, default 100;
~~D008~~ keep `provemark/stateful-check`; ~~D010~~ no forkable sources; ~~D011~~
postcondition may read the system; ~~D012~~ one setup closure fed by an initial
generator; ~~D013~~ stateful layer only (stateless is out of scope, Eris the
companion); ~~D014~~ self-contained SUT in the examples. See `DECISIONS.md` for
each rationale.

**SPEC-002 and SPEC-005 were kept `draft` until their turn** — both are now
`implemented`. They were approved last on purpose, because they learn the most from
the layers beneath them — the shrinker from how the generation core actually shrinks,
the entry point from how the examples actually read. The late sequencing was
deliberate, not an oversight.

## 1. Failing dogfood examples first

Before implementing anything, port the two hand-rolled suites from
`provemark/content-credentials` into `examples/` as the API you *want*, written
against the sketched interfaces. They will not run. That is the point: they are
the acceptance test for the API and the guard against building a framework
nobody needs (CLAUDE.md §5).

**These are design artefacts, not implementation.** They instantiate interfaces
that do not exist yet and are expected to be red. Writing them while the specs
are `draft` does not violate CLAUDE.md §1 step 4, which governs implementation
in `src/`. If writing one turns out to be impossible against the sketched API,
that is the finding — amend the spec.

- `examples/ImmutableBuilderExample` — pure, in-memory, model predicts the error
  boundary on blank arguments. The system under test is a **self-contained**
  minimal immutable builder (with* methods, last-write-wins, blank name →
  `build()` throws, `toArray()`), not a dependency on `content-credentials`: a
  general test library must not couple its CI to a specific C2PA package. Only the
  SUT is a stand-in; `BuilderModel` and the property structure are ported verbatim.
- `examples/ProvenanceChainExample` — HTTP-backed, `sign`/`read`, skips when the
  service is unreachable.

If expressing either one requires an abstraction not in the specs, amend the
spec — do not add the abstraction quietly.

Porting the two examples already shaped the specs: the setup amendment (D012), the
immutability limitation (documented in the README, not worked around), the
self-contained-SUT choice (D014), and — from example 2 — the combinator audit that
narrowed SPEC-003. D013 (a stateless property runner) was decided against for v0.1:
this is the stateful layer only.

## 2. SPEC-003 — generation core

First real implementation, because everything depends on it. Build in this
order, since each step is used by the next:

1. `Source` over `Random\Randomizer` + `Mt19937` (AC1), engine mode pinned to
   `MT_RAND_MT19937`. No fork — removed from scope.
2. `integers()` with shrinking toward an origin (AC2). **This is the crux.**
   Every other generator's shrinking derives from it; get it right before
   composing anything on top.
3. `constant` (degenerate: one value, no shrinking) and `elements`, which shrinks its
   index toward zero via `integers()` — so `elements` is the first case of AC3.
4. `map`, `associative` — the rest of AC3 (delegation). **AC1–AC3 done here.**

`bool`, `oneOf`, `filter`, `tuple`, `vector` were removed from scope by the
2026-07-30 combinator audit — neither dogfood suite uses them (§4).

Deferred to step 3, after SPEC-001: the command-alphabet generator (AC5) — it produces
`Command` instances, which do not exist until SPEC-001. So SPEC-003 finishes step 2 as
`approved` with AC1–AC3 implemented and AC5 pending, and became `implemented` once the
alphabet generator landed after SPEC-001. (The sequence length, once AC4 here, proved to be a
consumer's usage of `integers(1, n, origin: 1)` rather than a generation deliverable — it moved
to SPEC-005 AC9, where the sequence is drawn. D018.)

## 3. SPEC-001 — command contract and runner

Small once generation exists. Three parts carry more weight than their size
suggests:

- The execution record (AC4) is not a nicety: both SPEC-002's filtering and its
  non-determinism detection are built on it.
- `Outcome` (AC5) is what lets a command expect a failure, which dogfood example
  1 needs on its very first command.
- `Failure` (AC2) must be structured, because SPEC-002 AC1 compares failures and
  must not compare messages.

Finish by returning to SPEC-003 for the one piece deferred from step 2: the
command-alphabet generator (AC5), which needs the `Command` type defined here. That lands
SPEC-003 at `implemented`.

## 4. SPEC-002 — sequence shrinking

The reason the package exists. Build the meta-suite (`tests/Meta/`) *first* —
planted bugs with known minimal sequences (R8) — then make it pass. A shrinker
developed without the meta-suite cannot be trusted, and the meta-suite is what
makes the README's claims defensible.

Candidate families in the order given in the spec: empty sequence once, then
prefix-hold with suffix-length shrinking, then per-command arguments.

## 5. SPEC-005 — property entry point

The thing users actually call: seed in, generate, run, shrink on failure, report.
Small, but it is the package's public face, and D009 (throw or return on failure)
determines how every example reads.

Revisit here, once both dogfood examples exist — two examples judge these better
than one, and they are small enough not to guess at:
- Duplicated argument-generator wiring: one `Generator<Command>` per command type
  repeats the same argument generator (example-1 finding 3).
- `preCondition` returning constant `true` is boilerplate for precondition-free
  commands (example-1 finding 4).
- Confirm D011/AC8 (a postcondition that reads the system) is actually exercised by
  a test and not left an unused parameter in the contract — example 1 does not
  exercise it; example 2 is expected to.

## 6. Close the loop

- The two `examples/` suites from step 1 now pass, unmodified. If they needed
  changing, the API drifted from the specs — investigate before shipping.
- Traceability tables filled, all specs `implemented`.
- README limitations section checked against what was actually built (CLAUDE.md
  §7). Anything that turned out not to work is stated plainly.

## 7. After v0.1 — what has landed on `main`

The order above is v0.1's build order and is now history. Three specs have been
written and implemented since, and this section exists because a reader who opens
`ROADMAP.md` to see where the project stands should not have to read `NOTES.md` or
`CHANGELOG.md` to find out.

- **SPEC-008 — stateless property runner.** Implemented 2026-08-03, released in
  **0.2.0**. Reverses D013, which had ruled the stateless layer out of v0.1: the
  generation core turned out to carry it with no new machinery. `StatelessProperty`
  is constructor + `check()`, not a `forAll` facade (D028).
- **SPEC-009 — edge-biased generation.** Implemented 2026-08-03, released in
  **0.2.0**. Opt-in and off by default (D029), at a measured 10% (D030), so no
  recorded seed moves unless the caller asks for it.
- **SPEC-010 — value generators.** Implemented 2026-09-06, released in **0.3.0**
  (D041). `strings`, `floats`, `listsOf`, `subsetOf`, `oneOf` and optional keys on
  `associative`, each with its own origin-ward shrink — D024's condition for a string
  generator finally met, with `provemark/stateful-check-mcp` as the named consumer.

Three of its acceptance criteria were amended during implementation, each because a
criterion described a shrink family's intent rather than the sequence it produces
(R11-(a)). NOTES step 73 records the pattern; it is the thing to check first when
writing the next spec's ACs.

## What is next, and what it waits on

- **`stateful-check-mcp` SPEC-004** — the named consumer of SPEC-010. It pins `^0.2`
  and must move to `^0.3` before it can use any of this. Its AC13 carries the
  amendment D040 forced: an explicit shrink origin must be drawn from the alphabet,
  so a derived generator unions the schema `default`'s characters into the alphabet
  it passes, and records that union in its report.
- **Edge-biasing for the new generators** — deferred by D039, safe to defer because
  bias is opt-in by construction. It needs its own measurement in D030's shape, not
  just plumbing.
- **SPEC-004 (this repo), the optional Eris adapter** — still recorded, still
  unscheduled.

## Deliberately not in v0.1

- SPEC-004, the optional Eris adapter. Recorded, unscheduled.
- SPEC-007, the optional typed `assertPropertyPassed()` helper — **implemented**
  2026-08-02 (pulled forward from "after v0.1"). Amended the same day: the sketched
  Pest `toPass()` expectation could not clear PHPStan max, so a framework-agnostic
  typed helper replaces it. Convenience test-sugar over `PropertyResult`, shipped in
  `testing/` outside the PSR-4 root so the autoloaded runtime stays framework-free
  (R7 — PHPUnit stays `require-dev`).
- Forkable generation sources — removed from SPEC-003 as speculative.
- Symbolic results (R9a), parallel or scheduled interleaving (R5), a
  general-purpose generator library (SPEC-003 out of scope).
- The separate package for verifying AI-generated code — spec-to-test
  traceability enforcement, mutation-score gates, tests-untouchable-by-agent.
  That is a distinct project that will build on this one; keeping it out of this
  repo is what keeps this engine general and honest.
