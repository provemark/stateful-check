# Tutorial: testing a bank account

This walks through one small example end to end — a bank account and its ledger —
and introduces every concept as it comes up. By the end you will have a test that
generates a hundred command sequences per run, catches a bug that only shows up
after state accumulates, and shrinks it to the one command that matters.

The snippets below are complete: assembled in order into one file (with a
Composer autoloader), or dropped into a Pest test, they run against the engine as
shown — every result printed here is real output, not a sketch of one.

## The idea in one sentence

You describe what a system *should* do with a small, obviously-correct **model**,
and the library generates sequences of operations, runs them against both the real
system and the model, and reports the first sequence where the two disagree.

The model is the oracle. So it must stay simple enough to trust on sight — if it
needs the complexity of the real system, you have written the bug twice and the
test proves nothing. Model only what the system can actually be observed to do.

## The system, and its model

The **system under test** is the real thing. Here, a plain mutable account:

```php
final class Account
{
    public function __construct(public int $balance) {}

    public function deposit(int $amount): void
    {
        $this->balance += $amount;
    }

    public function withdraw(int $amount): void
    {
        $this->balance -= $amount;
    }
}
```

The **model** is an immutable shadow of it — it tracks only the balance, which is
all we mean to check:

```php
final readonly class LedgerModel
{
    public function __construct(public int $balance) {}

    public function deposit(int $amount): self
    {
        return new self($this->balance + $amount);
    }

    public function withdraw(int $amount): self
    {
        return new self($this->balance - $amount);
    }
}
```

The model is immutable — every transition returns a *new* model rather than
mutating the old one. That is not a style preference: the library reuses one
starting model across many candidate sequences while shrinking, and that is only
safe if a transition never changes the model it was handed.

## A command

A command is one operation the test can perform. It has four methods, and each has
a distinct job:

```php
use Provemark\StatefulCheck\Command;
use Provemark\StatefulCheck\Outcome;

/** @implements Command<LedgerModel, Account, null> */
final readonly class Deposit implements Command
{
    public function __construct(public int $amount) {}

    // 1. May this command run against the current model state? (Always, here.)
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    // 2. Do the real thing, against the real system.
    public function run(mixed $sut): mixed
    {
        $sut->deposit($this->amount);

        return null;
    }

    // 3. Say what the operation should have done, in the model. Pure: a new model.
    public function nextState(mixed $model): mixed
    {
        return $model->deposit($this->amount);
    }

    // 4. Do the real system and the model agree? Runs after every step.
    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->balance === $model->balance;
    }

    public function __toString(): string
    {
        return "deposit({$this->amount})";
    }
}
```

The `@implements Command<LedgerModel, Account, null>` line types all four methods
at once: `$model` is a `LedgerModel`, `$sut` an `Account`, and the third argument
is the type `run()` returns (nothing useful here, so `null`). With it, PHPStan
checks that your commands, model and system actually fit together.

The runner drives these in lockstep: for each command it checks the precondition,
calls `run()` on the system, computes `nextState()` for the model, and then calls
`postCondition()` to compare them. The moment a postcondition returns `false`, the
sequence has found a discrepancy and the run stops there.

## Preconditions: skipping instead of failing

You cannot withdraw more than the balance. That is a **precondition**, not a
failure — a `Withdraw` for more than is there simply should not run:

```php
/** @implements Command<LedgerModel, Account, null> */
final readonly class Withdraw implements Command
{
    public function __construct(public int $amount) {}

    public function preCondition(mixed $model): bool
    {
        return $model->balance >= $this->amount;   // only withdraw what is there
    }

    public function run(mixed $sut): mixed
    {
        $sut->withdraw($this->amount);

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model->withdraw($this->amount);
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->balance === $model->balance && $sut->balance >= 0;
    }

    public function __toString(): string
    {
        return "withdraw({$this->amount})";
    }
}
```

When a generated sequence reaches a `Withdraw` whose precondition is false against
the current model, the runner skips it and moves on. The precondition is evaluated
against the *model*, which is why the model must track whatever the preconditions
need to decide.

## The Outcome: reading what run() did

The fourth argument to `postCondition` is an `Outcome`. It describes what `run()`
did: its return value (`$outcome->value`), whether it threw (`$outcome->threw`),
and the exception if it did (`$outcome->exception`). The balance commands above
ignore it, but it is how a command asserts that an operation *should* fail:

```php
// Sketch: a command whose run() is expected to throw on bad input.
public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
{
    return $model->isValid()
        ? ! $outcome->threw                       // valid input: must not throw
        : $outcome->threw && $outcome->exception instanceof ValidationError;   // invalid: must throw
}
```

`examples/ImmutableBuilder` uses exactly this to check that building with a blank
name throws, and nothing else does.

## Generating and running

