<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\SequenceRunner;

/**
 * Ordered journal of every method the runner invoked, shared across the sequence.
 * A command holds a reference to it; the shrinker's shallow clone keeps that reference
 * shared (R9b), which is exactly what lets one log observe a whole run.
 */
final class CallLog
{
    /** @var list<array{string, string, int|null}> */
    public array $events = [];

    public function record(string $label, string $method, ?int $model): void
    {
        $this->events[] = [$label, $method, $model];
    }
}

/**
 * A command that records which method the runner called, in order, and which model value
 * each model-taking method saw. The model is an int; nextState advances it by one, so a
 * postcondition that sees `pre + 1` proves it ran after the transition, not before.
 *
 * @implements Command<int, mixed, null>
 */
final class SpyCommand implements Command
{
    public function __construct(
        private string $label,
        private CallLog $log,
    ) {}

    public function preCondition(mixed $model): bool
    {
        $this->log->record($this->label, 'pre', $model);

        return true;
    }

    public function run(mixed $sut): mixed
    {
        $this->log->record($this->label, 'run', null);

        return null;
    }

    /**
     * nextState must have no side effect that influences the model value; logging is not
     * such an effect. This case falls outside R6, it is not a permitted breach of it: the
     * property R6 protects — the transition looks at nothing but the model and is the same
     * every time — is asserted directly in the determinism test below.
     */
    public function nextState(mixed $model): mixed
    {
        $this->log->record($this->label, 'next', $model);

        return $model + 1;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        $this->log->record($this->label, 'post', $model);

        return true;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

it('runs a passing sequence to completion, each postcondition seeing the post-transition model', function () {
    $log = new CallLog;
    $commands = [
        new SpyCommand('a', $log),
        new SpyCommand('b', $log),
        new SpyCommand('c', $log),
    ];

    $result = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);

    expect($result->passed)->toBeTrue()
        ->and($result->executed)->toBe([true, true, true]);

    // Every method fired, in order, within each command and across the sequence.
    $trace = array_map(fn (array $e): string => "{$e[0]}.{$e[1]}", $log->events);
    expect($trace)->toBe([
        'a.pre', 'a.run', 'a.next', 'a.post',
        'b.pre', 'b.run', 'b.next', 'b.post',
        'c.pre', 'c.run', 'c.next', 'c.post',
    ]);

    $modelOf = function (string $label, string $method) use ($log): int {
        foreach ($log->events as [$l, $m, $model]) {
            if ($l === $label && $m === $method && $model !== null) {
                return $model;
            }
        }
        throw new RuntimeException("no model logged for {$label}.{$method}");
    };

    // The discriminator. Each postcondition saw nextState's output (pre + 1), never the
    // pre-transition model. A runner that handed the postcondition the model from before
    // the transition would make post === pre, and this would fail. The model also threads
    // 0, 1, 2 across the sequence, so "after the transition" is checked per command.
    foreach (['a' => 0, 'b' => 1, 'c' => 2] as $label => $pre) {
        expect($modelOf($label, 'pre'))->toBe($pre)
            ->and($modelOf($label, 'next'))->toBe($pre)
            ->and($modelOf($label, 'post'))->toBe($pre + 1)
            ->and($modelOf($label, 'post'))->not->toBe($modelOf($label, 'pre'));
    }
})->group('SPEC-001');

it('nextState is deterministic in the model: the same model in gives the same model out', function () {
    $probe = new SpyCommand('probe', new CallLog);

    expect($probe->nextState(7))->toBe(8)
        ->and($probe->nextState(7))->toBe(8);
})->group('SPEC-001');
