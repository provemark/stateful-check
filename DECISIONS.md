# DECISIONS

Answers to the open questions in the specs. One entry per decision, newest at the
bottom, never deleted.

This exists so a decision is made once. Without it the same question resurfaces
three sessions later, gets answered differently, and the codebase quietly ends up
with two conflicting assumptions in it. It is also the record of *why* — which is
the part that is impossible to reconstruct afterwards and the part that matters
when someone asks whether a claim in the README is justified.

**Only the maintainer decides.** An implementer that reaches an open question
stops and asks (CLAUDE.md §2). Nothing here may be filled in by inference from
the code.

Entry format:

```
## D### — <question in one line>
Spec:      SPEC-### <open question name>
Status:    open | decided
Decided:   <name, date>
Decision:  <what was chosen>
Because:   <the reasoning, including what was given up>
Revisit if: <the condition that would reopen this>
```

---

## D001 — Generics strategy for the Command contract

Spec: SPEC-001, "Generics"
Status: **decided (revised)**
Decided: maurice, 2026-07-30
Decision: Use PHPStan `@template` on `Command` — `TModel`, `TSut`, `TResult` — with
`@template` on `Outcome` and `Ref` too. A user binds them once with `@implements
Command<MyModel, MySut, MyResult>` above the class; PHPStan then types all four
methods, so no per-method annotation is needed. A user who wants none of it may
still bind `mixed`.
Supersedes: the initial 2026-07-30 decision (plain `mixed`, no `@template`), which
rested on a wrong assumption — that templates would add annotation noise to every
method of every user command class.
Because: the opposite is true, and porting dogfood example 1 proved it. *Without*
templates a user writes four `@param`/`@return` blocks per command class to satisfy
PHPStan max; *with* templates they write one `@implements` line. Templates remove
the noise, they do not add it. `TResult` additionally ties run()'s return to the
`Outcome` the postcondition inspects (the observation contract, finding 5 from the
example port), though it cannot force that value to equal the model's own
observable — the contract carries no such type. This correction belongs in the log:
the earlier call was reasoned from a factually inverted premise, not a change of
taste.
Revisit if: PHPStan's template support for this shape proves to have gaps that make
the annotations misleading rather than helpful.

## D002 — What "fails for the same reason" means after shrinking

Spec: SPEC-002, AC1
Status: **decided**
Decided: maurice, 2026-07-29
Decision: Failure identity is `FailureKind` + failing command class + exception
class (when the kind is an exception), compared via `Failure::sameKindAs()`.
Messages are not compared.
Because: comparing messages is too strict — a shrunk sequence legitimately
produces different numbers in them. Comparing nothing is too weak: the shrinker
could reduce toward a *different* bug and still claim success, which would
undermine R2. Two failure kinds must be covered, since a postcondition failure
carries no exception at all: `PostconditionFalse` and `UnexpectedException`.
Consequence: SPEC-001 was amended in the same pass to carry a structured
`Failure` instead of a free-text `?string $reason`. A SPEC-002 decision forced a
SPEC-001 data shape; that coupling is now explicit in both specs.
Revisit if: a third failure kind appears (for example a timeout), or if command
classes turn out not to be distinct enough in practice — a generic
`CallCommand` reused with different arguments would collapse distinct bugs into
one identity.

## D003 — Integrated versus external shrinking

Spec: SPEC-003, "Integrated versus external shrinking"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: External shrinking, as recommended. Recorded in `docs/prior-art.md` as a
deliberate divergence from fast-check, not an inherited constraint.
Because: it is testable in isolation, SPEC-002 is coherent as written, and the
advantage of integrated shrinking weighs lightly here — in our case the sequence
structure dominates, not the values.
Revisit if: per-command argument shrinking turns out not to work well enough in
practice without generator steering.

## D004 — Shrink origin: fixed at zero or configurable

Spec: SPEC-003, "Shrink origin"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Configurable origin, default 0.
Because: shrinking a timestamp toward zero is meaningless, and the cost of making
the origin configurable is negligible.
Revisit if: —

