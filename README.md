# stateful-check

Model-based (stateful) property testing for PHP. Generate sequences of
commands, run them against a system and a shadow model in lockstep, and shrink
a failure to a minimal counterexample.

> **Status: pre-release.** The v0.1 engine is implemented and dogfooded against
> two ported real-world suites, but it has not been tagged and the API may still
> change before 1.0.

## Why

Property-based testing generates values. A stateless property looks for an
*input* that breaks an invariant; a stateful one looks for an *ordering of
events* — the read before the write, the setter that clobbers an
unrelated slot, the retry that double-applies. You cannot reach those by
generating more values. You have to generate programs.

Erlang, Clojure, Python and TypeScript have had this for years. PHP does not.

## What it does

```php
use Provemark\StatefulCheck\{StatefulProperty, Setup};
use Provemark\StatefulCheck\Generation\Gen;

// Deposit and Withdraw are commands — four methods each: precondition, run,
// nextState, postcondition. Elided here; the examples/ directory has full ones.
$deposit  = Gen::map(fn (int $n) => new Deposit($n),  Gen::integers(1, 100));
$withdraw = Gen::map(fn (int $n) => new Withdraw($n), Gen::integers(1, 100));

$result = (new StatefulProperty(
    alphabet: [$deposit, $withdraw],
    initial: Gen::integers(0, 1000),                 // a starting balance, drawn per run
    setup: fn (int $opening) => new Setup(
        model:  new LedgerModel($opening),           // the shadow model
        system: new Account($opening),               // the real system under test
    ),
))->check();
```

Four parts per command: a precondition, execution against the real system, a
model transition, and a postcondition comparing the two. The runner checks after
every step; the shrinker reduces a twelve-command failure to the two commands
that actually matter. The initial state is generated as well, so the property is
checked across a *space* of starting points rather than one fixed setup.

## What it does not do

- **No parallel execution and no automatic race detection.** PHP is
  share-nothing and request-scoped. The headline feature of the Erlang original
  is not available here and will not be claimed.
- **No global minimum.** Shrinking returns a documented local minimum: no single
  further reduction step both stays valid and still fails.
- **No argument shrinking.** Shrinking makes a failing *sequence* shorter, but it
  does not simplify the values inside a command — a `Deposit(9999)` in a
  counterexample stays as it was drawn, even if `Deposit(1)` would fail just as
  well. fast-check and Hypothesis shrink both structure and values;
  this shrinks structure only. The value-shrinking layer was deliberately retracted
  when no planted-bug case needed it, rather than carried as speculative generality;
  a later version can add it behind the same interface.
- **Command choice is not shrunk.** The shrinker drops and reorders commands, but
  never replaces one with a simpler command from the alphabet. So a counterexample
  may contain a more complex command where a simpler one would have failed too: the
  minimum is over *which commands ran and in what order*, not over *which command
  each step could have been*. It is a known coverage gap, called out here because it
  can read as a surprising counterexample rather than a limitation.
- **No general-purpose generator library.** Generation is owned — seeded on PHP
  8.2's Random extension — but only the combinators this needs exist. It is not a
  replacement for a full property-testing toolkit.
- **No stateless property testing.** This is the *stateful* layer: it generates
  and shrinks command sequences. It has no `forAll` for input-based properties. If
  you also write those, pair it with a stateless property tester — [Eris](https://github.com/giorgiosironi/eris)
  is the natural companion in PHP.
- **No help with non-deterministic systems.** Shrinking requires a stable
  verdict. A flapping system aborts shrinking with a message rather than
  reporting a misleading counterexample.
- **No check that a system's own values are immutable.** The runner threads one
  system handle through the sequence (a `Ref` for immutable systems), which erases
  the distinction between an immutable value passed forward and a mutable object
  mutated in place. A property such as "an earlier builder instance is untouched by
  later commands" cannot be expressed here — it is a property of the system, not of
  the command sequence, and belongs in an ordinary test.

## Dependencies

None at runtime beyond PHP 8.2. Generation is built on the Random extension,
which gives seeded, isolated sources — the determinism that sound shrinking
requires.

## Prior art

`docs/prior-art.md` records what fast-check, stateful-check, PropEr and
Hypothesis do, and what the two earlier PHP attempts tell us. The closest
blueprint is fast-check's `fc.commands`.

## Development

Spec-driven: every change starts from an approved spec in `specs/`, tests come
before implementation, and each acceptance criterion maps to a test in the
spec's traceability table.

```bash
composer check    # pint + phpstan (max) + pest
composer meta     # shrinker correctness against planted bugs
composer examples # the two ported real-world dogfood suites
```

## Licence

MIT.
