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
