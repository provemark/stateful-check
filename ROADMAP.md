# ROADMAP — v0.1

Order of work. Dependencies are real: SPEC-002 cannot be built or tested without
SPEC-003, because shrinking a command's arguments requires generated values that
carry a shrink context.

Work one spec at a time, all five workflow steps (CLAUDE.md §1) per spec, before
starting the next.

---

## 0. Decisions before any spec is approved

Two blockers are marked in the specs and must be answered by the maintainer, not
guessed by an implementer. Both shape every signature written afterwards.

- **Generics strategy** (SPEC-001, open question). `mixed` plus PHPStan
  `@template` annotations, or `mixed` plus per-project docblocks? Decide before
  the `Command` contract is written.
- **"Same reason class"** (SPEC-002 AC1, open question). What counts as failing
  for the same reason after shrinking? Exception class plus failing command type
  is the likely answer; message comparison is too strict. AC1 cannot be tested
  until this is defined, and AC1 is the shrinker's central postcondition.

One architectural question is worth settling at the same time, though a
recommendation is on file:

- **Integrated versus external shrinking** (SPEC-003, open question).
  Recommendation: keep external. Record the choice in `docs/prior-art.md`.

## 1. Failing dogfood examples first

Before implementing anything, port the two hand-rolled suites from
`provemark/content-credentials` into `examples/` as the API you *want*, written
against the sketched interfaces. They will not run. That is the point: they are
the acceptance test for the API and the guard against building a framework
nobody needs (CLAUDE.md §4).

- `examples/ImmutableBuilderExample` — pure, in-memory, model predicts the error
  boundary on blank arguments.
- `examples/ProvenanceChainExample` — HTTP-backed, `sign`/`read`, skips when the
  service is unreachable.

If expressing either one requires an abstraction not in the specs, amend the
spec — do not add the abstraction quietly.

## 2. SPEC-003 — generation core

First real implementation, because everything depends on it. Build in this
order, since each step is used by the next:

1. `Source` over `Random\Randomizer` + `Mt19937`, with fork (AC1, AC2).
2. `integers()` with shrinking toward an origin (AC3). **This is the crux.**
   Every other generator's shrinking derives from it; get it right before
   composing anything on top.
3. `constant`, `elements`, `bool` — direct uses of the above.
4. `map`, `tuple`, `associative`, `vector` — delegation (AC5).
5. `filter` — last, because it is the footgun: the predicate must be re-checked
   during shrinking (AC4) and unsatisfiable filters must fail loudly (AC7).
6. Sequence-length generator (AC6).

## 3. SPEC-001 — command contract and runner

Small once generation exists. The execution record (AC4) is not a nicety: both
SPEC-002's filtering and its non-determinism detection are built on it.

## 4. SPEC-002 — sequence shrinking

The reason the package exists. Build the meta-suite (`tests/Meta/`) *first* —
planted bugs with known minimal sequences (R8) — then make it pass. A shrinker
developed without the meta-suite cannot be trusted, and the meta-suite is what
makes the README's claims defensible.

Candidate families in the order given in the spec: empty sequence once, then
prefix-hold with suffix-length shrinking, then per-command arguments.

## 5. Close the loop

- The two `examples/` suites from step 1 now pass, unmodified. If they needed
  changing, the API drifted from the specs — investigate before shipping.
- Traceability tables filled, all specs `implemented`.
- README limitations section checked against what was actually built (CLAUDE.md
  §6). Anything that turned out not to work is stated plainly.

## Deliberately not in v0.1

- SPEC-004, the optional Eris adapter. Recorded, unscheduled.
- Symbolic results (R9a), parallel or scheduled interleaving (R5), a
  general-purpose generator library (SPEC-003 out of scope).
- The separate package for verifying AI-generated code — spec-to-test
  traceability enforcement, mutation-score gates, tests-untouchable-by-agent.
  That is a distinct project that will build on this one; keeping it out of this
  repo is what keeps this engine general and honest.
