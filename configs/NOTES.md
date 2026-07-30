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

## Step 1 —

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

## Step 3 —

