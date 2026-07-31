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

Repo hygiene (deliberate, no D-number): `CLAUDE.md` is now tracked, and its stale "Not
published (gitignored)" header line is removed — it was never actually in `.gitignore`. The
§3 rules R1–R10 are architecture, not local scratch; losing them on a clean clone would make
the repo unreadable. R10 (the heterogeneous-generics gate, from the D017/D019 pattern) is
committed with this change.

## Step 21 — AC1 runner; a stale sketch surfaced (2026-07-30)

AC1 implemented: the smallest `SequenceRunner::run` that walks a passing sequence and reports
success. `preCondition` and `postCondition` are invoked but their results are not acted on —
skipping on a false precondition is AC3, stopping on a false postcondition is AC2 — each with
a comment so the omission is not mistaken for a bug. Spy-command test proves call order and
that the postcondition sees the post-transition model (`post === pre + 1`).

Implementing it exposed a stale sketch: `SequenceRunner::run` was written `@param list<Command>`
(bare), which does not type-check once a command binds a concrete model type — `TModel` is
invariant, so `Command<int, …>` is not `Command<mixed, …>`. Fixed to the generic signature
(`@template TModel`, `@template TSut`, `list<Command<TModel, TSut, mixed>>`, `callable(): TSut`).
No D-number: the alternative (leave `TSut` at `mixed`) is not a real option — it is the same
defect class as D017/D019 inverted (too narrow, not too broad), and a concrete system type
(dogfood example 2's `Ref`) would not flow through it.

Why the sketch was bare: it was written before D001, when `@template` was out of scope, and
was not revisited when D001 reversed that. That is a signal — other API sketches (PropertyResult,
StatefulProperty, Ref) may also predate a decision. Walk the remaining sketches for the same
staleness at a convenient point, before their specs are built.

## Step 22 — AC2 structured failure; a narrowing pattern for nullable result fields (2026-07-30)

AC2 implemented (see the commit). One test-quality note worth fixing forward: asserting on a
nullable result field (`RunResult::$failure` is `?Failure`) tempts an
`if (! $failure instanceof Failure) return;` guard to satisfy PHPStan. That guard narrows, but
on a genuinely null failure it *skips the rest of the block and the test passes* — the same
vacuum as a test that asserts nothing, now in narrowing disguise. The pattern is instead:

    $failure = $result->failure ?? throw new RuntimeException('expected a failure, got none');

which narrows just as well for PHPStan but fails loudly when the field is null. AC5 and AC6
inspect the same nullable `failure`/`exceptionClass` fields, so this is the established pattern
there too, not a one-off. Filed with the other narrowing observations: prefer `?? throw` over a
silent early-return whenever a test must narrow an optional before asserting on it.

## Step 23 — AC4 assigned by who produces the property, not who consumes it (2026-07-30)

AC4 ("the run records which commands executed") sharpened to two named properties: `executed`
is total and index-aligned (`count === count($commands)`, padding included), and it is
replayable given a deterministic system. The second could plausibly have been filed under
SPEC-002 AC8, which consumes it — AC8 replays the path and aborts if it diverges. It stays in
AC4 because it is a property of what `SequenceRunner` *produces*, not of what the shrinker
*does* with it: AC8 measures a divergence from a baseline, and the baseline has to be
guaranteed somewhere before a divergence means anything. Put it in SPEC-002 and a runner
invariant lives in the shrinker spec, unreadable from where the runner is defined.

That is the criterion, and it is the first time it was used here: **assign a property to the
layer that produces it, not the layer that consumes it.** Useful again wherever a lower layer's
guarantee is only *exercised* by a higher one (the replay baseline, later possibly the seed's
reproducibility, the clone's freshness).

AC4 was green on arrival, but not because it is redundant — it **records existing but
unspecified behaviour**. The padding that makes totality hold was built at AC2 to satisfy
`count(executed) === count($commands)` in one test's expected array, without being stated
anywhere as intended; it was incidental behaviour that happened to be correct. AC4 turns it
into a guarantee. That distinction matters for the next green-on-arrival case: check whether it
is this kind (unspecified behaviour being pinned down, worth an AC) or genuinely redundant with
an existing test (not worth one). Non-vacuity is shown by mutation — remove the padding and the
totality assertion fails — since there is no unimplemented behaviour to make it red first.

## Step 24 — AC5 throw path; two clarifications (2026-07-30)

AC5 implemented: `run()` is wrapped in `try/catch`, a throw becomes `Outcome::threw`, and the
run continues if the postcondition accepts it. `nextState` runs on the throw path too (R6 — the
transition ignores what actually happened), first really exercised here; a mutant that logs
`nextState` but discards its advance on a throw is caught by the model assertion alone.

Two things worth stating so they are not misread later:

1. **The precedence rule does not fire at AC5.** AC2's precedence rule classifies a `FailureKind`
   by whether `run()` threw. AC5 produces no `Failure` — its path is "postcondition accepts the
   throw → continue" — so no kind is classified here. What comes alive at AC5 is the
   `Outcome::threw` state: the postcondition sees `outcome->threw === true` for the first time.
   The precedence rule only *distinguishes* at AC6, where the same postcondition-false yields
   `UnexpectedException` instead of `PostconditionFalse` depending on whether `run()` threw. Until
   AC6, the runner's failure branch stays hardcoded `PostconditionFalse`; AC6 fixes the
   classification.

2. **The runner catches `Throwable`, not just `Exception` (deliberate).** A `TypeError`, a call on
   null, a `DivisionByZeroError` in the system under test is a real, order-dependent bug this tool
   exists to find — so it is wrapped and (AC6) shrunk to a minimal reproducer rather than crashing
   the run. This means **programming errors in the system are treated as findable bugs, not
   infrastructure failures**: an unexpected `Error` becomes an `UnexpectedException` finding the
   shrinker works on. The trade is accepted because an unexpected throw of any kind is a test
   failure, and it is consistent with `Outcome::threw(Throwable $exception)`, which already types
   its argument as `Throwable` — so this is a consistency confirmation, not a standalone decision
   (no D-number).


## Step 25 — AC7 Ref and handle threading; a third kind of green-on-arrival (2026-07-30)

AC7 introduced `src/Ref.php` (a mutable handle, required by dogfood example 1) and pinned two
runner properties. The runner needed **no change**: `freshSut()` was already called once outside
the loop and `$sut` never reassigned.

Green-on-arrival, but a different kind than AC4's. AC4 recorded *incidental* behaviour — the
padding happened to be correct, nobody chose it. AC7 records **intended-but-unspecified**
behaviour: threading one handle and never replacing it was a deliberate choice at AC1, only never
written down. That is a third category, and the distinction is worth keeping because it says how
firm the ground is: intended-but-unspecified is a stronger guarantee than incidental — the
behaviour was designed, the amendment just states it. So the three kinds of green-on-arrival seen
so far: genuinely redundant (skip the AC), incidental-but-correct (AC4), intended-but-unspecified
(AC7). Only the first is not worth an AC.

R10 gate is N/A for `Ref`, recorded rather than skipped silently: `Ref<T>` is invariant, but —
unlike a `Command` alphabet or a `Generator` set — it is never composed heterogeneously (the
runner takes one `TSut`, not a `list<Ref<…>>`). The composition R10 guards against has no subject
here, so the heterogeneous type-check has nothing to check.

Non-vacuity of the two runner properties shown by two isolated mutants, both using a same-object
factory so the properties do not move together: `$freshSut()` inside the loop fails only the
"exactly once" test (identity holds, same object returned); `clone $sut` before `run()` fails only
the "never replaced" test (the count stays one). One same-object factory kind, two tests — the
AC3 isolation discipline, adapted so each mutant measures exactly one property.

## Step 26 — a spec defect: an AC promised a field the contract cannot fill (2026-07-30)

Finalising SPEC-001 for `implemented`, the contract inventory (every field/enum-case checked
against an AC and a test) turned up `Failure::$reason`: AC2 prescribed "an optional human-readable
reason", but nothing fills it. The runner builds a four-argument `Failure`; no test touches it; and
crucially there is **no channel** by which a command could ever supply one — `postCondition`
returns a `bool`. The AC promised a field the contract has no path to populate.

Resolved as an amendment to AC2, not a §4 dead-code sweep: the promise leaves the spec text, not
only the field the code. Three options and why (b): (a) add a mechanism — needs a contract change
(a richer `postCondition` return), unneeded by the dogfood examples; (c) keep it as extension space
— conflicts with an AC that says a failure *carries* it; (b) remove from AC2 and `Failure`, and
reintroduce with its filling mechanism if a message channel is ever wanted. Recorded as a spec
defect caught by taking traceability seriously.

Contrast with `Outcome::$value`, also unread by the runner and untested here: that one has a
mechanism (`Outcome::returned`) and a real consumer — dogfood example 2's `read` postcondition
asserts on the returned report — so its coverage belongs to the examples port (§5), not a
SPEC-001 unit test (which, since the spy supplies the value, would be a tautology). "Named by an AC
but unfillable" and "built with a consumer elsewhere" are different classes; only the first is a
defect.

Forward-gate observation (the general lesson): the check that caught this — *is there a path by
which this promise can be fulfilled?* — is answerable when an AC is written, not only at
traceability, and it is the same cheap-before / expensive-after shape as R10. I am **not** promoting
it to R11 yet: R10 became a hard rule only after the same defect struck twice (D017, D019), and this
is one occurrence. Kept as this observation, ready to become R11 the moment a second AC promises
something the contract cannot deliver. Promoting on n=1 would itself violate the discipline of not
adding a rule before it is earned.

Process note (decided, left to rest): no pre-commit hook. `composer check` as an explicit, visible
step before committing beats an invisible gate that gets bypassed with `--no-verify` one day. The
earlier gate-slip came from putting `check` and `git commit` in one shell line; the lesson is to
keep them separate commands, not to automate.

## Step 27 — AC8 and SPEC-001 implemented (2026-07-30)

AC8 (postcondition observes the system) closed SPEC-001. Test-only, green on arrival — the runner
has handed the post-run `$sut` to the postcondition since AC1, so this is the same
intended-but-unspecified kind as AC7. The falsifiable part is "including": the postcondition sees
the state after *this* command's `run()`, which the shared handle (AC7) alone does not establish.
Non-vacuity by the snapshot mutant — `clone $sut` before `run`, pass the clone to `postCondition`
— which fails only the AC8 test (SpyCommand ignores `$sut`, AppendCommand records it in `run`), so
it isolates the one claim.

"Do not mutate the system" is written as an unenforceable D011 convention in the `postCondition`
docblock, not an AC8 guarantee: the runner cannot stop a mutating postcondition, and pretending
otherwise would be the kind of false guarantee this package exists to avoid.

SPEC-001 status → `implemented`. All eight ACs traced (AC → test → source). `composer check` green
across the whole suite. Next in ROADMAP: the dogfood examples port (§5), then SPEC-002.

## Step 28 — SPEC-003 AC5: the alphabet generator, and a two-sided traceability check (2026-07-30)

Returning to SPEC-003 for the two pieces deferred until SPEC-001 existed: the command-alphabet
generator and the sequence-length generator (AC4). Doing the alphabet generator first (it is why
the deferral existed — it produces `Command` instances) surfaced a gap: it was in scope but no AC
covered it. AC3 names only `elements`/`map`/`associative`. Added AC5.

That is the **mirror of the reason defect** (Step 26): there an AC promised a field no channel
could fill; here a deliverable existed with no AC. The two together are a **two-sided check to run
at every spec's move to `implemented`**: does every AC have a path that can fulfil it, and does
every scoped deliverable have an AC that pins it? More useful than two isolated observations —
kept as a candidate for the `implemented` checklist.

Two honesties written into AC5 so nothing reads as a guarantee it is not:
- **"Uniform choice" is not unit-tested.** One draw proves nothing about a distribution, and a
  statistical test in a unit suite is fragile. What the test asserts is source-determined,
  branch-recording, correctly-delegating selection — not uniformity. Uniformity is the design
  intent (fast-check ignores command bias too), stated, not asserted.
- **The branch choice is shrunk by no layer** — not the alphabet generator (it delegates only
  argument-shrinking to the chosen branch) and not SPEC-002 (which shrinks length and arguments
  but never replaces command A with command B). This is a **coverage gap, not a division of
  labour**: it would have been wrong to write it as "SPEC-002 handles it", because SPEC-002 does
  not. Consequence recorded: a shrunk counterexample may keep a more complex command where a
  simpler alphabet entry would also have failed. Acceptable for v0.1 — shortening by removal is
  almost always more useful than replacement.

R10 gate for AC5 run before building, and it passed: a throwaway `chkAlphabet(list<Generator<
Command<M, S, mixed>>>): Generator<Command<M, S, mixed>>` fed `Gen::constant(new SignChk)` beside
`Gen::constant(new ReadChk)` type-checks at PHPStan max; a `Gen::constant(42)` branch is rejected
(so the check is live). Deleted, not committed. The user-facing `oneOf` removed at the audit
returns here as the alphabet generator's internal mechanism, exactly as the scope predicted.

Then AC4 (sequence length), the other deferred piece, turned out to have **no SPEC-003
deliverable at all**. Its mechanism is `Gen::integers(1, n, origin: 1)` — a *usage* of an existing
combinator, and a wrapper class would only delegate (§4). The choice `min: 1, origin: 1` is a
consumer's decision, not a property of `integers`; by the Step-23 criterion it belongs to the layer
that produces the sequence — SPEC-005, which draws the length. So AC4 and the "sequence-length
generator" scope bullet were removed and the convention moved to SPEC-005 AC9, with D018.

That completes the two-sided traceability check into a **three-sided** one, run as a checklist when
a spec reaches `implemented`:
- does every **AC** have a path that can fulfil it? (the `Failure::$reason` defect — an AC with no
  channel);
- does every **deliverable** have an AC? (the alphabet generator — a deliverable with no AC);
- does every **scope item** have a deliverable? (the sequence-length generator — a scope promise
  whose "deliverable" was just a call to `integers`).
Each of the three struck once here; together they are the finalisation checklist. Not yet a hard
rule (R11) — one instance each — but the checklist is the candidate.

SPEC-003 → `implemented`. Three-sided check run: AC1–AC3 and AC5 each map to a test and source
(traceability filled); AC4 is a redirect to SPEC-005, not a hole; the only scope item that lacked a
deliverable (sequence-length generator) was removed, not left dangling. The AC number 5 is kept
(not renumbered to 4) so it stays consistent with commit c30f7e2 and this Step's narrative; the AC4
slot is a documented redirect rather than a renumber.

## Step 29 — SPEC-002 build: AC1 is an invariant, and AC order is not numeric (2026-07-30)

Starting SPEC-002 (approved). Two structural decisions before the first AC.

**AC1 is a cross-cutting invariant, not a build step.** "The returned sequence still fails, with the
same failure identity" (R2) is passed vacuously by a no-op shrinker that returns its input — so it
cannot be a first step tested against nothing. It is instead a standing assertion in *every*
shrinker test: whatever a test shrinks, the result is re-run and must still fail `sameKindAs` the
original. Its traceability row lists the tests that jointly cover it rather than a single test, and
AC7 (a planted bug shrunk to a known minimum) is where it is proven non-vacuously. This resolves the
vacuum structurally instead of per step.

**AC order is by dependency, not number — first time this happens here.** SPEC-001 and SPEC-003 built
AC1→ACn in order; SPEC-002 does not, because its dependency graph is not its numbering. The order:

    AC10 (sameKindAs — pure Failure function, the only leaf) →
    AC3 (filter non-executed — foundational, no candidate execution) →
    AC5 (empty candidate) → AC4 (structural, retain last executed) →
    AC2 (local minimum — lands last of the family group, because it requires ALL three families,
         including the argument-reduction family, to exist before "no single further reduction
         fails" can hold) →
    AC6 (cloning between candidates, D021 — its mechanism is built with the first candidate run;
         the explicit stateful-command test comes here and may be green-on-arrival) →
    AC9 (budget) → AC8 (non-determinism replay) →
    AC7 (meta planted-bug — the whole shrinker, and the real proof of the AC1 invariant).

Corrections to the maintainer's rough order: AC2 is *after* the argument family, not beside AC5
(it is the local-minimum guarantee over all families); AC6's cloning mechanism is early (needed for
any correct candidate run) while its explicit test is later.

## Step 30 — the commit-behind-the-gate rule (n=2 promotion) (2026-07-30)

The Pint gate let a failing commit through twice — both times because the commit ran as a step the
`composer check` did not gate (once with check and commit in one shell line but the commit not
chained behind `&&`, once similarly). Each was fixed with `--amend`, but a fix-after is not a
process. By the same n≥2 threshold that kept the reason/alphabet/scope-item observations off the
hard rules until they recurred (and that I used to refuse R11 at n=1), this recurred, so it is now a
rule in CLAUDE.md §6: `composer check && git commit`, the commit literally behind the gate. Not a
pre-commit hook — the maintainer's argument stands (an invisible gate gets bypassed with
`--no-verify`; a visible `&&` cannot be forgotten because the shell enforces it).

## Step 31 — AC5 removed (empty probe is dead code); R11 promoted on n=2 (2026-07-30)

Building toward the empty-candidate step surfaced that the empty sequence cannot fail in this model
(the runner checks nothing at zero commands), so AC5's "empty fails → empty counterexample" branch is
unreachable and its meta case unbuildable. The maintainer chose (d): remove the probe entirely (D022),
not implement it defensively. It is a guaranteed-useless execution, and the question it asks is
answered by construction — the shrinker only receives a *failing* RunResult, which can only fail
through a command. Three threads pulled together: AC5 removed (redirect, not renumbered), the
candidate families drop to two, and `shrunkOnce` goes with it (its only job was trying the probe once).

Dependency order now (AC5 gone): AC10 → AC3 → **AC4** → AC2 → AC6 → AC9 → AC8 → AC7. AC4 (structural)
becomes the first candidate family, and the shrink loop — generate, run, accept-and-restart —
originates there. That resolves the earlier tangle: without the empty probe there was nothing for a
first-candidate step to do.

R11 promoted from observation to rule on the n=2 threshold — the same one that kept it (and R11's own
existence) off at n=1, and that promoted the commit-behind-the-gate rule. Two ACs have now promised
the impossible: `Failure::$reason` (no channel to fulfil — the (a) failure) and AC5's empty probe
(unreachable trigger — the (b) failure). R11 makes the two-sided fulfillability check a pre-approval
gate, the cheap-before half of the three-sided traceability check that otherwise runs only at
`implemented`.

## Step 32 — AC3 test revised: a third kind of test-defect (conflated properties) (2026-07-30)

Building AC2's loop showed that AC3's test asserted `freshSut === 0` and `executions === 0` over the
*whole* shrink. That held only because the loop did not exist yet: with no loop, `shrink()` was just
the filter, so "the drop runs nothing" and "the whole shrink runs nothing" were the same number. Add
the loop and they diverge — the loop runs candidates to reduce — and the old assertion breaks.

AC3's claim is about the **filter** (the drop is read from `executed`, not discovered by trying), not
about `shrink()` as a whole. The old test conflated the two. Revised to a single-executed-command
scenario (`executed = [false, true, false]` → filtered to one command), where no further reduction is
even generated, so the filter's zero executions are cleanly observable and stay true once the loop
exists. Committed **on its own, green on the current (loopless) shrinker**, before AC2 — so the new
assertion is shown to hold on its own merit, not attributed to the loop that forced the revision
(§2: a test change must not ride in the commit of the code that broke it). The spec text carried the
same ambiguity and now says "the claim is about the filter".

This is a **third kind of test-defect**, beside the two vacuum cases (an assertion that tests nothing;
a narrowing guard that silently passes): an assertion that **accidentally conflated two properties
because one of them did not exist yet**. It reads as a real test and passes for the wrong reason —
green by the absence of behaviour, not by the behaviour itself. Watch for it whenever a later step
adds behaviour an earlier test implicitly assumed absent.

## Step 33 — AC2 loop: termination, the deferred alphabet param, and a redundant restart (2026-07-31)

The shrink loop (generate → run → accept-if-sameKindAs → restart → local minimum) came alive: the
runner enters the constructor, `executions` increments per candidate run (the AC3 zero-assertion now
guards a real counter), and AC1 is asserted structurally for the first time — the `sameKindAs`
condition in the accept-check *is* the invariant, not an end-assertion. The drift mutant (drop
`sameKindAs`) makes the shrinker accept a candidate that fails for a different reason and land on a
bug we were not shrinking; it fails exactly the drift test's identity assertion.

Three things recorded so a later step does not trip on them:

- **Termination is not explicit.** The loop stops when no candidate still fails, which is only
  guaranteed to terminate because every accepted candidate is strictly *shorter* — a property of the
  structural family, not of the loop. A length-preserving family (the argument family) breaks it, so
  the spec now forbids adding that family until AC9's budget is the safety net.
- **The `alphabet` parameter has no consumer yet.** It was added by amendment A for the argument
  family, deferred to AC7. Documented on `shrink` with the condition that fills it — the
  `Outcome::$value` class (mechanism + planned consumer both exist), not the `reason` class, so it
  will not read as an unexplained parameter at the three-sided check.
- **The restart is currently redundant.** With prefix + last and smallest-first ordering, the first
  accepted candidate is *provably* already a local minimum, so a single-pass mutant returns the same
  result and does not fail Test A — the restart is covered (it runs) but not mutation-distinguished.
  It becomes load-bearing at AC7, with a family whose first accept is not minimal. Kept now because it
  is the correct algorithm (the resolved "restart" open question), not speculative generality.

## Step 34 — AC6 cloning (property, not call); an explicit AC7 gate for the restart (2026-07-31)

AC6 was green on arrival — `stillFails` already shallow-clones each command (R9b, D021). The test
proves the *property*, not the implementation: a `Check` command with its own mutable counter runs
across several shrink candidates, records the counter it saw each time, and every record is 0 — each
candidate got a fresh clone, no residue leaked. The clone-removal mutant makes the original `Check`
accumulate (`$ran` reaches 3) and fails the assertion, so the property is load-bearing.

Confirmed a design question the maintainer raised before I fixed the assertion: an accepted candidate
carries the **original** `GeneratedValue`s, not the run clones — `stillFails` clones locally and
discards, so `$current = $candidate` is originals and the returned counterexample is the pristine
generated commands, never mutated. The test asserts `$check->ran === 0` on the original to nail this.

Shallow clone is half of D021 (point 2): a command holding a mutable *object* still shares it after a
shallow clone unless it implements `__clone`. Decided (with the maintainer): a documented author
responsibility, not an assertion — a user cannot be forced to write `__clone`, and a test would
exercise a made-up command, not a shrinker property. Instead the `Command` docblock now states the
failure mode: the held object leaks across candidates and the shrinker reports a wrong or unstable
counterexample with no error.

**AC7 gate (an explicit check, not an observation).** The restart (`do/while`) is currently redundant
— provably a no-op for prefix + last, breakable by no test. When AC7 lands, **verify it is load-
bearing**: a family whose first accepted candidate is not a local minimum (a richer structural family
or the argument family), against which a mutant that drops the restart fails an AC7 case. **If AC7
does not make it load-bearing, the `do/while` is speculative code and §4 requires removing it** — keep
a single pass. This is a required step of AC7, to be done or ticked off there, not deferred again.

## Step 35 — AC9 budget: "stopped before the minimum", not "budget reached" (2026-07-31)

The budget bounds candidate executions. The flag `budgetExhausted` means *budget-limited, not
minimal* — the check sits at the top of the foreach, so a run that confirms the local minimum on its
last allowed execution exits the pass naturally (no next candidate to trip the check) and is not
flagged, even though `executions === budget`. It only fires when the budget interrupts a pass
mid-search. Two mutants pin this in opposite directions so neither miss is caught by accident:
flagging on `executions >= budget` wrongly flags the at-budget case (fails Test A); never setting the
flag leaves the interrupted case unflagged (fails Test B). R3 both ways — claim no more than a local
minimum, and no less.

The test **measures** the minimum's cost N rather than hardcoding it (an implementation detail of
`candidateReductions` that changes when the strategy or families change at AC7), then checks budget N
(minimal) versus N-1 (budget-limited). The measuring budget is generous but **finite**, and the test
asserts the measurement terminated under it — `PHP_INT_MAX` would hang the suite with no error if the
loop ever failed to terminate. AC1 asserted on the budget-limited result too: it still fails
`sameKindAs` the original.

