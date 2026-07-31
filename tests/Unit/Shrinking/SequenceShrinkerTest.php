<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Failure;
use Provemark\StatefulCheck\FailureKind;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\Ref;
use Provemark\StatefulCheck\RunResult;
use Provemark\StatefulCheck\SequenceRunner;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/**
 * A minimal command double, distinguishable by label, so a filtered sequence can be asserted.
 *
 * @implements Command<mixed, mixed, null>
 */
final class Cmd implements Command
{
    public function __construct(private string $label) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

/**
 * A command with a configurable precondition and postcondition, so a *real* run produces a chosen
 * execution path and a real `Failure` — no fabricated `RunResult` needed. Used where the shrinker
 * replays the sequence and so requires it to genuinely reproduce (AC8).
 *
 * @implements Command<null, mixed, null>
 */
final class Fixed implements Command
{
    public function __construct(private string $label, private bool $pre, private bool $post) {}

    public function preCondition(mixed $model): bool
    {
        return $this->pre;
    }

    public function run(mixed $sut): mixed
    {
        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $this->post;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

it('filters the executed subset by reading the record, with no capacity to run a command (SPEC-002 AC3)', function () {
    // The trial-and-error CATCH, isolated from the shrink loop and from AC8's replay. `executedSubset`
    // takes only the sequence and the recorded run — no `freshSut`, no system — so it *structurally
    // cannot* discover the drop by trying candidates (a stronger guarantee than counting sut
    // constructions: the capability is absent, not merely unused). A fabricated record is fine here:
    // the filter reads it, it does not validate that it reproduces (that is shrink()'s job, AC8).
    $failing = [
        new GeneratedValue(new Cmd('a')), // skipped: precondition false
        new GeneratedValue(new Cmd('b')), // executed, and failed here
        new GeneratedValue(new Cmd('c')), // never reached (after the failure at b)
    ];
    $original = new RunResult(false, [false, true, false], new Failure(FailureKind::PostconditionFalse, 1, Cmd::class));

    $subset = (new SequenceShrinker(new SequenceRunner))->executedSubset($failing, $original);

    // Both the skipped (a) and never-reached (c) commands are gone; b remains.
    expect(array_map(fn (GeneratedValue $value): string => (string) $value->value, $subset))->toBe(['b']);
})->group('SPEC-002');

it('drops non-executed commands without running a candidate (SPEC-002 AC3)', function () {
    // The end-to-end CLAIM: shrink() composes the filter, and dropping the non-executed commands costs
    // zero candidate executions. `Fixed` makes the run genuine (a skips on a false precondition, b
    // fails its postcondition, c is never reached) so AC8's replay reproduces it and does not abort.
    $freshSut = fn (): stdClass => new stdClass;
    $failing = [
        new GeneratedValue(new Fixed('a', pre: false, post: true)), // skipped: precondition false
        new GeneratedValue(new Fixed('b', pre: true, post: false)),  // executed, and failed here
        new GeneratedValue(new Fixed('c', pre: true, post: true)),   // never reached (after b)
    ];
    $original = (new SequenceRunner)->run(array_map(fn (GeneratedValue $gv) => $gv->value, $failing), $freshSut, null);

    $result = (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing, $original, Gen::constant(new Fixed('unused', pre: true, post: true)), $freshSut, null,
    );

    // b remains, and no candidate ran to discover the drop: the filter reads the record, it does not
    // try. `executions` is the precise claim; the structural catch above proves the filter *cannot*
    // trial-and-error even if a mutant made this counter lie.
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['b'])
        ->and($result->executions)->toBe(0);
})->group('SPEC-002');

it('fails loudly when the executed record does not match the sequence length', function () {
    $failing = [new GeneratedValue(new Cmd('a')), new GeneratedValue(new Cmd('b'))];
    // Three executed entries for a two-command sequence: `$failing` and `$original` are not the same
    // run. Filtering on this would drop the wrong positions and silently return a wrong answer.
    $original = new RunResult(false, [true, false, true], new Failure(FailureKind::PostconditionFalse, 0, Cmd::class));

    expect(fn () => (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing,
        $original,
        Gen::constant(new Cmd('unused')),
        fn (): stdClass => new stdClass,
        null,
    ))->toThrow(LogicException::class);
})->group('SPEC-002');

it('every structural candidate retains the last executed command (SPEC-002 AC4)', function () {
    // The filtered failing sequence; d is the last executed command — the one that failed.
    $sequence = [
        new GeneratedValue(new Cmd('a')),
        new GeneratedValue(new Cmd('b')),
        new GeneratedValue(new Cmd('c')),
        new GeneratedValue(new Cmd('d')),
    ];

    $candidates = iterator_to_array((new SequenceShrinker(new SequenceRunner))->candidateReductions($sequence), false);

    // Non-vacuous: there is at least one genuine reduction to check the invariant against.
    expect($candidates)->not->toBeEmpty();

    foreach ($candidates as $candidate) {
        $labels = array_map(fn (GeneratedValue $gv): string => (string) $gv->value, $candidate);

        // Every candidate ends with d: removing the command that caused the failure is never a
        // useful reduction, so the structural family never drops it. And it is a real reduction.
        expect($labels)->not->toBeEmpty()
            ->and(end($labels))->toBe('d')
            ->and(count($labels))->toBeLessThan(count($sequence));
    }
})->group('SPEC-002');

// --- AC2: local minimum, over a Ref<int> counter system. ------------------------------------------

/**
 * Increments the counter; its postcondition fails once the counter reaches 2.
 *
 * @implements Command<null, Ref<int>, null>
 */
final class Trigger implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value++;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->value < 2;
    }

