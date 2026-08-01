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

A step-by-step walkthrough — every concept, building a real test, and reading a
shrunk counterexample — is in [docs/tutorial.md](docs/tutorial.md).

## What it does not do

- **No parallel execution and no automatic race detection.** PHP is
  share-nothing and request-scoped. The headline feature of the Erlang original
  is not available here and will not be claimed.
- **A local minimum, not a global one.** Shrinking returns a documented local
  minimum: no single further reduction — dropping a command *or* shrinking a value —
  both stays valid and still fails. It shortens the sequence **and** simplifies the
  values inside each command; it does not search exhaustively for a smaller
  counterexample.
- **Command choice is not shrunk.** The shrinker drops commands and shrinks their
  arguments, but never replaces a command with a *different, simpler* one from the
  alphabet. So the minimum is over *which commands ran, in what order, and with what
  argument values* — but not over *which command each step could have been*. A
  counterexample may keep a `Sign` where a `Read` would have failed too; it is a
  known coverage gap, called out because it can read as a surprising counterexample
  rather than a limitation.
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
