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
 * A planted initial-state-dependent bug for SPEC-005 AC8 (R8): the system carries a drawn integer `n`,
 * and a command fails only when `n === 0`. The bug surfaces **through a command's postcondition**
 * (D022 — there is no command-independent failure in this model). The minimal counterexample is
 * `[check]`, and it is reachable only if the shrinker holds the drawn initial (0) fixed across
 * candidates: a re-drawn initial gives most candidates `n !== 0`, where `check` passes.
 */
final class NBox
{
    public function __construct(public int $n) {}
}

/**
 * Fails iff the system's initial `n` is 0 — the planted bug, detected by the postcondition (D022).
 *
 * @implements Command<null, NBox, null>
 */
final class CheckN implements Command
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
        return $sut->n !== 0;
    }

    public function __toString(): string
    {
        return 'check';
    }
}

/**
 * Always passes — droppable noise, so the shrink has something to reduce (and the held-fixed initial
 * matters for that reduction).
 *
 * @implements Command<null, NBox, null>
 */
final class NoiseN implements Command
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
 * An initial-state generator that draws 0 or 1 (so the bug fires for some draws, not others) and
 * counts how often it is drawn — the direct observation that the shrinker does not re-draw it.
 *
 * @implements Generator<int>
 */
final class CountingInitial implements Generator
{
    public int $draws = 0;

    public function generate(Source $source): GeneratedValue
    {
        $this->draws++;

        return new GeneratedValue($source->nextInt(0, 1));
    }

    public function shrink(GeneratedValue $value): iterable
    {
        return [];
    }
}

it('holds the drawn initial state fixed while shrinking (SPEC-005 AC8)', function () {
    $initial = new CountingInitial;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new NoiseN), Gen::constant(new CheckN)],
        setup: fn (int $n): Setup => new Setup(model: null, system: new NBox($n)),
        initial: $initial,
        maxLength: 6,
        runs: 1,             // one generation run, so the draw count is unambiguous
    );

    $result = $property->check(seed: 3);   // verified: this seed draws n === 0 with noise before the check

    // Guard on the pinned seed: it must draw n === 0 (so the bug fires) with droppable noise before the
    // check (so the shrink actually reduces). If generation ever changes, these fail loudly rather than
    // the test silently measuring the wrong thing.
    expect($result->passed)->toBeFalse()
        ->and($result->vacuous)->toBeFalse()
        ->and($result->executions)->toBeGreaterThan(0);   // the shrink reduced → noise was present

    // AC8 (R8): the minimal counterexample is exactly [check] — reachable only if the drawn initial
    // (0) is held fixed across candidates.
    expect(array_map(fn (Command $c): string => (string) $c, $result->counterexample))->toBe(['check']);

    // The same held-fixed property, observed directly: the shrinker reuses the captured initial and
    // never re-draws it, so it was drawn exactly once — during generation.
    //
    // Where the protection comes from (verified, and worth knowing). The mechanism is green on arrival
    // (the freshSut built at AC2 captures the initial). Its mutant is a re-drawing freshSut, and it does
    // NOT merely produce a wrong counterexample: it triggers SPEC-002's own AC8 non-determinism abort —
    // a re-drawing freshSut is indistinguishable, from the shrinker's side, from a non-deterministic
    // system, so the replay diverges and shrinking aborts (executions → 0, the counterexample becomes
    // the original unshrunk sequence, draws → 2). So all three assertions above fall together, from that
    // one cause; they are one property from three angles, not independent checks. And SPEC-002 would
    // catch this fault even without this test — the guarantee that keeps SPEC-005's shrink deterministic
    // is largely SPEC-002's. This test documents that the held-fixed mechanism is present and correct;
    // it does not add an independent line of defence.
    expect($initial->draws)->toBe(1);
})->group('meta')->group('SPEC-005');