    public function __toString(): string
    {
        return 'trigger';
    }
}

/**
 * Does nothing — an irrelevant command.
 *
 * @implements Command<null, Ref<int>, null>
 */
final class Noise implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return 'noise';
    }
}

/**
 * Sets the counter to 1, so a following Blow does not divide by zero.
 *
 * @implements Command<null, Ref<int>, null>
 */
final class Prime implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value = 1;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return 'prime';
    }
}

/**
 * Throws when the counter is zero (as a division would); its postcondition always fails otherwise.
 *
 * @implements Command<null, Ref<int>, null>
 */
final class Blow implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        if ($sut->value === 0) {
            throw new DivisionByZeroError('counter is zero');
        }

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return 'blow';
    }
}

it('shrinks to a local minimum: no single further reduction still fails (SPEC-002 AC2)', function () {
    $freshSut = fn (): Ref => new Ref(0);
    // Two Triggers make it fail (counter reaches 2); the Noise between them is irrelevant.
    $failing = [new GeneratedValue(new Trigger), new GeneratedValue(new Noise), new GeneratedValue(new Trigger)];
    $original = (new SequenceRunner)->run(array_map(fn (GeneratedValue $gv) => $gv->value, $failing), $freshSut, null);

    $originalFailure = $original->failure ?? throw new RuntimeException('the failing sequence did not fail');

    $result = (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing, $original, Gen::constant(new Noise), $freshSut, null,
    );

    // AC1 invariant (cross-cutting, live for the first time): the returned sequence still fails, and
    // by the same identity — re-run against a fresh system.
    $rerun = (new SequenceRunner)->run($result->commands, $freshSut, null);
    $rerunFailure = $rerun->failure ?? throw new RuntimeException('the shrunk sequence did not fail');
    expect($rerun->passed)->toBeFalse()
        ->and($rerunFailure->sameKindAs($originalFailure))->toBeTrue();

    // AC2: it reduced (Noise dropped), running candidates as it went, and it is a local minimum —
    // no single further reduction still reproduces the original failure.
    expect(count($result->commands))->toBeLessThan(count($failing))
        ->and($result->executions)->toBeGreaterThan(0);

    $reWrapped = array_map(fn ($c) => new GeneratedValue($c), $result->commands);
    foreach ((new SequenceShrinker(new SequenceRunner))->candidateReductions($reWrapped) as $candidate) {
        $r = (new SequenceRunner)->run(array_map(fn (GeneratedValue $gv) => $gv->value, $candidate), $freshSut, null);
        $reproduces = ! $r->passed && $r->failure !== null && $r->failure->sameKindAs($originalFailure);
        expect($reproduces)->toBeFalse();
    }
})->group('SPEC-002');