With the budget in place, the spec condition that blocked the length-preserving argument family is
**satisfied** — a forward reference to AC7 (alongside the restart gate) to tick off at finalisation.

## Step 36 — AC8 non-determinism: broaden to path-or-verdict; a capability-free filter (2026-07-31)

The guard replays the failing sequence once, before filtering, and aborts if the system is unstable.
Broadened from the approved "execution-path mismatch" to **path *or* verdict** divergence (amendment,
2026-07-31): the replay runs anyway, so comparing the verdict — does it still `passed`-match and fail
the same kind? — is free and strictly stronger. It catches a system that reproduces the same path but
flips the outcome (a postcondition that fails once then passes). `passed` is compared to `passed`
directly, not the derived `failure === null` (two fields that only happen to be coupled). Two mutants:
disabling the guard kills both AC8 tests; narrowing to path-only keeps the path test green and turns
the verdict test red — proof the two scenes are cleanly separated (Flaky diverges only in path,
FlakyPost only in verdict) and the verdict clause is load-bearing.

**The one AC1 exception.** An abandoned result is flagged `abandonedNonDeterministic` and the two AC8
tests deliberately do *not* assert it still fails: the system is unstable, so no stable verdict
exists. Recorded on AC1's traceability row and in the AC8 amendment. `executions` of an abandoned run
is 0 — the replay is not a candidate execution (D007), a fixed one-run overhead outside the budget.

