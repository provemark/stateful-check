# SPEC-001: Command contract and model-driven sequential runner

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-30                               |
| Amended    | maurice, 2026-07-30 — `Command`'s `TResult` made `@template-covariant`, and `Outcome` made non-generic, so an alphabet may mix commands of different result types as `list<Command<M, S, mixed>>` (dogfood example 2: `sign` → null, `read` → report). `postCondition` now receives a non-generic `Outcome` and narrows the value if it needs the type. Same defect class as D017; recorded as D019. Re-approved on the same date. |
| Amended    | maurice, 2026-07-30 — `SequenceRunner::run` sketch brought in line with D001: `@template TModel`, `@template TSut`, `list<Command<TModel, TSut, mixed>>`, `callable(): TSut`. The bare `list<Command>` predated D001 and was never revisited; a concrete system type (dogfood example 2's `Ref`) would not type-check against it. No new decision — consistency fix. |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Property-based testing generates values, not programs. Bugs that depend on the
*order* of operations — a read before a write, a setter that clobbers an
unrelated slot, a retry that double-applies — are unreachable by generating more
inputs; you have to generate sequences of operations and check invariants after
each step.

Erlang (PropEr), Clojure (stateful-check), Python (Hypothesis) and TypeScript
(fast-check) all solve this with the same four-part shape: a command alphabet, a
shadow model, a transition function, and a postcondition. PHP has no
implementation of it. Every project that wants it hand-rolls the loop, as was
done twice in `provemark/content-credentials`.

This spec defines that shape as a reusable contract. Shrinking is SPEC-002; the
entry point that wires generation, execution and shrinking together is SPEC-005.

Two decisions here exist purely to make SPEC-002 simple, and are called out where
they occur: commands are independent (no symbolic results), and the runner
records which commands actually executed.

Governing rules: R1 (shrink over executed commands), R6 (model is the oracle),
R9 (commands reused across candidates) in CLAUDE.md.

## Scope

**In scope**

- A `Command` contract with four responsibilities: precondition, execution
  against the system under test, a **pure** model transition, and a
  postcondition comparing system to model.
- A sequential runner that drives system and model in lockstep and reports the
  first failing step.
- An `Outcome` wrapper so a command that is *expected* to throw can be modelled
  as normal behaviour rather than as a crash.
- A **structured** failure description, so SPEC-002 AC1 can compare two failures
  without comparing free-text messages.
- Recording, per command, whether it was executed — the execution path SPEC-002
  shrinks over and SPEC-002 AC8 uses to detect non-determinism.
- A `Ref` holder for immutable systems under test, required by dogfood example 1.
- Read access to the system handle from the postcondition, so an assertion can
  describe system state rather than only a return value.
- A cloning contract so a command carrying state can be replayed safely across
  the shrinker's candidates (R9b): every command is shallow-cloned, always, not
  opt-in (D006); a command holding an object it must not share implements
  `__clone`.

**Out of scope** (each needs its own spec before it may be built)

- Sequence shrinking (SPEC-002).
- Value generation and value-level shrinking (SPEC-003).
- Seeded end-to-end reproducibility. The runner receives a concrete list of
  commands and has no seed of its own; reproducibility is a property of the
  pipeline that generates them (SPEC-005 AC3).
- Parallel, scheduled or interleaved execution, and any form of race detection
  (R5).
- **Symbolic results** — commands referencing the return value of earlier
  commands. See the resolved open question below: deliberately excluded from
  v0.1, and the contract carries no reference slot.
- A `wellFormed()` abstract replay. It was specified to serve the shrinker; the
  shrinker does not need it (R1). Not built until something requires it.

## Design notes

Three points the acceptance criteria depend on.

**Step order.** For each command the runner performs, in this order:
`preCondition(model)` → if false, skip and continue → `run(sut)`, wrapped into an
`Outcome` → `model' = nextState(model)` → `postCondition(model', $sut, outcome)`.

The postcondition therefore receives the model **after** the transition: the
question it answers is "does the system now match what the model says it should
be". `RunResult` carries the pre-transition model as well, for diagnostics.

**The postcondition may read the system.** It receives the system handle
alongside the outcome. Withholding it would be an accidental restriction rather
than a designed one: fast-check's `run(model, real)` has the same access, and the
hand-rolled dogfood suite reached the system directly from its assertions. A
postcondition that cannot see the system can only check a single command's return
value, which is too narrow for "does the system now match the model".

Two disciplines come with that access. Reads should be cheap and deterministic —
over HTTP, an extra read per command doubles the traffic, so prefer returning
what you need from `run()`. And **anything that may throw belongs in `run()`**,
because only invocations made by the runner are wrapped into an `Outcome`; an
exception escaping a postcondition is an error in the test, not a captured
outcome.

**The transition runs even when `run()` threw.** This is deliberate: in the
common case the command mutated the system and *then* threw, so the model must
advance to the state in which it predicted that throw. The hazard to be aware of
is the reverse case — a command that throws *before* mutating leaves the model
ahead of the system, and every subsequent postcondition fails for the wrong
reason. Commands must therefore be written so that the pure transition describes
what `run()` does regardless of whether it completes; where that is impossible,
put the throwing operation last.

**`nextState` stays pure and outcome-independent.** It does not receive the
`Outcome`. This is deliberate and is what protects R6: a transition that can see
what actually happened can simply mirror the system, and a model that mirrors the
system can never disagree with it. A command that expects a failure must predict
it *from the model*, exactly as the hand-rolled dogfood suite does — the model
knows the agent name is blank, so it knows `build()` must throw.

**The system under test is a handle owned by the runner.** Commands may mutate
it; the runner never replaces it. For an immutable system — dogfood example 1,
where every operation returns a new builder — the handle is a small mutable
holder (`Ref`) whose contents the command swaps. This keeps R9a intact: the next
command reads current state from the handle, not from a previous command's return
value.

## Behavior

- **AC1 — a passing sequence runs to completion**
  - Given a system and model that agree
  - When the runner executes the sequence
  - Then every enabled command is executed in order, every postcondition is
    checked after its command's transition, and the run reports success.

- **AC2 — the first failing postcondition stops the run, with a structured
  failure**
  - Given a sequence in which command *k* returns normally and leaves system and
    model disagreeing
  - When the runner executes it
  - Then execution stops at *k*, and the result carries a `Failure` with kind
    `PostconditionFalse`, the failing index, the command's class, the model
    before and after the transition, and an optional human-readable reason that
    is **not** part of failure identity.
  - **Precedence rule:** `FailureKind` is decided by whether `run()` threw, not
    by the postcondition. Returned normally + postcondition false →
    `PostconditionFalse`. Threw + postcondition false → `UnexpectedException`
    (AC6). The two criteria overlap on "postcondition false"; this rule is what
    separates them, and without it two implementers would classify differently.

- **AC3 — a command whose precondition fails is skipped, not a failure**
  - Given a command whose `preCondition()` is false in the current model state
  - When the runner reaches it
  - Then `run()` is never called, `nextState()` is not applied, the sequence
    continues with the next command, and the run does not fail because of it.
  - *This is the mechanism that makes R1 sound: a shortened sequence can never be
    ill-formed, because commands that no longer apply are simply skipped.*

- **AC4 — the run records which commands executed**
  - Given any completed or failed run
  - When its result is inspected
  - Then it exposes, per position, whether that command was executed — in a form
    SPEC-002 can filter on and replay against.

- **AC5 — an expected exception is not a failure**
  - Given a command whose `run()` throws, and whose `postCondition()` returns
    true for an `Outcome` in the thrown state (see the precedence rule in AC2)
  - When the runner executes it
  - Then the exception is caught, wrapped into the `Outcome`, passed to the
    postcondition, and the run continues normally.
  - *This is the blank-argument case in dogfood example 1: the model predicts the
    error, so the error is correct behaviour.*

- **AC6 — an unexpected exception is a failure, not a crash** *(required: error
  path)*
  - Given a command whose `run()` throws and whose `postCondition()` returns
    false for that outcome
  - When the runner executes it
  - Then the run reports a `Failure` with kind `UnexpectedException`, carrying
    the exception's class and the failing command's class, rather than
    propagating the exception uncaught.

- **AC7 — the system handle is threaded, never replaced**
  - Given a sequence operating on an immutable system through a `Ref`
  - When the sequence executes
  - Then each command observes the state left by the previous executed command,
    and the runner holds the same handle instance throughout.

- **AC8 — the postcondition can observe the system**
  - Given a command whose postcondition asserts on system state rather than on
    the value returned by `run()`
  - When the runner executes it
  - Then the postcondition receives the same system handle the command ran
    against, reflecting all mutations made up to and including that command.

## API sketch

Illustrative only.

```php
// namespace Provemark\StatefulCheck;

/**
 * The result of invoking a command: a returned value, or a thrown Throwable.
 * A command that expects to throw asserts on this in its own postCondition, so
 * an expected error is ordinary behaviour (AC5).
 *
 * Not generic in the value (D019). `postCondition` sees the outcome without the
 * command's `TResult`, so the value is always `mixed`; a command that needs its
 * result's type narrows it. A type parameter could not be covariant anyway — the
 * `returned()` factory would put it in a parameter position — so it would only
 * force `TResult` invariant again, which is the defect D019 removes.
 */
final readonly class Outcome
{
    private function __construct(
        public bool $threw,
        public mixed $value = null,
        public ?Throwable $exception = null,
    ) {}

    public static function returned(mixed $value): self;

    public static function threw(Throwable $e): self;
}

enum FailureKind
{
    case PostconditionFalse;
    case UnexpectedException;
}

/**
 * Structured so SPEC-002 AC1 can ask "did the shrunk sequence fail for the same
 * reason" without comparing messages, which legitimately differ after shrinking.
 * Identity is kind + commandClass + exceptionClass; $reason is for humans only.
 */
final readonly class Failure
{
    public function __construct(
        public FailureKind $kind,
        public int $index,
        public string $commandClass,
        public ?string $exceptionClass = null,
        public ?string $reason = null,
    ) {}

    /** Identity comparison for SPEC-002 AC1. Ignores index and reason. */
    public function sameKindAs(self $other): bool;
}

/**
 * A single operation in a generated sequence.
 *
 * Commands must be independent (R9a): no command may consume the result of an
 * earlier command. The shrinker shallow-clones every command before each
 * candidate; a command that holds an object it must not share implements
 * __clone (R9b, D006).
 *
 * Three type parameters carry the model, the system handle, and the value run()
 * returns. A user binds them once with `@implements Command<MyModel, MySut,
 * MyResult>` above the class; PHPStan then types all four methods, so no per-method
 * annotation is needed (D001). A user who wants none of this may still bind `mixed`.
 *
 * `TResult` is covariant (D019): it appears only in run()'s return, so a
 * `Command<M, S, null>` is a `Command<M, S, mixed>`, and an alphabet may mix
 * commands of different result types as `list<Command<M, S, mixed>>` (dogfood
 * example 2: `sign` returns null, `read` returns a report). The price is that the
 * postcondition receives a non-generic `Outcome` and narrows the value itself; the
 * result type can no longer flow into the observation contract statically. Same
 * defect class as the invariant `Generator` at D017.
 *
 * @template TModel
 * @template TSut
 *
 * @template-covariant TResult
 */
interface Command
{
    /**
     * May this run in the given model state? False means: skip, do not fail.
     *
     * @param TModel $model
     */
    public function preCondition(mixed $model): bool;

    /**
     * Execute against the system handle. May mutate it; must not replace it.
     *
     * @param  TSut  $sut
     * @return TResult
     */
    public function run(mixed $sut): mixed;

    /**
     * MUST be pure, and MUST NOT depend on what actually happened (R6).
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function nextState(mixed $model): mixed;

    /**
     * $model is the state AFTER nextState. Does the system now match it?
     *
     * $sut is the same handle run() operated on: read it, do not mutate it, and
     * keep reads cheap. Anything that may throw belongs in run(), because only
     * the runner's invocation is wrapped into an Outcome.
     *
     * The outcome is a non-generic `Outcome` (its value is `mixed`), not tied to
     * TResult, so that TResult stays covariant (D019).
     *
     * @param  TModel  $model
     * @param  TSut    $sut
     */
    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool;

    /** Used in counterexample output, e.g. "withdraw[3]". Keep it short. */
    public function __toString(): string;
}

/**
 * Mutable handle for an immutable system under test (AC7).
 *
 * @template T
 */
final class Ref
{
    /** @param T $value */
    public function __construct(public mixed $value) {}
}

final readonly class RunResult
{
    /** @param list<bool> $executed per position: did this command run? (AC4) */
    public function __construct(
        public bool $passed,
        public array $executed,
        public ?Failure $failure = null,
        public mixed $modelBefore = null,
        public mixed $modelAfter = null,
    ) {}
}

final class SequenceRunner
{
    /**
     * A sequence runs commands sharing one model type and one system type; only their
     * result types vary, which the covariant TResult absorbs (bound to mixed here).
     *
     * @template TModel
     * @template TSut
     *
     * @param  list<Command<TModel, TSut, mixed>>  $commands
     * @param  callable(): TSut  $freshSut
     * @param  TModel  $initialModel
     */
    public function run(array $commands, callable $freshSut, mixed $initialModel): RunResult;
}
```

## Open questions

- ~~**Symbolic results.**~~ **Resolved: no.** fast-check's `ICommand` has no
  reference mechanism either, and that is precisely why its shrinker can drop
  non-executed commands without re-validating anything. Adding references would
  force precondition re-validation and reference renumbering back into SPEC-002.
  Excluded from v0.1 as a documented limitation (R9a). If it is ever added, it is
  a new major version with SPEC-001 and SPEC-002 amended together.
- ~~**Skipped versus filtered commands.**~~ **Resolved: skip** (AC3), as
  fast-check does. It keeps generation simple and is the precondition for R1.
- ~~**How does a command declare an expected failure?**~~ **Resolved:** the
  runner wraps every invocation in an `Outcome` and hands it to the
  postcondition (AC5). No fifth contract method, and `nextState` stays pure.
- ~~**How is an immutable system threaded through a sequence?**~~ **Resolved:**
  the system under test is a handle; immutable systems use `Ref` (AC7).
- ~~**Can a postcondition observe the system, or only the command's return
  value?**~~ **Resolved (D011):** it receives the handle (AC8). The earlier
  signature omitted it by accident, not by design.
- ~~**Where does seeded reproducibility live?**~~ **Resolved:** not here. The
  runner takes a concrete command list, so it has no seed. Moved to SPEC-005 AC3.
- ~~**Generics — `@template` or plain `mixed`?**~~ **Resolved (D001, revised):**
  `@template` on `Command` (`TModel`, `TSut`, `TResult`), and on `Outcome` and
  `Ref`. One `@implements Command<…>` line per class types all four methods; without
  templates a user writes four annotation blocks per class. The earlier "plain
  `mixed`" resolution was reversed once porting dogfood example 1 showed templates
  *remove* annotation noise rather than adding it.
- ~~**Cloning: opt-in interface or always clone?**~~ **Resolved (D006):** always
  clone, shallow. The opt-in `Cloneable` interface is removed; a command holding
  an object it must not share implements `__clone`.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | tests/Unit/SequenceRunnerTest.php :: "runs a passing sequence to completion…" (SPEC-001) | src/SequenceRunner.php :: SequenceRunner::run |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
