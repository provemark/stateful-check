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
 * A minimal command, so the alphabet is a well-typed `Generator<Command>` — the guard only reads the
 * alphabet's length, but the constructor's type is honest and PHPStan enforces it. Its model/system
 * types are `null` to agree with the guard tests' `Setup<null, null>` (alphabet and setup must share
 * TModel/TSut, and Setup is invariant).
 *
 * @implements Command<null, null, null>
 */
final class StubCommand implements Command
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
        return 'stub';
    }
}

// A valid setup and initial for the guard tests: they must reach the guard, so the now-required
// setup/initial are provided even though the guard throws before using them.
$validSetup = fn (mixed $i): Setup => new Setup(model: null, system: null);

it('throws at construction when the command alphabet is empty (SPEC-005 AC6)', function () use ($validSetup) {
    expect(fn () => new StatefulProperty(
        alphabet: [],
        setup: $validSetup,
        initial: Gen::constant(null),
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('throws at construction when the maximum length is below one (SPEC-005 AC6)', function () use ($validSetup) {
    expect(fn () => new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: $validSetup,
        initial: Gen::constant(null),
        maxLength: 0,
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('throws at construction when the run count is below one (SPEC-005 AC6)', function () use ($validSetup) {
    expect(fn () => new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: $validSetup,
        initial: Gen::constant(null),
        runs: 0,
    ))->toThrow(InvalidArgumentException::class);
})->group('SPEC-005');

it('constructs without throwing when the configuration is valid (SPEC-005 AC6)', function () use ($validSetup) {
    // The discriminator: a guard that always threw would pass the three throw-cases above. A valid
    // configuration must construct cleanly, proving the guard rejects only the run-nothing shapes and
    // is not blindly throwing. Non-vacuous.
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: $validSetup,
        initial: Gen::constant(null),
    );

    expect($property)->toBeInstanceOf(StatefulProperty::class);
})->group('SPEC-005');

// --- AC1 sub-step 1: the skeleton — draw one sequence, convert setup, run, aggregate. -------------

/** A shared counter so the test can observe how many times a command actually executed, independent
 *  of what the result self-reports. */
final class RunCounter
{
    public int $runs = 0;
}

/**
 * A command that always passes and records each execution against a shared counter. The counter is
 * what proves a run really happened — a `check()` that returned `passed: true` without executing
 * anything would leave it at zero.
 *
 * @implements Command<null, null, null>
 */
final class RecordingCommand implements Command
{
    public function __construct(private RunCounter $counter) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $this->counter->runs++;

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
        return 'rec';
    }
}

it('runs a drawn sequence and reports success for a passing property (SPEC-005 AC1)', function () {
    $counter = new RunCounter;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new RecordingCommand($counter))],
        setup: fn (mixed $initial): Setup => new Setup(model: null, system: null),
        initial: Gen::constant(null),   // required (amendment): explicit "no initial state"
    );

    $result = $property->check(seed: 12345);

    // A passing property reports success — and it genuinely ran: the recording double saw `run()` at
    // least once, so `passed === true` is not a vacuous "returned true without executing anything".
    // (Independent observation, not the result's self-report; sub-step 2 extends it to n runs.)
    expect($result->passed)->toBeTrue()
        ->and($counter->runs)->toBeGreaterThan(0);
})->group('SPEC-005');

// --- AC1 sub-step 2: n runs, fresh setup and drawn initial per sequence, stop at the first failure. -

/** A plain counter, so a setup closure can record how many times it was called (once per sequence). */
final class Tally
{
    public int $count = 0;
}

/**
 * An initial-state generator that counts its draws — proves the initial is drawn per sequence (n),
 * not once and reused (1). A `Gen::map` side effect would make an impure generator, so an explicit
 * double is the honest form. Never shrunk here (no failure path uses it), so `shrink()` is empty.
 *
 * @implements Generator<null>
 */
final class CountingGenerator implements Generator
{
    public int $draws = 0;

    public function generate(Source $source): GeneratedValue
    {
        $this->draws++;

        return new GeneratedValue(null);
    }

    public function shrink(GeneratedValue $value): iterable
    {
        return [];
    }
}

/**
 * Passes on the first sequence, fails from the second: its postcondition reads a shared tally the
 * setup increments once per sequence. Used to prove the loop runs past the first sequence and STOPS
 * at the first failing one.
 *
 * @implements Command<null, null, null>
 */
final class FailsFromSecondSequence implements Command
{
    public function __construct(private Tally $tally) {}

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
        return $this->tally->count < 2;
    }

    public function __toString(): string
    {
        return 'failsFrom2';
    }
}

