<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Failure;
use Provemark\StatefulCheck\FailureKind;
use Provemark\StatefulCheck\Generation\Gen;
use Provemark\StatefulCheck\Generation\GeneratedValue;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\RunResult;
use Provemark\StatefulCheck\Shrinking\SequenceShrinker;

/**
 * A minimal command double, distinguishable by label, so a filtered sequence can be asserted.
 *
 * @implements Command<mixed, mixed, null>
 */
final class Cmd implements Command
{
    public function __construct(private string $label) {}

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
        return $this->label;
    }
}

it('drops skipped and never-reached commands without running a candidate (SPEC-002 AC3)', function () {
    $failing = [
        new GeneratedValue(new Cmd('a')),
        new GeneratedValue(new Cmd('b')), // skipped: precondition false
        new GeneratedValue(new Cmd('c')), // executed, and failed here
        new GeneratedValue(new Cmd('d')), // never reached (after the failure at c)
    ];
    // The original failing run: a ran, b was skipped, c ran and failed, d was never reached. Both
    // meanings of `false` occur, so neither drop path is left untested.
    $original = new RunResult(false, [true, false, true, false], new Failure(FailureKind::PostconditionFalse, 2, Cmd::class));

    $freshSutCalls = 0;
    $freshSut = function () use (&$freshSutCalls): stdClass {
        $freshSutCalls++;

        return new stdClass;
    };

    $result = (new SequenceShrinker)->shrink(
        $failing,
        $original,
        Gen::constant(new Cmd('unused')), // the alphabet is not touched at this stage
        $freshSut,
        null,
    );

    // Both the skipped (b) and never-reached (d) commands are gone; a and c remain, in order.
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['a', 'c']);

    // The discriminator: the drop was read from `executed`, not discovered by trying. A shrinker
    // that removed each command and re-ran to see if the failure survived would call freshSut; this
    // one calls it zero times and runs zero candidates.
    expect($freshSutCalls)->toBe(0)
        ->and($result->executions)->toBe(0);
})->group('SPEC-002');

it('fails loudly when the executed record does not match the sequence length', function () {
    $failing = [new GeneratedValue(new Cmd('a')), new GeneratedValue(new Cmd('b'))];
    // Three executed entries for a two-command sequence: `$failing` and `$original` are not the same
    // run. Filtering on this would drop the wrong positions and silently return a wrong answer.
    $original = new RunResult(false, [true, false, true], new Failure(FailureKind::PostconditionFalse, 0, Cmd::class));

    expect(fn () => (new SequenceShrinker)->shrink(
        $failing,
        $original,
        Gen::constant(new Cmd('unused')),
        fn (): stdClass => new stdClass,
        null,
    ))->toThrow(LogicException::class);
})->group('SPEC-002');
