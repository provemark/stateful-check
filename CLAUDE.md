# CLAUDE.md — stateful-check

Project instructions, tracked in the repo. §3's R1–R10 are architecture rules that
follow from prior-art research and from two real defects (D017, D019); the rest of the
codebase does not read without them, so this file is not local scratch.

**Package:** `provemark/stateful-check` — namespace `Provemark\StatefulCheck\`
Model-based (stateful) property testing for PHP: generate command sequences,
run them against a system and a shadow model, shrink failures to a minimal
counterexample.

> Vendor/namespace is a single find-replace if you decide against `provemark`.
> Do it before the first tag, not after.

---

## 1. Workflow — five mandatory steps

No implementation code exists before an approved spec. In order, every time:

1. **Spec.** Write or amend `specs/SPEC-###-<slug>.md` from `specs/TEMPLATE.md`.
   Status starts `draft`. Do not write implementation code while `draft`.
2. **Approval.** Maintainer (maurice) approves explicitly. Status → `approved`.
   Record name + date in the header table. Any open question the spec marks as a
   blocker must be answered in `DECISIONS.md` first.
3. **Tests first.** Write the Pest tests for every acceptance criterion, each
   tagged `->group('SPEC-###')`. They must fail for the right reason before any
   implementation exists.
4. **Implement.** Smallest change that makes the ACs pass. No speculative
   generality, no features the specs do not require.
5. **Traceability.** Fill the spec's traceability table (AC → test → source).
   Status → `implemented`. Run `composer check`; it must be green.

Amendments: only the Traceability section of an `approved` spec may change
without re-approval. Everything else needs a proposed amendment or a new spec
that supersedes.

## 2. Working protocol — how implementation proceeds

Implementation is deliberately slow. The point is not to produce the package
quickly; it is that the maintainer understands every design decision in it, and
can defend the claims it makes. A correct package he cannot explain is a failure.

**One acceptance criterion at a time.** Never batch. The unit of work is: one AC
→ one failing test → the smallest implementation that passes it → stop and
report. Not one spec, not one class. One AC.

**Explain before writing.** At the start of each step, state in plain language
what is about to be built, which AC it satisfies, what the approach is, and what
the alternatives were. Then wait for the maintainer to say go. Do not write code
in the same turn as the proposal.

**Prove the test fails first, and for the right reason.** Show the actual failure
output before implementing. A test that passes before the implementation exists
is testing nothing, and this is the most common way a test suite quietly becomes
decorative.

**Stop and report after every step.** Say what changed, which command proves it
(and its output), what is now green, and what the next step would be. Then stop.
Do not continue to the next AC unprompted.

**Never guess an open question.** The specs mark open questions explicitly, some
as blockers. If a step touches one, stop and ask. Guessing produces plausible
code built on an unmade decision, which is the hardest kind of mistake to find
later. Record every answer in `DECISIONS.md`.

**Never weaken a test to make code pass.** If a test appears wrong, stop and say
so; do not adjust it. Tests and implementation must not change in the same step.
This rule exists because relaxing an assertion is the path of least resistance
and it silently destroys the value of the suite.

**Never add an abstraction the spec does not require.** If something seems
necessary but is not specced, stop and propose a spec amendment. Interfaces,
base classes, config options and "we'll need this later" hooks all fall under
this.

**Size check.** If more than roughly fifty lines are needed before a test goes
green, the step was too large. Stop, split it, and say so.

**Register.** Explanations to the maintainer are in Dutch; code, comments,
commit messages, specs and documentation are in English. Explain the *why* and
name the trade-off, not just what was typed. Flag anything uncertain rather than
presenting a guess with confidence — including uncertainty about whether an
approach is the right one.

**Commits.** One commit per AC. Message references the spec and criterion, e.g.
`SPEC-003 AC3: integer shrinking terminates at the origin`. Small commits are
what make it possible to back out a wrong turn without losing the rest.

**At the start of every session**, read in this order: `CLAUDE.md`,
`ROADMAP.md`, `DECISIONS.md`, `NOTES.md`, and the spec currently being worked.
Then state where the work stands and what the next single step is — and wait.