## D005 — Shrink breadth: how many candidates per value

Spec: SPEC-003, "Shrink breadth"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Minimal breadth — binary reduction toward the origin plus the origin
itself, nothing more.
Because: measuring against the meta-suite is cheaper than guessing a wider
strategy up front.
Revisit if: the meta-suite identifies a minimum this cannot reach.

## D006 — Cloning: opt-in interface or always clone

Spec: SPEC-001, "Cloning"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Always clone; remove the opt-in `Cloneable` interface. The shrinker
clones every command (shallow `clone`) before each candidate. A command that holds
an object it must not share implements `__clone`.
Because: opt-in fails silently — a user writes a command with state, forgets the
interface, and gets unreliable shrinking with no warning, which is exactly the
failure this package is built against. Always-cloning is one line, and its cost
disappears against running the system itself. This diverges from fast-check, which
clones only when the command supports it — recorded as a deliberate divergence. A
shallow clone does not copy held objects; whoever holds one implements `__clone`.
Revisit if: —

## D007 — Budget default for shrinking

Spec: SPEC-002, "Budget default"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Budget is a count of candidate executions, not a time budget. Default
100. Document how to lower it for slow systems.
Because: a time budget breaks determinism — the same seed shrinks less far on a
slow machine, making the counterexample machine-dependent. That is unacceptable
for a package whose core promise is reproducibility.
Revisit if: —

## D009 — Does the entry point throw or return on failure?

Spec: SPEC-005, "Does the entry point throw or return"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Return a `PropertyResult`; do not throw. The SPEC-005 sketch already
assumes this.
Because: returning is framework-agnostic and composable, and the boilerplate is a
single `expect()` line. Throwing would make an exception type the package's public
failure channel and couple it to test-framework conventions.
Revisit if: —

## D010 — Removed: forkable generation sources

Spec: SPEC-003 (former AC2)
Status: **decided — feature removed**
Decided: maurice, 2026-07-29
Decision: `Source::fork()` dropped from SPEC-003. Sources are sequential only.
Because: nothing in the package uses it. Generation walks one source in order,
and shrinking reuses captured contexts rather than regenerating, so no
independent sub-stream is needed. Keeping it would also have obliged us to verify
`Randomizer` clone semantics — an unverified assumption carried for no gain.
First application of CLAUDE.md §4 against our own draft rather than an incoming
request.
Confirmed 2026-07-29: `Randomizer` is not cloneable at all — it throws
`Error: Trying to clone an uncloneable object`. The engine is cloneable and a
cloned engine continues the same stream, so branching remains possible if it is
ever needed, but only one level down.
Revisit if: a generation strategy is ever specced that needs branching, for
example generating independent parallel sessions for explicit interleaving. Build
it on the engine, not the Randomizer.

## D011 — May a postcondition observe the system under test?

Spec: SPEC-001, AC8
Status: **decided**
Decided: maurice, 2026-07-29
Decision: yes. `postCondition(mixed $model, mixed $sut, Outcome $outcome): bool`
receives the same handle the command ran against. Read-only by convention, and
anything that may throw stays inside `run()`.
Because: the omission was accidental. The signature was derived from the
hand-rolled dogfood suite, where the command held the system as its own property
and the assertions reached it directly — that access was lost in the
abstraction, not given up. fast-check's `run(model, real)` has the same access.
Without it a postcondition can only inspect one command's return value, which is
too narrow for "does the system now match the model".
Cost accepted: it becomes easy to write an expensive postcondition — over HTTP an
extra read per command doubles the traffic. Documented as a discipline rather
than prevented by the type system.
Revisit if: expensive or non-deterministic postconditions turn out to be a
recurring problem in practice, in which case the answer is guidance or a
cheap-read helper, not removing the parameter.

## D008 — Vendor and package name