**AC3 decomposed under pressure from AC8.** Adding the replay guard exposed that the old AC3 test
fabricated an `$original` (`Cmd` always passes, but the record claimed a failure) — the guard
correctly reported that as non-determinism and the test broke. The fix was not to patch AC3 into the
AC8 commit but to split it first, in its own commit on the pre-AC8 shrinker (the bb432d1 discipline):
extract the executed-subset filter into a public, pure `executedSubset(failing, original)` that takes
no system and no `freshSut`, so it **structurally cannot** trial-and-error — a stronger catch than the
old `freshSutCalls === 0` counter, and one that assumes nothing about AC8's replay. The counter was a
proxy that AC8 would have re-entangled (its value shifts 0→1 with the replay); `executions === 0`
stays the end-to-end claim, the structural filter test is the independent catch. This is maurice's
"make the filter separately callable" alternative, chosen over "keep the counter at value 1" because
the value-1 form both re-conflates and cannot be committed green on the pre-AC8 shrinker.

## Step 37 — AC7 planted bug; a family narrower than its own spec; restart proven load-bearing (2026-07-31)

The planted case (`tests/Meta/OrderDependentShrinkTest.php`): an order-dependent bug — `OrderTrip`
corrupts the system's value only when `OrderPrime` ran earlier, the model is the honest oracle — with
noise on both sides of `Prime`, so the known minimum is `[Prime, Trip]` and reaching it needs to drop
both the leading and the middle noise.