it('runs n sequences, each with a fresh setup and its own drawn initial (SPEC-005 AC1)', function () {
    $runs = new RunCounter;
    $setups = new Tally;
    $initialGenerator = new CountingGenerator;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new RecordingCommand($runs))],
        setup: function (mixed $initial) use ($setups): Setup {
            $setups->count++;

            return new Setup(model: null, system: null);
        },
        initial: $initialGenerator,
        runs: 5,
    );

    $result = $property->check(seed: 999);

    // Independent observations, not the result's self-report. `$setups->count === 5`: setup called
    // once per sequence, so each run gets a fresh model+system — a leak would call it once, and a
    // `system === model` postcondition would not catch that (model leaks with it, symmetrically).
    // `$initialGenerator->draws === 5`: the initial is drawn per sequence (D012's cover-the-space),
    // not once and reused (which would be 1).
    expect($result->passed)->toBeTrue()
        ->and($setups->count)->toBe(5)
        ->and($initialGenerator->draws)->toBe(5)
        ->and($runs->runs)->toBeGreaterThan(0);
})->group('SPEC-005');

it('stops at the first failing sequence and reports failure (SPEC-005 AC1)', function () {
    $tally = new Tally;
    $draws = new CountingGenerator;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new FailsFromSecondSequence($tally))],
        setup: function (mixed $initial) use ($tally): Setup {
            $tally->count++;

            return new Setup(model: null, system: null);
        },
        initial: $draws,   // counts how many sequences were generated — the quantity the stop is about
        runs: 5,
    );

    $result = $property->check(seed: 999);

    // "Fails" and "stops" are two properties (SPEC-001 AC2). The property fails, AND the loop halts at
    // the failing sequence (the 2nd): the initial generator was drawn exactly twice, not five times.
    // The initial-DRAW count is the actual quantity the stop is about — how many sequences were
    // generated — where a setup counter measures "how many systems were built", which the shrinker
    // (AC2) also does, so it does not stay a clean measure of the stop as later layers are added.
    expect($result->passed)->toBeFalse()
        ->and($draws->draws)->toBe(2);
})->group('SPEC-005');

it('advances one seeded stream across the sequences, so they are not all identical (SPEC-005 AC1)', function () {
    $received = [];
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new StubCommand)],
        setup: function (mixed $initial) use (&$received): Setup {
            $received[] = $initial;

            return new Setup(model: null, system: null);
        },
        initial: Gen::integers(0, 1_000_000),   // varies per draw, so re-seeding shows up
        runs: 5,
    );

    $property->check(seed: 999);

    // One `Source` runs through all sequences. Re-seeding it per iteration would draw the SAME
    // sequence five times; with a varying generator the drawn initials would then be all identical.
    // That they are not is the cheapest catch for a per-iteration re-seed — which the call-counting
    // observations above cannot see.
    expect(count(array_unique($received)))->toBeGreaterThan(1);
})->group('SPEC-005');

// --- AC9: sequence length is drawn in [1, n], never zero. -----------------------------------------

it('draws every sequence length in [1, n], never zero (SPEC-005 AC9)', function () {
    $runs = new RunCounter;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new RecordingCommand($runs))],
        setup: fn (mixed $i): Setup => new Setup(model: null, system: null),
        initial: Gen::constant(null),
        maxLength: 1,   // integers(1, 1) is always 1, so every one of the n sequences runs exactly one command
        runs: 10,
    );

    $property->check(seed: 42);

    // Every sequence has length 1 (min: 1, never 0), so exactly n commands ran across n sequences. The
    // `min: 0` mutant — integers(0, 1) — would draw some empty sequences, dropping the total below n.
    expect($runs->runs)->toBe(10);
})->group('SPEC-005');

// --- AC10: a run in which no command ever executed is vacuous, not passed. -------------------------

/**
 * Its precondition never holds, so it is always skipped — no command ever runs. An alphabet of only
 * these is the classic vacuous property: n sequences that verify nothing.
 *
 * @implements Command<null, null, null>
 */
final class NeverRuns implements Command
{
    public function preCondition(mixed $model): bool
    {
        return false;
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
        return 'never';
    }
}

