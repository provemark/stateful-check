<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;
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

// --- AC1 sub-step 2: n runs, fresh setup and drawn initial per sequence, stop at the first failure. -

/** A plain counter, so a setup closure can record how many times it was called (once per sequence). */
final class Tally
{
    public int $count = 0;
}

/**
 * An initial-state generator that counts its draws — proves the initial is drawn per sequence (n),
 * not once and reused (1). A `Gen::map` side effect would make an impure generator, so an explicit
 * double is the honest form. Never shrunk here (no failure path uses it), so `shrink()` is empty.
 *
 * @implements Generator<null>
 */
final class CountingGenerator implements Generator
{
    public int $draws = 0;

    public function generate(Source $source): GeneratedValue
    {
        $this->draws++;

        return new GeneratedValue(null);
    }

    public function shrink(GeneratedValue $value): iterable
    {
        return [];
    }
}

/**
 * Passes on the first sequence, fails from the second: its postcondition reads a shared tally the
 * setup increments once per sequence. Used to prove the loop runs past the first sequence and STOPS
 * at the first failing one.
 *
 * @implements Command<null, null, null>
 */
final class FailsFromSecondSequence implements Command
{
    public function __construct(private Tally $tally) {}

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
        return $this->tally->count < 2;
    }

    public function __toString(): string
    {
        return 'failsFrom2';
    }
}

it('runs n sequences, each with a fresh setup and its own drawn initial (SPEC-005 AC1)', function () {
    $runs = new RunCounter;
    $setups = new Tally;
    $initialGenerator = new CountingGenerator;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new RecordingCommand($runs))],
        setup: function (mixed $initial) use ($setups): Setup {
            $setups->count++;

            return new Setup(model: null, system: null);
        },
        initial: $initialGenerator,
        runs: 5,
    );

    $result = $property->check(seed: 999);

    // Independent observations, not the result's self-report. `$setups->count === 5`: setup called
    // once per sequence, so each run gets a fresh model+system — a leak would call it once, and a
    // `system === model` postcondition would not catch that (model leaks with it, symmetrically).
    // `$initialGenerator->draws === 5`: the initial is drawn per sequence (D012's cover-the-space),
    // not once and reused (which would be 1).
    expect($result->passed)->toBeTrue()
        ->and($setups->count)->toBe(5)
        ->and($initialGenerator->draws)->toBe(5)
        ->and($runs->runs)->toBeGreaterThan(0);
})->group('SPEC-005');

it('stops at the first failing sequence and reports failure (SPEC-005 AC1)', function () {
    $setups = new Tally;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new FailsFromSecondSequence($setups))],
        setup: function (mixed $initial) use ($setups): Setup {
            $setups->count++;

            return new Setup(model: null, system: null);
        },
        initial: Gen::constant(null),
        runs: 5,
    );

    $result = $property->check(seed: 999);

    // "Fails" and "stops" are two properties (SPEC-001 AC2). The property fails (passed false) AND the
    // loop halts at the failing sequence (the 2nd), so only 2 setups happened — not all 5. A loop that
    // ran all 5 would still report false but leave `$setups->count === 5`, catching a missing stop.
    expect($result->passed)->toBeFalse()
        ->and($setups->count)->toBe(2);
})->group('SPEC-005');

it('advances one seeded stream across the sequences, so they are not all identical (SPEC-005 AC1)', function () {
    $received = [];
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: function (mixed $initial) use (&$received): Setup {
            $received[] = $initial;

            return new Setup(model: null, system: null);
        },
        initial: Gen::integers(0, 1_000_000),   // varies per draw, so re-seeding shows up
        runs: 5,
    );

    $property->check(seed: 999);

    // One `Source` runs through all sequences. Re-seeding it per iteration would draw the SAME
    // sequence five times; with a varying generator the drawn initials would then be all identical.
    // That they are not is the cheapest catch for a per-iteration re-seed — which the call-counting
    // observations above cannot see.
    expect(count(array_unique($received)))->toBeGreaterThan(1);
})->group('SPEC-005');

// --- AC9: sequence length is drawn in [1, n], never zero. -----------------------------------------

it('draws every sequence length in [1, n], never zero (SPEC-005 AC9)', function () {
    $runs = new RunCounter;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new RecordingCommand($runs))],
        setup: fn (mixed $i): Setup => new Setup(model: null, system: null),
        initial: Gen::constant(null),
        maxLength: 1,   // integers(1, 1) is always 1, so every one of the n sequences runs exactly one command
        runs: 10,
    );

    $property->check(seed: 42);

    // Every sequence has length 1 (min: 1, never 0), so exactly n commands ran across n sequences. The
    // `min: 0` mutant — integers(0, 1) — would draw some empty sequences, dropping the total below n.
    expect($runs->runs)->toBe(10);
})->group('SPEC-005');
