<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\SequenceRunner;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/**
 * Always fails, carrying an integer argument for the argument family to reduce (SPEC-006). A single
 * `Overdraw` is structurally minimal (length 1), so only the argument family can make it smaller.
 *
 * @implements Command<null, null, null>
 */
final class Overdraw implements Command
{
    public function __construct(public int $amount) {}

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
        return false;
    }

    public function __toString(): string
    {
        return "overdraw({$this->amount})";
    }
}

it("reduces a command's argument to the smallest that still fails (SPEC-006 AC1)", function () {
    // A command drawn from a real alphabet, so its GeneratedValue carries the context the argument
    // family shrinks through. It always fails, so every smaller argument still fails — the minimum is
    // the origin (1, the range's floor).
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Overdraw => new Overdraw($n), Gen::integers(1, 100)),
    ]);
    $drawn = $alphabet->generate(Source::seeded(7));

    // Guard the pinned seed: it draws a large, reducible value (well above the origin 1). Fails loudly
    // if generation ever changes, rather than silently testing a value with nothing to reduce.
    expect((string) $drawn->value)->toBe('overdraw(93)');

    $freshSut = fn (): ?object => null;
    $original = (new SequenceRunner)->run([$drawn->value], $freshSut, null);

    $result = (new SequenceShrinker(new SequenceRunner))->shrink(
        [$drawn],
        $original,
        $freshSut,
        null,
        $alphabet,
    );

    // Structural shrinking cannot touch a length-1 sequence; only the argument family reduces the value.
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['overdraw(1)']);
})->group('SPEC-006');

it('yields length-preserving, single-position candidates — the alphabet shrinks, one at a time (SPEC-006 AC2)', function () {
    // A shape-pin, green on arrival — the family already has this shape. The mutant (accumulating changes
    // across positions instead of resetting per candidate) is the proof, not a red-first failure.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Overdraw => new Overdraw($n), Gen::integers(1, 100)),
    ]);
    $sequence = [$alphabet->generate(Source::seeded(7)), $alphabet->generate(Source::seeded(3))];

    $candidates = iterator_to_array(
        (new SequenceShrinker(new SequenceRunner))->argumentReductions($sequence, $alphabet),
        false,
    );

    // The family yields exactly the per-position alphabet shrinks — pin the count so a mutant yielding
    // nothing, or two positions at once, is caught. Non-vacuous: there is at least one reduction.
    $expected = 0;
    foreach ($sequence as $wrapper) {
        $expected += count(iterator_to_array($alphabet->shrink($wrapper), false));
    }
    expect($expected)->toBeGreaterThan(0)
        ->and($candidates)->toHaveCount($expected);

    foreach ($candidates as $candidate) {
        expect($candidate)->toHaveCount(count($sequence));   // length-preserving

        // Exactly one position differs from the parent (by identity — unchanged positions are the same
        // wrapper object; `shrink()` never yields the value itself, so the changed one always differs).
        $differing = 0;
        foreach ($candidate as $i => $wrapper) {
            if ($wrapper !== $sequence[$i]) {
                $differing++;
            }
        }
        expect($differing)->toBe(1);
    }
})->group('SPEC-006');

it('every argument reduction strictly lowers the (length, distance-to-origin) measure (SPEC-006 AC4)', function () {
    // The measure the termination proof rests on, asserted directly — because a test that merely completes
    // proves only that this case ended, and a family that failed to strictly decrease would hang the loop
    // (accept a non-progressing candidate forever), which never reddens, it just runs until the suite is
    // killed. Iterating `argumentReductions` here checks the measure WITHOUT running the accept loop, so a
    // non-decreasing candidate fails an assertion instead of hanging. The family is length-preserving, so
    // only the distance-to-origin sum can move; the range floor is the origin (1), so distance is amount − 1.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Overdraw => new Overdraw($n), Gen::integers(1, 100)),
    ]);
    $sequence = [$alphabet->generate(Source::seeded(7)), $alphabet->generate(Source::seeded(3))];

    $amountOf = function (mixed $wrapper): int {
        return $wrapper instanceof GeneratedValue && $wrapper->value instanceof Overdraw ? $wrapper->value->amount : 0;
    };
    $sumOf = fn (array $wrappers): int => array_sum(array_map($amountOf, $wrappers));

    $parent = $sumOf($sequence);
    $candidates = iterator_to_array(
        (new SequenceShrinker(new SequenceRunner))->argumentReductions($sequence, $alphabet),
        false,
    );

    expect($candidates)->not->toBeEmpty();
    foreach ($candidates as $candidate) {
        expect(count($candidate))->toBe(count($sequence))     // length component unchanged
            ->and($sumOf($candidate))->toBeLessThan($parent);  // distance-to-origin sum strictly lower
    }
})->group('SPEC-006');

