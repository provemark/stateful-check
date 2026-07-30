# NOTES

Friction log. Everything that cost time, surprised us, or turned out to be wrong.
Append-only, newest step at the bottom. This is the raw material for write-ups —
capture it while it is fresh, because the detail evaporates within days.

Format per entry: what was tried, what happened, what it means.

---

## Step 0 — starting position (2026-07-29)

Carried over from the hand-rolled stateful tests in
`provemark/content-credentials`:

- Eris `Generators::seq()` produces a sequence of *values*; reading those as
  *operations* is the whole trick behind the hand-rolled version. Confirm
  whether it shrinks its own length.
- Eris has no `Command` concept, no model callbacks, no sequence shrinking.
- Pest needs `uses(\Eris\TestTrait::class)` for `$this->forAll()` inside
  closures. Untested against every Pest 3 patch version.
- Generator names have moved between Eris releases. Pin the surface.

Open from day one: whether Eris exposes a threadable seed at all (SPEC-003).
If it does not, deterministic reproduction — and therefore sound shrinking —
needs a different answer.

## Step 1 — read fast-check's CommandsArbitrary (2026-07-29)

Read `CommandWrapper` and `CommandsArbitrary` in full. Three findings, one of
which simplified the design significantly.

**R1 was wrong.** The original rule said every shrink candidate must be
re-validated against the model. fast-check does no such thing, and the reason
generalises to us: a command whose precondition fails is *skipped*, not fatal
(SPEC-001 AC3), so a shortened sequence can never be ill-formed. Re-validation
solves a problem we do not have. R1 rewritten, SPEC-001 AC4 (`wellFormed()`)
deleted, SPEC-002 rewritten around filtering-to-executed instead.

The catch, now explicit as R9a: this holds only while commands are independent.
fast-check's `ICommand` has no way to reference an earlier command's result, and
that is precisely what buys the simplicity. Symbolic results are therefore out of
v0.1 by design, not by postponement — the SPEC-001 open question is resolved,
not deferred.

**Cloning.** `CommandWrapper::clone()` exists because command instances are
reused across candidates. Any state a command carries would leak between shrink
attempts. New rule R9b; new AC in SPEC-002.

**Determinism comes free with the replay path.** `replayPath` is a boolean array
of "which commands ran", and `filterOnReplay` throws on a mismatch with actual
execution. That is a much cheaper non-determinism detector than re-running
sequences and comparing verdicts, which is what SPEC-002 AC6 originally proposed.
Rewritten as AC8.

Also settled: candidate order (empty sequence once, then prefix-hold with suffix
length shrinking keeping the last command, then per-command arguments); length is
its own arbitrary so length shrinking is integer shrinking; generation is uniform
with no bias; and the port must carry a shrink context per value, which resolved
the one irreversible open question in SPEC-003.

Still unverified, and now the critical path: whether Eris exposes a threadable
seed **and** whether it can shrink a previously generated value outside its own
`forAll` loop. If either answer is no, the Eris adapter cannot implement the port
and we own generation after all. Both are blockers on SPEC-003; investigate them
together before approving anything.

## Step 2 — dropped Eris; the package owns generation (2026-07-29)

SPEC-003 rewritten from "port and adapter" to "generation core". The trigger was
noticing that both of its blockers were about Eris rather than about the problem:
whether a seed can be threaded through it, and whether it can shrink a value
outside its own `forAll` loop. Either answer being "no" would have collapsed the
design late.

PHP 8.2's Random extension settles it. `Random\Randomizer` over
`Random\Engine\Mt19937($seed)` is seeded, per-instance isolated, cloneable and
serialisable — exactly the determinism R4 needs, and better than a global
`mt_srand()` because two sources cannot interfere. Eris predates the extension
and almost certainly cannot offer this.

What has to be built is smaller than expected. One integer generator with sound
shrinking, and combinators on top: `elements` is a shrinking index into an array
(so it shrinks toward the first element for free), `bool` is `choose(0,1)`,
`oneOf` delegates, `map`/`tuple`/`vector` pass shrinking through. And no
primitive string generator is needed at all — both dogfood suites build strings
from `elements` over fragments composed with `map` and `vector`. Worth
remembering as an argument against building one.

Costs, honestly: a few hundred lines plus tests, a second meta-test surface (the
generators now need testing too, not just the shrinker), and `filter` is the
known footgun — the predicate must be re-checked while shrinking or the
counterexample is invalid. Eris moved to SPEC-004: optional, `suggest`, and
explicitly unscheduled.

Side effect worth recording: external shrinking was chosen because Eris was a
black box. Now that generation is owned, fast-check's integrated model is
available. Kept external anyway — simpler to test in isolation — so it is now a
choice, not a constraint. Noted in `docs/prior-art.md`.

