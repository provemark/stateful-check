# SPEC-005: Property entry point

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-31                               |
| Amended    | maurice, 2026-07-31 — AC6 broadened: it now guards `runs < 1` alongside an empty alphabet and `maxLength < 1` (all three are ways a property would run nothing, the failure mode AC6's own justification forbids — the trigger list was narrower than the promise), and the guard is pinned to **construction** (`InvalidArgumentException`), removing the "when invoked" ambiguity. A fourth way to run nothing — every command's precondition always failing — is a *runtime* vacuous pass, not construction-detectable; deferred to AC1 as an open question, not folded into AC6. |
| Amended    | maurice, 2026-07-31 — `initial` is **required**, not `?Generator = null`. The sketched "omitted ⇒ `Gen::constant(null)` internally" cannot type-check: the internal `null` fed to `setup: Closure(TInitial)` is unsound for a non-null `TInitial` (PHPStan max, `argument.type`, verified). The user passes `Gen::constant(null)` explicitly for "no initial state" — "one code path" preserved, only the omit-convenience dropped. Found while building AC1 sub-step 1: the unbuildability was in the *combination* of two sketch elements (`initial`'s default × `setup`'s parameter type), which a per-class review of the sketches missed. |
| Amended    | maurice, 2026-07-31 — AC9 narrowed: the "shrinking it approaches 1 / `origin: 0` must break it" clause is removed — its trigger is unreachable, because SPEC-002 shrinks the command list structurally and never shrinks the drawn length through the length generator, so `origin: 1` has no consumer (vestigial, kept in code with a comment). AC9 now pins only what is true and testable: the length is drawn in `[1, n]`, never zero (`min: 1`). R11's fourth "unreachable trigger" instance; it escaped approval because AC9 was transplanted from SPEC-003 AC4 and transplanted text does not re-pass the gate. |
| Amended    | maurice, 2026-07-31 — AC10 added: a run in which no command executed across any sequence is reported `passed: false` with a `vacuous` qualification (no counterexample, no `Failure`), the runtime counterpart of AC6 — a property that verified nothing must never look like a pass, and a silent marker on a green result would be that same failure one layer up. Condition is exactly zero executed (objective, not a threshold), observed from `RunResult::$executed`; vacuous and failure are mutually exclusive (a failure requires a command to have run). Rendering the vacuous case is AC4's job; AC10 owns the verdict and the flag. |
| Amended    | maurice, 2026-07-31 — AC3 split explicitly: it owns **generation** reproduction (same seed → same sequences; a different seed → different), and the "same **counterexample**" half — a found failure re-found on the same seed — is delivered at AC2 with a forward reference, so AC3 is not checked off with the most valuable half uncovered. Cross-process reproduction is stated as measured (`docs/verification/mt19937.php`), not derived, with the prior-art caveats (other PHP minors, 32-bit, non-Linux). A new requirement is recorded: the whole seed→outcome chain must stay free of non-deterministic sources (unordered iteration, time, `spl_object_id`), turning an accidental truth into a checkable one. |
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
  - Then the **same sequences are generated and the same commands execute**, and a
    *different* seed generates different sequences — so the seed genuinely threads
    through generation, not merely a deterministic stream that ignores it.
  - *The "same **counterexample**" half of reproduction — that a found failure is
    re-found on the same seed — is delivered and tested at **AC2**, which builds the
    failure path and the shrinker. AC3 owns generation reproduction; AC2 owns
    counterexample reproduction. Recorded so AC3 is not checked off while the most
    valuable half of reproducibility is uncovered (the same one-AC-one-deliverable
    split as AC10's rendering → AC4).*
  - *Across separate processes: **measured, not derived** — `docs/verification/mt19937.php`
    runs the seeded engine in independent processes and confirms identical output. Still
    unverified, and honestly so (prior-art): other PHP minor versions, 32-bit builds,
    non-Linux platforms — 32-bit is the plausible edge (`PHP_INT_SIZE` affects range
    mapping). Re-run the script if the package ever claims support beyond 64-bit Linux. A
    subprocess test here would only re-measure a property of the PRNG.*
  - *Requirement — the whole chain must stay deterministic, not just the generator.
    `check(seed)` reproduces only if everything between the seed and the outcome is free of
    non-deterministic sources: no iteration over unordered structures, no wall-clock time,
    no `spl_object_id`/identity-hash ordering. True today, but a property of the loop that
    was nowhere required; stating it turns an accidental truth into a checkable one.*

- **AC4 — the result renders a complete, reproducible failure report**
  - Given any result, passing or failing
  - When it is inspected
  - Then it names the seed used — including when the seed was generated rather than
    supplied — and, on a failure, `counterexampleAsString()` produces the one
    reproduction artefact: the seed, the drawn initial state **and** the command
    sequence in its readable form (`seed=123 · initial=… · withAgent[1],build[2]`).
    The seed is inside the string, not only a field: it is one line copied out of a
    CI log, and without it the artefact its own name promises to reproduce cannot be
    re-run. All of it is one output, tested as one — a seed with no commands, or
    commands with no initial state, cannot reproduce the failure or even be fully
    read, which defeats the point. The command-sequence format is already load-bearing: SPEC-002's AC7
    meta-test asserts the shrunk counterexample by its string form, so this AC pins
    the surrounding report, not the per-command rendering (SPEC-001's `__toString`).
  - *For a **vacuous** result (AC10) there is no counterexample; `counterexampleAsString()` renders an
    explanation instead of an empty command string — that nothing was verified, and the likely cause
    (an alphabet whose preconditions never hold, or a model too strict). AC10 owns the verdict and the
    flag; this AC owns turning that flag into a message.*

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
  - Given an initial-state generator (required — `Gen::constant(null)` for "none")
    and a setup that builds both model and system from a drawn initial state
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
    never zero. A zero-length sequence is deliberately never drawn because it has nothing to run
    and so nothing to fail: the runner checks nothing at zero commands (D022), so an empty draw
    could only ever pass — a wasted run. (SPEC-002 no longer probes the empty sequence either;
    D022 removed that candidate for the same reason.)
  - *This is a convention, not a new generator: it is a usage of `integers`, whose behaviour AC2
    of SPEC-003 already covers. What a test here pins is the choice `min: 1` — a change to `[0, n]`
    must break it — guarding D018.*
  - *`origin: 1` is vestigial (amendment 2026-07-31). It would matter only if the drawn length were
    shrunk **through this generator**, but SPEC-002 shrinks the command **list** structurally
    (dropping contiguous chunks) and never calls the length generator to reduce its value — the
    length is never shrunk after generation. So `origin: 1` has no consumer, and the earlier
    "shrinking it approaches 1 / `origin: 0` must break it" was a clause with an unreachable trigger
    (the same class as the empty-candidate branch; R11's fourth instance). It escaped approval
    because AC9 was transplanted from SPEC-003 AC4, and transplanted text does not re-pass R11. The
    `origin: 1` argument is kept in the code with a comment: it is the right value if length-shrinking
    is ever added.*
  - *Note (D018): "at least one" is about the **generated** length, not the number of commands in
    a counterexample. A command whose precondition fails is skipped and drops out of the shrink
    representation (R1), so a shrunk counterexample may contain zero executed commands even though
    the drawn length was ≥ 1.*

- **AC10 — a run in which no command ever executed is reported as vacuous, not passed** *(amendment 2026-07-31; the runtime counterpart of AC6)*
  - Given a property whose sequences all pass, but across **every sequence that ran** not one command
    executed — each was skipped by a false precondition (R1) — read from each run's `RunResult::$executed`,
    which the runner already records (nothing new is counted)
  - When the entry point finishes
  - Then the result reports **`passed: false`** with a **`vacuous`** qualification, and no counterexample
    and no `Failure` (nothing failed — nothing ran). The `vacuous` flag carries the distinction:
    `passed: false` with `vacuous: true` means "there was nothing to check", `vacuous: false` a
    counterexample — so an empty counterexample is never mistaken for a bug in this package. A property
    that verified nothing must never report success — the runtime counterpart of AC6's construction guard.
    (Rendering the vacuous case in a message is AC4's `counterexampleAsString()`; AC10 owns the verdict
    and the flag.)
  - *Vacuous and failure are mutually exclusive, so no precedence is needed — and here is the reasoning
    to re-check if the failure path ever changes: a postcondition runs only **after** `run()`, so a
    postcondition failure means `run()` executed; and a `run()` that throws has itself executed. So every
    failing sequence has run at least one command, and a run that executed nothing cannot have failed. If
    a future change lets a sequence fail without executing a command, this exclusion breaks and the
    precedence of `vacuous` versus a counterexample must be defined.*
  - *The cause, for the user: this is almost always an alphabet whose preconditions never hold, or a model
    too strict — loosen the preconditions (or the model) so commands can run. AC4's rendering surfaces
    this; AC10 records it.*
  - *The condition is exactly **zero** executed, not a threshold: "too few ran" is a gradual judgement the
    tool cannot defend; zero is objective and catches the fault that matters. A single all-skipped sequence
    stays legitimate — only a whole run in which nothing executed is vacuous.*

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
        public bool $vacuous = false,   // AC10: passed false because nothing ran, not a counterexample
    ) {}

    /** "seed=123 · initial=Png · withAgent[1],build[2]" — the one reproduction artefact:
     *  seed, initial state and command sequence together, incomplete without any (AC4). A
     *  budget-limited or abandoned result also carries a "not a confirmed minimum" marker (R3). */
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
     * @param  Generator<TInitial>                            $initial  drawn once per sequence and passed to
     *                                                                  $setup. Required, not omittable: passing
     *                                                                  `Gen::constant(null)` for "no initial state"
     *                                                                  is explicit, and a `?Generator = null` default
     *                                                                  cannot type-check — the internal
     *                                                                  `Gen::constant(null)` would feed `null` to
     *                                                                  `Closure(TInitial)`, unsound for a non-null
     *                                                                  `TInitial` (amendment 2026-07-31).
     * @return PropertyResult<TModel, TSut, TInitial>                   (via check)
     */
    public function __construct(
        private array $alphabet,
        private Closure $setup,
        private Generator $initial,
        private int $maxLength = 10,
        private int $runs = 100,
        private int $budget = 100,   // max shrink candidate executions (D007); the consumer of $budgetExhausted (AC5)
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

Filled per AC as it is implemented — the Traceability section may change on an `approved` spec without
re-approval. Every acceptance criterion maps to at least one test; every source file maps back to this
spec. All ten acceptance criteria are now implemented; the status flip to `implemented` follows the
three-sided traceability check.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | `tests/Unit/StatefulPropertyTest.php` :: "runs a drawn sequence and reports success…" + "runs n sequences, each with a fresh setup and its own drawn initial" + "stops at the first failing sequence and reports failure" + "advances one seeded stream across the sequences…" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::check` (the generate → run → report loop) |
| AC2                  | `tests/Unit/StatefulPropertyTest.php` :: "shrinks the first failing sequence to a counterexample, with a fresh system per candidate" + "reproduces the same counterexample from the same seed…" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::check` (the shrink wiring, the fresh-per-candidate `freshSut`); `src/PropertyResult.php` :: `$counterexample`, `$failure`, `$executions` |
| AC3                  | `tests/Unit/StatefulPropertyTest.php` :: "reproduces the same generation from the same seed, and varies with a different one" (SPEC-005); the counterexample-reproduction half is "reproduces the same counterexample from the same seed…" (delivered with AC2) | `src/StatefulProperty.php` :: `StatefulProperty::check` (`Source::seeded($seed)`); `src/Generation/Source.php` :: `Source::seeded` |
| AC4                  | `tests/Unit/StatefulPropertyTest.php` :: "reports the seed it was given" + "generates and reports a seed when none is given, and that seed reproduces" + "reports the drawn initial state that produced the counterexample" + "renders the seed, initial state and command sequence as one artefact" + "renders any initial state without a fatal" (six-type totality proof) + "renders a vacuous result as an explanation…" + "renders the seed and a no-counterexample note for a passing result" + "marks a budget-limited or abandoned counterexample as not a confirmed minimum" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::check` (`?int $seed` + `random_int` auto-seed; `$initialValue` into the result; `noInitial()` for the pass/vacuous branches); `src/PropertyResult.php` :: `$seed`, `$initial`, `TInitial`, `counterexampleAsString` (seed + initial + commands in one string, the R3 "not a confirmed minimum" marker, the vacuous explanation) |
| AC5                  | `tests/Unit/StatefulPropertyTest.php` :: "reports an abandoned (non-deterministic) shrink as a qualification, not a clean counterexample" + "reports a budget-limited shrink as a qualification" (SPEC-005) — each mutant-proven independently (dropping either flag reddens only its own test) | `src/StatefulProperty.php` :: `StatefulProperty::check` (propagates the flags), `$budget` param (the consumer of `budgetExhausted`); `src/PropertyResult.php` :: `$budgetExhausted`, `$abandonedNonDeterministic`, and the four-way-exclusion + R3 docblock |
| AC6                  | `tests/Unit/StatefulPropertyTest.php` :: "throws at construction when the command alphabet is empty / maximum length is below one / run count is below one" + "constructs without throwing when the configuration is valid" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::__construct` (the run-nothing guard) |
| AC7                  | folded into AC1 (the setup conversion has no consumer without the loop); the one-consistent-setup guarantee is asserted by "runs n sequences, each with a fresh setup and its own drawn initial" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::check` (`setup($initial)` once per execution → `initialModel` + `freshSut`); `src/Setup.php` |
| AC8                  | `tests/Meta/InitialStateFixedShrinkTest.php` :: "holds the drawn initial state fixed while shrinking" (groups `meta`, `SPEC-005`) — a planted initial-state bug (via a command, D022) shrinks to `[check]` only because the initial is held fixed | `src/StatefulProperty.php` :: `StatefulProperty::check` (the shrink's `freshSut = fn () => setup($initialValue)->system`, reusing the captured initial). Note: the mechanism is green on arrival (built at AC2); a re-drawing `freshSut` is caught by SPEC-002's own AC8 abort (indistinguishable from a flaky system), so this test documents the property rather than adding an independent defence |
| AC9                  | `tests/Unit/StatefulPropertyTest.php` :: "draws every sequence length in [1, n], never zero" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::check` (`Gen::integers(1, $this->maxLength, origin: 1)`) |
| AC10                 | `tests/Unit/StatefulPropertyTest.php` :: "reports a run in which no command ever executed as vacuous, not passed" (SPEC-005) | `src/StatefulProperty.php` :: `StatefulProperty::check` (the vacuous branch, `$anyExecuted`); `src/PropertyResult.php` :: `$vacuous` |