Spec: CLAUDE.md header
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Keep `provemark/stateful-check`, namespace `Provemark\StatefulCheck\`.
Because: the maintainer chooses `provemark` as the org for v0.1.
Revisit if: —

## D012 — One setup closure instead of separate model and system factories

Spec: SPEC-005, "model factory / system factory"
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Replace the two nullary `model` and `system` closures with a single
`setup` closure returning both — a `Setup(model, system)` — fed by an optional
`initial` generator drawn once per sequence. Modelled on fast-check's
ModelRunSetup. SPEC-005's scope, sketch and usage are amended.
Because: the nullary closures could not thread a per-sequence generated initial
state (a `MediaType`, a tenant id) into model and system consistently — porting
dogfood example 1 could only hardcode one `MediaType` or wrap the whole property
in an outer loop. One setup fed by a generator fixes that and removes the
model/system asymmetry in the same move.
Revisit if: a use case needs the initial state itself to shrink; the amendment
draws it per sequence but does not reduce it (SPEC-005 open question).

## D013 — Does the package need a stateless property runner too?

Spec: SPEC-005 (scope boundary); surfaced while porting dogfood example 1
Status: **decided**
Decided: maurice, 2026-07-30
Decision: Option (b). Position the package explicitly as the *stateful* layer only.
Stateless property testing is out of scope for v0.1; the README says so and names
Eris as the natural companion. A minimal stateless `forAll` over the generation
core (option (a)) is recorded as the natural v0.2 candidate, not built now.
Because: scope discipline (CLAUDE.md §3–§4) is explicit that nothing goes in that
the two dogfood *stateful* suites do not need, and a stateless runner is a new
surface with its own spec, ACs and shrinking semantics. The finding still has real
weight — the dogfood file itself contains stateless properties we cannot express —
so it is named as a future direction rather than dismissed. What was given up: a
user with both kinds of property needs a second library for now.
Revisit if: the stateless gap is felt often enough in practice to justify a spec,
in which case build (a) on the owned generation core, which already gives seeded
generation and value-level shrinking for free.

## D014 — The dogfood examples' system under test is self-contained

Spec: ROADMAP step 1 (examples); surfaced while porting dogfood example 1
Status: **decided**
Decided: maurice, 2026-07-30
Decision: `examples/` ships a minimal, self-contained immutable builder as the
system under test — the same shape as the real one (with* methods, last-write-wins,
blank name → `build()` throws, `toArray()`) — rather than depending on
`provemark/content-credentials`, even as a dev dependency. `BuilderModel` and the
property structure are ported verbatim; only the SUT is a stand-in.
Because: a general-purpose test library must not couple its CI to a specific C2PA
package's release cycle, and a dev dependency on it would break for anyone cloning
the repo who does not have that package. The fidelity loss is confined to the SUT,
which is deliberately kept the same shape so the port stays faithful where it
matters — the model and the property.
Revisit if: the stand-in ever drifts from the real builder's observable contract
in a way that hides a difference the example is meant to demonstrate.

## D015 — Integer shrink origin: clamp an implicit default, throw an explicit out-of-range value

Spec: SPEC-003, `integers(int $min, int $max, ?int $origin = null)` (AC2)
Status: **decided**
Decided: maurice, 2026-07-30
Decision: The origin parameter is `?int $origin = null`, and the two cases are
treated differently — this distinction is the decision:
  - `null` (not given): the effective origin is `0` clamped into `[$min, $max]`,
    with no fuss. So `integers(10, 20)` shrinks toward `10`.
  - an explicit value inside `[$min, $max]`: used as given.
  - an explicit value outside `[$min, $max]`: throws at construction, naming the
    origin and the bounds.
Because: an origin that is *implicit* — the default `0` leaking through
`integers(10, 20)` — must clamp, because throwing would break the common case for
no reason. But an origin that is *explicitly* passed out of range is almost
certainly a caller error, and silently relocating it hides the mistake. The
nullable parameter is what lets the contract tell "the caller said nothing" apart
from "the caller asked for something impossible", and answer each correctly.
Revisit if: a real need appears to pass an out-of-range origin deliberately (none
is known), in which case the throw becomes a clamp with a documented rationale.