## Step 3 — spec review found four real gaps (2026-07-29)

Had the specs read end to end before approving anything. Eight findings, all
valid; four needed amendments. Worth recording that reading found what step 1 of
the roadmap was designed to find — cheaper, and a sign the specs are detailed
enough to review.

**The expected-failure gap (the important one).** SPEC-001 AC6 promised that a
command which *expects* to throw reports success, but `postCondition($model,
$result)` receives the return value of `run()`, and the runner caught exceptions
before the postcondition ever saw them. There was no path for the Throwable to
reach the assertion, and no way for a command to declare it wanted one. Dogfood
example 1 needs exactly this on its first command (blank name → model predicts
the exception). Fixed with an `Outcome` wrapper: the runner wraps every
invocation, returned-or-thrown, and hands it to the postcondition.

Deliberately *not* fixed by passing the outcome to `nextState`. That would let
the model see what actually happened, and a model that can mirror the system can
never disagree with it — R6 gone. The model must predict the failure from its own
state, which is what the hand-rolled suite already did.

**Failure identity leaks across specs.** D002 ("fails for the same reason") could
not be defined while `RunResult` carried only a free-text `?string $reason`.
Comparing messages is too strict, comparing nothing is too weak. So SPEC-001 now
carries a structured `Failure` (kind + command class + exception class) with
`sameKindAs()`, and SPEC-002 AC1 uses it. Note the direction: a SPEC-002 decision
forced a SPEC-001 data shape. Worth remembering that spec boundaries do not stop
design pressure.

**Nothing owned the seed.** SPEC-001 AC5 claimed seeded reproducibility, but the
runner takes a concrete command list and never sees a seed. The criterion had no
home because the object it described did not exist: the entry point that wires
generation, execution and shrinking together. Written as SPEC-005; the AC moved
there.

**How does an immutable system get threaded?** For the HTTP service the SUT
mutates via side effects, fine. For the immutable builder every operation returns
a *new* builder that the next command needs — and passing it forward would break
R9a. Resolved by stating that the SUT is a handle owned by the runner, with a
small `Ref` holder for immutable systems. It earns its place because dogfood
example 1 cannot be written without it.

**Removed `fork()` from SPEC-003.** Nothing uses it. Generation is sequential
from one source; shrinking reuses captured contexts rather than regenerating.
Keeping it would have obliged us to verify `Randomizer` clone semantics for no
gain. First real application of §4 against my own earlier draft.

**Build order was circular and I had not noticed.** SPEC-003's command-alphabet
generator produces `Command` instances, but SPEC-003 is built before SPEC-001.
That one piece is now deferred to step 3.

Still to verify experimentally before SPEC-003 is approved: whether `Mt19937`
gives identical sequences across PHP patch versions and platforms. SPEC-003 AC1
claims reproducibility "across separate processes" and R4 rests on it. One small
script.

## Step 4 — second review; the abstraction had quietly lost a capability (2026-07-29)

Second read of the amended specs. Gaps A–H confirmed closed except E, which is
correctly reduced to one guarded experiment. Four textual breakages found and
fixed, one of them mine: inserting a `### Removed` heading in the CHANGELOG
orphaned the `prior-art.md` line above it, so the changelog claimed a file had
been deleted that had only been edited. Small, but exactly the kind of thing that
only surfaces by reading rather than grepping.

**The substantive finding: `postCondition` could not see the system.** Its
signature was `($model, Outcome)`, so the only observation channel was the return
value of the current command. Traced it back: the signature came from the
hand-rolled dogfood suite, where the command held the SUT as its own property and
the assertions reached it directly. That access was lost when the pattern was
abstracted into a contract — not given up, just dropped. fast-check's
`run(model, real)` has it. Added as AC8 and D011.

Two disciplines documented with it, because the access is easy to misuse: keep
reads cheap and deterministic (over HTTP an extra read per command doubles the
traffic), and keep anything that may throw inside `run()`, because only the
runner's invocation is wrapped into an `Outcome`. An exception escaping a
postcondition is a broken test, not a captured outcome.

**Also specified: the AC2/AC6 precedence.** Both criteria can be true at once —
"postcondition returned false" — and nothing said which kind wins. The rule is
that `FailureKind` is decided by whether `run()` threw, not by the postcondition.
Unspecified, this is the first place two implementations would silently diverge.

**And a hazard worth remembering: the transition runs even when `run()` threw.**
That is right for the normal case, where the command mutated and *then* threw, so
the model must advance to the state in which it predicted the throw. It is wrong
if a command throws *before* mutating — then the model runs ahead of the system
and every later postcondition fails for the wrong reason. Wrote it up as a
constraint on how commands are written: the throwing operation goes last.

Confirmed still outstanding: D001, D004, D008, D009, and the `Mt19937`
cross-process experiment.

