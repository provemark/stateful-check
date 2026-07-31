# SPEC-005: Property entry point

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-31                               |
| Amended    | maurice, 2026-07-31 — AC6 broadened: it now guards `runs < 1` alongside an empty alphabet and `maxLength < 1` (all three are ways a property would run nothing, the failure mode AC6's own justification forbids — the trigger list was narrower than the promise), and the guard is pinned to **construction** (`InvalidArgumentException`), removing the "when invoked" ambiguity. A fourth way to run nothing — every command's precondition always failing — is a *runtime* vacuous pass, not construction-detectable; deferred to AC1 as an open question, not folded into AC6. |
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
- The entry point is **generic** over the model, system, and initial-state types
  (`@template TModel, TSut, TInitial`), like `SequenceRunner`. Leaving them `mixed`
  is not minimalism — it is the same entry point with less information, and it would
  leave the dogfood setup's carefully typed `BuilderModel`/`Ref` unchecked against
  the commands (the D017/D019 lesson). Because the entry point *holds* the alphabet,
  the templates are class-level, not per-method. `TInitial` threads through to
  `PropertyResult` so a consumer reads the drawn initial state typed, not as `mixed`.
  The **R10 gate applies to `TModel`/`TSut`/`TResult` only** — the alphabet is the
  heterogeneous composition (many commands, one model/system, varying result), and it
  type-checks at PHPStan max (verified). `TInitial` needs no such check: it comes from
  a single `initial` generator, so there is no heterogeneous list to compose — the
  same reason `Ref<T>` never triggered R10.
- The generate → run → (on failure) shrink → report loop. On a failure the entry
  point keeps the failing `RunResult` from the run and threads it — with the bare
  `list<Command>`, `freshSut`, and `initialModel` — into SPEC-002's `shrink()`,
  which consumes rather than rediscovers it (AC2).
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
  - Then generation stops at the first failing sequence; that sequence's **run** is
    handed to the shrinker — the bare `list<Command>`, the failing `RunResult` from
    that run, and the same `freshSut` and `initialModel` (SPEC-002's `shrink()` is a
    consumer of what already happened: it reads the `RunResult` for the executed-set
    filter, the replay baseline, and the `sameKindAs` baseline, and does not re-run
    the sequence to rediscover them). The result carries the shrunk counterexample
    and its `Failure`.

- **AC3 — the same seed reproduces the same result** *(R4; formerly SPEC-001
  AC5)*
  - Given a seed
  - When the entry point is invoked twice with that seed and the same arguments
  - Then the same sequences are generated, the same commands execute, and the
    same verdict and counterexample are produced — including across separate
    processes.

- **AC4 — the result renders a complete, reproducible failure report**
  - Given any result, passing or failing
  - When it is inspected
  - Then it names the seed used — including when the seed was generated rather than
    supplied — and, on a failure, `counterexampleAsString()` produces the one
    reproduction artefact: the drawn initial state **and** the command sequence in
    its readable form (`initial=… · withAgent[1],build[2]`). All of it is one
    output, tested as one — a seed with no commands, or commands with no initial
    state, cannot reproduce the failure or even be fully read, which defeats the
    point. The command-sequence format is already load-bearing: SPEC-002's AC7
    meta-test asserts the shrunk counterexample by its string form, so this AC pins
    the surrounding report, not the per-command rendering (SPEC-001's `__toString`).

- **AC5 — a qualified shrink is reported, not hidden**
  - Given a failure whose shrinking hit the budget or was abandoned because the
    system is non-deterministic
  - When the result is inspected
  - Then it reports the counterexample together with that qualification, so no
    reader mistakes a budget-limited result for a minimum (R3).

- **AC6 — a configuration that would run nothing fails loudly at construction** *(required: error path)*
  - Given a command alphabet that is empty, a maximum length below one, or a run
    count below one — any static configuration under which the property would
    execute nothing
  - When the `StatefulProperty` is constructed
  - Then the constructor throws immediately (`InvalidArgumentException`) with a
    message naming the problem, rather than letting an invalid property exist that
    would silently report success over zero sequences — a property that ran nothing
    must never look like a property that passed. Each of the three conditions is one
    way to run nothing, so all three are guarded together. (A construction-time throw
    is stricter than a check-time one: the invalid property never exists to be run.)

- **AC7 — model and system start from one consistent setup** *(D012)*
  - Given an optional initial-state generator and a setup that builds both model
    and system from a drawn initial state
  - When the sequence is first run
  - Then the initial value is **drawn once per sequence**, and the initial run calls
    `setup($initial)` **once**, deriving both sides from that single call —
    `initialModel = $setup->model` and the system `$setup->system` — so the API offers
    no way to seed model and system inconsistently. The value is **drawn** once; how
    `setup` is then re-called per shrink candidate (for a fresh system, with the model
    reused) is AC8's concern. No execution ever seeds model and system from different
    draws.

- **AC8 — shrinking holds the initial state fixed** *(D012, R4)*
  - Given a failing sequence whose setup was built from a drawn initial state
  - When the sequence is shrunk
  - Then every candidate is run against a **fresh system** from that same initial
    state — `freshSut = fn () => setup($initial)->system`, so `setup` is re-called per
    candidate for a fresh system, but with the value drawn once, never re-drawn.
    Re-drawing would change the system under the shrinker and make the result
    meaningless.
  - *Asymmetry, and why it is safe (R6).* `shrink()` takes the model as a single
    **value** (`initialModel`, reused across every candidate) but the system as a
    **factory** (`freshSut`, a fresh instance per candidate). The system is mutable,
    so it must be fresh each candidate (R9b); the model is shared. That sharing is
    sound **only because `nextState` is pure** — it returns a new model and never
    mutates the one passed in. A `nextState` that mutated it would leak model state
    between candidates, the R9b leak one layer up, silently. So `nextState`'s purity
    is load-bearing here as well as for R6's oracle argument; neither may be relaxed
    without breaking the other (recorded at R6).
  - *Requirement (D022): the bug whose initial state is held fixed must surface
    **through a command** — a command's postcondition (or a thrown exception) is
    what detects the bad initial state. A failure that fires for a specific initial
    state with **no** command cannot occur in this model: the runner checks nothing
    at zero commands (D022), so there is no command-independent failure to find. The
    minimal counterexample therefore always retains at least one executed command; a
    planted case that tried to fail on the initial state alone would be the
    empty-sequence failure in disguise, and is unreachable. The meta-test for this
    AC (`tests/Meta/`) must plant the bug accordingly.*

- **AC9 — a sequence length is drawn in `[1, n]`, never zero** *(D018; was SPEC-003 AC4)*
  - Given a maximum length *n*
  - When the entry point draws a sequence
  - Then its length is drawn by `Gen::integers(1, $n, origin: 1)` — always between 1 and *n*,
    never zero, and shrinking it approaches 1. A zero-length sequence is deliberately never
    drawn because it has nothing to run and so nothing to fail: the runner checks nothing at
    zero commands (D022), so an empty draw could only ever pass — a wasted run. (SPEC-002 no
    longer probes the empty sequence either; D022 removed that candidate for the same reason.
    This convention is why the empty case never even reaches the shrinker, D018.)
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

/**
 * @template TModel
 * @template TSut
 * @template TInitial
 */
final readonly class PropertyResult
{
    /**
     * @param  list<Command<TModel, TSut, mixed>>  $counterexample
     * @param  TInitial|null  $initial  the drawn initial state (AC4), typed so a consumer can inspect
     *                                  it without a cast; null on a pass (no counterexample) and when
     *                                  no initial generator was supplied
     */
    public function __construct(
        public bool $passed,
        public int $seed,
        public int $runs,
        public array $counterexample = [],
        public ?Failure $failure = null,
        public mixed $initial = null,
        public bool $budgetExhausted = false,
        public bool $abandonedNonDeterministic = false,
    ) {}

    /** "initial=Png · withAgent[1],build[2]" — the one reproduction artefact: initial
     *  state and command sequence together, incomplete without either (AC4). */
    public function counterexampleAsString(): string;
}

/**
 * The setup for one sequence: a fresh model and a fresh system, built together so
 * they start consistent. Modelled on fast-check's ModelRunSetup, which returns
 * `{ model, real }`. Replaces the earlier pair of independent model/system
 * closures (D012): those were nullary, so they could not thread a generated
 * initial state — a MediaType, a tenant id — into both sides consistently, and
 * they left model and system asymmetric for no reason.
 *
 * @template TModel
 * @template TSut
 */
final readonly class Setup
{
    /**
     * @param  TModel  $model
     * @param  TSut    $system
     */
    public function __construct(public mixed $model, public mixed $system) {}
}

/**
 * @template TModel
 * @template TSut
 * @template TInitial
 */
final class StatefulProperty
{
    /**
     * @param  list<Generator<Command<TModel, TSut, mixed>>>  $alphabet  heterogeneous in TResult
     *                                                                    (covariant, D019); one TModel/TSut
     * @param  Closure(TInitial): Setup<TModel, TSut>         $setup    fn($initial) => new Setup($model, $system)
     * @param  Generator<TInitial>|null                       $initial  drawn once per sequence and passed to
     *                                                                  $setup. Omitted ⇒ Gen::constant(null),
     *                                                                  applied internally, so $setup always
     *                                                                  receives a value and there is one code path.
     * @return PropertyResult<TModel, TSut, TInitial>                   (via check)
     */
    public function __construct(
        private array $alphabet,
        private Closure $setup,
        private ?Generator $initial = null,   // null ⇒ Gen::constant(null) internally
        private int $maxLength = 10,
        private int $runs = 100,
    ) {}

    /** @return PropertyResult<TModel, TSut, TInitial> */
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
    // A domain generator is composed from primitives — `Gen` provides no `mediaType()`.
    initial: Gen::elements([MediaType::png(), MediaType::jpeg()]),  // drawn once per sequence
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

**Dogfood example 2 (HTTP), skip-when-unreachable is test-level, not an API feature.**
`preCondition(mixed $model): bool` receives only the model, never the system, so a
command *cannot* skip on live service reachability. In the original the skip is a
Pest guard **before** `check()` (and the fake's `isReachable()` exists to make that
guard visible); it decides whether to run the property at all, not whether to run a
command mid-sequence. This is deliberate and stays test-level: the API offers no
per-command reachability check, and no one should expect one from `preCondition`.

## Open questions

- ~~**Does the entry point throw or return on failure?**~~ **Resolved (D009):**
  return a `PropertyResult`, as sketched — framework-agnostic and composable, with
  the assertion left to a single `expect()` line. Throwing would make an exception
  type the package's public failure channel.
- **A vacuous pass — every precondition always fails — is undetected. — open, decide at AC1.**
  If every command in the alphabet has a precondition that never holds, each of the *n* sequences
  is generated and then fully skipped: zero executed commands, `passed === true`, indistinguishable
  from a real pass. It is the same family as AC6's "run nothing" but a **runtime** property, so it
  cannot be caught at construction (AC6). For a tool built to catch false confidence this is the
  sharpest false confidence there is. It belongs on the table at **AC1**, where the implementation
  already needs an independent count of how many commands actually ran (self-reported `runs` is not
  enough): that same observation is what would detect a run in which nothing executed. Decide there
  whether it is part of AC1 (a passing run must have executed at least one command across its
  sequences) or its own AC. Not decided here.
- ~~**Stop at the first failure, or keep generating?**~~ **Resolved: stop at the
  first**, as AC2 says and as every implementation does. Continuing would find
  independent failures in one pass but complicates the result shape; not added
  speculatively.
- ~~**Default run count.**~~ **Resolved: default `runs = 100`** (the QuickCheck
  convention, fine in memory), with the `runs:` constructor parameter as the escape
  the HTTP-backed dogfood example uses to lower it. The default is obvious at the
  call site and one argument overrides it.
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