it('does not drift to a candidate that fails for a different reason (SPEC-002 AC1/AC2)', function () {
    $freshSut = fn (): Ref => new Ref(0);
    // [Prime, Blow]: Prime sets the counter to 1, Blow (counter != 0) fails its postcondition — a
    // PostconditionFalse. Dropping Prime leaves [Blow], which throws on a zero counter — an
    // UnexpectedException, a different kind. The shrinker must reject that drift.
    $failing = [new GeneratedValue(new Prime), new GeneratedValue(new Blow)];
    $original = (new SequenceRunner)->run(array_map(fn (GeneratedValue $gv) => $gv->value, $failing), $freshSut, null);

    $originalFailure = $original->failure ?? throw new RuntimeException('the failing sequence did not fail');
    expect($originalFailure->kind)->toBe(FailureKind::PostconditionFalse);

    $result = (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing, $original, Gen::constant(new Noise), $freshSut, null,
    );

    // The returned sequence still fails the ORIGINAL kind — the loop did not accept [Blow]'s
    // UnexpectedException and drift to a bug we were not looking for (R2). This is what the
    // sameKindAs condition in the accept-check protects.
    $rerun = (new SequenceRunner)->run($result->commands, $freshSut, null);
    $rerunFailure = $rerun->failure ?? throw new RuntimeException('the shrunk sequence did not fail');
    expect($rerun->passed)->toBeFalse()
        ->and($rerunFailure->sameKindAs($originalFailure))->toBeTrue();
})->group('SPEC-002');

it('fails loudly when the original run did not fail', function () {
    $failing = [new GeneratedValue(new Cmd('a'))];
    $passing = new RunResult(true, [true]); // a passing run has no failure to shrink toward

    expect(fn () => (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing,
        $passing,
        Gen::constant(new Cmd('unused')),
        fn (): stdClass => new stdClass,
        null,
    ))->toThrow(LogicException::class);
})->group('SPEC-002');

// --- AC6: cloning isolates candidates. ------------------------------------------------------------

/** Records the internal counter a command saw at each run, shared across a command's clones. */
final class CloneLog
{
    /** @var list<int> */
    public array $records = [];

    public function record(int $ran): void
    {
        $this->records[] = $ran;
    }
}

/**
 * Sets the counter to 1, so a following Check fails.
 *
 * @implements Command<null, Ref<int>, null>
 */
final class Setup implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value = 1;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return 'setup';
    }
}

/**
 * Carries mutable state ($ran) of its own, records it into a shared log at each run, and fails its
 * postcondition when a Setup ran before it. If the shrinker clones it per candidate (R9b), $ran is
 * always the pristine 0; if state leaked, it would accumulate.
 *
 * @implements Command<null, Ref<int>, null>
 */
final class Check implements Command
{
    public int $ran = 0;

    public function __construct(private CloneLog $log) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $this->log->record($this->ran);
        $this->ran++;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->value !== 1;
    }

    public function __toString(): string
    {
        return 'check';
    }
}

