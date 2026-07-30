# SPEC-002: Sequence shrinking

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | maurice                                           |
| Approved   | — (draft)                                         |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This is the reason the package exists. Everything in SPEC-001 is a fifty-line
loop any project can hand-roll — and two projects already have. What nobody
builds themselves, and what ordinary property-based testing does not provide, is
reducing a failing *sequence* to the minimal sequence that still fails.

Value-level shrinking (SPEC-003) minimises a failing command's arguments. The
sequence itself does not shrink: you get the failing program at full generated length and
reduce it by hand. A twelve-command counterexample tells you almost nothing; the
two-command version tells you the bug.

The strategy is taken from fast-check's `CommandsArbitrary`, which solves exactly
this problem. Two things make it simpler than first assumed. Because a command
whose precondition fails is skipped rather than fatal (SPEC-001 AC3), a shortened
sequence can never be ill-formed, so candidates need no re-validation (R1). And
because execution stops at the failure, the last executed command is by
construction the one that caused it, so it is always retained.

Governing rules: R1 (shrink over executed commands), R2 (never return a passing
sequence), R3 (documented local minimum), R4 (determinism detected via execution
path), R8 (planted-bug meta-tests), R9 (clone between candidates).

## Scope

**In scope**

- Filtering the sequence to the commands that actually executed, using the
  execution record from SPEC-001 AC4.
- Candidate generation in three families, in this order:
  1. **the empty sequence**, tried exactly once, as the first candidate;
  2. **structural** — hold a prefix of length *k*, shrink the length of the
     retained suffix, always keeping the last executed command;
  3. **per-command argument** reduction via the generation core (SPEC-003),
     sequence length unchanged.
- Lazy candidate generation: candidates are produced on demand, not materialised.
- Cloning every command before running a candidate (R9b).
- Recording an execution path and detecting divergence on replay as the
  non-determinism signal (R4).
- A budget (maximum candidate executions) so shrinking terminates on expensive
  systems.

**Out of scope** (each needs its own spec before it may be built)

- Re-validating candidates against the model. Not needed — see Problem and R1.
  If symbolic results are ever introduced, this returns and the whole strategy
  must be re-specced.
- Global minimisation, exhaustive search, or any claim beyond a local minimum.
- Shrinking across parallel or scheduled interleavings (R5).
- Re-ordering commands as a reduction step. Deletion and argument reduction only;
  re-ordering changes semantics in ways the model may not catch.
- Caching or memoising system-under-test executions between candidates.

## Behavior

- **AC1 — the returned sequence still fails** *(R2, the central postcondition)*
  - Given a failing sequence
  - When it is shrunk
  - Then the returned sequence, executed against a fresh system, still fails —
    and fails with the same reason class as the original.

- **AC2 — the returned sequence is a local minimum** *(R3)*
  - Given a shrunk sequence
  - When any single further reduction candidate is generated from it
  - Then every such candidate passes. No single additional reduction step still
    fails.

- **AC3 — commands that did not execute are dropped** *(R1)*
  - Given a failing run in which some commands were skipped (precondition false)
    or never reached (after the failure)
  - When shrinking begins
  - Then those commands are absent from the shrink representation and from the
    returned counterexample, without any candidate being executed to discover it.

- **AC4 — the last executed command is always retained**
  - Given any structural reduction candidate
  - When it is produced
  - Then it still ends with the last executed command of the sequence it was
    derived from — that command caused the failure, so removing it is never a
    useful reduction.

- **AC5 — the empty sequence is tried once**
  - Given a failing sequence being shrunk for the first time
  - When candidates are generated
  - Then the empty sequence is the first candidate; if it fails, shrinking is
    complete and the counterexample is empty (the failure was not caused by the
    commands). On subsequent shrinks of an already-shrunk sequence it is not
    retried.

- **AC6 — commands are cloned between candidates** *(R9b)*
  - Given a command carrying mutable state
  - When it appears in two successive candidates
  - Then the second candidate receives a fresh clone, and no state from the first
    execution is observable in it.

- **AC7 — a planted bug shrinks to its known minimal sequence** *(R8)*
  - Given a system with a deliberately planted order-dependent bug and a known
    minimal reproducing sequence
  - When a long failing sequence is shrunk
  - Then the result equals that known minimal sequence exactly, compared by its
    string representation.

- **AC8 — an execution-path mismatch aborts shrinking** *(required: error path,
  R4)*
  - Given a recorded execution path and a replay in which a command marked
    not-executed does execute (or the reverse)
  - When the mismatch is detected
  - Then shrinking aborts, the original counterexample is returned unshrunk, and
    the result reports that the system under test is not deterministic — never a
    "minimal" sequence derived from unstable runs.

- **AC9 — shrinking respects the budget**
  - Given a budget of *n* candidate executions
  - When shrinking a sequence that could be reduced further
  - Then no more than *n* executions occur, the best sequence found so far is
    returned, and the result is flagged as budget-limited rather than minimal.

## API sketch

Illustrative only.

```php
// namespace Provemark\StatefulCheck\Shrinking;

final readonly class ShrinkResult
{
    /** @param list<Command> $commands */
    public function __construct(
        public array $commands,
        public int $originalLength,
        public int $executions,
        public bool $budgetExhausted = false,
        public bool $abandonedNonDeterministic = false,
    ) {}

    /** "inc[1],check[1]" — the counterexample as a reader sees it. */
    public function __toString(): string;
}

final class SequenceShrinker
{
    public function __construct(
        private SequenceRunner $runner,
        private int $budget = 200,
    ) {}

    /** @param list<Command> $failing */
    public function shrink(array $failing, callable $freshSut, mixed $initialModel): ShrinkResult;
}

/**
 * Candidates, lazily, in the order defined in Scope. `$executed` comes from
 * RunResult::$executed (SPEC-001 AC4); non-executed positions are already gone
 * from $sequence by the time this is called.
 *
 * @param  list<Command>  $sequence  executed commands only
 * @return iterable<list<Command>>
 */
function candidateReductions(array $sequence, bool $shrunkOnce): iterable;
```

Counterexample rendering matters more than it looks. fast-check invests real
effort in `toString` delegation, and the reason its shrink tests can assert an
exact string like `inc[1],check[1]` is that the plumbing exists. Budget for it.

## Open questions

- ~~**Restart versus continue after an accepted reduction.**~~
  **Resolved: restart.** fast-check tracks a `shrunkOnce` flag and produces a
  fresh context per accepted candidate, i.e. candidate generation begins again
  from the newly reduced sequence. Match it.
- ~~**Flat iterable versus shrink tree for candidates.**~~
  **Resolved: lazy iterable, with per-command context.** Structural candidates
  need no tree; per-command argument shrinking needs the context that the
  generation core carries alongside each value (SPEC-003).
- **"Same reason class" (AC1) — open, blocker for the test.** Needs a definition
  before AC1 can be tested. Exception class plus failing command type is probably
  right; comparing messages is too strict. Fix it before writing the test.
- **Budget default — open, non-blocker.** 200 executions is a guess: far more
  than needed in memory, already minutes over HTTP. Consider a time budget
  instead of, or alongside, a count.
- **Does the retained-suffix strategy need a matching prefix strategy? — open,
  non-blocker.** fast-check holds a prefix and shrinks the suffix length. Whether
  the mirror case (hold a suffix, shrink a prefix) finds anything extra is
  unmeasured. Do not add it speculatively; add it if a meta-test demonstrates a
  minimum it cannot reach.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