## 3. Hard domain rules

Derived from prior art (`docs/prior-art.md`) and from what already went wrong in
this space. Violating these produces a tool that lies, which is worse than no
tool. Treat them as invariants of the codebase, not preferences.

**R1 — Shrinking operates on the commands that actually ran.**
A shortened sequence cannot become ill-formed: a command whose precondition no
longer holds is skipped by the runner (SPEC-001 AC3), so the candidate stays a
legal program with fewer executed commands. Shrinking therefore filters to the
executed subset rather than re-validating candidates against the model. This is
sound only while commands are independent (R9a). If symbolic results are ever
added, this rule and SPEC-002 must be revisited together — that is the single
change that would force re-validation back in.

**R2 — The shrinker must never return a passing sequence.**
A returned counterexample must still fail. This is the shrinker's central
postcondition and must be asserted in the meta-suite, not assumed.

**R3 — The result is a documented local minimum, not a global one.**
"No single reduction step still fails" is the guarantee. Never claim minimality
beyond that, in docs, README, or error output.

**R4 — Shrinking requires determinism, and detects its absence.**
The same sequence must produce the same verdict *and the same set of executed
commands*. Divergence between the recorded execution path and an actual replay
is the cheap, reliable signal that the system is non-deterministic: detect it
there and abort with a clear message, rather than reporting a counterexample
derived from unstable runs.

**R5 — Never claim parallel execution or race detection.**
PHP is share-nothing and request-scoped, and v0.1 is strictly sequential. Note
that fast-check achieves race detection in single-threaded JavaScript via a
deterministic scheduler over async operations; whether an analogous approach is
possible in PHP with Fibers is an open research question (`docs/prior-art.md`),
not a promise. Until it is answered and specced, the package claims sequential
testing only.

**R6 — The model is the oracle, so it must stay trivially verifiable.**
If the model needs the complexity of the system, the bug is written twice. Model
only what the system under test can actually expose; modelling unobservable
state produces assertions that quietly pass.

**R7 — No runtime dependencies.**
The package owns its generation (SPEC-003), built on PHP 8.2's Random extension.
`require` contains PHP and nothing else. This is not purity for its own sake: an
earlier draft depended on Eris and carried two blockers that could have collapsed
the design late (whether a seed can be threaded through it, and whether it can
shrink a value outside its own `forAll` loop). Owning generation removes both,
and a testing tool with no dependencies is materially easier to adopt.

Eris support, if ever built, is an optional adapter behind the same `Generator`
interface (SPEC-004), declared under `suggest`. It may not be used by the
package's own suites, examples or docs. Never fork it; never depend on an
unmerged PR.

**R8 — Every shrinking behaviour needs a planted-bug meta-test.**
`tests/Meta/` contains systems with deliberately planted bugs and asserts the
exact minimal sequence the shrinker returns. A shrinker that merely does not
crash is not tested.

**R9 — Commands are reused across shrink candidates.**
The shrinker replays the same `Command` objects many times. Any state a command
carries leaks between candidates and destroys reproducibility.

- **R9a — Commands must be independent.** A command may not depend on the return
  value of an earlier command. v0.1 has no symbolic results; this is a deliberate
  limitation (SPEC-001) and the precondition for R1.
- **R9b — Commands are always cloned; state that cannot be shallow-cloned must
  declare `__clone`.** The shrinker shallow-clones every command (plain `clone`)
  before running a candidate — always, not opt-in (D006). A shallow clone does not
  copy held objects, so a command that holds one it must not share implements
  `__clone`. There is no opt-in `Cloneable` interface: opt-in fails silently, and
  a silently-unreliable shrinker is exactly what this package exists to prevent.
  This diverges from fast-check, which clones only when the command supports it.

**R10 — A generic-typed contract is proven heterogeneous before its spec is approved.**
Before a spec that introduces a `@template` type is approved, verify statically that
a *heterogeneous* list of that type type-checks under PHPStan max — a throwaway file
holding e.g. `list<Command<M, S, mixed>> = [$a, $b]` at two different type arguments,
run through `phpstan analyse --level=max`, then deleted, never committed. This is the
"Step-18 tell" made a gate: an invariant `@template` reads fine in isolation and only
fails when something composes instances at different arguments — exactly what a
command alphabet or a generator set does. The gate is cheap and has already caught the
same defect twice, on `Generator` (D017) and `Command` (D019), each after the spec was
approved. Catch it before, not after.

