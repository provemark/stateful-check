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

## D016 — Integer range width must fit in a PHP int (overflow guard)

Spec: SPEC-003, `integers()` (AC2)
Status: **decided**
Decided: maurice, 2026-07-30
Decision: `integers($min, $max, ...)` throws at construction when the range width
`$max − $min` would exceed `PHP_INT_MAX` — so the full unbounded range,
`integers(PHP_INT_MIN, PHP_INT_MAX)`, is not supported in v0.1; bound the range. The
check is made WITHOUT subtracting: `$min < 0 && $max > PHP_INT_MAX + $min`.
Because: this is exactly the silent surprise the guard exists to prevent, and it
bites twice. (1) `$max − $min` on the full range silently becomes a float (~1.84e19),
so a naive width check measures nothing — hence the subtraction-free test above.
(2) Inside `shrink`, `2 ** $k` becomes a float from k = 63 and `intdiv()` then throws
a `TypeError`; with a near-`PHP_INT_MAX` gap, k reaches 63 — hence shrinking uses
repeated halving (`$step = intdiv($step, 2)`), never exponentiation. Both are
determinism breaking at the extremes, the wrong kind of surprise for a package that
promises reproducibility. Because `$v` and the origin both lie in `[$min, $max]`,
bounding the width keeps every `gap = $v − origin` within an int, so `shrink` is safe.
Revisit if: a real use needs the unbounded range, which would require an
overflow-safe midpoint instead of a width guard.

## D017 — Generator is covariant; shrink takes GeneratedValue<mixed>

Spec: SPEC-003, `Generator` interface (amendment)
Status: **decided**
Decided: maurice, 2026-07-30
Decision: `Generator` is `@template-covariant T`, and `shrink()` takes
`GeneratedValue<mixed>` rather than `GeneratedValue<T>`. T then appears only in output
positions (`generate(): GeneratedValue<T>`, `shrink(): iterable<GeneratedValue<T>>`),
which is what covariance requires. Implementations narrow the value they receive at
runtime and throw a `LogicException` on the wrong shape — the pattern already used by
`elements`, `map` and `associative`, now extended to `integers`.
Alternative rejected: keep `Generator` invariant and document heterogeneous
`associative` as an unsupported edge (option (a)). Rejected because dogfood example 1
*uses* the heterogeneous case (`['name' => …, 'version' => …]`) and only passes because
`examples/` is outside the PHPStan paths — a concealed defect, not a documented limit,
and §4 says the examples define what the contract must support.
Because: the trigger was concrete — a heterogeneous keyed record did not type-check
under PHPStan max, since `Generator<int>` is not a `Generator<mixed>` while `Generator`
is invariant. Covariance makes `Generator<int>` a `Generator<mixed>`, so a heterogeneous
set type-checks with no per-call gymnastics. The design is coherent with the already-
opaque `mixed` context (Step 12): shrink's input was never statically trustworthy about
its value's type anyway.
Revisit if: the runtime narrowing proves error-prone in practice. The widened
`shrink` parameter trades the static guarantee "you receive the right kind of value"
for a runtime check, contract-wide; if that trade turns out to hide real bugs, revisit
whether a narrower, invariant shrink with an explicit variance escape is worth the
friction.

## D018 — A generated sequence length is at least one; the empty sequence is SPEC-002's

Spec: SPEC-005, AC9 (was SPEC-003 AC4 — moved when it proved to have no SPEC-003 deliverable: the
length is `integers(1, n, origin: 1)`, a consumer's usage of a combinator, drawn where the entry
point builds the sequence, not a property of the generation core)
Status: **decided — implementation pending with SPEC-005**
Decided: maurice, 2026-07-30
Decision: The sequence-length generator generates a length in `[1, n]` and shrinks
toward 1 — never toward 0. The lower bound of 1 is a **generation** bound, not only a
shrink origin: a length of zero is never produced. That completes the division of labour
with SPEC-002: the empty sequence is SPEC-002's own first shrink candidate ("did the
commands cause the failure at all?"), a semantically distinct step, and this layer never
touches it. AC4's wording is sharpened accordingly, from "at most *n*" to "between 1 and
*n*".
Because: a length origin of 0 would be redundant with SPEC-002's empty-first candidate —
two paths to empty — and would generate degenerate empty properties. Keeping the empty
case wholly in SPEC-002, and the length in `[1, n]` here, keeps each layer doing one
thing. The reasoning is tied to SPEC-002's empty-sequence candidate, which is sharp now
and hard to reconstruct three steps on — hence recording the decision before building.
Note — a boundary that will surface at build: "at least one" is about the *generated*
length, not the number of commands in a *counterexample*. A command whose precondition
fails is skipped and drops out of the shrink representation (R1), so a shrunk
counterexample can legitimately contain **zero** executed commands even though the
generated length was ≥ 1. Consistent, but not what a reader of "at least one" expects —
recorded here and in NOTES so it is not later mistaken for a contradiction.
Revisit if: a use appears for generating the empty sequence directly (none is known;
SPEC-002 already owns the empty case).