it('reports a run in which no command ever executed as vacuous, not passed (SPEC-005 AC10)', function () {
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new NeverRuns)],
        setup: fn (mixed $i): Setup => new Setup(model: null, system: null),
        initial: Gen::constant(null),
        runs: 5,
    );

    $result = $property->check(seed: 7);

    // Every command is skipped by its false precondition, so across all sequences nothing executed.
    // That must not look like a pass (AC6's runtime counterpart): passed false, flagged vacuous, and
    // distinguished from a real counterexample by the flag.
    expect($result->passed)->toBeFalse()
        ->and($result->vacuous)->toBeTrue();
})->group('SPEC-005');

// --- AC3: the same seed reproduces the generation; a different seed varies it. --------------------

it('reproduces the same generation from the same seed, and varies with a different one (SPEC-005 AC3)', function () {
    $drawnWith = function (int $seed): array {
        $received = [];
        (new StatefulProperty(
            alphabet: [Gen::constant(new StubCommand)],
            setup: function (mixed $initial) use (&$received): Setup {
                $received[] = $initial;

                return new Setup(model: null, system: null);
            },
            initial: Gen::integers(0, 1_000_000),
            runs: 5,
        ))->check(seed: $seed);

        return $received;
    };

    // Same seed threads the same seeded stream through generation → identical draws. A different seed
    // varies them, so the seed genuinely reaches the generator rather than a stream that ignores it.
    // The second assertion is the mutation catch: a seed-ignoring but deterministic impl survives
    // "same → same" but not "different → different".
    expect($drawnWith(1))->toBe($drawnWith(1))
        ->and($drawnWith(1))->not->toBe($drawnWith(2));
})->group('SPEC-005');

// --- AC2: a failing property returns a shrunk counterexample, with a fresh system per candidate. ---

/** A mutable system counter, built fresh by setup. If the shrinker reused one across candidates, its
 *  reduced candidate would run on a dirty counter and reproduce the failure wrongly. */
final class IntBox
{
    public int $value = 0;
}

/**
 * Increments the system counter; its postcondition fails once it reaches 2. Reducing `[inc, inc]` to
 * `[inc]` must run on a FRESH counter (1 < 2, passes) so the single command does not reproduce — which
 * only holds if each shrink candidate gets a fresh system. A leaked system shrinks wrongly to `[inc]`.
 *
 * @implements Command<null, IntBox, null>
 */
final class IncrementFailsAtTwo implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value++;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->value < 2;
    }

    public function __toString(): string
    {
        return 'inc';
    }
}

it('shrinks the first failing sequence to a counterexample, with a fresh system per candidate (SPEC-005 AC2)', function () {
    $setups = new Tally;
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new IncrementFailsAtTwo)],
        setup: function (mixed $initial) use ($setups): Setup {
            $setups->count++;

            return new Setup(model: null, system: new IntBox);
        },
        initial: Gen::constant(null),
        maxLength: 2,
        runs: 1,             // one generation run, so the setup-call count is unambiguous
    );

    $result = $property->check(seed: 1);

    // A real failure (not vacuous), carrying the shrunk counterexample and its Failure. The
    // counterexample is [inc, inc], not [inc]: reducing to a single inc runs on a FRESH counter
    // (1 < 2, passes) so it does not reproduce — which holds only if each candidate gets a fresh
    // system. A leaked system would shrink wrongly to [inc]. So this assertion is itself a
    // fresh-system check.
    expect($result->passed)->toBeFalse()
        ->and($result->vacuous)->toBeFalse()
        ->and($result->failure)->not->toBeNull()
        ->and(array_map(fn (Command $c): string => (string) $c, $result->counterexample))->toBe(['inc', 'inc']);

    // The AC7 guarantee, one layer deeper (AC8's precursor): exactly one setup call per execution — the
    // failing generation run (1), the shrinker's non-determinism replay (1), and each candidate
    // execution (ShrinkResult::$executions). Exact, not a lower bound: an impl that called setup once
    // extra and shared that one system across candidates would not match.
    expect($setups->count)->toBe(2 + $result->executions);
})->group('SPEC-005');

/**
 * Always fails, carrying a drawn integer. SPEC-002 shrinks the sequence, not the argument, so the
 * drawn value survives into the counterexample — which makes the counterexample vary with the seed,
 * so a re-draw anywhere in the wiring becomes visible.
 *
 * @implements Command<null, null, null>
 */
final class TaggedFailure implements Command
{
    public function __construct(private int $tag) {}

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
        return "tf({$this->tag})";
    }
}

