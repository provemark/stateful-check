<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Generation\Generator;
use Provemark\StatefulCheck\Generation\Source;
use Provemark\StatefulCheck\Outcome;

/**
 * SPEC-003 AC5 — the command-alphabet generator selects a branch from the source, records which
 * branch in the GeneratedValue's context, and delegates argument-shrinking to the generator that
 * produced the command. "Uniform choice" is the intent but not asserted here (one draw proves
 * nothing about a distribution); what is tested is source-determined, branch-recording,
 * correctly-delegating selection. `shrinkValuesOf()` is defined in ElementsGeneratorTest.php.
 *
 * Two minimal commands of different concrete class, so that delegating to the wrong branch is
 * visible: shrinking a TagCmd must yield TagCmds, never NumCmds.
 */
/** @implements Command<mixed, mixed, null> */
final class NumCmd implements Command
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
        return true;
    }

    public function __toString(): string
    {
        return "num({$this->n})";
    }
}

/** @implements Command<mixed, mixed, null> */
final class TagCmd implements Command
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
        return true;
    }

    public function __toString(): string
    {
        return "tag({$this->n})";
    }
}

/**
 * @return Generator<Command<mixed, mixed, mixed>>
 */
function twoBranchAlphabet(): Generator
{
    return Gen::alphabet([
        Gen::map(fn (int $n): NumCmd => new NumCmd($n), Gen::integers(0, 9)),
        Gen::map(fn (int $n): TagCmd => new TagCmd($n), Gen::integers(0, 9)),
    ]);
}

/**
 * A typed empty alphabet, so the empty-rejection test can call `Gen::alphabet` without leaving
 * its template types unresolvable (an empty literal infers nothing).
 *
 * @return list<Generator<Command<mixed, mixed, mixed>>>
 */
function emptyAlphabet(): array
{
    return [];
}

it('generates a command chosen by the source, the same for the same seed', function () {
    $alphabet = twoBranchAlphabet();

    $first = $alphabet->generate(Source::seeded(7))->value;

    // A command from one of the branches, and the same seed selects the same command.
    expect($first)->toBeInstanceOf(Command::class)
        ->and($alphabet->generate(Source::seeded(7))->value)->toEqual($first);
})->group('SPEC-003');

it('shrinks by delegating to the recorded branch, not the other one', function () {
    $alphabet = twoBranchAlphabet();

    // A value as the alphabet produces for branch 1 (TagCmd) with argument 8: the context carries
    // the chosen index plus the branch's own GeneratedValue (as map(..., integers) produces it).
    $branchValue = new GeneratedValue(new TagCmd(8), new GeneratedValue(8));
    $gv = new GeneratedValue(new TagCmd(8), [1, $branchValue]);

    // Delegation to the *recorded* branch is the falsifiable part: every candidate is a TagCmd —
    // a generator that ignored the index and used branch 0 would yield NumCmds — with the
    // argument shrunk like integers(0, 9) of 8 toward the origin.
    $args = [];
    foreach (shrinkValuesOf($alphabet, $gv) as $v) {
        expect($v)->toBeInstanceOf(TagCmd::class);
        if ($v instanceof TagCmd) {
            $args[] = $v->n;
        }
    }
    expect($args)->toBe([0, 4, 6, 7]);
})->group('SPEC-003');

it('fails loudly when the recorded branch index is out of range', function () {
    $alphabet = twoBranchAlphabet(); // two branches: valid indices are 0 and 1

    // A composite context naming a branch that does not exist. Silently falling back to branch 0
    // or an empty candidate list would turn off shrinking with no signal — the exact silent
    // degradation this package exists to prevent. This failure mode is new to the alphabet
    // generator: it is the first with a composite context, so an index can be out of range.
    $branchValue = new GeneratedValue(new TagCmd(8), new GeneratedValue(8));
    $gv = new GeneratedValue(new TagCmd(8), [5, $branchValue]);

    expect(fn () => [...$alphabet->shrink($gv)])->toThrow(LogicException::class);
})->group('SPEC-003');

it('rejects an empty alphabet', function () {
    // Uniform choice over nothing is meaningless, and an internal integers(0, -1) is degenerate.
    expect(fn () => Gen::alphabet(emptyAlphabet()))->toThrow(InvalidArgumentException::class);
})->group('SPEC-003');
