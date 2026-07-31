<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\Setup;
use Provemark\StatefulCheck\StatefulProperty;

/**
 * A minimal command, so the alphabet is a well-typed `Generator<Command>` — the guard only reads the
 * alphabet's length, but the constructor's type is honest and PHPStan enforces it. Its model/system
 * types are `null` to agree with the guard tests' `Setup<null, null>` (alphabet and setup must share
 * TModel/TSut, and Setup is invariant).
 *
 * @implements Command<null, null, null>
 */
final class StubCommand implements Command
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
        return 'stub';
    }
}

// A valid setup and initial for the guard tests: they must reach the guard, so the now-required
// setup/initial are provided even though the guard throws before using them.
$validSetup = fn (mixed $i): Setup => new Setup(model: null, system: null);

it('throws at construction when the command alphabet is empty (SPEC-005 AC6)', function () use ($validSetup) {
    expect(fn () => new StatefulProperty(
        alphabet: [],
        setup: $validSetup,
        initial: Gen::constant(null),
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('throws at construction when the maximum length is below one (SPEC-005 AC6)', function () use ($validSetup) {
    expect(fn () => new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: $validSetup,
        initial: Gen::constant(null),
        maxLength: 0,
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('throws at construction when the run count is below one (SPEC-005 AC6)', function () use ($validSetup) {
    expect(fn () => new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: $validSetup,
        initial: Gen::constant(null),
        runs: 0,
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('constructs without throwing when the configuration is valid (SPEC-005 AC6)', function () use ($validSetup) {
    // The discriminator: a guard that always threw would pass the three throw-cases above. A valid
    // configuration must construct cleanly, proving the guard rejects only the run-nothing shapes and
    // is not blindly throwing. Non-vacuous.
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: $validSetup,
        initial: Gen::constant(null),
    );

    expect($property)->toBeInstanceOf(StatefulProperty::class);
})->group('SPEC-005');

// --- AC1 sub-step 1: the skeleton — draw one sequence, convert setup, run, aggregate. -------------

/** A shared counter so the test can observe how many times a command actually executed, independent
 *  of what the result self-reports. */
final class RunCounter
{
    public int $runs = 0;
}

/**
 * A command that always passes and records each execution against a shared counter. The counter is
 * what proves a run really happened — a `check()` that returned `passed: true` without executing
 * anything would leave it at zero.
 *
 * @implements Command<null, null, null>
 */
final class RecordingCommand implements Command
{
    public function __construct(private RunCounter $counter) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $this->counter->runs++;

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
        return 'rec';
    }
}

it('runs a drawn sequence and reports success for a passing property (SPEC-005 AC1)', function () {
    $counter = new RunCounter;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new RecordingCommand($counter))],
        setup: fn (mixed $initial): Setup => new Setup(model: null, system: null),
        initial: Gen::constant(null),   // required (amendment): explicit "no initial state"
    );

    $result = $property->check(seed: 12345);

    // A passing property reports success — and it genuinely ran: the recording double saw `run()` at
    // least once, so `passed === true` is not a vacuous "returned true without executing anything".
    // (Independent observation, not the result's self-report; sub-step 2 extends it to n runs.)
    expect($result->passed)->toBeTrue()
        ->and($counter->runs)->toBeGreaterThan(0);
})->group('SPEC-005');
