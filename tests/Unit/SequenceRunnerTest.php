<?php

declare(strict_types=1);

use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\FailureKind;
use Provemark\StatefulCheck\Outcome;
use Provemark\StatefulCheck\Ref;
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
    /** The outcome this command's postcondition last received (AC5 capture). */
    public ?Outcome $lastOutcome = null;

    public function __construct(
        private string $label,
        private CallLog $log,
        private bool $preconditionHolds = true,
        private bool $postconditionHolds = true,
        private ?Throwable $runThrows = null,
    ) {}

    public function preCondition(mixed $model): bool
    {
        $this->log->record($this->label, 'pre', $model);

        return $this->preconditionHolds;
    }

    public function run(mixed $sut): mixed
    {
        $this->log->record($this->label, 'run', null);

        if ($this->runThrows !== null) {
            throw $this->runThrows;
        }

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
        $this->lastOutcome = $outcome;

        return $this->postconditionHolds;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

/**
 * A user-library-style exception subclass, used to check that `exceptionClass` records the
 * concrete thrown class rather than generalising up to a parent (AC6, D020).
 */
final class BoomException extends RuntimeException {}

/** Records the system handle each command was given, so a test can compare their identity (AC7). */
final class HandleLog
{
    /** @var list<object> */
    public array $seen = [];

    public function record(object $handle): void
    {
        $this->seen[] = $handle;
    }
}

/**
 * A command over an immutable string system held in a Ref: it appends its tag by swapping the
 * Ref's value, and records the handle it saw. Threading works only if every command is given
 * the same handle carrying the previous command's state (AC7).
 *
 * @implements Command<null, Ref<string>, null>
 */
final class AppendCommand implements Command
{
    public function __construct(
        private string $tag,
        private HandleLog $handles,
    ) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $this->handles->record($sut);
        $sut->value = $sut->value.$this->tag;

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
        return "append({$this->tag})";
    }
}

/**
 * A command whose postcondition asserts on system state rather than on run()'s return: it appends
 * its tag in run(), then records what `$sut->value` reads at postcondition time. That reading must
 * reflect this command's own mutation, not the state before it (AC8).
 *
 * @implements Command<null, Ref<string>, null>
 */
final class ObserveCommand implements Command
{
    public ?string $observed = null;

    public function __construct(private string $tag) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value = $sut->value.$this->tag;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        $this->observed = $sut->value;

        return true;
    }

    public function __toString(): string
    {
        return "observe({$this->tag})";
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

it('stops at the first failing postcondition and reports a structured failure', function () {
    $log = new CallLog;
    $commands = [
        new SpyCommand('a', $log),
        new SpyCommand('b', $log, postconditionHolds: false),
        new SpyCommand('c', $log),
    ];

    $result = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);

    expect($result->passed)->toBeFalse()
        ->and($result->executed)->toBe([true, true, false]);

    // The discriminator is that the run STOPPED, not merely that it failed. executed could
    // be set administratively while the runner looped on to the end; the absence of any
    // event for 'c' is what actually proves nothing past the failing position ran.
    $trace = array_map(fn (array $e): string => "{$e[0]}.{$e[1]}", $log->events);
    expect($trace)->toBe([
        'a.pre', 'a.run', 'a.next', 'a.post',
        'b.pre', 'b.run', 'b.next', 'b.post',
    ])
        ->and(array_filter($log->events, fn (array $e): bool => $e[0] === 'c'))->toBe([]);

    // The precedence rule speaks here for the first time instead of being a tautology:
    // run() returned normally, so kind is PostconditionFalse and exceptionClass is null.
    // The throw path (AC6) is the case that would make both differ.
    // `?? throw` narrows $failure to non-null for PHPStan and fails loudly if it is null,
    // where an `if (! instanceof) return` would silently pass the whole assertion block.
    // This is the pattern for the same situation in AC5/AC6.
    $failure = $result->failure ?? throw new RuntimeException('expected a failure, got none');

    expect($failure->kind)->toBe(FailureKind::PostconditionFalse)
        ->and($failure->index)->toBe(1)
        ->and($failure->commandClass)->toBe(SpyCommand::class)
        ->and($failure->exceptionClass)->toBeNull()
        ->and($result->modelBefore)->toBe(1)
        ->and($result->modelAfter)->toBe(2)
        ->and($result->modelBefore)->not->toBe($result->modelAfter);

    // modelBefore and modelAfter must visibly differ, or "filled correctly" cannot be told
    // apart from "handed the same value twice". At b the model enters as 1; nextState makes
    // it 2, and the failing postcondition is checked against that 2.
})->group('SPEC-001');