**The family was narrower than its own specification** — the mirror of the pre-D001 staleness (there
the spec lagged the code; here the code lagged the spec). SPEC-002 line 55 says the structural family
is "hold a prefix of length k, **shrink the length of the retained suffix**, always keeping the last
executed command," but `candidateReductions` fixed the suffix at length one (just the last command).
So it could drop a middle chunk that runs up to the last, but never the *leading* junk — it stalled
at `[noise, prime, trip]`. maurice caught this from the spec text before 7a and had me verify it: the
red output confirmed the exact stall, so it was a defect to fix (widen to the real family), not a
design choice between enriching the family and redefining the minimum. Writing the meta-case first is
what surfaced it — the discipline earning its keep. The fix widened the family to drop any one
contiguous chunk `[k, k+s)` while keeping prefix and last; no spec change, the code caught up.

**Order is now load-bearing** where it was not for a linear family. The accept-loop takes the first
still-failing candidate, so the offer order is the greedy path. Chosen: **largest drop first** (`s`
descending, `k` ascending within), matching fast-check's length-shrinking — a big reduction accepted
early reaches a small minimum in fewer passes. Documented at `candidateReductions` like the choice
order in `Gen::elements`.

**The restart is load-bearing (overturns the Step 34 suspicion).** With the narrow family a single
pass sufficed, so the restart looked redundant. The widened family drops one *contiguous* chunk per
candidate, but `[Prime, Trip]` from `[Noise, Prime, Noise, Trip]` needs two *non-contiguous* drops —
leading and middle — so it is only reached across two passes. Mutant (restart → single pass) stalls at
`[prime, noise, trip]`, confirming necessity. Kept, not removed; the Step 34 gate is discharged.

