<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
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