Now assemble the property. Commands need generated arguments, so each command type
is paired with a generator for its value. The `initial` generator draws the account's
**starting balance** — so the property is checked across a whole space of opening
states, not one fixed setup:

```php
use Provemark\StatefulCheck\{StatefulProperty, Setup};
use Provemark\StatefulCheck\Generation\Gen;

$deposit  = Gen::map(fn (int $n) => new Deposit($n),  Gen::integers(1, 100));
$withdraw = Gen::map(fn (int $n) => new Withdraw($n), Gen::integers(1, 100));

$result = (new StatefulProperty(
    alphabet: [$deposit, $withdraw],
    initial: Gen::integers(0, 1000),                 // a starting balance, drawn per run
    setup: fn (int $opening) => new Setup(
        model:  new LedgerModel($opening),
        system: new Account($opening),
    ),
))->check();
```

`setup` receives one drawn initial value and builds the model and the system from
it together — there is no way to seed them from different states. `check()` then
generates and runs many sequences (100 by default, each up to 10 commands long).

A note on the alphabet: commands are drawn uniformly. To make depositing twice as
likely as withdrawing, list `$deposit` twice — there is no weighting knob, by design.

### In a test

`check()` returns a result; you assert on it. In Pest:

```php
it('keeps the ledger and the account in agreement', function () {
    $deposit  = Gen::map(fn (int $n) => new Deposit($n),  Gen::integers(1, 100));
    $withdraw = Gen::map(fn (int $n) => new Withdraw($n), Gen::integers(1, 100));

    $result = (new StatefulProperty(
        alphabet: [$deposit, $withdraw],
        initial: Gen::integers(0, 1000),
        setup: fn (int $opening) => new Setup(
            model:  new LedgerModel($opening),
            system: new Account($opening),
        ),
    ))->check();

    // On failure, the counterexample string is the assertion message.
    expect($result->passed)->toBeTrue($result->counterexampleAsString());
});
```

Against the correct `Account`, this passes:

```
PASS  seed=12345
```

`check()` picks a seed, reports it, and re-running with the same seed reproduces
the same run exactly — the property below.

## Catching a bug

Now plant a bug. Suppose the real account silently caps the balance at 1000 — the
kind of limit that hides until enough money accumulates:

```php
public function deposit(int $amount): void
{
    $this->balance = min($this->balance + $amount, 1000);   // BUG: a hidden cap
}
```

A single deposit into an empty account will not reveal this. You have to *reach* a
balance near the cap first — which is exactly what generating sequences over a
space of starting balances does. Run it, and it fails:

```
FAIL  seed=12345 · initial=949 · deposit(63)
```

Read the counterexample string left to right:

- `seed=12345` — the seed this run was generated from. Re-run with
  `->check(seed: 12345)` and you get this identical failure.
- `initial=949` — the drawn starting balance. The bug needs a high opening balance
  to be near the cap; the generator found one.
- `deposit(63)` — the one command that triggers it. 949 + 63 = 1012, but the capped
  account stops at 1000, so the model and the system disagree.

## What shrinking did

The sequence that actually failed during generation was longer:

```
deposit(63), withdraw(56)
```

The `withdraw(56)` had nothing to do with the bug — the disagreement already
happened at `deposit(63)`. The shrinker removed it, leaving the shortest sequence
that still fails: `deposit(63)` alone. That is the whole value of shrinking — you
are handed the one command that matters, not the random walk that happened to hit it.

Two honest limits are visible right in this counterexample:

- `deposit(63)` is *not* reduced to `deposit(52)`, even though depositing 52 into a
  949 balance would overflow the cap just as well. The shrinker shortens sequences
  but does not simplify the numbers inside a command.
- `initial=949` is held fixed while shrinking, not minimised. The reported starting
  balance is the one that was drawn, not the smallest one that would fail.

Both are deliberate; see the [limitations](../README.md#what-it-does-not-do) in the
README.

## Two results you should recognise

`check()` can report two failures that are not counterexamples:

- **Vacuous.** If every command in every sequence was skipped by a false
  precondition, nothing was ever verified. That is reported as a failure with a
  `vacuous` flag — a property that checked nothing must never look like a pass.
  Usually it means the preconditions are too strict for the alphabet.
- **Not a confirmed minimum.** If shrinking hit its budget, or was abandoned
  because the system gave different verdicts on identical runs (non-determinism),
  the counterexample string says so. It is still a real failure, but the tool does
  not claim it is minimal.

## Where to go next

- `examples/ImmutableBuilder` — a pure, immutable system (uses `Ref` and the
  `Outcome` throw-checking shown above).
- `examples/ProvenanceChain` — a mutable, stateful system with a real service shape.
- The [limitations](../README.md#what-it-does-not-do) — what this tool does not do,
  stated plainly, so a surprising result reads as a known boundary rather than a bug.
```