**Budget default (100) reassessed, as maurice asked.** The candidate count is now quadratic in length
(`L·(L−1)/2` per pass), where the fixed-suffix family was linear. For the doubles and the dogfood
example lengths it is nothing; for a length-~14 sequence one pass is ~91 candidates, so 100 covers
roughly a single pass there and several passes on shorter sequences. Verdict: 100 stays a defensible
*default* — a safety net, user-configurable via the constructor — but it is now a **real** bound on
long sequences (D007's intent), not a formality. What makes a tight budget safe rather than silently
wrong is AC9's honest `budgetExhausted` flag ("stopped before the minimum"). No change to the default;
recorded so a later reader knows the quadratic cost was weighed, not overlooked.

## Step 38 — Retracting a dead layer: $alphabet, the GeneratedValue wrapper, and D021 (2026-07-31)

Finalising AC7 exposed that `$alphabet` was an unused parameter — and pulling that thread showed it
was not a dead *parameter* but a dead *layer*. The shrinker reads only `->value` off every
`GeneratedValue`, never `->context`; the context is read solely by the generators (SPEC-003). The
wrapper-as-shrinker-input existed only so family 3 could reduce arguments from the context, and D021
existed only to make that safe. With family 3 deferred out of scope (no v0.1 case needs it — the AC7
bug is argument-free), all three go together: the shrinker now takes a bare `list<Command>`. One
retraction commit before 7d, not split — the parts need each other, and a half-state (wrapper gone,
spec not updated) is not a meaningful point in the history (unlike the AC3 revision, where the split
proved the test on its own).

Two observations maurice drew, both deliberately **not** made into rules:

1. **The speculative-abstraction pattern is now n=2 (D010 `fork()`, this layer) but needs no R12.**
   Both are an abstraction added on an expectation that did not arrive, removed when the evidence
   stayed absent. §4 already forbids exactly this ("no abstraction the dogfood suites don't need"). A
   rule against something a rule already covers dilutes the set. What is worth recording is not a new
   rule but the *shape*: I added both on my own initiative and maurice restored §4 both times — an
   observation about how these amendments arise, not a gate.

2. **"The spec describes something that does not exist" is now n=3 — and that IS a pattern worth
   naming.** Failure::$reason (a field no channel could supply), the empty-sequence probe (a branch
   the runner cannot trigger, D022), and now D021 (a "new wrapper" the code never built — `replay()`
   always ran bare clones). The first two are caught by R11 at approval; D021 is a different flavour —
   a decision's *prose* drifting from the *code* with nothing failing — and only the three-sided
   traceability pass at `implemented` catches it. So it reinforces why that pass exists rather than
   asking for a new rule: prose and code diverge silently, and a periodic reconciliation is the only
   thing that surfaces it.

## Step 39 — 7d finalisation: AC1's real proof, and why originalLength is kept where $alphabet went (2026-07-31)

**AC1's proof, and its honest limit.** The AC7 meta-case now asserts AC1 (the returned `[Prime, Trip]`,
re-run, still fails `sameKindAs` the original). It is green on arrival — the shrinker only ever returns
a sequence it just made fail (the fourth kind from Step 32's distinction) — so its non-vacuity is
proven by a mutant: dropping `sameKindAs` from the accept-condition. The result: the meta-case stays
green (its system has one failure kind, so the loop has no different-kind failure to drift to), while
the unit "does not drift…" test falls. So the `sameKindAs` half is genuinely proven *there* (Prime/Blow,
where dropping Prime yields an UnexpectedException), and the meta-case's `passed === false` half is what
it protects — a shrinker that over-reduced to a passing sequence breaks it. Recorded on AC1's
traceability row rather than left as an overclaim of "proven by AC7".

**The three-sided check did not close silently — it surfaced `ShrinkResult::$originalLength`**, a field
set but never read or asserted, no AC. Kept and tested (asserted in the AC2 test, where original 3 ≠
shrunk 2 makes it non-vacuous), *not* removed like `$alphabet` — and the distinction matters, or this
reads as inconsistency. `$alphabet`/the wrapper were an input *mechanism* carrying a dead code path,
built on an assumption about how shrinking would work that did not arrive. `originalLength` is an output
*fact* about what happened, recorded when it is known, with no path and no assumption behind it. It
belongs to the group that describes the outcome — `executions`, `budgetExhausted`,
`abandonedNonDeterministic` — and without it a consumer cannot see whether the shrink reduced anything
at all, the first question anyone asks of a counterexample. Carrying consistency to the point where a
result object cannot describe its own outcome is the rule pushed one step too far. The three-sided
check's job is to force that judgement into the open, not to auto-delete; here it kept the field, at
$alphabet it removed the layer.

**AC6's source corrected** stillFails → replay: the clone moved into `replay` when it was extracted at
AC8. Another prose-drift the traceability pass caught — small, but the same class as D021.

## Step 40 — SPEC-005 AC6: the gate enforced "build with the consumer" as a type error (2026-07-31)

AC6 is the construction guard: empty alphabet / `maxLength < 1` / `runs < 1` throw
`InvalidArgumentException` (a `runs < 1` broadening amended in — the trigger list was narrower than
AC6's own "run nothing" promise). The plan was a full five-parameter constructor storing the config;
PHPStan max refused it. `$setup`/`$initial`, accepted but unused, tripped `constructor.unusedParameter`;
promoting them would trip `property.onlyWritten` (the SPEC-002 AC3 trap). So the constructor shrank to
the three parameters the guard actually reads; `setup`/`initial`/`check()` join at AC1 with their
consumer. (Even the test needed a real `Generator<Command>` — `Gen::constant(null)` is `Generator<null>`
and the honest alphabet type rejects it — so a `StubCommand` double, not `null`.)

Two observations worth keeping:

1. **The gate enforced a rule the rulebook only asks for by judgement.** §4 ("no abstraction the dogfood
   suites don't need") and R10 ask a human to weigh; `constructor.unusedParameter` + `property.onlyWritten`
   simply *refuse* a parameter or field with no consumer. This is the exact shape I broke twice — fork()
   (D010) and $alphabet (D021) — where we deliberately added no rule because §4 covered it. It turns out
   PHPStan max enforces a large slice of it for free, as a type error rather than a review note. Useful
   when judging a future sketch: if a constructor holds config for a not-yet-built reader, the gate will
   reject it before review does.

2. **Fourth time an API sketch lagged reality — a new variant: unbuildable from the start.** The prior
   three (Failure::$reason, the empty-sequence probe, D021's never-built wrapper) were prose describing
   something that did not exist or a decision the code never honoured. This one is different: the SPEC-005
   sketch showed a five-parameter constructor that PHPStan max *cannot compile* as drawn — not stale from a
   later decision, but never buildable as written. Checked the remaining SPEC-005 sketches for the same
   property: `Setup` and `PropertyResult` are clean — they are `public readonly` value objects, so their
   fields are read externally and neither the unused-parameter nor the write-only-property rule fires. The
   defect was specific to `StatefulProperty`'s *private* config awaiting a deferred `check()`.

## Step 41 — SPEC-005 AC1 sub-step 1: the skeleton, and an unbuildable sketch in a combination (2026-07-31)

AC1's cut (AC9 and AC7 folded in, since neither has a consumer without the loop; AC8 stays separate —
it needs shrinking, AC2): sub-step 1 is the skeleton — draw one sequence, convert the setup, run,
aggregate. It brought `check(int $seed)` (required seed; auto-generation + its indivisible reporting
are AC4), `Setup<TModel, TSut>`, a minimal `PropertyResult { public bool $passed }` (grows field by
field), and the generics on `StatefulProperty` with their consumer. The AC7 conversion sits in the
first executing line, per maurice's ordering correction: one `setup($initial)` call, `initialModel =
$model`, `freshSut = fn () => $system`. The passing test uses a run-recording double so `passed` is
not a vacuous "returned true without executing" — `$counter->runs > 0` is the independent observation.

**A third unbuildable-as-sketched form (second in SPEC-005), and this one lived in a *combination*.**
The sketch's `initial: ?Generator = null` with "omitted ⇒ `Gen::constant(null)` internally" cannot
type-check: the internal `null` fed to `setup: Closure(TInitial)` is unsound for a non-null `TInitial`
(PHPStan max, `argument.type`). Fix (amended): `initial` is required; the user writes
`Gen::constant(null)` for "no initial state" — one code path preserved, only the omit-convenience gone.
A factory method was rejected: it hides the choice the user should make and needs its own test path.

The lesson maurice drew: my per-class sketch review (Step 40) checked `Setup` and `PropertyResult` in
isolation and found them clean, but the unbuildability was in the *interaction* of two elements —
`initial`'s default × `setup`'s parameter type. The generalizable form: verify sketch elements that
share type parameters *in combination*, not one class at a time. It bit twice more the same day: making
`initial` required grew the constructor, so the four committed AC6 guard tests (which constructed
without `setup`/`initial`) hit `ArgumentCountError` before the guard — mechanically updated to supply
the now-required args (assertions unchanged, not weakened); and the guard tests' `StubCommand` had to
move from `Command<mixed, mixed, null>` to `Command<null, null, null>` so the alphabet's TModel/TSut
would agree with the tests' `Setup<null, null>` (`Setup` is invariant). The remaining SPEC-005 sketch
interaction — the full generic `PropertyResult<TModel, TSut, TInitial>` (counterexample vs the shrinker's
`ShrinkResult.commands`, initial vs the drawn `TInitial`) — reads clean on paper but is flagged to
verify in combination when AC2/AC4 build it, not to assume.

## Step 42 — SPEC-005 AC1 sub-step 2: n runs, fresh per sequence, stop at the first failure (2026-07-31)

The skeleton's body moved into `for ($run = 0; $run < $this->runs; $run++)`; `runs` is now promoted
(a reader exists). Three independent observations, each catching a distinct way the loop could be
quietly wrong — none of them the result's self-reported `runs`:

- **Fresh setup per sequence** (`$setups->count === n`): `setup()` is called inside the loop, so each
  run gets a fresh model+system and inherits nothing. maurice's sharp point: a "system === model"
  postcondition would *not* catch a setup-once leak, because model and system leak together and the
  comparison stays true — a test that passes because the fault is symmetric. The setup-call counter
  catches it directly (a leak calls setup once → count 1).
- **Initial drawn per sequence** (`draws === n`), decided, not once-and-reused: AC7's "once per
  sequence" and D012's whole motive — vary the initial to cover the space (all media types, not PNG
  a hundred times). A setup-call counter alone would miss a draw-once-reuse (setup still called n
  times), so a counting `CountingGenerator` double observes the draw count directly.
- **One advancing seeded stream** (`count(array_unique($receivedInitials)) > 1`): `Source::seeded()`
  sits *outside* the loop and advances, so each run draws a different sequence. maurice's catch:
  re-seeding inside the loop would draw the *same* sequence n times — invisible to the call-counters,
  which only count. A varying initial generator plus "the drawn initials are not all identical" is
  the cheapest catch for a per-iteration re-seed.

**The failure branch is proven, not just built.** Stop at the first failing sequence with a bare
`passed: false` (AC2 will enrich it with the shrunk counterexample). The test uses
`FailsFromSecondSequence` (passes sequence 1, fails from 2 via a shared tally the setup increments),
and asserts both `passed === false` **and** `$setups->count === 2` — the loop halted at the failing
sequence, not all n. "Stops" and "fails" are two properties (the SPEC-001 AC2 lesson); a loop that
ran all n would still report false but leave the count at n. Building the branch without this second
assertion would have been the `Failure::$reason`/D021 shape: correct, plausible, never verified.

## Step 43 — SPEC-005 AC9 (sub-step 3a): a vestigial `origin`, and R11's fourth escape (2026-07-31)

AC9 is a convention, not a generator: the length is drawn `integers(1, maxLength, origin: 1)`. The
behaviour already existed (sub-step 1's loop), so the test is green on arrival and mutant-proven —
`maxLength: 1` makes every one of n sequences exactly one command, so `runCounter === n`; the `min: 0`
mutant (`integers(0, 1)`) drew 7 empty sequences of 10 (seed 42), dropping the total to 3, which the
test catches. `min: 1` is load-bearing.

**`origin: 1` is vestigial — a fourth "unreachable trigger" (R11's class).** AC9's text promised
"shrinking it approaches 1", but `origin` only matters if the drawn length is shrunk *through this
generator*, and it never is: SPEC-002 shrinks the command **list** structurally (dropping contiguous
chunks) and never calls the length generator to reduce its value. So the "shrink approaches 1 /
`origin: 0` must break it" clause had a trigger nothing can reach — the same class as the empty-candidate
branch (D022) and `Failure::$reason`. Amended to what is true and testable (`[1, n]`, never zero);
`origin: 1` kept in the code with a comment (no consumer now, the right value if length-shrinking ever
comes).

The escape is itself the lesson maurice named: **R11 catches unreachable-trigger clauses at approval,
but AC9 was *transplanted* from SPEC-003 AC4, and transplanted text does not re-pass the gate.** Moving
an approved AC into a new spec re-homes its assumptions (here, that "the length shrinks" — true nowhere
in this architecture) without re-checking them against the new context. Worth carrying: when an AC moves
between specs, run R11 on it again in its new home; approval in the old one does not transfer.

## Step 44 — SPEC-005 AC10 (sub-step 3b): the vacuous pass, verdict not marker (2026-07-31)

New AC (amendment): a run in which no command ever executed across any sequence — an alphabet whose
preconditions never hold — is reported `passed: false` with a `vacuous` qualification, not `passed:
true`. It is the runtime counterpart of AC6: a property that verified nothing must never look like a
pass. The shape shifted during design: my first proposal was an AC5-style marker on a *passing*
result, but maurice's point settled it — a marker nobody reads leaves the green check green, which is
the same silent failure one layer up. So the verdict itself flips; the flag only distinguishes this
`passed: false` from a real counterexample (an empty counterexample without the flag would look like a
package bug).

Observed from `RunResult::$executed` — the run layer already records it, nothing new counted. The
condition is exactly **zero** executed, not a threshold: "too few ran" is a gradual judgement the tool
cannot defend; zero is objective. Vacuous and failure are mutually exclusive, recorded as reasoning
(not just a conclusion) so it can be re-checked if the failure path changes: a postcondition runs only
after `run()`, and a throwing `run()` has itself run, so every failing sequence executed at least one
command. Rendering the vacuous case is deferred to AC4 (it owns `counterexampleAsString`); AC10 owns
the verdict and the flag — the R11 catch of a clause promising an artefact that doesn't exist yet.

**Two mutants, both sides (maurice's point, the AC6-precedence two-sidedness).** Removing the branch
proves something happens at zero (the AC10 test falls back to `passed: true`) but not that it stays
quiet at non-zero — an always-`vacuous: true` implementation would survive it. So the second mutant
forces the condition always true and confirms a normal *passing* property falls over (the sub-step-1/2
tests assert `passed: true` and go red). One-sided mutation would have left "only fires at zero"
unproven.

## Step 45 — SPEC-005 AC3: reproduce generation before AC2 leans on it (2026-07-31)

Determinism comes before the failure path: debugging a shrinker on a loop not shown to reproduce is
the wrong order (maurice). AC3's behaviour already existed (the loop threads `Source::seeded($seed)`),
so the test is green on arrival and mutant-proven. Two-sided, like AC10: `$drawnWith(1) === $drawnWith(1)`
(same seed reproduces) **and** `$drawnWith(1) !== $drawnWith(2)` (a different seed varies). Only the
second catches a seed-ignoring but deterministic impl — the `Source::seeded(0)` mutant survives "same →
same" but breaks "different → different".

**AC3 split, so it is not checked off with the best half uncovered.** Its transplanted text (from
SPEC-001 AC5) promised "the same verdict **and counterexample**", but the counterexample does not exist
until AC2. Amended: AC3 owns *generation* reproduction; the "a found failure is re-found on the same
seed" half is delivered at AC2 with a forward reference — the one-AC-one-deliverable split, same shape
as AC10's rendering → AC4. (Another transplanted AC needing re-examination in its new home; cf. AC9.)

Two things recorded in the same motion (maurice):
- **Cross-process is measured, not derived.** `docs/verification/mt19937.php` runs the seeded engine in
  independent processes; the stronger formulation is "checked", not "follows from Mt19937". The honest
  caveats stay (prior-art): other PHP minors, 32-bit, non-Linux unverified — re-run if support is ever
  claimed beyond 64-bit Linux. A subprocess test would only re-measure a PRNG property.
- **A new requirement: the whole chain must stay deterministic, not just the generator.** `check(seed)`
  reproduces only if nothing between seed and outcome introduces non-determinism — no unordered
  iteration, no wall-clock time, no `spl_object_id` ordering. True today but nowhere required; stating it
  makes an accidental truth checkable, and the place a future non-deterministic addition would be caught.

## Step 46 — SPEC-005 AC2: the failure path, a fresh system per candidate, and a predictive lesson (2026-07-31)

On the first failing sequence, `check()` hands the run to SPEC-002's `shrink()` — the bare commands,
the failing `RunResult`, and a `freshSut` — and returns the shrunk counterexample, its `Failure`, and
the shrink's `executions`. The verify-first combination check (a throwaway: does `PropertyResult<TModel,
TSut>` fill from `ShrinkResult->commands` at max?) passed before any code was written.

**The freshSut is the AC7 guarantee, one layer deeper — and it is not the generation form.** At AC1 the
run used a *captured* `fn () => $setup->system` (one setup call, AC7). But the shrinker calls `freshSut`
**per candidate**, so a captured system would be shared across candidates — the R9b leak one level up,
and a subtly wrong counterexample. So the shrink's freshSut re-calls setup: `fn () =>
($this->setup)($initialValue)->system`. Two observations pin it: the counterexample is `[inc, inc]`, not
`[inc]` (reducing to one inc runs on a fresh counter, 1 < 2, so it does not reproduce — only true with
fresh systems; a leak shrinks wrongly to `[inc]`), and the exact setup count. maurice's sharpening: not
`setups > 1` (a lower bound an "extra-call-then-share" impl survives) but `setups === 2 + executions`.
The `2` is 1 generation run + 1 for the shrinker's AC8 non-determinism replay (a freshSut call not
counted in `executions`) — a refinement of the proposed `1 + executions` I reasoned and then confirmed
empirically. The number matching the model is information the shrinker behaves as expected, not noise.

**A combination wall after building (fourth of its kind), fixed cleanly.** Making `PropertyResult`
generic, the pass and vacuous branches return `PropertyResult<mixed, mixed>` — an empty counterexample
gives PHPStan nothing to bind `TModel/TSut` from, clashing with `check()`'s `@return`. The verify-first
had checked the *failure* combination, not this pass/vacuous return typing. Fixed with a typed
`noCounterexample(): list<Command<TModel, TSut, mixed>>` returning `[]` — an empty list is a valid such
list, so the element type is stated without an inline `@var`.

**A predictive lesson on choosing an observation (n=3).** AC2's shrink broke the sub-step-2 stop-test:
its `setups->count === 2` counted setup calls, and the shrinker builds systems too, so the count stopped
measuring the stop. Third time a later AC invalidated an earlier observation — AC8 broke the AC3 filter
test the same way. Both times a counter conflated two things because the second did not yet exist; both
fixes moved to the quantity *closer to the claim* (there, `executions`; here, the initial-draw count,
which the shrinker never touches because it reuses the captured initial). The forward-looking rule this
yields, for choosing a test's observation: ask not only "does this catch the fault" but "will this keep
measuring what I mean when layers are added below it". A proxy that happens to equal the real quantity
today drifts the moment a new layer shares the proxy's cause. Revised separately, before AC2, and shown
to still bite (a continue-past-failure mutant makes the initial-draw count 5, not 2).

## Step 47 — SPEC-005 AC2-2b: reproducing the counterexample, and a test with no honest mutant (2026-07-31)

AC3's forward-referenced half: same seed → same counterexample. Green on arrival, and — unlike AC3's
generation test — **there is deliberately no mutant**, because none would prove anything:

- "Different seed → different counterexample" is *unsound*, not merely weak: two seeds can legitimately
  shrink to the same minimal counterexample — that is what shrinking does. Asserting it would be false.
- A seed-ignoring impl (`Source::seeded(0)`) uses the same seed for both calls, so it *passes* the
  reproduction assertion. It is caught at AC3 (the generation test's different-seed side), not here.
- The shrinker is deterministic by construction (no randomness to mutate).

So reproducibility of the counterexample follows from AC3 (generation reproduces) + SPEC-002 R4 (the
shrinker is deterministic). Constructing a mutant that reddens without discriminating would be the
`mt_rand` refusal at AC4 again — a red that proves nothing.

**What the test IS, precisely (maurice's framing):** the *composition* is the failure mode. AC3 covers
generation, SPEC-002 covers the shrinker, but nothing covered that the wiring between them in `check()`
passes determinism through — exactly the AC3 chain requirement (no unordered iteration, no wall-clock
time, no `spl_object_id`), which had no test guarding it. This test is that guard: it falls the moment
someone introduces a non-deterministic source in `check()`, and the drawn integer carried in the
counterexample (`TaggedFailure`, whose argument SPEC-002 does not shrink) makes any re-draw visible. Its
strength is derived — resting on AC3 and SPEC-002 — and it is labelled so in the test: not a weak test
with an excuse, but a test with a precisely delimited task.

## Step 48 — Why the SPEC-005 traceability table drifted empty (2026-07-31)

Caught by maurice, not by a gate: the SPEC-005 Traceability rows sat empty through AC1, AC2, AC3, AC6,
AC7, AC9, AC10, while at SPEC-002 they were filled per AC. Nothing failed over it, and that is the
point — no gate watches an empty traceability table. `composer check` does not read the spec; the
three-sided completeness check (every AC a test, every deliverable an AC, every scope item a deliverable)
runs only at `implemented`, so between `approved` and `implemented` the table can sit blank for the whole
build. The fix is a habit, not a gate: fill the AC's Traceability row in the same commit that implements
it — the section may change on an `approved` spec without re-approval, so nothing blocks it. Recorded
because "a habit is the fix" is exactly the kind of thing that quietly lapses again unless the reason it
was needed is written down: the table is the running record of what is done, and it drifted because its
only reader (the finalisation check) was months away.

## Step 49 — SPEC-005 AC8: a layer-boundary property, found by reporting the mutant instead of passing through it (2026-07-31)

AC8 (shrinking holds the initial state fixed) is the first `tests/Meta/` case for SPEC-005: a planted
initial-state-dependent bug — a command fails only when the drawn `n === 0`, surfaced through its
postcondition (D022) — shrinks to `[check]` only if the shrinker holds the failing draw fixed. Green on
arrival: the mechanism is the `freshSut = fn () => setup($initialValue)->system` built at AC2, which
reuses the captured initial rather than re-drawing. maurice's caution up front — do the two observations
(the `[check]` counterexample and the "drawn exactly once" counter) catch different things? — reasoned
to "no" before writing and confirmed after: both fall under the one re-draw mutant. One property, three
angles (the guard `executions > 0` falls too).

**The mutant's outcome was the real finding, and only reporting it instead of passing through surfaced
it.** A re-drawing `freshSut` does not merely produce a wrong counterexample: it makes the failing
sequence produce two different verdicts across the shrinker's replay, so SPEC-002's own AC8
non-determinism guard **aborts** (`executions → 0`, the counterexample becomes the original unshrunk
sequence). That is a **layer-boundary property**: a bug in SPEC-005's wiring manifests, one layer down,
as a *non-deterministic system* — because from the shrinker's side the two are indistinguishable. So the
guarantee that keeps SPEC-005's shrink deterministic is largely SPEC-002's; AC8's meta-test documents
that the held-fixed mechanism is present and correct, it does not add an independent line of defence.

The consequence for a reader of the abort (maurice's point, and where it belongs): `abandonedNonDeterministic`
has two causes the shrinker cannot tell apart — a genuinely flaky system, or a caller whose `freshSut`
rebuilds fresh state per candidate instead of holding it fixed. That caveat now lives at the flag itself
(`ShrinkResult::$abandonedNonDeterministic` docblock), not only here — so someone debugging a production
abort is told to rule out their own wiring before blaming the system. The lesson under the lesson: an
adversarial check earns its keep not only when it kills a mutant but when *how* it kills one reveals
where a guarantee actually lives.
