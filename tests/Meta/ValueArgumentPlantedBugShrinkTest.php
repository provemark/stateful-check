<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\SequenceRunner;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/**
 * SPEC-010 AC13 (R8) — the value generators actually plug into SPEC-006's argument family.
 *
 * Every other criterion in this spec proves a generator shrinks correctly in isolation. This one
 * plants a bug in a system under test and asserts the EXACT minimal counterexample the shrinker
 * reports, which is the only thing that shows the two halves are wired together. A shrinker that
 * merely does not crash is not tested (R8).
 */
/** @implements Command<null, stdClass, null> */
final class NameCmd implements Command
{
    public function __construct(public string $name) {}

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

    /** The planted bug: any name of four characters or more breaks the system. */
    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        $characters = preg_split('//u', $this->name, -1, PREG_SPLIT_NO_EMPTY);

        return count($characters === false ? [] : $characters) < 4;
    }

    public function __toString(): string
    {
        return "name({$this->name})";
    }
}

/**
 * @implements Command<null, stdClass, null>
 *
 * @phpstan-type Items list<int>
 */
final class ItemsCmd implements Command
{
    /** @param  list<int>  $items */
    public function __construct(public array $items) {}

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

    /** The planted bug: two items or more break the system, whatever they are. */
    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return count($this->items) < 2;
    }

    public function __toString(): string
    {
        return 'items('.implode(',', $this->items).')';
    }
}

/**
 * Draws from the alphabet until the command satisfies $wanted, so the shrink has a real distance
 * to cover. A seed hunt rather than a hand-built GeneratedValue: the context must be the one the
 * generator itself produces, or the test would prove something about a context nobody creates.
 *
 * @param  Generator<Command<null, stdClass, mixed>>  $alphabet
 * @param  callable(Command<null, stdClass, mixed>): bool  $wanted
 * @return GeneratedValue<Command<null, stdClass, mixed>>
 */
function drawUntil(Generator $alphabet, callable $wanted): GeneratedValue
{
    for ($seed = 1; $seed < 5000; $seed++) {
        $drawn = $alphabet->generate(Source::seeded($seed));

        if ($wanted($drawn->value)) {
            return $drawn;
        }
    }

    throw new RuntimeException('could not draw the planted-bug scenario — generation changed.');
}

it('shrinks a planted string-argument bug to exactly four first-alphabet characters (SPEC-010 AC13)', function () {
    $alphabet = Gen::alphabet([
        Gen::map(fn (string $s): NameCmd => new NameCmd($s), Gen::strings(0, 8, ['a', 'b', 'c'])),
    ]);

    $drawn = drawUntil($alphabet, fn (Command $c): bool => $c instanceof NameCmd && strlen($c->name) >= 7);

    $freshSut = fn (): stdClass => new stdClass;
    $sequence = [$drawn];
    $original = (new SequenceRunner)->run(
        array_map(fn (GeneratedValue $wrapper): Command => $wrapper->value, $sequence),
        $freshSut,
        null,
    );
    expect($original->passed)->toBeFalse();

    $result = (new SequenceShrinker(new SequenceRunner))->shrink($sequence, $original, $freshSut, null, $alphabet);

    // Four characters, because three pass; all 'a', because the simplification family aims at the
    // alphabet's first character. Both families had to work, in that order (AC4).
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['name(aaaa)']);
})->group('meta')->group('SPEC-010');

it('shrinks a planted list-argument bug to exactly two items at the origin (SPEC-010 AC13)', function () {
    $alphabet = Gen::alphabet([
        Gen::map(
            /** @param  list<mixed>  $items */
            function (array $items): ItemsCmd {
                $ints = [];
                foreach ($items as $item) {
                    $ints[] = is_int($item) ? $item : 0;
                }

                return new ItemsCmd($ints);
            },
            Gen::listsOf(Gen::integers(0, 50), 0, 5),
        ),
    ]);

    $drawn = drawUntil($alphabet, fn (Command $c): bool => $c instanceof ItemsCmd && count($c->items) >= 4);

    $freshSut = fn (): stdClass => new stdClass;
    $sequence = [$drawn];
    $original = (new SequenceRunner)->run(
        array_map(fn (GeneratedValue $wrapper): Command => $wrapper->value, $sequence),
        $freshSut,
        null,
    );
    expect($original->passed)->toBeFalse();

    $result = (new SequenceShrinker(new SequenceRunner))->shrink($sequence, $original, $freshSut, null, $alphabet);

    // Two items, because one passes; both zero, because element reduction delegates to
    // integers(0, 50) and lands on its origin. Removal before reduction, as AC6 requires.
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['items(0,0)']);
})->group('meta')->group('SPEC-010');
