<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\StatefulProperty;

/**
 * A minimal command, so the alphabet is a well-typed `Generator<Command>` — the guard only reads the
 * alphabet's length, but the constructor's type is honest and PHPStan enforces it.
 *
 * @implements Command<mixed, mixed, null>
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

it('throws at construction when the command alphabet is empty (SPEC-005 AC6)', function () {
    expect(fn () => new StatefulProperty(
        alphabet: [],
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('throws at construction when the maximum length is below one (SPEC-005 AC6)', function () {
    expect(fn () => new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        maxLength: 0,
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('throws at construction when the run count is below one (SPEC-005 AC6)', function () {
    expect(fn () => new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        runs: 0,
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('constructs without throwing when the configuration is valid (SPEC-005 AC6)', function () {
    // The discriminator: a guard that always threw would pass the three throw-cases above. A valid
    // configuration must construct cleanly, proving the guard rejects only the run-nothing shapes and
    // is not blindly throwing. Non-vacuous.
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
    );

    expect($property)->toBeInstanceOf(StatefulProperty::class);
})->group('SPEC-005');