it('skips a command whose precondition is false and continues the sequence', function () {
    $log = new CallLog;
    $commands = [
        new SpyCommand('a', $log),
        new SpyCommand('b', $log, preconditionHolds: false),
        new SpyCommand('c', $log),
    ];

    $result = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);

    // Skipping is not a failure, and the run continues past the skipped middle command.
    expect($result->passed)->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and($result->executed)->toBe([true, false, true]);

    // The discriminator is not executed[1] === false — a runner could set that while still
    // running b. It is that b's run/nextState/postCondition never fired: b contributes only
    // its precondition event to the log. (The runner does call preCondition, to decide.)
    $trace = array_map(fn (array $e): string => "{$e[0]}.{$e[1]}", $log->events);
    expect($trace)->toBe([
        'a.pre', 'a.run', 'a.next', 'a.post',
        'b.pre',
        'c.pre', 'c.run', 'c.next', 'c.post',
    ])
        ->and(array_filter($log->events, fn (array $e): bool => $e[0] === 'b' && $e[1] !== 'pre'))->toBe([]);

    // The model must not advance over a skipped command: had b's nextState wrongly run, c
    // would see 2, and every later postcondition would fail for the wrong reason (the hazard
    // in the design notes). c seeing the un-advanced 1 is the only external pin on that.
    $modelOf = function (string $label, string $method) use ($log): int {
        foreach ($log->events as [$l, $m, $model]) {
            if ($l === $label && $m === $method && $model !== null) {
                return $model;
            }
        }
        throw new RuntimeException("no model logged for {$label}.{$method}");
    };
    expect($modelOf('a', 'post'))->toBe(1)
        ->and($modelOf('c', 'pre'))->toBe(1);
})->group('SPEC-001');

it('records a total, index-aligned, replayable execution path', function () {
    // Four commands, the run stops in the middle (b fails). c and d are never reached.
    $commands = [
        new SpyCommand('a', new CallLog),
        new SpyCommand('b', new CallLog, postconditionHolds: false),
        new SpyCommand('c', new CallLog),
        new SpyCommand('d', new CallLog),
    ];

    $result = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);

    // Total and index-aligned: one bool per position, positions after the stop padded false.
    // The length relation is the pin — this is what SPEC-002 AC3 filters on. Without the
    // padding, executed would be [true, true] and lose its correspondence with $commands.
    expect($result->executed)->toHaveCount(count($commands))
        ->and($result->executed)->toBe([true, true, false, false]);

    // Replayable: the same sequence against a fresh (deterministic) system reproduces the
    // same executed. The runner adds no variation of its own — this is the baseline SPEC-002
    // AC8 measures a divergence against. It is conditional on determinism, which the spies are.
    $replay = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);
    expect($replay->executed)->toBe($result->executed);
})->group('SPEC-001');

it('catches an expected exception, passes it to the postcondition, and continues', function () {
    $log = new CallLog;
    $boom = new RuntimeException('boom');
    $b = new SpyCommand('b', $log, runThrows: $boom);
    $commands = [
        new SpyCommand('a', $log),
        $b,
        new SpyCommand('c', $log),
    ];

    // If the runner did not catch, $boom would propagate out of this call and there would be
    // no RunResult at all. Getting one back is itself the proof the exception was caught.
    $result = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);

    // The run continued and did not fail: b's nextState/postCondition still ran, and c ran fully.
    expect($result->passed)->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and($result->executed)->toBe([true, true, true]);

    $trace = array_map(fn (array $e): string => "{$e[0]}.{$e[1]}", $log->events);
    expect($trace)->toBe([
        'a.pre', 'a.run', 'a.next', 'a.post',
        'b.pre', 'b.run', 'b.next', 'b.post',
        'c.pre', 'c.run', 'c.next', 'c.post',
    ]);

    // The exception was wrapped into the Outcome and handed to b's postcondition — not a
    // returned outcome. `?? throw` narrows and fails loudly if b saw nothing (the AC2 pattern).
    $outcome = $b->lastOutcome ?? throw new RuntimeException('b saw no outcome');
    expect($outcome->threw)->toBeTrue()
        ->and($outcome->exception)->toBe($boom);

    // nextState runs on the throw path too (R6: the transition ignores what actually happened).
    // Had it been skipped, the model would lag the system by one step — the hazard in the design
    // notes. b enters at model 1, so its post-transition model is 2, and c then sees 2.
    $modelOf = function (string $label, string $method) use ($log): int {
        foreach ($log->events as [$l, $m, $model]) {
            if ($l === $label && $m === $method && $model !== null) {
                return $model;
            }
        }
        throw new RuntimeException("no model logged for {$label}.{$method}");
    };
    expect($modelOf('b', 'post'))->toBe(2)
        ->and($modelOf('c', 'pre'))->toBe(2);
})->group('SPEC-001');