## Step 5 — Mt19937 verified, and it moved two things (2026-07-29)

Ran the one outstanding experiment (`docs/verification/mt19937.php`) on PHP
8.3.6 / Linux / 64-bit. The core claim holds: same seed gives an identical
sequence within a process, between independent instances, and **across separate
processes**. Immune to a global `mt_srand()`. Both `Randomizer` and the engine
serialise and resume. SPEC-003 AC1 stands, and with it R4 and SPEC-005 AC3.

Two things came out that the spec had not accounted for.

**The mode argument changes the stream.** `new Mt19937($seed, MT_RAND_PHP)`
produces different output from the default `MT_RAND_MT19937` — 575 against 506
for the same seed. Leaving the mode to the default would mean a future change to
it silently invalidates every recorded seed, which is the worst failure mode
imaginable for a reproducibility guarantee: nothing errors, the numbers just
quietly become different ones. Now pinned explicitly in AC1 and in the sketch.

**`Randomizer` cannot be cloned. At all.** It throws
`Error: Trying to clone an uncloneable object`. That is an unexpectedly hard
confirmation of D010: had `fork()` stayed in the spec, it would have had to be
built on the engine (which *is* cloneable and continues the same stream), and we
would have discovered that during implementation rather than during review.
Removing an unused feature turned out to also remove an assumption that was
plainly false.

Not tested, and stated as such in prior-art: other PHP minors, 32-bit builds,
non-Linux platforms. 32-bit is the plausible edge, because `PHP_INT_SIZE` affects
range mapping. Re-run the script before claiming support beyond 64-bit Linux.

Also settled a naming mismatch the review caught: SPEC-005 wrote `Gen::elements()`
while SPEC-003 listed bare functions. Adopted the `Gen` facade and named it in
SPEC-003. Trivial, but the dogfood examples would not have compiled as written.

## Step 6 — decisions locked; two went against my own advice (2026-07-30)

Every v0.1-gating open question is decided (D001, D003–D009; D002/D010/D011 were
already). SPEC-001 and SPEC-003 approved. SPEC-002 and SPEC-005 left `draft` on
purpose — they learn the most from the layers beneath, so they wait their turn.

Two decisions overturned my earlier recommendation, and both are better for it.

**D001 — no `@template`, against my "templated interface" advice.** I had argued
templates cost the library nothing because users could opt out. The sharper point
I missed: templates are additive. Plain `mixed` now can grow `@template` later in
a minor without a runtime break; the reverse — removing templates once shipped —
cannot. So the reversible choice is `mixed`, and the dogfood suites do not need
the safety anyway (§4).

**D006 — always clone, against my "measure opt-in versus always" advice.** I
framed it as a performance trade-off to measure, which is the wrong axis. The
deciding property is the failure mode: opt-in `Cloneable` fails *silently* —
forget the interface on a stateful command and shrinking is quietly unreliable,
with no error. For a package whose whole purpose is not to lie about results, a
silent-unreliability footgun is disqualifying regardless of the copy cost, which
vanishes against running the system anyway. Removed the interface; always
shallow-clone; `__clone` for held objects. Divergence from fast-check recorded.

The rest went as recommended: external shrinking (D003), configurable origin with
default 0 (D004), minimal breadth (D005), a count budget of 100 rather than a time
budget (D007, because a time budget would break determinism), keep `provemark`
(D008), return rather than throw (D009).

One stale cross-reference fixed in passing: SPEC-003's `filter` sketch pointed at
"AC4, AC7", a leftover from before the fork-AC removal renumbered the list. It is
AC3 and AC6 now.

## Step 7 — planned the port of dogfood example 1; four outcomes (2026-07-30)

Explained the port of `BuilderSequencePropertyTest` before writing it. Of its four
`it()` blocks only one is a genuine stateful property (matches-the-model-after-
every-step); that becomes `ImmutableBuilderExample`. The other three drove the
findings.

**Setup asymmetry fixed before writing — D012.** The original does
`forAll(Gen::mediaType(), seq(commands))` and threads the one `MediaType` into both
builder and model. Our `StatefulProperty` took two *nullary* closures, so the
initial state could only be hardcoded (`MediaType::Png`) or wrapped in an outer
loop — a detour, and a real gap. Amended SPEC-005 to a single `setup` closure
returning `Setup(model, system)`, fed by an optional `initial` generator drawn once
per sequence, after fast-check's ModelRunSetup. Fixes the MediaType threading and
the model/system asymmetry in one move. The initial state is drawn but not yet
shrunk; left as an open question because ordering, not the initial value, is the
bug axis here.

