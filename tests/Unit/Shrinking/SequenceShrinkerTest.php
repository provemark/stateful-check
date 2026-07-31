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

it('drops non-executed commands by reading the record, without running a candidate (SPEC-002 AC3)', function () {
    // Only b executed and failed; a was skipped (precondition false) and c was never reached (after
    // the failure). Both meanings of a false position drop out, leaving b.
    $failing = [
        new GeneratedValue(new Cmd('a')), // skipped: precondition false
        new GeneratedValue(new Cmd('b')), // executed, and failed here
        new GeneratedValue(new Cmd('c')), // never reached (after the failure at b)
    ];
    $original = new RunResult(false, [false, true, false], new Failure(FailureKind::PostconditionFalse, 1, Cmd::class));

    $freshSutCalls = 0;
    $freshSut = function () use (&$freshSutCalls): stdClass {
        $freshSutCalls++;

        return new stdClass;
    };

    $result = (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing,
        $original,
        Gen::constant(new Cmd('unused')),
        $freshSut,
        null,
    );

    // Both the skipped (a) and never-reached (c) commands are gone; b remains.
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['b']);

    // The claim is specifically about the FILTER: it drops non-executed commands by reading
    // `executed`, running nothing to discover the drop. (A trial-and-error shrinker would call
    // freshSut per command.) A single-command representation has no further reduction to generate,
    // so no candidate runs and this stays true once the shrink loop exists — the drop's zero
    // executions are not entangled with the loop's.
    expect($freshSutCalls)->toBe(0)
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
