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

## Step 12 —