## 4. Scope discipline

v0.1 is: `Command` contract, model-driven sequential runner, sequence shrinking,
PHPUnit + Pest integration. Nothing else.

Order of work is fixed in `ROADMAP.md`, and the dependency is real: SPEC-002
cannot be built or tested before SPEC-003, because shrinking a command's
arguments needs generated values that carry a shrink context.

Out of scope until separately specced: parallel or scheduled interleaving,
symbolic results, generation strategies beyond uniform command choice (fast-check
ignores bias here too — uniform is enough to be useful), targeted or
coverage-guided generation, a general-purpose generator library, database or HTTP
helpers, reporting formats.

Also deliberately out of this repo: the separate package for verifying
AI-generated code (spec-to-test traceability enforcement, mutation-score gates,
tests untouchable by the implementing agent). It will build on this engine.
Keeping it separate is what keeps this one general and its claims honest.

If a proposed abstraction is not needed to express the two existing real suites
(see §5), it does not go in.

## 5. Dogfooding

The package must be able to express, without extension, the hand-rolled stateful
tests already written in `provemark/content-credentials`:

- `tests/Unit/Property/BuilderSequencePropertyTest.php` — pure, in-memory,
  immutable builder, blank-name error boundary predicted by the model.
- `tests/Integration/Property/ProvenanceChainPropertyTest.php` — real HTTP
  service, `sign`/`read` commands, skip-when-unreachable.

Port both into `examples/` and keep them passing. They are the acceptance test
for the API, and the honest answer to "does this abstraction earn its place".

## 6. Quality gates

`composer check` must pass before any spec reaches `implemented`:

- Pest (`--group=SPEC-###` for the spec under work; full suite before done)
- PHPStan at max, no baseline, no `@phpstan-ignore` without an inline reason
- Pint (Laravel preset)
- Meta-suite green (`--group=meta`)

`declare(strict_types=1)` in every file. Classes `final` unless a spec requires
extension. Value objects `readonly`. No suppression of errors to make a gate
pass.

**Commit behind the gate.** Run the commit literally chained to a green check —
`composer check && git commit …` — so a failing check makes the commit impossible
rather than leaving vigilance to catch it. Never run the commit as a step the check
does not gate. This is a rule, not a preference: the ungated form slipped a
Pint-failing commit through twice (an `--amend` fix each time), which is the n=2 that
promotes it here — the same threshold that kept R11 off at n=1. Not a pre-commit hook,
deliberately: an invisible gate gets bypassed with `--no-verify`; a visible `&&` does not.

## 7. Writing style for docs and README

Plain, specific, and honest about limits. State what the tool cannot do in the
same breath as what it can — the limitations section is a feature, and it is the
reason a sceptical reader trusts the rest. No marketing register. No comparison
tables against tools we have not run.

## 8. Prior art is required reading

`docs/prior-art.md` records what the mature implementations do and where they
disagree. Consult it before designing anything; cite it in specs.

The blueprint for shrinking is fast-check's `CommandsArbitrary` (read
`CommandWrapper` first, then `CommandsArbitrary`). Its candidate strategy,
execution filtering and replay-path mechanism are the basis for SPEC-002.

Two deliberate divergences, both to be preserved:

- fast-check merges the model transition and the postcondition into a single
  `run(model, real)`; we keep `nextState` pure and separate (PropEr/stateful-check
  split). The pure transition is what keeps the model usable as an oracle and lets
  it be immutable. Do not "simplify" it to match fast-check without a spec.
- fast-check's shrinking is integrated into the arbitrary; ours is an external
  shrinker over a finished sequence. Originally forced by treating Eris as a black
  box, now a deliberate choice (SPEC-003 open question) because it is simpler to
  test in isolation.

Do not invent a strategy without first recording why the existing one does not
fit.
