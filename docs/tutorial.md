# Tutorial: testing a bank account

This walks through a small example end to end — a bank account and its ledger —
introducing every concept as it comes up, and then applies them to something more
realistic: an LRU cache with a genuine ordering bug. By the end you will have a
test that generates a hundred command sequences per run, catches a bug that only
shows up after state accumulates, and shrinks it to the commands that matter.

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

## A more realistic example: an LRU cache

An LRU (least-recently-used) cache holds a fixed number of entries and, when it is
full, evicts the one used longest ago. They are everywhere, and one bug in them is
a classic: a *read* is supposed to count as a use, so it should protect an entry
from eviction — and it is easy to write a `get` that forgets to.

That bug is invisible to any single operation. You only see it in a *sequence*:
fill the cache, read an old entry, add a new one, then read the old entry again.
Exactly the kind of thing this tool is for.

The system under test:

```php
final class LruCache
{
    /** @var array<string,int> most-recently-used last */
    private array $store = [];

    public function __construct(private int $capacity) {}

    public function put(string $key, int $value): void
    {
        unset($this->store[$key]);          // drop any old position
        $this->store[$key] = $value;        // reinsert as most-recent
        if (count($this->store) > $this->capacity) {
            array_shift($this->store);      // evict the least-recently-used
        }
    }

    public function get(string $key): ?int
    {
        if (! array_key_exists($key, $this->store)) {
            return null;
        }
        $value = $this->store[$key];
        unset($this->store[$key]);          // a read refreshes recency
        $this->store[$key] = $value;

        return $value;
    }

    public function count(): int
    {
        return count($this->store);
    }
}
```

The model is the same idea, immutable, and deliberately naive — an ordered array,
where "recency" is just position. The model only has to be *right*, not efficient,
so it does not need the real thing's data structures:

```php
final readonly class CacheModel
{
    /** @param array<string,int> $entries most-recently-used last */
    public function __construct(public int $capacity, private array $entries = []) {}

    public function put(string $key, int $value): self
    {
        $entries = $this->entries;
        unset($entries[$key]);
        $entries[$key] = $value;
        if (count($entries) > $this->capacity) {
            array_shift($entries);
        }

        return new self($this->capacity, $entries);
    }

    public function touch(string $key): self     // a read moves a key to most-recent
    {
        if (! array_key_exists($key, $this->entries)) {
            return $this;
        }
        $entries = $this->entries;
        $value = $entries[$key];
        unset($entries[$key]);
        $entries[$key] = $value;

        return new self($this->capacity, $entries);
    }

    public function lookup(string $key): ?int
    {
        return $this->entries[$key] ?? null;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
```

Two commands. `Put` returns nothing, and only checks that cache and model hold the
same number of entries. `Get` is the interesting one: it *returns a value*, and its
postcondition compares that returned value — read from the `Outcome` — against what
the model says should be there. This is where the command's third type parameter
(`int|null`, what `run()` returns) and `$outcome->value` earn their place:

```php
/** @implements Command<CacheModel, LruCache, null> */
final readonly class Put implements Command
{
    public function __construct(public string $key, public int $value) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        $sut->put($this->key, $this->value);

        return null;
    }

    public function nextState(mixed $model): mixed
    {
        return $model->put($this->key, $this->value);
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $sut->count() === $model->count();
    }

    public function __toString(): string
    {
        return "put({$this->key},{$this->value})";
    }
}

/** @implements Command<CacheModel, LruCache, int|null> */
final readonly class Get implements Command
{
    public function __construct(public string $key) {}

    public function preCondition(mixed $model): bool
    {
        return true;
    }

    public function run(mixed $sut): mixed
    {
        return $sut->get($this->key);
    }

    public function nextState(mixed $model): mixed
    {
        return $model->touch($this->key);
    }

    public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
    {
        return $outcome->value === $model->lookup($this->key);   // the read agrees with the model
    }

    public function __toString(): string
    {
        return "get({$this->key})";
    }
}
```

The property draws keys from a small set, so collisions and evictions actually
happen, and draws the capacity as the initial state, so it is checked across cache
sizes:

```php
$key = Gen::elements(['a', 'b', 'c']);
$put = Gen::map(
    fn (array $a) => new Put($a['key'], $a['value']),
    Gen::associative(['key' => $key, 'value' => Gen::integers(1, 9)]),
);
$get = Gen::map(fn (string $k) => new Get($k), $key);

$result = (new StatefulProperty(
    alphabet: [$put, $put, $get],            // put twice as often as get
    initial: Gen::integers(2, 4),            // the capacity, drawn per run
    setup: fn (int $capacity) => new Setup(
        model:  new CacheModel($capacity),
        system: new LruCache($capacity),
    ),
))->check();
```

Against the correct cache this passes. Now plant the bug — a `get` that returns the
value but forgets to refresh recency:

```php
public function get(string $key): ?int
{
    if (! array_key_exists($key, $this->store)) {
        return null;
    }

    return $this->store[$key];   // BUG: a read no longer counts as a use
}
```

and one run reports:

```
FAIL  seed=5 · initial=2 · put(a,5),put(b,9),get(a),put(c,9),get(a)
```

Read that as a story, with a cache of capacity 2:

1. `put(a,5)`, `put(b,9)` — the cache is now full: `a`, `b`.
2. `get(a)` — reading `a` *should* make it most-recently-used, leaving `b` next to
   go. The buggy cache returns `5` but leaves the order untouched.
3. `put(c,9)` — the cache is full, so something is evicted. The correct cache drops
   `b`; the buggy one still thinks `a` is oldest and drops `a`.
4. `get(a)` — the model says `a` is still there, worth `5`. The buggy cache evicted
   it and returns `null`. They disagree, and the run stops.

No single command is wrong on its own — the bug lives entirely in the *ordering*,
and only the second `get(a)` can see it. That is what a stateful test finds and a
per-operation unit test does not.

The sequence that actually failed during generation was ten commands long:

```
put(a,5),get(c),put(b,9),get(b),get(a),get(c),get(a),put(c,9),get(a),put(b,9)
```

The shrinker cut it to the five above and confirmed each is load-bearing: remove
any one — the first `put`, the fill, the refreshing `get`, the evicting `put`, or
the observing `get` — and the bug no longer reproduces.

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