**The immutability property cannot be expressed — and that is a boundary, not a
bug.** The original pins an early builder instance and asserts later commands leave
it untouched. Our SUT is one mutable handle (`Ref`), which erases the distinction
between an immutable value passed forward and a mutable object mutated in place —
exactly what that property tests. So a stateful API of this shape structurally
cannot carry it. Recorded in the README limitations and kept as honest text, not
worked around: it is a property of the system, testable in an ordinary test, not of
the command sequence.

**The example's SUT is a self-contained stand-in, against my recommendation.** I
argued for a dev-dependency on `content-credentials` for fidelity; overruled, and
correctly. A general test library must not depend on a specific C2PA package, not
even dev-only — that couples our CI to their release cycle and breaks for anyone
cloning the repo. So `examples/` gets a minimal immutable builder of the same shape
(with* methods, last-write-wins, blank name → `build()` throws, `toArray()`), while
`BuilderModel` and the property structure are ported verbatim. Only the SUT is a
replacement; the fidelity loss is confined to it.

**A second library, or an explicit boundary — D013, left open.** Commutativity and
immutability are not stateful properties, and the package has only a stateful
entry point — no `forAll` over the generation core. A user with both kinds needs
two libraries. Either add a thin stateless runner (cheap, since generation and
value shrinking are owned) or position explicitly as the stateful layer only.
Recommended (b) for v0.1 on scope discipline, but the dogfood file containing
properties we cannot express is real weight toward (a) as a v0.2 spec. The
maintainer decides.

## Step 8 — wrote dogfood example 1; where the API chafed (2026-07-30)

Wrote `examples/ImmutableBuilder/` — property #1 only, per D013. SUT is the
self-contained stand-in (D014); `BuilderModel` and the postcondition logic are the
original. Red for the right reason (`Gen` not found), runs under `composer
examples`, excluded from `composer check` (phpstan on src+tests, phpunit suite on
tests, so only Pint sees it — and it is Pint-clean).

The point of the step is the friction, so:

**`mixed` in every command method is the sharpest cost of D001.** `run(mixed
$sut)`, `nextState(mixed $model)`, `postCondition(mixed $model, mixed $sut, …)` —
every body then calls methods on `mixed` (`$model->canBuild()`,
`$sut->value->build()`). It is invisible here only because examples are outside the
PHPStan paths; a user on PHPStan max gets no help and must add a `@param` to every
one of the four methods on every command class. The cost is per-method, not
per-class — worse than I framed it when I recommended templates. D001 still looks
right (templates are additive later), but the ergonomics bill is real and should
be shown honestly in the docs.

**`Gen::map` over `Gen::tuple` reads worse than the original's `associative`.**
Building a two-argument command is `Gen::map(fn (array $nv) => new
WithSoftwareAgent($nv[0], $nv[1]), Gen::tuple($name, $version))`. The positional
`$nv[0]`/`$nv[1]` is untyped and easy to transpose; the original's
`associative(['name' => …, 'version' => …])` named its parts. A spread-map
(`fn ($name, $version) => …` applied over a tuple) would remove the indexing.
Worth a combinator or at least a documented pattern.

**One `Generator<Command>` per command type duplicates the argument wiring.** The
original had a single command generator with an `op` field; ours needs two, each
repeating the identical `Gen::tuple($name, $version)` map. Fine for two commands,
but it scales linearly with the alphabet.

**`preCondition` returning constant `true` is pure boilerplate** for the common
precondition-free command. A default (a trait, or a default method) would remove it.

**The observation contract is by convention, unchecked.** `run()` returns `mixed`,
and the postcondition must compare it to `$model->expectedToArray()`. Nothing in
the contract says run's return type and the model's observable must match; get it
wrong and the postcondition silently compares incomparable things. Threading build
through as the last statement (Vorm A) makes it work, but the coupling is implicit.

**Note for step 5, no action now: this example does not exercise AC8 or D011.**
`$sut` is unused in the postcondition because observation flows through the
`Outcome`. So the read-the-system capability we added (D011) and the "shrinking
holds the initial fixed while re-reading the system" of AC8 are not touched by
dogfood 1. Example 2 (the provenance chain) probably does exercise D011, since it
reads system state after signing. At step 5, confirm D011 is actually covered by
some test and has not been left an unused parameter hanging in the contract.

**Pre-existing: `composer check` is red on `docs/verification/mt19937.php`.** Pint
flags the verification script (single quotes, import order, spacing). Not my
example — it is Pint-clean — and it predates this step. Needs either a `composer
pint` pass over that file or a Pint exclusion for `docs/`. Flagged, not touched.

## Step 9 — acted on the example-1 findings; one reversed a decision (2026-07-30)

