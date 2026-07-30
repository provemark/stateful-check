# SPEC-001: Command contract and model-driven sequential runner

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

Property-based testing generates values, not programs. Bugs that depend on the *order* of
operations — a read before a write, a setter that clobbers an unrelated slot, a
retry that double-applies — are unreachable by generating more inputs; you have
to generate sequences of operations and check invariants after each step.

Erlang (PropEr), Clojure (stateful-check), Python (Hypothesis) and TypeScript
(fast-check) all solve this with the same four-part shape: a command alphabet, a
shadow model, a transition function, and a postcondition. PHP has no
implementation of it. Every project that wants it hand-rolls the loop, as was
done twice in `provemark/content-credentials`.

This spec defines that shape as a reusable contract. Shrinking is SPEC-002;
without it this is a convenience, not a tool. Two decisions here exist purely to
make SPEC-002 simple, and are called out where they occur: commands are
independent (no symbolic results), and the runner records which commands
actually executed.

Governing rules: R1 (shrink over executed commands), R4 (determinism), R6 (model
is the oracle), R9 (commands reused across candidates) in CLAUDE.md.

## Scope

**In scope**

- A `Command` contract with four responsibilities: precondition, execution
  against the system under test, a **pure** model transition, and a
  postcondition comparing system to model.
- A sequential runner that drives system and model in lockstep and reports the
  first failing step.
- Recording, per command, whether it was executed — the execution path SPEC-002
  shrinks over and SPEC-002 AC8 uses to detect non-determinism.
- Optional cloning of commands, so a command carrying state can still be replayed
  safely (R9b).

**Out of scope** (each needs its own spec before it may be built)

- Sequence shrinking (SPEC-002).
- Value generation and value-level shrinking (SPEC-003).
- Parallel, scheduled or interleaved execution, and any form of race detection
  (R5).
- **Symbolic results** — commands referencing the return value of earlier
  commands. See the resolved open question below: deliberately excluded from
  v0.1, and the contract carries no reference slot.
- A `wellFormed()` abstract replay. It was specified to serve the shrinker; the
  shrinker does not need it (R1). Not built until something requires it.
- Test-framework integration beyond a plain callable entry point.

## Behavior

- **AC1 — a passing sequence runs to completion**
  - Given a system and model that agree, and a sequence of commands
  - When the runner executes the sequence
  - Then every enabled command is executed in order, every postcondition is
    checked after its command, and the run reports success.

- **AC2 — the first failing postcondition stops the run**
  - Given a sequence in which command *k* leaves system and model disagreeing
  - When the runner executes it
  - Then execution stops at *k*, and the result carries the failing index, the
    command, the model state before it, and the postcondition's reason.

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

- **AC5 — the same seed reproduces the same run** *(R4)*
  - Given a seed and a generated sequence
  - When the runner is invoked twice with that seed
  - Then the same sequence is produced, the same commands execute, and the same
    verdict is reached.

- **AC6 — an exception from the system is a failure, not a crash** *(required:
  error path)*
  - Given a command whose `run()` throws an unexpected exception
  - When the runner executes it
  - Then the run reports a failure at that index with the exception attached,
    rather than propagating it uncaught; and a command that is *expected* to
    throw, asserted in its own `postCondition()`, reports success.

## API sketch

Illustrative only.

```php
// namespace Provemark\StatefulCheck;

/**
 * A single operation in a generated sequence.
 *
 * Commands must be independent (R9a): no command may consume the result of an
 * earlier command. They must be stateless, or implement Cloneable (R9b).
 *
 * @template TModel
 * @template TSut
 */
interface Command
{
    /** May this run in the given model state? False means: skip, do not fail. */
    public function preCondition(mixed $model): bool;

    /** Execute against the real system. */
    public function run(mixed $sut): mixed;

    /** How the model changes as a result. MUST be pure; may return a new model. */
    public function nextState(mixed $model): mixed;

    /** Did the system agree with the model? */
    public function postCondition(mixed $model, mixed $result): bool;

    /** Used in counterexample output, e.g. "withdraw[3]". Keep it short. */
    public function __toString(): string;
}

/** Implemented only by commands that carry mutable state (R9b). */
interface Cloneable
{
    public function cloneCommand(): static;
}

final readonly class RunResult
{
    /**
     * @param  list<bool>  $executed  per position: did this command run? (AC4)
     */
    public function __construct(
        public bool $passed,
        public array $executed,
        public ?int $failedAtIndex = null,
        public ?Command $failedCommand = null,
        public mixed $modelBefore = null,
        public ?Throwable $exception = null,
        public ?string $reason = null,
    ) {}
}

final class SequenceRunner
{
    /** @param list<Command> $commands */
    public function run(array $commands, callable $freshSut, mixed $initialModel): RunResult;
}
```

## Open questions

- ~~**Symbolic results.** Should `Command` carry a reference slot even if unused
  in v0.1, since adding it later is a breaking change?~~
  **Resolved: no.** fast-check's `ICommand` has no reference mechanism either,
  and that is precisely why its shrinker can drop non-executed commands without
  re-validating anything. Adding references would force precondition
  re-validation and reference renumbering back into SPEC-002. Excluded from v0.1
  as a documented limitation (R9a). If it is ever added, it is a new major
  version with SPEC-001 and SPEC-002 amended together.
- ~~**Skipped versus filtered commands.** Skip a command whose precondition
  fails, or reject the whole sequence at generation time?~~
  **Resolved: skip** (AC3), as fast-check does. It keeps generation simple and is
  the precondition for R1.
- **Generics (open, non-blocker).** PHP has no runtime generics; PHPStan
  templates give the safety at static-analysis level only. Confirm the annotation
  strategy before writing the contract, since it shapes every downstream
  signature. Decide whether `TModel`/`TSut` are worth the annotation noise or
  whether `mixed` plus per-project docblocks is enough.
- **Cloning: opt-in interface or always-clone (open, non-blocker).** The sketch
  makes cloning an opt-in interface. Always cloning via `clone` is simpler but
  imposes a copy on every candidate for stateless commands, which is the common
  case. Measure before choosing.

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
