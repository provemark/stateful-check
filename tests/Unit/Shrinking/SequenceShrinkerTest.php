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

it('drops non-executed commands by reading the record, without running a candidate (SPEC-002 AC3)', function () {
    // Only b executed and failed; a was skipped (precondition false) and c was never reached (after
    // the failure). Both meanings of a false position drop out, leaving b.
    $failing = [
        new GeneratedValue(new Cmd('a')), // skipped: precondition false
        new GeneratedValue(new Cmd('b')), // executed, and failed here
        new GeneratedValue(new Cmd('c')), // never reached (after the failure at b)
    ];
    $original = new RunResult(false, [false, true, false], new Failure(FailureKind::PostconditionFalse, 1, Cmd::class));

    $freshSutCalls = 0;
    $freshSut = function () use (&$freshSutCalls): stdClass {
        $freshSutCalls++;

        return new stdClass;
    };

    $result = (new SequenceShrinker)->shrink(
        $failing,
        $original,
        Gen::constant(new Cmd('unused')),
        $freshSut,
        null,
    );

    // Both the skipped (a) and never-reached (c) commands are gone; b remains.
    expect(array_map(fn (Command $c): string => (string) $c, $result->commands))->toBe(['b']);

    // The claim is specifically about the FILTER: it drops non-executed commands by reading
    // `executed`, running nothing to discover the drop. (A trial-and-error shrinker would call
    // freshSut per command.) A single-command representation has no further reduction to generate,
    // so no candidate runs and this stays true once the shrink loop exists — the drop's zero
    // executions are not entangled with the loop's.
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

it('every structural candidate retains the last executed command (SPEC-002 AC4)', function () {
    // The filtered failing sequence; d is the last executed command — the one that failed.
    $sequence = [
        new GeneratedValue(new Cmd('a')),
        new GeneratedValue(new Cmd('b')),
        new GeneratedValue(new Cmd('c')),
        new GeneratedValue(new Cmd('d')),
    ];

    $candidates = iterator_to_array((new SequenceShrinker)->candidateReductions($sequence), false);

    // Non-vacuous: there is at least one genuine reduction to check the invariant against.
    expect($candidates)->not->toBeEmpty();

    foreach ($candidates as $candidate) {
        $labels = array_map(fn (GeneratedValue $gv): string => (string) $gv->value, $candidate);

        // Every candidate ends with d: removing the command that caused the failure is never a
        // useful reduction, so the structural family never drops it. And it is a real reduction.
        expect($labels)->not->toBeEmpty()
            ->and(end($labels))->toBe('d')
            ->and(count($labels))->toBeLessThan(count($sequence));
    }
})->group('SPEC-002');
