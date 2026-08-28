# Sprint Protocol

This repository is developed through isolated, committed sprints.

## Mandatory workflow

Every sprint MUST:

1. Inspect the current repository state before making changes.
2. Read `dev-docs/SPRINT_PROTOCOL.md`.
3. Read `dev-docs/SPRINT_STATE.md`.
4. Read only the architecture/design documents relevant to the current sprint.
5. Inspect the actual source code before relying on historical documentation.
6. Define and respect a strict sprint scope.
7. Do not modify files outside the declared scope unless a discovered dependency makes it strictly necessary.
8. If an architectural assumption is false, stop and reassess rather than implementing around it.
9. Add or update tests for all new behavior.
10. Preserve existing security and architectural invariants.
11. Run targeted tests for the changed subsystem.
12. Run relevant regression suites.
13. Run syntax/static/security checks appropriate to the changed files.
14. Run `git diff --check`.
15. Inspect `git diff` before committing.
16. Stage ONLY files belonging to the current sprint.
17. Never stage `.tokensave/` or unrelated pre-existing changes.
18. Commit exactly once at the end of the sprint.
19. Update `dev-docs/SPRINT_STATE.md` before the commit.
20. Produce a concise final report after the commit.

## STOP conditions

STOP implementation and report the issue if:

- the requested architecture cannot be implemented without violating an existing invariant;
- a security boundary must be weakened;
- an existing security guarantee would be silently changed;
- the task requires modifying an explicitly protected component outside scope;
- the actual source contradicts a required design assumption;
- a new public behavior cannot be specified unambiguously;
- tests cannot establish the required security property;
- the implementation would require inventing semantics not supported by the repository or task.

Do not silently work around a STOP condition.

## Security invariants

Unless the current sprint explicitly changes and re-approves them:

- no caller-selected executable/script;
- no arbitrary shell execution from HTTP/API code;
- no caller-selected authenticated actor;
- authentication precedes authorization;
- authorization precedes lock acquisition and execution;
- sensitive values must not appear in targets, responses, errors, logs, or exceptions;
- API v2 must not bypass CommandAdapter for registered operations;
- API-owned operation allowlists must remain explicit;
- API public contracts must not expose internal registry-only parameters;
- mutation uncertainty must never be represented as confirmed success without source evidence;
- existing legacy behavior must not be changed accidentally.

## Documentation

Design and implementation documents belong under:

`dev-docs/`

API v2 documents belong under:

`dev-docs/api-v2/`

Do not create new design/implementation Markdown files in the repository root.

## Testing

At minimum:

- targeted tests for the sprint;
- relevant subsystem regression tests;
- `git diff --check`.

When the sprint changes API v2:

- API suite;
- auth suite if authentication is affected;
- adapter suite if adapter behavior is affected.

Prefer three consecutive clean runs for security-sensitive changes.

## Git

One sprint = one commit.

Commit format:

`feat(<area>): <short description>`

or, when appropriate:

`fix(<area>): <short description>`

Documentation/process-only commits may use:

`chore(<area>): <short description>`

Before committing:

- `git status --short`
- `git diff --check`
- `git diff --stat`
- `git diff`

Stage only sprint-owned files.

Never commit `.tokensave/`.

## Final report

The final report MUST contain:

1. Sprint name
2. Commit hash
3. Commit message
4. Files changed
5. Implementation summary
6. Security verification
7. Tests and results
8. Regression results
9. Architectural findings
10. Deferred work
11. STOP conditions
12. Final verdict
