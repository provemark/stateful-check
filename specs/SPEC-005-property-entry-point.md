# SPEC-005: Property entry point

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | maurice                                           |
| Approved   | — (draft)                                         |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-001 executes a concrete list of commands. SPEC-002 shrinks a concrete
failing list. SPEC-003 generates values. Nothing connects them, and nothing owns
the seed.

That gap surfaced concretely: SPEC-001 originally carried an acceptance criterion
about seeded reproducibility, but `SequenceRunner::run()` takes a command list and
never sees a seed. The criterion had no home because the object it describes did
not exist.

This spec defines that object: the thing a user actually calls. It generates a
sequence from a seed, runs it, and on failure shrinks it and reports a
counterexample. It is small, but it is the only part of the package most users
will name, so its shape is also the package's public face.

Governing rules: R2 (never report a passing counterexample), R3 (local minimum),
R4 (determinism), CLAUDE.md §4 (build only what the dogfood suites need).

## Scope

**In scope**

- A single entry point taking: the command alphabet, a **setup** that produces a
  fresh model and system together — optionally parameterised by a generated
  initial state — a maximum sequence length, a number of runs, and a seed (D012).
- The generate → run → (on failure) shrink → report loop.
- Deterministic reproduction from a seed, end to end (this is where SPEC-001's
  former AC5 lives).
- A result object carrying pass/fail, the seed used, the number of runs
  performed, and on failure the shrunk counterexample, its `Failure`, and the
  initial state drawn for it.
- Rendering a counterexample as a readable string, e.g. `inc[1],check[1]`.
- Reporting, not throwing, when shrinking was budget-limited (SPEC-002 AC9) or
  abandoned for non-determinism (SPEC-002 AC8) — those are qualifications on the
  result, not separate failures.

**Out of scope** (each needs its own spec before it may be built)

- PHPUnit or Pest integration beyond being callable from a test. No custom
  assertions, no trait, no plugin. If the dogfood examples read badly without
  one, that is evidence for a later spec, not licence to add it now.
- Parallel runs, progress reporting, statistics on command distribution.
- Automatic re-running of a stored counterexample from a file.

## Behavior

- **AC1 — a passing property runs the requested number of sequences**
  - Given a property that always holds
  - When the entry point is invoked with *n* runs
  - Then *n* sequences are generated and executed, and the result reports success
    with the seed used.

- **AC2 — a failing property returns a shrunk counterexample**
  - Given a property that fails for some sequence
  - When the entry point is invoked
  - Then generation stops at the first failing sequence, that sequence is passed
    to the shrinker, and the result carries the shrunk counterexample and its
    `Failure`.

- **AC3 — the same seed reproduces the same result** *(R4; formerly SPEC-001
  AC5)*
  - Given a seed
  - When the entry point is invoked twice with that seed and the same arguments
  - Then the same sequences are generated, the same commands execute, and the
    same verdict and counterexample are produced — including across separate
    processes.

- **AC4 — the seed and the generated initial state are always reported**
  - Given any result, passing or failing
  - When it is inspected
  - Then it names the seed used — including when the seed was generated rather than
    supplied — and, on a failure, the initial state drawn for the counterexample,
    which `counterexampleAsString()` renders. Without both, the failure cannot be
    reproduced or even fully read, which defeats the point.

- **AC5 — a qualified shrink is reported, not hidden**
  - Given a failure whose shrinking hit the budget or was abandoned because the
    system is non-deterministic
  - When the result is inspected
  - Then it reports the counterexample together with that qualification, so no
    reader mistakes a budget-limited result for a minimum (R3).

- **AC6 — an empty or unusable alphabet fails loudly** *(required: error path)*
  - Given an empty command alphabet, or a maximum length below one
  - When the entry point is invoked
  - Then it throws immediately with a message naming the problem, rather than
    silently reporting success over zero sequences — a property that ran nothing
    must never look like a property that passed.

- **AC7 — model and system start from one consistent setup** *(D012)*
  - Given an optional initial-state generator and a setup that builds both model
    and system from a drawn initial state
  - When a sequence is generated
  - Then the value drawn for that sequence is passed to the setup exactly once, and
    the model and system it returns both begin from that same initial state — the
    API offers no way to seed the two inconsistently.

- **AC8 — shrinking holds the initial state fixed** *(D012, R4)*
  - Given a failing sequence whose setup was built from a drawn initial state
  - When the sequence is shrunk
  - Then every candidate is run through `setup` called with that **same** initial
    state; the value is never re-drawn per candidate, which would change the system
    under the shrinker and make the result meaningless.

- **AC9 — a sequence length is drawn in `[1, n]`, never zero** *(D018; was SPEC-003 AC4)*
  - Given a maximum length *n*
  - When the entry point draws a sequence
  - Then its length is drawn by `Gen::integers(1, $n, origin: 1)` — always between 1 and *n*,
    never zero, and shrinking it approaches 1. The empty sequence is deliberately never drawn
    here: it is SPEC-002's own first shrink candidate ("did the commands cause the failure at
    all?"), a semantically distinct step this layer does not touch (D018).
  - *This is a convention, not a new generator: it is a usage of `integers`, whose behaviour AC2
    of SPEC-003 already covers. What a test here pins is the choice `min: 1, origin: 1` — a
    change to `[0, n]` or `origin: 0` must break it — guarding D018.*
  - *Note (D018): "at least one" is about the **generated** length, not the number of commands in
    a counterexample. A command whose precondition fails is skipped and drops out of the shrink
    representation (R1), so a shrunk counterexample may contain zero executed commands even though
    the drawn length was ≥ 1.*

