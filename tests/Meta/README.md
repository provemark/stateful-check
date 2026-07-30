# Meta-suite — planted-bug tests for the shrinker (R8)

Every shrinking behaviour needs a system with a **deliberately planted bug** and a
**known minimal reproducing sequence**, so the shrinker is tested against a real
reduction and not merely "does not crash" (CLAUDE.md §3, R8). Each case here asserts
the exact minimal sequence the shrinker returns, compared by its string form.

The dogfood examples in `examples/` do **not** exercise the shrinker: they are
written to pass, and shrinking runs only on a *failing* sequence. Validating the
shrinker is this suite's job alone. Run with `composer meta` (`--group=meta`).

## Planned cases

- **Order-dependent bug → minimal two-command sequence.** A system that fails only
  when command B follows command A. A long failing sequence must shrink to exactly
  `A, B` (SPEC-002 AC7).
- **Skipped and unreached commands are dropped.** A failing run with
  precondition-skipped commands and commands after the failure; the counterexample
  must contain neither, discovered without executing a candidate (SPEC-002 AC3).
- **Cloning isolates candidates.** A command carrying mutable state must not leak
  between successive candidates (SPEC-002 AC6, D006).
- **Non-determinism aborts.** A system whose executed path diverges on replay must
  abort shrinking and report non-determinism, never a "minimal" sequence derived
  from unstable runs (SPEC-002 AC8).
- **Budget-limited shrink.** A reducible failure under a tight budget returns the
  best sequence found so far, flagged as budget-limited rather than minimal
  (SPEC-002 AC9).
- **Initial-state-dependent bug (SPEC-005 AC8).** A bug that fires only for one
  specific generated initial state. The shrunk counterexample must **name that
  initial state and hold it fixed** — the shrinker re-runs every candidate through
  `setup` with the same initial state that failed, never re-drawing it. Neither
  dogfood example reaches this (both pass), so it has no coverage without a planted
  case here. Surfaced while porting example 2.
