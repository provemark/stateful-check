<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\SequenceRunner;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/**
 * A planted order-dependent bug (R8, SPEC-002 AC7): the system corrupts its value when `Trip` runs
 * after `Prime`, and behaves correctly otherwise. The model is the honest oracle (it always predicts
 * the correct value, `+1` per `Trip`), so the corruption surfaces as a failing postcondition. The
 * known minimal reproducing sequence is exactly `[Prime, Trip]`.
 *
 * The system holds the state; the commands are stateless and independent (R9a) — they read and mutate
 * the system, never each other's results — so no `__clone` is needed.
 */
final class OrderSystem
{
    public int $value = 0;

    public bool $primed = false;
}

/**
 * Genuine no-op that still executes and passes: it touches neither `value` nor `primed`, so the model
 * stays in sync and its postcondition holds. It exists to be *dropped* by the candidate families —
 * crucially by executing, not by being filtered as non-executed (AC3), which is what makes it prove
 * the family's reach rather than the filter's.
 *
 * @implements Command<int, OrderSystem, null>
 */
final class OrderNoise implements Command
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
        return $sut->value === $model;
    }

    public function __toString(): string
    {
        return 'noise';
    }
}

/**
 * Arms the bug: sets the system's `primed` flag. It does not change the observable value, so the
 * model is unchanged and its own postcondition passes — a primed-but-not-yet-tripped system is still
 * correct.
 *
 * @implements Command<int, OrderSystem, null>
 */
final class OrderPrime implements Command
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
        return $sut->value === $model;
    }

    public function __toString(): string
    {
        return 'prime';
    }
}

/**
 * Should increment the value by one. The bug: on a primed system it corrupts the value instead. The
 * model always predicts the correct `+1`, so a primed `Trip` fails its postcondition — and only then.
 *
 * @implements Command<int, OrderSystem, null>
 */
final class OrderTrip implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        if ($sut->primed) {
            $sut->value = 999; // the planted bug: corruption when Prime ran earlier
        } else {
            $sut->value++;
        }

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model + 1; // the honest transition: Trip increments by one
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->value === $model;
    }

    public function __toString(): string
    {
        return 'trip';
    }
}

it('shrinks an order-dependent bug to its known minimal sequence [Prime, Trip] (SPEC-002 AC7)', function () {
    $freshSut = fn (): OrderSystem => new OrderSystem;
    // Noise on both sides of Prime: reaching [Prime, Trip] requires dropping the LEADING noise (a
    // capability the retained-suffix family needs) and the MIDDLE noise in a SEPARATE pass (a
    // non-contiguous drop the loop's restart provides). One planted case exercises both.
    $failing = [
        new OrderNoise,
        new OrderPrime,
        new OrderNoise,
        new OrderTrip,
    ];
    $original = (new SequenceRunner)->run($failing, $freshSut, 0);

    // Guard against a false red: every command — both Noise included — must genuinely execute and the
    // run must fail at Trip. If a Noise were skipped or unreached it would fall out via AC3 before the
    // family sees it, and the case would prove the filter, not the family's reach.
    expect($original->passed)->toBeFalse()
        ->and($original->executed)->toBe([true, true, true, true]);

    $result = (new SequenceShrinker(new SequenceRunner))->shrink(
        $failing, $original, $freshSut, 0,
    );

    // The exact minimal sequence, by string form (SPEC-002 AC7).
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['prime', 'trip']);
})->group('meta')->group('SPEC-002');