## D019 — Command's TResult is covariant; Outcome is non-generic

Spec: SPEC-001, `Command` interface and `Outcome` (amendment)
Status: **decided**
Decided: maurice, 2026-07-30
Decision: `Command`'s `TResult` is `@template-covariant`, and `Outcome` is not generic in
its value. `TResult` then appears only in `run(): TResult`, which covariance requires; the
postcondition receives a plain `Outcome` (value `mixed`) and narrows it at runtime if it
needs the type. This is the same fix as D017, applied to the same defect class one layer up.
Alternative rejected (a): keep `TResult` invariant and let the postcondition carry
`Outcome<TResult>`. Rejected because a heterogeneous alphabet — dogfood example 2 mixes
`sign` (result `null`) with `read` (result a report) as `list<Command<M, S, mixed>>` — then
does not type-check under PHPStan max, since an invariant `Command<M, S, null>` is not a
`Command<M, S, mixed>`. Verified against a throwaway check file before the amendment:
`chkAlphabet() should return list<Command<…, mixed>> but returns array{SignChk, ReadChk}`.
Alternative rejected (b): make `Outcome` covariant instead of non-generic, keeping
`Outcome<TResult>`. Impossible: its `returned(T $value)` factory puts `T` in a parameter
position, which covariance forbids; and an invariant `Outcome<mixed>` fails on `threw()`,
which returns `Outcome<null>`. A non-generic `Outcome` sidesteps both — the value was always
`mixed` at the postcondition regardless, so a type parameter bought nothing.
Because: the trigger was concrete and identical in shape to D017 — an invariant generic
parameter blocks the heterogeneous collection the dogfood examples require (§4), and
`examples/` being outside the PHPStan paths would have concealed it. Covariance on the one
output-only parameter restores the subtyping the alphabet needs.
Revisit if: the runtime narrowing in postconditions proves error-prone, as with D017. The
cost is the same trade, and sharper here: `run()`'s result type no longer flows into the
observation contract statically — the postcondition sees `mixed` and must narrow. If a
symbolic-results design ever ties `run()`'s output to later commands (R9a), revisit whether
that observation contract should be typed again.

## D020 — Failure.exceptionClass is the concrete thrown class; failure identity is exact-class equality

