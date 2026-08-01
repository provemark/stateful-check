<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\SequenceRunner;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/** A system flag: whether a Arm has run. */
final class ArmBox
{
    public bool $primed = false;
}

/**
 * Structural noise: does nothing, always passes — must be dropped by the structural family.
 *
 * @implements Command<null, ArmBox, null>
 */
final class Filler implements Command
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
 * Setup: arms the flag, always passes — needed, so the structural family cannot drop it.
 *
 * @implements Command<null, ArmBox, null>
 */
final class Arm implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->primed = true;

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
 * The planted bug: fails only if an Arm has run AND its argument is at least 50.
 *
 * @implements Command<null, ArmBox, null>
 */
final class Amount implements Command
{
    public function __construct(public int $n) {}

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
        return ! ($sut->primed && $this->n >= 50);
    }

    public function __toString(): string
    {
        return "amount({$this->n})";
    }
}

it('shrinks a planted bug needing both families to its exact minimal sequence and value (SPEC-006 AC7)', function () {
    // R8, and the argument-family analogue of SPEC-002 AC7's order-dependent bug — but this one needs
    // BOTH families, so it exercises the combined minimum (AC3) and the structure-first order. The bug
    // fires only when a Arm has armed the flag AND an Amount carries a value >= 50. So the minimum is
    // reached only if the structural family drops the noise and keeps the Arm, and the argument family
    // lowers the Amount to the threshold: [prime, amount(50)] — 49 passes, and dropping prime removes the
    // flag. Filler and Arm are constant branches (no argument shrinks); Amount is the reducible one.
    $alphabet = Gen::alphabet([
        Gen::constant(new Filler),
        Gen::constant(new Arm),
        Gen::map(fn (int $n): Amount => new Amount($n), Gen::integers(1, 100)),
    ]);

    // Draw one of each with real context; a large Amount so there is a real distance to the threshold.
    $noise = $prime = $amount = null;
    for ($seed = 1; $seed < 5000 && ($noise === null || $prime === null || $amount === null); $seed++) {
        $drawn = $alphabet->generate(Source::seeded($seed));
        $command = $drawn->value;
        if ($command instanceof Filler && $noise === null) {
            $noise = $drawn;
        }
        if ($command instanceof Arm && $prime === null) {
            $prime = $drawn;
        }
        if ($command instanceof Amount && $command->n >= 80 && $amount === null) {
            $amount = $drawn;
        }
    }
    if ($noise === null || $prime === null || $amount === null) {
        throw new RuntimeException('could not draw the [Filler, Arm, Amount>=80] scenario — generation changed.');
    }

    // Filler around the Arm and the Amount: the structural family must remove it, keep the Arm.
    $sequence = [$noise, $prime, $noise, $amount, $noise];
    $freshSut = fn (): ArmBox => new ArmBox;
    $original = (new SequenceRunner)->run(
        array_map(fn (GeneratedValue $wrapper): Command => $wrapper->value, $sequence),
        $freshSut,
        null,
    );
    // Guard: the whole prefix executes and the bug fires (the last noise is never reached, dropped up front).
    expect($original->passed)->toBeFalse();

    $result = (new SequenceShrinker(new SequenceRunner))->shrink($sequence, $original, $freshSut, null, $alphabet);

    // Exactly the minimal sequence AND the minimal argument value: both families cooperated.
    expect(array_map(fn (Command $command): string => (string) $command, $result->commands))->toBe(['prime', 'amount(50)']);
})->group('meta')->group('SPEC-006');