**Finding 1 did more than add nuance — it reversed D001.** In Step 8 I wrote that
D001 "still looks right". It did not. The original reasoning — that `@template`
would add annotation noise to every method of every user command class — is
factually inverted: *without* templates a user writes four `@param`/`@return` blocks
per class to satisfy PHPStan max; *with* one `@implements Command<…>` line PHPStan
types all four methods. Templates remove the noise. `Command`, `Outcome` and `Ref`
now carry `@template`; `TResult` also ties run()'s return to the `Outcome` the
postcondition reads (finding 5), though not to the model's own observable. D001 in
DECISIONS is rewritten and marked as superseding a decision reasoned from a wrong
premise — that correction belongs in the log, not a silent overwrite.

**Finding 2 fixed with `Gen::associative`, no new combinator.** It was already in
SPEC-003's scope; named keys (`['name' => …, 'version' => …]`) remove the positional
`$nv[0]`/`$nv[1]` indexing and read closer to the original. Example updated.

**Findings 3 and 4 deferred, not dropped.** The duplicated argument-generator wiring
and the `preCondition: true` boilerplate are both real but small; two examples judge
them better than one. Left here and added to ROADMAP step 5 as explicit revisit
points, alongside the D011/AC8 coverage check.

**Fixed `docs/verification/mt19937.php` with Pint.** One standard for the whole repo,
no `docs/` exclusion. `composer check`'s Pint stage is green again.

## Step 10 — wrote dogfood example 2; the mutable-SUT case, and what it changed (2026-07-30)

Wrote `examples/ProvenanceChain/` — the core provenance property, ported from the
integration suite. `ProvenanceModel` verbatim; the SUT is a self-contained in-memory
`ProvenanceSession` (D014, option b). Same harness discipline as example 1: red for
the right reason, `composer examples`, Pint-clean, out of `composer check`.

**D011 is exercised now, cost and all.** Observation is a fresh read of the system in
Sign's postcondition (Vorm 2), not run()'s return — the read-the-SUT capability
example 1 never touched. And it shows the bill the discipline warns about: Sign reads
once in its postcondition, Read reads twice (run + idempotency check). Over a real
service every one of those is a round-trip. So D011 earns its place under §4 — the
second dogfood suite genuinely needs it. Read profile documented as comments in the
example.

**AC8 is exercised by NEITHER example, and not for the reason I first gave.** I said
example 2 would only lightly touch AC8 because its initial state is trivial. The real
reason is deeper: shrinking runs only on a *failing* sequence, and both examples are
written to pass, so the shrinker — and with it AC8 — is never reached by the dogfood
suites at all. Correct division of labour (examples validate the API shape, the
meta-suite validates shrinking), but it means AC8 needs a planted-bug case with a
generated initial state. Added to `tests/Meta/README.md`: a bug that fires only at one
initial state, whose shrunk counterexample must name that state and hold it fixed,
never re-drawing it.

**Weighting the alphabet is an idiom, not a gap.** To lean 2:1 toward signing, the
alphabet lists `Sign` twice. The command-alphabet generator picks uniformly with no
built-in bias — as fast-check does deliberately — so duplication is the intended way
to weight, and it earns no abstraction. Documented as a comment in the example; not a
step-5 revisit item.

**Does anything from example 1 feel different now?** Two things. The `mixed`
ergonomics cost (finding 1) is *milder* for a mutable SUT: with `TSut =
ProvenanceSession` the postcondition reads a fully typed handle, no `Ref<…>` to
unwrap, so `@implements` buys more here than in the immutable case where `$sut->value`
still resolved to `mixed` short of templating `Ref`. And the observation contract
(finding 5) reads as *more* load-bearing: example 2 has two result types across its
commands — `null` from Sign, `Report` from Read — and the per-command `TResult` in
`@implements` is what keeps each `$outcome->value` honest. With one result type in
example 1 it looked optional; across two commands it reads as necessary.

## Step 11 — combinator audit; an assumption of mine, falsified by the ports (2026-07-30)

Audited SPEC-003's combinator list against what the two examples actually use, now
that they exist. Used: `constant`, `elements`, `map`, `associative`. Removed as unused
(§4): `bool`, `oneOf`, `filter`, `tuple`, `vector`. SPEC-003 amended and re-approved.

The audit is exactly what writing the examples first is for — it turned two of my
earlier assumptions into findings:

**The map+vector string-building claim was wrong.** SPEC-003's Problem section said
both suites "build their strings from `elements` over fixed fragments composed with
`map` and `vector`". The ports do no such thing: they draw whole names with
`elements`. So `vector` never appears, and the justification for keeping it was
fictional. Corrected in the spec.

**`oneOf` as used was always flattenable.** Every `oneOf` in example 1 was
`oneOf(elements(...), elements(...))` or `oneOf(constant(null), elements(...))` — a
union of fixed-value generators, which is just one `elements([...])`. The general
capability (union of arbitrary generators) is real but unused, so it goes under the
same §4 rule. Example 1 rewritten to a single `elements`.

