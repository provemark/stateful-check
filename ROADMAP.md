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

**SPEC-002 and SPEC-005 stay `draft` on purpose.** They are approved only when
their turn comes, because they learn the most from the layers beneath them — the
shrinker from how the generation core actually shrinks, the entry point from how
the examples actually read. This is deliberate sequencing, not an oversight.

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
3. `constant`, `elements` — direct uses of the above.
4. `map`, `associative` — delegation (AC3).
5. Sequence-length generator (AC4).

`bool`, `oneOf`, `filter`, `tuple`, `vector` were removed from scope by the
2026-07-30 combinator audit — neither dogfood suite uses them (§4).

Deferred to step 3, after SPEC-001: the command-alphabet generator. It produces
`Command` instances, so it cannot be built before that type exists.

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
command-alphabet generator, which needs the `Command` type defined here.

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

## Deliberately not in v0.1

- SPEC-004, the optional Eris adapter. Recorded, unscheduled.
- Forkable generation sources — removed from SPEC-003 as speculative.
- Symbolic results (R9a), parallel or scheduled interleaving (R5), a
  general-purpose generator library (SPEC-003 out of scope).
- The separate package for verifying AI-generated code — spec-to-test
  traceability enforcement, mutation-score gates, tests-untouchable-by-agent.
  That is a distinct project that will build on this one; keeping it out of this
  repo is what keeps this engine general and honest.