it('the shrink loop terminates on its own, without the budget biting (SPEC-006 AC4)', function () {
    // Both commands always fail and both are reducible, so the length-preserving argument family runs; a
    // deliberately huge budget cannot be the reason the loop stops. Reaching the assertion means it
    // terminated, and `budgetExhausted === false` means it stopped on the measure, not the bound — which is
    // what "termination does not rest on the budget" requires.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Overdraw => new Overdraw($n), Gen::integers(1, 100)),
    ]);
    $sequence = [$alphabet->generate(Source::seeded(7)), $alphabet->generate(Source::seeded(11))];
    $freshSut = fn (): ?object => null;
    $original = (new SequenceRunner)->run(array_map(fn (GeneratedValue $w): Command => $w->value, $sequence), $freshSut, null);

    $result = (new SequenceShrinker(new SequenceRunner, budget: 100_000))->shrink(
        $sequence,
        $original,
        $freshSut,
        null,
        $alphabet,
    );

    expect($result->budgetExhausted)->toBeFalse();
})->group('SPEC-006');

it('yields no argument candidates for a value already at its origin (SPEC-006 AC6, case a)', function () {
    // The normal end of every shrink, not an error path — `overdraw(1)` has no smaller value that still
    // fails — so green on arrival (AC1 and AC4 already reach it). The pin: an origin value yields nothing
    // and the family simply moves on, rather than throwing or fabricating a command.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Overdraw => new Overdraw($n), Gen::integers(1, 100)),
    ]);
    $reducible = $alphabet->generate(Source::seeded(7));   // overdraw(93)
    $atOrigin = null;
    foreach ($alphabet->shrink($reducible) as $shrunk) {
        if ((string) $shrunk->value === 'overdraw(1)') {
            $atOrigin = $shrunk;
            break;
        }
    }
    if ($atOrigin === null) {
        throw new RuntimeException('could not obtain an origin (overdraw(1)) wrapper — generation changed.');
    }

    $candidates = iterator_to_array(
        (new SequenceShrinker(new SequenceRunner))->argumentReductions([$atOrigin], $alphabet),
        false,
    );
    expect($candidates)->toBeEmpty();
})->group('SPEC-006');

it('lets a generator context error propagate, never swallows it (SPEC-006 AC6, case c)', function () {
    // A wrapper whose context the alphabet cannot read is a generator/usage bug. The family lets the
    // LogicException propagate — loud — rather than catching it and silently skipping the position, which
    // would be the exact silent degradation this package exists to prevent. Green on arrival (there is no
    // catch); mutant-proven — a swallowing try/catch around `$alphabet->shrink()` reddens this.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): Overdraw => new Overdraw($n), Gen::integers(1, 100)),
    ]);
    $badContext = new GeneratedValue(new Overdraw(5), 'not a [int, GeneratedValue] context');

    expect(fn () => iterator_to_array(
        (new SequenceShrinker(new SequenceRunner))->argumentReductions([$badContext], $alphabet),
        false,
    ))->toThrow(LogicException::class);
})->group('SPEC-006');

/**
 * Fails only when its argument is at least 50 — so the argument family converges to the *threshold* (50),
 * not the origin: 49 passes. A minimum that is a real boundary, not just the range floor.
 *
 * @implements Command<null, null, null>
 */
final class AtLeast implements Command
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
        return $this->n < 50;
    }

    public function __toString(): string
    {
        return "atLeast({$this->n})";
    }
}

it('reduces a value to the minimal that still fails, not the origin — a combined local minimum (SPEC-006 AC3)', function () {
    // The combined local minimum is **self-referential**, exactly as SPEC-002 AC2 records: this shows the
    // loop stops where no single structural OR argument reduction still fails — not that the families are
    // strong enough to reach the true minimum. Only AC7 (a planted bug with a known minimum) can test that,
    // and with two families there is more room for a minimum that sits higher than needed. `AtLeast` fails
    // only at n >= 50, so the argument family converges to exactly 50 (49 passes → no further argument
    // reduction still fails), and length 1 leaves no structural reduction — a combined minimum at a real
    // threshold, not the range floor.
    $alphabet = Gen::alphabet([
        Gen::map(fn (int $n): AtLeast => new AtLeast($n), Gen::integers(1, 100)),
    ]);
    $drawn = $alphabet->generate(Source::seeded(7));
    expect((string) $drawn->value)->toBe('atLeast(93)');   // guard the pinned seed

    $freshSut = fn (): ?object => null;
    $original = (new SequenceRunner)->run([$drawn->value], $freshSut, null);

    $result = (new SequenceShrinker(new SequenceRunner))->shrink([$drawn], $original, $freshSut, null, $alphabet);

    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['atLeast(50)']);
})->group('SPEC-006');
