# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Specs SPEC-001 (command contract and runner), SPEC-002 (sequence shrinking),
  SPEC-003 (generation core), SPEC-004 (optional Eris adapter, unscheduled). All
  `draft`.
- `ROADMAP.md` — order of work for v0.1, and the two maintainer decisions that
  must precede any approval.
- `docs/prior-art.md` — fast-check, stateful-check, PropEr, Hypothesis, and the
  two earlier PHP attempts.

### Changed

- Specs revised after reading fast-check's `CommandsArbitrary` source. Candidate
  re-validation dropped (unnecessary: skipped preconditions cannot make a
  shortened sequence ill-formed); shrinking now filters to executed commands.
  Symbolic results excluded from v0.1 by design. Command cloning and execution
  -path-based non-determinism detection added.
- Eris dropped as a runtime dependency. SPEC-003 rewritten from "generator port
  over Eris" to a self-owned generation core on PHP 8.2's Random extension,
  which removes both of that draft's blockers. Eris moved to SPEC-004: optional,
  `suggest`, unscheduled.