it('reproduces the same counterexample from the same seed — the wiring stays deterministic (SPEC-005 AC2/AC3)', function () {
    $counterexampleWith = function (int $seed): array {
        $result = (new StatefulProperty(
            alphabet: [Gen::map(fn (int $n): TaggedFailure => new TaggedFailure($n), Gen::integers(0, 1_000_000))],
            setup: fn (mixed $initial): Setup => new Setup(model: null, system: null),
            initial: Gen::constant(null),
            maxLength: 4,
            runs: 1,
        ))->check(seed: $seed);

        return array_map(fn (Command $c): string => (string) $c, $result->counterexample);
    };

    // Same seed → the identical counterexample, end to end (AC3's forward-referenced half). This guards
    // not the determinism of generation (AC3) or of the shrinker (SPEC-002 R4) — those are tested — but
    // of the WIRING between them: the AC3 requirement that the seed→outcome chain stay free of
    // non-deterministic sources (unordered iteration, wall-clock time, spl_object_id) has no other
    // test, and this is it. It falls the moment check() introduces such a source — the drawn integer in
    // the counterexample makes any re-draw visible. There is deliberately NO mutant: a different seed
    // may legitimately shrink to the same counterexample (so "different seed → different" is unsound),
    // and a seed-ignoring impl uses the same seed both times and passes here (it is caught at AC3
    // instead). The test's strength is derived, resting on AC3 and SPEC-002; its job is precisely to
    // guard the wiring.
    $first = $counterexampleWith(7);

    expect($first)->not->toBeEmpty()          // a real counterexample, so the comparison is not [] === []
        ->and($counterexampleWith(7))->toBe($first);
})->group('SPEC-005');

// --- AC5: a qualified shrink (budget-limited or abandoned) is reported, not hidden. ----------------

/** A counter shared across a command's invocations and its clones, making the command flaky. */
final class IntCounter
{
    public int $n = 0;
}

/**
 * Flaky: its postcondition fails on the first invocation and passes after, so the failing sequence's
 * verdict flips on the shrinker's replay and SPEC-002 aborts (non-determinism). It holds shared
 * mutable state and does not implement `__clone`, so the flakiness survives the shrinker's cloning.
 *
 * @implements Command<null, null, null>
 */
final class FlakyCheck implements Command
{
    public function __construct(private IntCounter $counter) {}

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
        return $this->counter->n++ !== 0;
    }

    public function __toString(): string
    {
        return 'flaky';
    }
}

/**
 * Increments the system counter; fails once it reaches 3, so the filtered failing sequence is long
 * enough (three commands) that a budget of 1 interrupts the shrink mid-search.
 *
 * @implements Command<null, IntBox, null>
 */
final class IncrementFailsAtThree implements Command
{
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->value++;

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model;
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->value < 3;
    }

    public function __toString(): string
    {
        return 'inc';
    }
}

it('reports an abandoned (non-deterministic) shrink as a qualification, not a clean counterexample (SPEC-005 AC5)', function () {
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new FlakyCheck(new IntCounter))],
        setup: fn (mixed $initial): Setup => new Setup(model: null, system: null),
        initial: Gen::constant(null),
        maxLength: 3,
        runs: 1,
    );

    $result = $property->check(seed: 1);

    // The system is non-deterministic (the flaky command flips verdict on replay), so SPEC-002 aborts
    // the shrink. That must be reported, not hidden: passed false, flagged abandoned, and exactly that
    // one of the four false-kinds. The counterexample is the original, unshrunk (R3: not minimal).
    expect($result->passed)->toBeFalse()
        ->and($result->abandonedNonDeterministic)->toBeTrue()
        ->and($result->budgetExhausted)->toBeFalse()
        ->and($result->vacuous)->toBeFalse();
})->group('SPEC-005');

it('reports a budget-limited shrink as a qualification (SPEC-005 AC5)', function () {
    $property = new StatefulProperty(
        alphabet: [Gen::constant(new IncrementFailsAtThree)],
        setup: fn (mixed $initial): Setup => new Setup(model: null, system: new IntBox),
        initial: Gen::constant(null),
        maxLength: 5,
        runs: 100,
        budget: 1,
    );

    $result = $property->check(seed: 1);

    // A budget of 1 stops the shrink before it confirms the minimum: passed false, flagged
    // budget-limited, and exactly that one of the four false-kinds. The counterexample is best-so-far
    // (R3: not a confirmed minimum).
    expect($result->passed)->toBeFalse()
        ->and($result->budgetExhausted)->toBeTrue()
        ->and($result->abandonedNonDeterministic)->toBeFalse()
        ->and($result->vacuous)->toBeFalse();
})->group('SPEC-005');