Spec: SPEC-001, AC6 (`Failure.exceptionClass`); consumed by SPEC-002 AC1
Status: **decided**
Decided: maurice, 2026-07-30
Decision: when `run()` throws, `Failure.exceptionClass` is the concrete class of the thrown
throwable (`$exception::class`), not a generalisation to a parent type. This field is part of the
failure identity SPEC-002 AC1 compares two failures on ("did the shrunk sequence fail for the same
reason"), and that comparison is exact-class equality — `RuntimeException` and a
`RuntimeException` subclass are different failure kinds.
Alternative rejected: record (or compare by) a parent class, i.e. an `instanceof`-style identity
where a subclass matches its parent. Rejected as the default because it is a genuinely different
semantics — it would call two failures throwing different concrete classes "the same" — and it is
not needed by the two dogfood examples. It remains a deliberate future choice if a use appears,
not something to back into.
Because: the concrete class is the exact, predictable thing that was thrown; it is the simplest
identity and the one a reader expects. Generalising up front bakes a comparison policy into the
data, where exact-class equality keeps the policy in one place (SPEC-002 AC1) and open to change.
Revisit if: a system under test throws **dynamically composed or anonymous exception classes** —
then the class name differs between two runs of the *same* defect (e.g. an anonymous
`class@anonymous…` name, or a per-instance generated class), and SPEC-002 AC1 would see two
identical defects as different failures, defeating same-reason comparison. That concrete failure
mode, not a taste preference, is what would force an `instanceof`-style or fingerprint-based
identity.

## D021 — Retracted: the GeneratedValue<Command> wrapper's command/context pairing

Spec: SPEC-002 (cloning between candidates, R9b); the alphabet generator's context (SPEC-003 AC5)
Status: **retracted, 2026-07-31 — the whole layer it governed is gone**
Retracted: maurice, 2026-07-31. D021 existed only to make family 3's use of the wrapper's context
safe ("family 3 re-derives argument reductions from the context's integer value… does not read the
wrapper's command"). With the argument family deferred out of SPEC-002 to a later spec, nothing reads
the context: the shrinker takes bare `list<Command>`, and the `$alphabet` parameter, the
`GeneratedValue` wrapper as the shrinker's input, and this decision all go together (the same shape as
D010/`fork()` — an abstraction added on an expectation that did not arrive, removed when the evidence
stayed absent; the second time that has happened, covered by §4, not a new rule).

Recorded separately because it is informative on its own: **the code never conformed to D021.** D021
says a candidate clones the command "into a **new wrapper** carrying the shallow clone and the same,
unchanged context"; the shrinker never built such a wrapper — `replay()` clones the command out and
runs it bare, discarding the wrapper. So this was a decision written and never implemented, surfaced
only at the AC7 traceability check. That is the same class as `Failure::$reason` (a documented field
no channel could supply) — the **third** time "the spec describes something that does not exist" has
shown up, and now the pattern worth naming: a decision's prose can drift from the code with nothing
failing, and only a traceability pass catches it.

The original decision, now void, is kept below for the record.

Decision: a `GeneratedValue<Command>` pairs a command with the context that produced it in one
`generate()`. Running a candidate clones the command out of the wrapper (R9b) into a **new wrapper**
carrying the shallow clone and the **same, unchanged context**; the context is shrink data, never
executed, so it is never cloned. The shrinker never re-pairs a command with a foreign context. On
any divergence between the two, the **command is authoritative** — it is what runs and what the
counterexample reports as having failed; the context is only a shrink aid, since family 3 re-derives
argument reductions from the context's integer value (through `map`), not from the wrapper's command.
Alternative rejected (a): clone the context alongside the command. Rejected — the context is opaque,
immutable shrink data (Step 12); cloning it buys nothing and invites the two copies to drift apart.
Alternative rejected (b): do not clone at all, reuse the command across candidates. Rejected by
R9b/D006 — a command carrying mutable state would leak between candidates and silently corrupt the
run under the shrinker.
Because: shallow clone + shared context is the minimum that satisfies R9b without touching the opaque
context, and it is safe precisely because command-identity and context serve different consumers (run
vs shrink), and family 3 does not read the wrapper's command.
Revisit if / guard: the safety rests on command and context staying a matched pair. If a wrapper is
ever built pairing a command with a foreign context, family 3 yields candidates that are reductions
of a *different* command — a silent inconsistency (nothing throws, the counterexample is merely
wrong), the same class the LogicException narrowing guards catch elsewhere. This is a
shrinker-internal invariant, so its guard is a **unit test** at SPEC-002 build (assert a family-3
candidate's command corresponds to its context's provenance), **not** a planted-system meta case: the
meta-suite tests system bugs, not wrapper consistency.

## D022 — The empty-sequence shrink probe is removed; it cannot fail in this model

Spec: SPEC-002 (was AC5, the empty candidate)
Status: **decided**
Decided: maurice, 2026-07-30
Decision: the shrinker does not try the empty sequence as a candidate. In this model the empty
sequence cannot fail — SPEC-001's runner checks nothing at zero commands, so `run([])` always
returns `passed: true` — so probing it is a guaranteed-useless execution (exactly what AC9's budget
exists to avoid), and its "shrinking is complete, counterexample is empty" branch is unreachable
dead code. The question the probe asks — did the commands cause the failure? — is already answered
by construction: the shrinker only ever receives a *failing* `RunResult`, and a run can only fail
through a command's postcondition returning false or a command's `run()` throwing. There is no
command-independent failure to find.
Alternative rejected: implement the probe defensively (run the empty sequence, accept it if it
fails). Rejected because the branch is not merely unlikely but *impossible* to trigger here, so it
is dead code that R8 could never give a planted-bug meta-test — an untestable branch is worse than
an absent one. fast-check keeps the probe because its property can fail outside the commands; ours
cannot.
Because: removing it is smaller, faster (one fewer execution per shrink), and honest about what the
model can do. AC5 is left as a numbered redirect rather than renumbered, and `shrunkOnce` — whose
only purpose was trying the probe exactly once — goes with it.
Revisit if: invariants are ever checked *before the first command* (a start-of-run postcondition,
or setup/initial-state failures surfaced as run failures). Then the empty sequence could fail, the
probe becomes reachable, and it returns with a meta-test.

## D023 — Argument shrinking: command/context coupling by construction, no runtime guard

Spec: SPEC-006 (argument shrinking); successor to the retracted D021
Status: **decided**
Decided: maurice, 2026-08-01
Decision: In SPEC-006's argument family, each candidate at a position is a whole
`GeneratedValue<Command>` taken directly from `Generator::shrink()`, which produces the command and
its context together. The shrinker never re-pairs a command with a foreign context and never
constructs a command from a context it did not arrive with. Command/context coupling therefore holds
**by construction**, and there is **no runtime matched-pair guard**.
Alternative rejected: the retracted D021's guard (assert a candidate's command matches its context's
provenance/class). Rejected on three counts: (1) it fires on legitimate class-changing shrinks —
`Gen::map(fn ($n) => $n > 5 ? new Big($n) : new Small($n), integers(0, 10))` shrinks one branch across
command classes, so a class check aborts valid shrinks; (2) it does not catch the genuine desync
(right class, wrong context); (3) that desync is designed away by the by-construction coupling. A guard
that fires on the legitimate and misses the real is D021's own failure mode — an invariant that sounds
protective but guards nothing, which is why D021 "was never even implemented".
Because: the safe pairing D021 tried to enforce at runtime is instead guaranteed by never separating
command from context — the family yields whole `shrink()` values. The correct minimum is no guard,
recorded honestly so the retracted guard is not reintroduced as "protection". This removes only the
*pair* guard: the length-mismatch guard (SPEC-006 AC5 case b — `count(wrappers)` must equal the run's
executed count, a `LogicException` otherwise) stays. That is input validation, not pair-guarding, and
the same class as SPEC-002's existing `count(executed) === count(failing)` check; "no runtime guard"
means no *coupling* guard, not that AC5's malformed-input guard is dropped.
Revisit if: a future family constructs commands itself instead of taking whole `GeneratedValue`s from
`shrink()` — e.g. branch-choice shrinking (SPEC-003 AC5, out of scope) that assembles a command from a
foreign context — reintroducing a path where command and context can diverge. Then the by-construction
argument no longer holds and an explicit guard is needed again.