it('classifies the failure kind by whether run() threw, not by the postcondition', function () {
    // Two sequences identical except whether b's run() throws; b's postcondition is false in
    // both. A runner that always chose one kind on a false postcondition would pass a
    // single-branch test — needing opposite kinds here is what makes the precedence rule
    // falsifiable, and AC2's kind assertion no longer a tautology.
    $boom = new BoomException('boom');

    $returned = (new SequenceRunner)->run(
        [new SpyCommand('a', new CallLog), new SpyCommand('b', new CallLog, postconditionHolds: false)],
        fn () => new stdClass,
        0,
    );
    $threw = (new SequenceRunner)->run(
        [new SpyCommand('a', new CallLog), new SpyCommand('b', new CallLog, postconditionHolds: false, runThrows: $boom)],
        fn () => new stdClass,
        0,
    );

    $rf = $returned->failure ?? throw new RuntimeException('expected a failure, got none');
    $tf = $threw->failure ?? throw new RuntimeException('expected a failure, got none');

    expect($rf->kind)->toBe(FailureKind::PostconditionFalse)
        ->and($rf->exceptionClass)->toBeNull();
    expect($tf->kind)->toBe(FailureKind::UnexpectedException)
        ->and($tf->exceptionClass)->toBe(BoomException::class)
        ->and($tf->exceptionClass)->not->toBe(RuntimeException::class); // concrete class, not a parent
})->group('SPEC-001');

it('an unexpected exception is a failure that stops the run, carrying the exception class', function () {
    $log = new CallLog;
    $boom = new BoomException('boom');
    $commands = [
        new SpyCommand('a', $log),
        new SpyCommand('b', $log, postconditionHolds: false, runThrows: $boom),
        new SpyCommand('c', $log),
    ];

    $result = (new SequenceRunner)->run($commands, fn () => new stdClass, 0);

    // Stopped at b: c never ran. The absence of any 'c' event is the proof, not executed alone.
    expect($result->passed)->toBeFalse()
        ->and($result->executed)->toBe([true, true, false])
        ->and(array_filter($log->events, fn (array $e): bool => $e[0] === 'c'))->toBe([]);

    $failure = $result->failure ?? throw new RuntimeException('expected a failure, got none');
    expect($failure->kind)->toBe(FailureKind::UnexpectedException)
        ->and($failure->index)->toBe(1)
        ->and($failure->commandClass)->toBe(SpyCommand::class)
        ->and($failure->exceptionClass)->toBe(BoomException::class)
        ->and($result->modelBefore)->toBe(1)  // nextState runs on the throw path
        ->and($result->modelAfter)->toBe(2);
})->group('SPEC-001');

it('calls the system factory exactly once per run', function () {
    // A counting factory that always returns the same handle. Returning one object (rather than
    // a fresh one each call) is deliberate: it lets the call count move under a per-command
    // mutation without the handle identity moving too, isolating "exactly once" from "never
    // replaced". freshSut's fresh-per-candidate contract is SPEC-002's concern, not this one.
    $ref = new Ref('');
    $calls = 0;
    $freshSut = function () use ($ref, &$calls): Ref {
        $calls++;

        return $ref;
    };
    $handles = new HandleLog;
    $commands = [
        new AppendCommand('a', $handles),
        new AppendCommand('b', $handles),
        new AppendCommand('c', $handles),
    ];

    $result = (new SequenceRunner)->run($commands, $freshSut, null);

    // A second call would hand the sequence a fresh system and erase its history. Nothing but
    // the runner guards this, so it is the runner's property to test.
    expect($result->passed)->toBeTrue()
        ->and($calls)->toBe(1);
})->group('SPEC-001');

it('threads one handle to every command and never replaces it', function () {
    // Same-object factory again, so a per-command call would not move identity — this test
    // then isolates "never replaced", which a clone-before-run mutation breaks.
    $ref = new Ref('');
    $freshSut = fn (): Ref => $ref;
    $handles = new HandleLog;
    $commands = [
        new AppendCommand('a', $handles),
        new AppendCommand('b', $handles),
        new AppendCommand('c', $handles),
    ];

    $result = (new SequenceRunner)->run($commands, $freshSut, null);

    expect($result->passed)->toBeTrue();

    // Identity, not content: every command received the very handle freshSut returned, not a
    // copy. That is what "never replaced" means.
    expect($handles->seen)->toHaveCount(3);
    [$h0, $h1, $h2] = $handles->seen;
    expect($h0)->toBe($ref)
        ->and($h1)->toBe($ref)
        ->and($h2)->toBe($ref)
        ->and($ref->value)->toBe('abc');

    // Each command saw the state the previous left: a, then ab, then abc.
})->group('SPEC-001');

it('lets the postcondition observe the system, reflecting mutations up to and including this command', function () {
    $ref = new Ref('');
    $a = new ObserveCommand('a');
    $b = new ObserveCommand('b');
    $c = new ObserveCommand('c');

    $result = (new SequenceRunner)->run([$a, $b, $c], fn (): Ref => $ref, null);

    expect($result->passed)->toBeTrue()
        ->and($a->observed)->toBe('a')
        ->and($b->observed)->toBe('ab')
        ->and($c->observed)->toBe('abc');

    // The falsifiable part is "including": each postcondition read the system AFTER its own run(),
    // so it saw its own mutation. Were the postcondition given the pre-run state, a would read ''
    // and b 'a'. This does not follow from the shared handle (AC7) — sharing the instance says
    // nothing about whether the mutation was applied yet when the postcondition looked.
})->group('SPEC-001');