it('clones a command between candidates, so its mutable state does not leak (SPEC-002 AC6)', function () {
    $log = new CloneLog;
    $check = new Check($log);
    $failing = [new GeneratedValue(new Setup), new GeneratedValue(new Noise), new GeneratedValue($check)];
    // Fabricated so Check stays pristine until the shrinker clones it (a real run would mutate it).
    $original = new RunResult(false, [true, true, true], new Failure(FailureKind::PostconditionFalse, 2, Check::class));

    (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing, $original, Gen::constant(new Noise), fn (): Ref => new Ref(0), null,
    );

    // Check ran in several candidates. If each candidate cloned it (R9b), it always saw its pristine
    // counter (0); a leak would let $ran accumulate across candidates. Non-vacuous: it ran more than
    // once, so a leak would show. The original object is never mutated — the loop carries the
    // original GeneratedValues, and stillFails clones locally.
    expect(count($log->records))->toBeGreaterThan(1)
        ->and($check->ran)->toBe(0);
    foreach ($log->records as $ran) {
        expect($ran)->toBe(0);
    }
})->group('SPEC-002');

it('respects the budget: reaching the minimum within it is minimal, one short is budget-limited (SPEC-002 AC9)', function () {
    $freshSut = fn (): Ref => new Ref(0);
    $failing = [new GeneratedValue(new Trigger), new GeneratedValue(new Noise), new GeneratedValue(new Trigger)];
    $original = (new SequenceRunner)->run(array_map(fn (GeneratedValue $gv) => $gv->value, $failing), $freshSut, null);
    $originalFailure = $original->failure ?? throw new RuntimeException('the failing sequence did not fail');

    // Measure how many candidate runs reaching the local minimum costs — do not hardcode it. That
    // number is an implementation detail of candidateReductions and changes when the strategy or
    // families change (AC7); the "last allowed run confirms the minimum vs one short" distinction
    // must stay valid regardless. The measuring budget is generous but FINITE — PHP_INT_MAX would
    // hang the suite with no error if the loop ever failed to terminate (e.g. once a length-
    // preserving family lands). Assert the measurement itself terminated naturally, well under
    // budget, so N is a valid natural cost.
    $measuringBudget = 1000;
    $unbounded = (new SequenceShrinker(new SequenceRunner, budget: $measuringBudget))->shrink(
        $failing, $original, Gen::constant(new Noise), $freshSut, null,
    );
    $n = $unbounded->executions;
    $minimum = array_map(fn (Command $c): string => (string) $c, $unbounded->commands);
    expect($unbounded->budgetExhausted)->toBeFalse() // the measurement was not itself budget-limited
        ->and($n)->toBeGreaterThan(1)                // non-vacuous: the minimum takes more than one run
        ->and($n)->toBeLessThan($measuringBudget);   // and it terminated well under the measuring budget

    // Budget exactly N: the minimum is confirmed on the last allowed run — minimal, NOT budget-
    // limited, even though executions === budget. The flag means "stopped before the minimum", not
    // "budget reached".
    $atBudget = (new SequenceShrinker(new SequenceRunner, budget: $n))->shrink(
        $failing, $original, Gen::constant(new Noise), $freshSut, null,
    );
    expect($atBudget->budgetExhausted)->toBeFalse()
        ->and($atBudget->executions)->toBe($n)
        ->and(array_map(fn (Command $c): string => (string) $c, $atBudget->commands))->toBe($minimum);

    // Budget N - 1: one run short of confirming the minimum — budget-limited, best-so-far returned.
    $underBudget = (new SequenceShrinker(new SequenceRunner, budget: $n - 1))->shrink(
        $failing, $original, Gen::constant(new Noise), $freshSut, null,
    );
    expect($underBudget->budgetExhausted)->toBeTrue()
        ->and($underBudget->executions)->toBe($n - 1);

    // AC1 invariant holds even when budget-limited: the returned sequence still fails, same kind.
    $rerun = (new SequenceRunner)->run($underBudget->commands, $freshSut, null);
    $rerunFailure = $rerun->failure ?? throw new RuntimeException('the budget-limited result did not fail');
    expect($rerun->passed)->toBeFalse()
        ->and($rerunFailure->sameKindAs($originalFailure))->toBeTrue();
})->group('SPEC-002');
