<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\SequenceRunner;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/** A shared counter and a "a Bump has run" flag — the flag is system state, not an argument. */
final class TripBox
{
    public int $counter = 0;

    public bool $bumped = false;
}

/**
 * Setup only: raises the counter and sets the flag, never fails.
 *
 * @implements Command<null, TripBox, null>
 */
final class Bump implements Command
{
    public function __construct(public int $n) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->counter += $this->n;
        $sut->bumped = true;

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
        return "bump({$this->n})";
    }
}

/**
 * Fails only if the counter has reached its argument AND a Bump has run.
 *
 * @implements Command<null, TripBox, null>
 */
final class Trip implements Command
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
        return ! ($sut->counter >= $this->n && $sut->bumped);
    }

    public function __toString(): string
    {
        return "trip({$this->n})";
    }
}

it('never returns a counterexample containing a non-executed command — the argument-family tripwire (SPEC-006 AC5)', function () {
    // The closest anyone got to reaching AC5's trigger: [Bump(b), Trip(t1 > b), Trip(t2 <= b)]. The tail
    // Trip fails on the counter+flag Bump provides; reducing the middle Trip toward the origin could make
    // it fail first, leaving the retained tail unexecuted. It does not happen — structure-first drops the
    // passing middle Trip, and the tail fails with the surviving Bump — so the shrinker recovers to a
    // fully-executed counterexample. This asserts that, unconditionally.
    //
    // TRIPWIRE, not mutant-proven. The violation cannot currently be constructed (three documented
    // attempts; the reachability finding in NOTES and SPEC-006), so no mutant reddens this and it is not
    // a proof of correctness. Its value is that it fires if a future change — a different family order, or
    // a finer-grained `sameKindAs` — makes the trigger reachable. The re-filter (AC5) keeps it green even
    // then; this is what would catch the re-filter being removed once the trigger is reachable.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Bump => new Bump($n), Gen::integers(1, 100)),
        Gen::map(fn (int $n): Trip => new Trip($n), Gen::integers(1, 100)),
    ]);

    // Draw a Bump(b) with b in [40, 70], a Trip(t1 > b), and a Trip(t2 in [5, b]). Deterministic search;
    // guarded below so it fails loudly if generation changes rather than silently testing nothing.
    $bump = null;
    $b = 0;
    for ($seed = 1; $seed < 3000 && $bump === null; $seed++) {
        $drawn = $alphabet->generate(Source::seeded($seed));
        $command = $drawn->value;
        if ($command instanceof Bump && $command->n >= 40 && $command->n <= 70) {
            $bump = $drawn;
            $b = $command->n;
        }
    }
    $head = $tail = null;
    for ($seed = 1; $seed < 6000 && ($head === null || $tail === null); $seed++) {
        $drawn = $alphabet->generate(Source::seeded($seed));
        $command = $drawn->value;
        if ($command instanceof Trip && $command->n > $b && $head === null) {
            $head = $drawn;
        }
        if ($command instanceof Trip && $command->n <= $b && $command->n >= 5 && $tail === null) {
            $tail = $drawn;
        }
    }

    // Fail loudly if generation changed and the scenario cannot be assembled, rather than testing nothing.
    if ($bump === null || $head === null || $tail === null) {
        throw new RuntimeException('could not draw the [Bump, Trip>b, Trip<=b] scenario — generation changed.');
    }

    $sequence = [$bump, $head, $tail];
    $freshSut = fn (): TripBox => new TripBox;
    $original = (new SequenceRunner)->run(
        array_map(fn (GeneratedValue $wrapper): Command => $wrapper->value, $sequence),
        $freshSut,
        null,
    );

    // Guard the scenario: the whole sequence executes and the failure is the tail — otherwise this is not
    // the forward-move case and the tripwire would be arming nothing.
    expect($original->passed)->toBeFalse()
        ->and($original->executed)->toBe([true, true, true]);

    $result = (new SequenceShrinker(new SequenceRunner))->shrink($sequence, $original, $freshSut, null, $alphabet);

    // The invariant: every command in the returned counterexample actually ran in its own replay.
    $replay = (new SequenceRunner)->run(
        array_map(fn (Command $command): Command => clone $command, $result->commands),
        $freshSut,
        null,
    );
    expect(in_array(false, $replay->executed, true))->toBeFalse();
})->group('meta')->group('SPEC-006');