**`filter` was the big cut.** It carried the re-check-while-shrinking footgun and two
of its own ACs. Neither suite filters, so all of that leaves the v0.1 surface; it
returns only in its own amendment when a real suite needs it.

Net: the core to build is four combinators plus `integers()`, not nine. Writing the
examples before the generators paid for itself here.

## Step 12 — the generation contract; the one step where red-first does not apply (2026-07-30)

Defined `Generator` and `GeneratedValue` before any combinator, because the context
shape they fix determines how every combinator shrinks. Design A: a generator holds
its own config and reads only the opaque context of the value handed back to
`shrink()`; a primitive integer therefore carries a `null` context, while a composite
stores its sub-value(s) — `elements` the index, `map` the inner value, `associative`
the per-key values.

**Context stays `mixed`, on purpose.** Opaque to everyone but the producing generator,
exactly as fast-check keeps `Value.context` as `unknown`. Flagged in the code not to
template it to `@template TContext` now that D001 put the rest of the contract on
templates — that would leak the opacity the design needs. Someone will want to
"improve" it; the comment is there to stop them.

**The alphabet consequence, recorded now so it is no surprise three steps on.** The
command-alphabet generator is internally a `oneOf` over the alphabet's generators —
`oneOf` left the user-facing surface in the audit, not the mechanism. Its context must
carry the chosen branch plus that branch's context, so SPEC-002's per-command argument
shrinking can tell which alphabet entry made a command and delegate to the right
generator. Written into SPEC-003 at the command-alphabet-generator scope item.

**This is the one step where red-first does not apply, and that is deliberate — not a
precedent.** An interface has no behaviour to fail-test, and a test on a readonly
constructor would assert PHP's own promotion, i.e. nothing (the "test that tests
nothing" §2 warns against). So the gate for this step is **PHPStan max, not Pest**: the
templates and signatures either type-check or they do not. The first behaviour test
arrives with `integers()` (AC2). Do not read this as "interfaces need no test" — it is
specific to a contract that has no behaviour.

## Step 13 — integers() landed; the crux, and one limitation recorded (2026-07-30)

AC2 done: `Gen::integers()` + `IntegersGenerator` — binary reduction toward the origin
by repeated halving, D015 clamp/throw, D016 width guard. Green.

Verified the guard and overflow tests are not vacuous, by mutation, before committing
(none of that intermediate state committed): removing the D015 throw reddened only the
origin-out-of-range test; removing the D016 guard reddened only the width test;
replacing repeated halving with `2 ** $k` reddened only the gap-above-2^62 test, with
a `TypeError` exactly as predicted. Each test pins its own guard, none coincidentally.

**Known limitation, recorded not solved: uniform generation misses the interesting
values.** `integers()` draws uniformly, so a wide range almost never yields 0, min or
max — precisely where bugs cluster; QuickCheck-likes bias toward small values and
edges. SPEC-003's deliberate "no bias" was about *command choice*, not integers, and
stays. It is moot now: the dogfood suites use only `elements` and small ranges. The
condition under which it starts to matter: a real suite draws a command argument as
`integers()` over a wide range and relies on hitting an edge to trigger a bug — then
edge-biasing (or a small default range) earns its own spec.

## Step 14 — planning constant/elements; the duplicate-choices trade-off (2026-07-30)

Porting the interface clause "a candidate is never the input" onto `elements` exposed
a real choice. `elements(['x', 'x', 'y'])` would shrink index 1 (`'x'`) to index 0
(`'x'`) — a different index but the SAME value, so the clause breaks at the value
level though it still holds at the index level. It terminates, but wastes budget and
the docblock promise reads as false. Two options: deduplicate the choices at
construction, or weaken the clause to "never the same index". Chose dedup, so the
clause stays true as written and the promise a reader sees is the promise they get.

Two smaller `elements` behaviours settled with it, no decision-log entry because
neither has a real alternative: it normalizes non-list arrays with `array_values`
(keys dropped, order the only meaning), and it throws on an empty array.

## Step 15 — the second half of the opaque-context price, and a redundancy the mutation found (2026-07-30)

Two refinements to `elements`, both surfaced by the mutation check.

**The mutation found `array_values` was dead code.** Removing it did not redden the
normalize test, because the value-dedup loop already re-indexes to a list and so drops
the keys. Uncovered code that does nothing is the first thing to rot, so it is gone;
one pass now deduplicates and normalizes, documented as such in the spec and the code.