## API sketch

Illustrative only.

```php
// namespace Provemark\StatefulCheck;

final readonly class PropertyResult
{
    /** @param list<Command> $counterexample */
    public function __construct(
        public bool $passed,
        public int $seed,
        public int $runs,
        public array $counterexample = [],
        public ?Failure $failure = null,
        public mixed $initial = null,   // initial state drawn for the counterexample (AC4)
        public bool $budgetExhausted = false,
        public bool $abandonedNonDeterministic = false,
    ) {}

    /** "initial=Png · inc[1],check[1]" — includes the initial state, without which
     *  the counterexample is incomplete (AC4). */
    public function counterexampleAsString(): string;
}

/**
 * The setup for one sequence: a fresh model and a fresh system, built together so
 * they start consistent. Modelled on fast-check's ModelRunSetup, which returns
 * `{ model, real }`. Replaces the earlier pair of independent model/system
 * closures (D012): those were nullary, so they could not thread a generated
 * initial state — a MediaType, a tenant id — into both sides consistently, and
 * they left model and system asymmetric for no reason.
 */
final readonly class Setup
{
    public function __construct(public mixed $model, public mixed $system) {}
}

final class StatefulProperty
{
    /**
     * @param  list<Generator<Command>>  $alphabet
     * @param  Closure(mixed): Setup     $setup    fn($initial) => new Setup($model, $system)
     * @param  Generator<mixed>|null     $initial  drawn once per sequence and passed to
     *                                             $setup. Omitted ⇒ Gen::constant(null),
     *                                             applied internally, so $setup always
     *                                             receives a value and there is one code path.
     */
    public function __construct(
        private array $alphabet,
        private Closure $setup,
        private ?Generator $initial = null,   // null ⇒ Gen::constant(null) internally
        private int $maxLength = 10,
        private int $runs = 100,
    ) {}

    public function check(?int $seed = null): PropertyResult;
}
```

Usage, as the dogfood examples should read. Note that the alphabet is a list of
*generators of commands*, not commands — each draws its arguments, including the
blank strings that drive the error boundary:

```php
$result = (new StatefulProperty(
    alphabet: [
        Gen::map(
            fn (string $n) => new WithSoftwareAgent($n),
            Gen::elements(['ACME GenAI', 'agent-2', '']),   // '' → build() must throw
        ),
        Gen::map(
            fn (string $n) => new WithClaimGenerator($n),
            Gen::elements(['Content Credentials', '']),
        ),
    ],
    initial: Gen::mediaType(),                             // drawn once per sequence
    setup: fn (MediaType $type) => new Setup(
        model:  BuilderModel::initial($type),
        system: new Ref(ManifestBuilder::forAiGeneratedImage($type)),
    ),
))->check();

expect($result->passed)->toBeTrue($result->counterexampleAsString());
```

And the shape of a command in that alphabet, showing where the throwing call
lives. This is the pattern to follow: the operation that may throw is the **last**
thing `run()` does, so the runner captures it in the `Outcome` and so the pure
transition still describes what happened (SPEC-001 design notes).

```php
public function run(mixed $sut): mixed          // $sut is the Ref
{
    $sut->value = $sut->value->withSoftwareAgent($this->name);
    return $sut->value->build();                 // throws when the name is blank
}

public function nextState(mixed $model): mixed  // pure; predicts the throw
{
    return $model->withSoftwareAgent($this->name);
}

public function postCondition(mixed $model, mixed $sut, Outcome $outcome): bool
{
    return $outcome->threw === ! $model->canBuild();
}
```

## Open questions

- ~~**Does the entry point throw or return on failure?**~~ **Resolved (D009):**
  return a `PropertyResult`, as sketched — framework-agnostic and composable, with
  the assertion left to a single `expect()` line. Throwing would make an exception
  type the package's public failure channel.
- **Stop at the first failure, or keep generating? — open, non-blocker.** AC2
  stops at the first, which is what every implementation does. Continuing would
  find independent failures in one pass but complicates the result shape. Do not
  add it speculatively.
- **Default run count — open, non-blocker.** 100 is the QuickCheck convention and
  is fine in memory, but the HTTP-backed dogfood example will want far fewer.
  Confirm the default and make it obvious how to lower it.
- ~~**Does the initial state shrink?**~~ **No — a documented limitation under R3
  (D012).** The value drawn from `initial` is held fixed for the whole
  counterexample (AC8); only the command sequence is reduced. So the reported
  counterexample may name an initial state that is not itself minimal. That is
  precisely R3's "documented local minimum, not global" guarantee — stated plainly,
  not hidden. For inputs like `MediaType`, where the bug axis is ordering, it costs
  nothing. Add initial-state shrinking only if a meta-test shows a counterexample it
  would meaningfully reduce.
- ~~**Where does the throwing call live in dogfood example 1?**~~ **Resolved:**
  inside `run()`, as its last statement, so the runner captures it in the
  `Outcome` (see the sketch above). The alternative — a separate `Build` command,
  or calling `build()` from the postcondition — would put the throw outside the
  runner's `Outcome` wrapping, where it is an uncaught error rather than a
  captured one.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