## D024 — No string generator yet; it arrives only with a free-text consumer, and never without its shrink

Spec: generation (SPEC-003); the argument family (SPEC-006) is why the shrink is now inseparable
Status: **decided**
Decided: maurice, 2026-08-01
Decision: The generator set stays `integers`, `constant`, `elements`, `map`, `associative`, `alphabet`.
No general **string** generator is added now. `elements([...])` already covers a *fixed* set of strings
(enum-like choices — the dogfood's agent names); what is absent is a generator for *free* strings —
arbitrary characters, lengths, unicode — which you would want only when a command argument is a genuinely
open text field (a parser, a name/input field). It is not added because no case needs it (§4): this is a
**stateful** tester, its generators exist to make command arguments, and rich value generation is
deliberately Eris's job (README; R7 — owned generation, no runtime deps, only what the sequences need).
The crucial part, in the decision not an aside: **since SPEC-006 a string generator is inseparable from
its `shrink()`.** A `strings()` without a shrink that reduces length *and* simplifies characters toward an
origin (the empty string, or a single simple char) would drop an un-shrinkable `string("…9999 chars…")`
into a counterexample — reintroducing exactly the boundary SPEC-006 just removed for `integers`. So the
work is not "add a generator" but "add a generator **and** its origin-ward shrink"; the shrink is the real
cost and must be built *with* it, never deferred. A shrink-less string generator would be worse than its
absence, because it silently degrades the SPEC-006 guarantee for string arguments.
Alternative rejected: add it now for completeness/maturity. Rejected — speculative generality (§4), the
same test the 2026-07-30 combinator audit applied.
Because: the generator set earns its place against the two dogfood suites, not against a feature checklist.
Note — this is a *different* case from the audit's removals: `bool`, `oneOf`, `filter`, `tuple`, `vector`
were *removed* (they existed and were audited out — see SPEC-003's scope/out-of-scope, the single place
recording "which combinators do not exist and why"). The string generator **never existed**; this entry
records a deliberate non-addition, not a removal, which is why it lives here and not there.
Revisit if: a concrete case appears with a **free text field as a command argument** — a parser command, a
name/input field, or the separate AI-generated-code verification package that will build on this engine.
Then add `strings()` **with** its origin-ward `shrink()`, via a spec, with that case as the named consumer.

## D025 — The stateless runner takes a single generator, not a variadic forAll

Spec: SPEC-008, OQ1
Status: **decided**
Decided: maurice, 2026-08-03
Decision: `StatelessProperty` (renamed from the sketch's `Property`, 2026-08-03)
takes one `Generator<T>` and one `callable(T): bool`. The
multi-argument case (`forAll($a, $b, …)`) is expressed by composing a single
`Gen::associative([...])` or `Gen::map(...)` — never a variadic API.
Because: it is the minimum that expresses the dogfood stateless properties —
commutativity needs name *and* version, which `Gen::associative(['name' => …,
'version' => …])` carries as one value — and it mirrors exactly how the stateful
side already builds a multi-argument command (SPEC-003, Steps 8–9). Crucially, no
shrinking capability is lost: `associative` shrinks component-by-component (Step
17), which is the same reduction a variadic `forAll` over independent generators
would give, so the composed form is not a weaker substitute — it is equivalent
where it matters. A variadic API is therefore speculative generality (§4), and it
is additive later without a break if a real need appears.
Revisit if: a suite needs per-argument shrinking that component-wise `associative`
demonstrably cannot express (none is known, and the equivalence above suggests none
will).

## D026 — Stateless non-determinism is detected by re-checking the final counterexample once

Spec: SPEC-008, OQ2
Status: **decided**
Decided: maurice, 2026-08-03
Decision: after shrinking settles on a counterexample, the runner re-runs the
predicate **once** on that value. If the verdict flips (the value now passes), the
result reports a non-determinism qualification instead of a clean counterexample,
and does not throw.
Because: R4 (determinism, and detect its absence) is a core promise, but the
stateful R4 detector — replay-path divergence (SPEC-002 AC8) — has no analogue
here: the stateless runner has no execution path, only the predicate's verdict on a
value. Verdict-stability on the one value that would be *reported* is the available
signal, and a value whose verdict flips is precisely a counterexample that would not
reproduce — worse to report clean than to qualify. The check is deliberately
minimal: it does not re-check every draw or every shrink step (that would roughly
double the run); it guards only the reported value, where instability misleads a
user most. This is the qualification pattern SPEC-005 AC5 already uses, not a throw.
Revisit if: non-deterministic predicates prove common enough that per-draw detection
earns its cost, or a stronger guarantee (re-check each accepted shrink candidate) is
wanted — both are strictly-more-expensive supersets of this one.

## D027 — The stateless result is a separate PropertyValueResult, not a reused PropertyResult

Spec: SPEC-008, OQ3 (delegated to the implementer; recorded as maurice's by delegation, 2026-08-03)
Status: **decided**
Decided: maurice (delegated), 2026-08-03
Decision: a separate `final` `readonly` `PropertyValueResult<T>`; SPEC-005's
`PropertyResult` is not overloaded to carry both. The knock-on — SPEC-007's
`assertPropertyPassed()` is typed to `PropertyResult` — is resolved by a **sibling**
assertion helper for the value result, **not** a shared `PropertyResultInterface`;
that choice is settled at SPEC-008 implementation, not now.
Because: `PropertyResult` carries a command list, a drawn initial state, and a
runner `Failure` — none of which exist for a single-value property. Reusing it forces
those three fields nullable and vestigial for the stateless case, which is exactly
the trap SPEC-005's 2026-07-31 scope-trim amendment named: an unused field in a
public result object cannot be removed without a breaking change. A separate object
keeps every field always-meaningful. A shared interface to let one assertion helper
serve both is an abstraction §4 forbids until two concrete result types exist to
justify it — after this spec they will, so the interface (if ever wanted) becomes a
later, evidence-backed move, not a speculative one now; the sibling helper is the
smaller step that needs no new abstraction.
Revisit if: a third result type appears, giving a shared `PropertyResultInterface`
two-plus real implementers — then `assertPropertyPassed()` can accept the interface
and the sibling helper folds into it.

## D028 — The stateless entry point is constructor + check(), not a forAll facade

Spec: SPEC-008, OQ4
Status: **decided**
Decided: maurice, 2026-08-03
Decision: `StatelessProperty` is a constructed object with a `check(?int $seed)` method
returning `PropertyValueResult`, matching SPEC-005's `StatefulProperty`. A fluent
`forAll(...)->then(...)` facade is not built now.
Because: consistency with the existing entry point is worth more than the mild
readability of a `forAll` facade, and the facade is pure sugar over the same object
— additive later without a breaking change if it earns its place (§4). Building both
now would be speculative generality with no consumer asking for the second form.
Revisit if: the examples or users find `check()` awkward enough that a `forAll`
facade earns its place; it can be added over the same `StatelessProperty` without a break.

## D029 — Edge-biasing is opt-in (a parameter, default off), not on by default

Spec: SPEC-009, OQ1
Status: **decided**
Decided: maurice, 2026-08-03
Decision: edge-biasing is an **opt-in parameter** on `integers()` (e.g. `edgeBias`,
default `0` = today's pure uniform), not built on by default and not a separate
wrapper. It composes because the leaf carries it — `map`/`associative` inherit the
bias with no change.
Because: reproducibility (R4) is a core promise and the whole suite pins seeds.
On-by-default would change the draw sequence of *every* existing seed — breaking every
seed-pinned test and invalidating any seed a user has recorded — which is exactly the
machine-independent-reproducibility promise the package leads with. Opt-in keeps every
existing seed reproducible and makes biasing a deliberate, composable choice. A wrapper
was rejected because a generic `withEdges($gen)` needs the inner generator to expose its
edges, which only `integers` has; a parameter on `integers` is the smaller, honest shape.
The cross-link worth stating: this **runs against D006's logic**, which rejected an opt-in
`Cloneable` *because opt-in fails silently*. The same silent-degradation risk exists here —
a user who wants edge-bias but forgets the parameter gets uniform generation and silently
misses edge bugs. D006 still chose always-on there because cloning always-on was *free*;
edge-bias always-on is *not* free (it costs reproducibility of every seed), so the trade
genuinely differs and opt-in wins here. But the silent-degradation risk is real and is the
revisit condition, not an oversight.
Revisit if: the AI-testing consumer finds opt-in too easy to forget in practice (the D006
failure mode) — then reconsider default-on for a future major once recorded seeds are
understood as bias-inclusive, or a project-level default that a suite sets once.

## D030 — The measured recommended edge-bias frequency is 10%

Spec: SPEC-009, OQ2
Status: **decided (measured)**
Decided: maurice, 2026-08-03
Decision: the recommended edge-bias frequency is **10%** (`edgeBias: 10`). It is a
*recommended* value — documented and used by the AC5 meta-test — **not a code default**:
D029 keeps the parameter opt-in with default `0` (off), so 10% is what a user should
reach for when turning bias on, not what the library imposes.
Because: measured, not guessed (the D007/Step 60 discipline). Against a planted edge-only
bug (a property that fails only at `max`) over `integers(0, 1_000_000)` with a 100-run
budget, across 40 seeds: uniform (`0%`) found it in **0/40**, `5%` in 36/40, `8%` in 37/40,
and **`10%` in 40/40** — 10% is the smallest fully-reliable frequency in the sample, and it
keeps 90% of draws uniform so ordinary (non-edge) coverage is retained. The 90/10
uniform/edge split is also the conventional balance in QuickCheck descendants.
Caveat (stated, not hidden): the reliable frequency is a function of **range width, run
budget, and edge-set size**, not a universal constant. 10% is measured for this
configuration (a million-wide range, 100 runs, the 2-element edge-set `{0, max}`). A wider
edge-set (OQ3 neighbours) dilutes each edge's share and would need a higher frequency for
the same reliability; a much wider range or smaller budget shifts it too.
Revisit if: OQ3 adds neighbours to the edge-set (re-measure — each edge's share drops), or a
real suite's range/budget differs enough that 10% under- or over-shoots.