**The opaque context (Step 12) has a second cost, now paid.** Reading the `mixed`
context back to delegate the index shrink needs a runtime narrowing — the first half,
handled PHPStan-clean without a cast or ignore. The second half is what that narrowing
does when the context is the WRONG shape. Silently returning no candidates was wrong:
shrinking would stop and leave a counterexample un-shrunk with no signal — exactly the
failure class this package exists to prevent. So `elements` now throws a
`LogicException` naming the generator and the expected context shape. A context of the
wrong shape is a generator bug, not user input, so `LogicException` (not
`InvalidArgumentException`) is right, and it is loud. This is the pattern `map` and
`associative` follow in step 4; it is written into SPEC-003 AC3 so it is not reinvented
per combinator. The two halves together are the full price of keeping the context
opaque — worth it for the encapsulation, but not free.

## Step 16 — map: the dedup clause one layer up, and covariance (2026-07-30)

**`map` filters candidates whose mapped value equals the input — the Step 14 dedup
decision, one layer up.** A non-injective function collapses distinct inner values onto
the same output: `map(fn ($n) => $n % 2, integers(0, 100))` maps inner 50 and inner 38
both to 0, so a shrunk inner can map back to the input value and break the "a candidate
is never the input" clause at the value level. `map.shrink` drops those. The comparison
is strict `===`, consistent with `elements`' `in_array(..., true)` dedup — and it
carries the same caveat: `===` is identity for objects, so two equal-but-distinct
objects are treated as different values and slip through. Same limitation as `elements`,
recorded here as its continuation.

**`GeneratedValue` is now `@template-covariant T`.** It is a readonly holder that only
ever yields its value, so covariance is sound, and it resolved a literal-type friction
the map tests hit (a `GeneratedValue<int<0,0>>` did not fit an invariant
`GeneratedValue<int>`). Also learned: map's context-narrowing was PHPStan-clean without
the `is_int` guard `elements` needed, because map's inner is a template `Generator<TIn>`
(mixed flows into `TIn`), whereas `elements`' inner is a concrete `Generator<int>` that
mixed cannot enter without narrowing. A concrete inner type costs a runtime guard; a
template inner does not.

**Honest about the order: covariance came from test friction, not a design insight.**
The conclusion is right — a readonly holder should be covariant — but I did not reason
my way there from the design; a red PHPStan message pushed me, and the justification
came after. Worth recording, because that is exactly the direction a contract takes
shape by accident: a type refinement adopted to silence a message, rationalized later.
It happens to be sound here; next time it might not be.

**And the price of the opaque context is uneven between generators — the second half of
the Step 15 observation.** `elements` has a concrete `Generator<int>` inner, so a
wrong-shaped context is caught by a runtime guard and a `LogicException`. `map` has a
template `Generator<TIn>` inner, so there is no equivalent guard: a context holding a
`GeneratedValue` whose value is the wrong type does not hit `map`'s `LogicException`, it
falls through to a `TypeError` deeper in `$fn`, or — with a loosely typed `$fn` — runs
silently. Not a bug (the context is generator-internal, never user input), but someone
reading `elements` and `map` side by side expects the same protection and does not get
it. The opacity buys encapsulation; the bill is paid unevenly.

## Step 17 — associative: R3 made concrete, and the third layer of the dedup decision (2026-07-30)

**Component-by-component shrinking is where R3's local minimum stops being abstract.**
`associative` reduces one key at a time. A bug that only fires when two keys are
coupled — `n == m`, or `n > m` — cannot be reached by reducing either key alone: drop
`n` and the coupling breaks, so that candidate passes; same for `m`. The greedy loop
then halts on a record that still looks reducible. This is exactly R3 ("a documented
local minimum, not a global one"), and it is the first place in the build where the
guarantee has teeth. Rather than hide it, a test asserts the mechanism directly — every
candidate changes exactly one component — which is honest about the limit without
pretending to a global minimum. Reducing coupled keys together is out of scope for
v0.1; it would need a different strategy and its own spec.

**"A candidate is never the input" is derived here — the third layer of the Step 14
decision.** At `elements` (Step 14) dedup made it true; at `map` (Step 16) a filter made
it true; at `associative` it is true only because each component generator already makes
it true. `associative` adds nothing of its own — a component that changes cannot leave
the record equal to the input. But that means a *user's* generator that breaks the
clause propagates the break straight through `associative`. Documented as a derived
guarantee in SPEC-003, not silently relied on: the same decision, now three layers deep,
and the deepest layer is the one a user controls.

## Step 18 — the covariant Generator amendment; a concealed defect, not a limit (2026-07-30)

Building `associative` surfaced that a heterogeneous keyed record does not type-check
under PHPStan max: `Generator<int>` is not a `Generator<mixed>` while `Generator` is
invariant. I first reached green with three compromises (a concrete-class return, a
homogeneous-only test, a direct-construction empty test) and was ready to call the
heterogeneous case a documented limit. It is not a limit — it is a concealed defect.
Dogfood example 1 *uses* the heterogeneous case and only "passes" because `examples/`
is outside the PHPStan paths. §4 says the examples define the contract, so the honest
move was to amend the contract, not to document around the hole. Made `Generator`
`@template-covariant T` with `shrink(GeneratedValue<mixed>)` (D017). All three
compromises then dissolved: the facade returns `Generator<…>` again, the test is
heterogeneous again, and the empty test calls `Gen::associative([])` again.

**The cost, contract-wide and stated plainly (the Step 15/16 line, one level up
again).** With `shrink(GeneratedValue<mixed>)`, every implementation loses the static
guarantee that it receives the right kind of value; that guarantee moves entirely to
the runtime narrowing and its `LogicException`. `integers` now narrows with `is_int`
and throws, just as `elements`/`map`/`associative` narrow their context. The uneven
protection I first noted at `map` (Step 16) is now the uniform rule: the type says
`mixed`, and the generator is responsible for the check. That is coherent with the
opaque context (Step 12) — shrink's input was never statically trustworthy about its
value anyway — but it is a real trade, not a free win.

**Where this hid, and the tell.** The gap existed from the contract commit; the
covariance was wrong from the first line of `Generator`. It stayed invisible through
`integers`, `constant`, `elements`, `map` because none of them combines *multiple*
generators of *different* types — the place invariance bites. `associative` is the
first, so it is where the defect surfaced. The tell worth remembering: a generic
contract's variance is exercised only when something composes several instances at
different type arguments; until then a wrong variance annotation is silent. Look there
first when a contract "seems fine".

## Step 19 — AC4 deferred; length origin decided ahead of its build (2026-07-30)

AC4 (the sequence-length generator) has no honest content yet. Both its clauses speak of
*sequences*, and sequences are built by the command-alphabet generator, which is deferred
to after SPEC-001 (it needs `Command`). The length generator on its own is just
`integers()` producing a length — testing it now would re-test AC2, not AC4. So AC4
shifts to land with the alphabet generator; SPEC-003 stays `approved` with AC1–AC3
implemented and AC4 pending. A spec waiting on a dependency is more honest than a test
that proves nothing. (It was tempting to reason the other way — declare a thin
length-generator "done" — and worth noting that the temptation existed.)

**The length origin is decided now (D018), before its build, because the reasoning is
tied to SPEC-002's empty-sequence candidate and that is sharp today.** Length generates
in `[1, n]` and shrinks toward 1 — the lower bound is a *generation* bound too, so a
length of zero is never produced. That gives a clean division of labour: SPEC-002 owns
the empty sequence as its own first candidate; the length layer never touches it. Origin
0 would be two paths to empty. AC4's wording is sharpened from "at most *n*" to "between
1 and *n*".

**A boundary to remember, so it is not mistaken for a bug later:** "at least one" is
about the *generated* length, not the command count in a *counterexample*. A command
whose precondition fails is skipped and drops from the shrink representation (R1), so a
shrunk counterexample can contain zero executed commands even though the generated length
was ≥ 1. Consistent; just not what "at least one" leads a reader to expect.

## Step 20 — TResult invariance defect fixed before the contract-commit (2026-07-30)

Starting SPEC-001, the maintainer flagged that `Command` was about to repeat D017: with
`@template TResult` invariant and `Outcome<TResult>` in `postCondition`, `TResult` is pinned
invariant, and dogfood example 2's alphabet mixes `sign` (result `null`) with `read` (result
a report). Verified statically first, with a throwaway check file holding
`list<Command<ChkModel, ChkSut, mixed>> = [new SignChk, new ReadChk]` at PHPStan max. It
failed exactly as predicted: `chkAlphabet() should return list<Command<…, mixed>> but returns
array{SignChk, ReadChk}`. So the defect was real, not theoretical — the same class as D017,
one layer up.

Fix (D019): `TResult` → `@template-covariant`, `Outcome` → non-generic. Two false starts
worth recording, because they show why *non-generic* rather than *covariant* `Outcome`:
- making `Outcome` covariant fails — `returned(T $value)` puts `T` in a parameter position,
  which covariance forbids (the constructor is exempt, a factory is not);
- making it invariant `Outcome<mixed>` fails — `threw()` returns `Outcome<null>`, and invariant
  `Outcome<null>` ≠ `Outcome<mixed>`.
Non-generic sidesteps both: the postcondition always saw the value as `mixed` anyway, so the
type parameter bought nothing. Literal note for later: D019 talks about "widening to
`Outcome<mixed>`" by analogy to D017, but the realisation is a plain non-generic `Outcome` —
the two above are why.

After the fix: project `composer check` green (34 pass), and the throwaway re-ran clean
(`[OK] No errors`) — the heterogeneous alphabet now type-checks. Throwaway deleted, not
committed. SPEC-001 amended and re-approved (header + D019, referencing D017 as precedent).

