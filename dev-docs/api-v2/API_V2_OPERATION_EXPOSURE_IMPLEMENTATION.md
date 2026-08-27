# API v2 — Operation Exposure Implementation

**Sprint 3 deliverable.** Expands API v2 from Sprint 2's single
proving-ground operation (`domain.get`) to the full set of seven
already-supported adapter operations, per
[`API_V2_HTTP_CONTRACT_DESIGN.md`](./API_V2_HTTP_CONTRACT_DESIGN.md) and
[`API_V2_HTTP_ENTRY_POINT_IMPLEMENTATION.md`](./API_V2_HTTP_ENTRY_POINT_IMPLEMENTATION.md).

## 1. Sprint Scope

"Expose the seven already-supported core operations through the
existing API v2 HTTP layer." No new adapter operations, no
authentication/authorization-policy changes, no rate limiting, audit
logging, idempotency, async jobs, Cloud Account/tenant/roles, or
expiration/rotation. `CommandAdapter`, `CommandRegistry`,
`ParameterValidator`, `AuthorizerInterface`, `SameUserAuthorizer`,
`LockManager`, `bin/*`, legacy `web/api/index.php`, and the
authentication classes were **not modified**.

## 2. Operations Exposed

`OperationAllowlist::ALLOWED_OPERATIONS` (fixed `const`, unchanged
mechanism from Sprint 2 — not derived from `CommandRegistry`):

```
domain.get, domain.list, domain.create, domain.delete,
database.create, database.delete, backup.schedule
```

## 3. Public API Parameter Contracts

New this sprint: `web/inc/api/OperationParameterContract.php` — an
explicit, API-owned, **name-level** table, checked by
`ExecuteRequestHandler` before normalization. It answers only "which
parameter names may a caller supply, and which are required" — never
type/shape/emptiness, which remains `CommandAdapter`'s/
`ParameterValidator`'s exclusive job (Sprint 2's established split,
unchanged).

| Operation | Required params (public) | Sensitive | Public identifier type | Normalized before adapter? |
|---|---|---|---|---|
| `domain.get` | `user`, `domain` | — | both raw/public = internal | No |
| `domain.list` | `user` | — | raw/public = internal | No |
| `domain.create` | `user`, `domain` | — | raw/public = internal | No |
| `domain.delete` | `user`, `domain` | — | raw/public = internal | No |
| `backup.schedule` | `user` | — | raw/public = internal | No |
| `database.create` | `user`, `database`, `dbuser`, `password` | `password` | `database`/`dbuser`: raw suffix, public = internal | No |
| `database.delete` | `user`, `database` | — | `database`: public = raw suffix, internal = `{user}_{suffix}` | **Yes** |

None of these operations has an optional public parameter this sprint.

**What the authorizer ultimately sees**, for every operation: exactly
`CommandAdapter`'s own internally-built `$target` — the same,
already-normalized `$params` array this layer passes into
`invoke()`. No second target is ever constructed anywhere in
`ExecuteRequestHandler` (verified by tests #39/#44, §7 below).

## 4. Internal Adapter Parameter Contracts (for contrast)

Re-confirmed directly from `CommandRegistry` (unmodified) — fixed
parameters below are **not** in the public contract table above and a
caller can never set them:

- `domain.create`: fixed `ip=""`, `restart="yes"`, `aliases=""`,
  `proxy_ext=""`.
- `domain.delete`: fixed `restart="yes"`.
- `database.create`: fixed `type="mysql"`, `host=""`,
  `charset="UTF8MB4"`. `password` is declared `sensitive: true`,
  `delivery: "temp_file"` — unchanged, untouched by this sprint.
- `database.delete`: has **no** `dbuser` parameter at all (recovered
  internally by the underlying script from `db.conf`) — the public
  contract correctly does not invent one.

## 5. Normalization Rules

`web/inc/api/ParameterNormalizer.php` (updated this sprint, only for
`database.delete`):

- Only transforms when both `user` and `database` are non-empty
  strings; otherwise passes the params array through untouched, letting
  `CommandAdapter`'s own validator reject a missing/non-string/empty
  value with its established error vocabulary. This deliberately does
  **not** duplicate `ParameterValidator`'s rules — it performs only the
  minimal structural check ("are these the two strings I need to build
  the transformed value") required to normalize at all.
- **Rejects** (`VALIDATION_FAILED`/422) if `database` already starts
  with exactly `{the request's own user}_` — an exact self-prefix
  match only.
- **Does not** attempt to detect a database identifier that merely
  *resembles* `{some other username}_{suffix}`. `user=admin,
  database=other_wordpress_db` is normalized to
  `admin_other_wordpress_db`, not rejected — see §6 for the full
  reasoning behind this deliberate boundary.
- Otherwise transforms `database` to `{user}_{database}`.
- All six other exposed operations map their public parameters 1:1 to
  the adapter contract — re-verified directly against `CommandRegistry`
  this sprint; no new normalization rule was needed or added for any of
  them.

## 6. `database.create` vs `database.delete` Asymmetry

Both operations' **public** contract is identical in shape — `database`
is always the raw, unprefixed suffix (e.g. `"wordpress_db"`), consistent
for both `database.create` and `database.delete`. The asymmetry is
**exclusively internal**, and is resolved exclusively in
`ParameterNormalizer`, never in `CommandAdapter`/`CommandRegistry`:

```
database.create:  public "wordpress_db"        -> adapter "wordpress_db"          (unchanged)
database.delete:  public "wordpress_db"        -> adapter "admin_wordpress_db"    (prefixed by the API layer)
```

**Why `database.delete`'s rejection check is self-prefix-only, not
"foreign-prefix" detection** (resolved explicitly before this sprint's
implementation began): a check general enough to flag
`other_wordpress_db` as "looks already prefixed" would necessarily also
flag `wordpress_db` itself (`wordpress` alone is a syntactically valid
username shape) — breaking the most basic accepted case. Detecting a
prefix belonging to a *real, different* Hestia username would require
querying the live user list, which is a business/existence check this
architecture deliberately keeps inside the underlying `v-*` script
(`is_object_valid()`), never the API/normalization layer — adding it
here would duplicate business logic this sprint is explicitly forbidden
from adding to `ParameterNormalizer`. The actual security property this
might have been mistaken for — "an embedded foreign username can never
redirect the operation to that user's namespace" — is instead guaranteed
structurally, unconditionally, by construction: `target.user` (what
`SameUserAuthorizer` checks) is **always** `params.user` verbatim, never
parsed out of `database` in any way. Tests #43/#44 (§9) prove this
directly.

## 7. Authorization Ordering

Unchanged from Sprint 2:

```
authenticate -> allowlist -> validate (envelope) -> validate (parameter names, NEW)
  -> normalize -> CommandAdapter::invoke()
    -> resolve -> validate (adapter/value-level) -> normalize (none) -> authorize -> lock -> execute
```

The new parameter-name-contract check (§3) sits between envelope
validation and normalization — a pure name/presence check, never a
value check, and never itself constructs anything resembling an
authorization target.

`ExecuteRequestHandler` passes the *same* `$params` array — the one
`ParameterNormalizer::normalize()` returned — into
`CommandAdapter::invoke()`. `CommandAdapter` builds its own internal
`$target` from that exact array during its own (unmodified) validation
step, and passes that same `$target` to `SameUserAuthorizer`. There is
no second, independently-constructed authorization target anywhere in
this sprint's new code — verified directly by test #39
(`testDatabaseDeleteSendsNormalizedTargetToAuthorizer`, using
`SpyAuthorizer` to capture exactly what `CommandAdapter` sent) and test
#44 (the case-(c) security regression, §9).

## 8. Response Mapping

`ResponseMapper` (Sprint 2, unmodified) is reused unchanged — no
operation-specific response envelope was created. Verified for all
seven operations via the test suite (§10):

- `domain.create`/`domain.delete` exit code `20` (`E_RESTART`, declared
  `known_post_mutation_exit_codes`) → `succeeded_with_warning`/200 —
  unchanged adapter classification, re-verified end to end through the
  new HTTP paths (tests #33/#35).
- `database.create`/`database.delete` follow their existing,
  unmodified registry-derived classification (`confirmed` on exit 0).
  **`database.delete`'s `confirmed` is only the adapter's current
  classification** — `CommandRegistry`'s own docblock (unmodified,
  re-cited here, not reinterpreted) documents that the underlying
  script's `DROP DATABASE` statement is itself unchecked, so exit 0
  only proves "the script believed it succeeded," not that the drop
  definitively took effect. Sprint 3 does not alter this semantic.
- `backup.schedule` exit code `4` (`E_EXISTS`) has **no**
  `known_post_mutation_exit_codes` declared, so `CommandAdapter`
  classifies it `mutationState: unknown` for every non-zero exit —
  `ResponseMapper` maps this to `outcome: "unknown"`/`207`/
  `UNKNOWN_OUTCOME`, **not** `"failed"` (test #41, verified explicitly).
  This is deliberately not "improved": `E_EXISTS` fires *before* the
  one mutating line in `v-schedule-user-backup` (confirmed by the
  registry's own source citation), so a more precise classification
  is possible in principle, but declaring it now would be scope
  creep — `known_post_mutation_exit_codes` declarations remain
  `CommandRegistry`'s decision, unmodified this sprint.
- `backup.schedule`'s `succeeded` **only ever means the backup job was
  successfully queued** — it does not mean an archive was created. This
  is the existing, unmodified adapter semantic (Sprint 1/2); restated
  here, unchanged, per this sprint's explicit documentation requirement.

## 9. Security Properties

All fourteen items from the sprint's own security-test requirement are
covered by the suite (§10 lists the exact tests):

1. All seven operations reach `CommandAdapter` on a valid, authorized
   request (tests #31–#40).
2. Unknown operations rejected (test #12, pre-existing).
3. Raw script names rejected (test #24, pre-existing).
4. Caller-supplied `actor` rejected (tests #14/#15, pre-existing).
5. Authentication alone determines `actor.user` (test #17, pre-existing).
6. `SameUserAuthorizer` denies cross-user access for **all seven**
   operations — table-driven (test #42).
7. Denial occurs before adapter execution — proven for all seven (test
   #42) and specifically before lock acquisition (tests #20/#42).
8. `database.delete` normalization cannot be used to target another
   user's database: `target.user` is always `params.user`, verbatim
   (tests #39/#44) — the database string's content has zero effect on
   it, even when it contains an embedded, foreign-looking prefix (test
   #44).
9. `database.delete` sends the same normalized identifier to both the
   authorizer and the adapter — one representation, never two (test
   #39).
10. `database.create`'s password: delivered via `CommandAdapter`'s
    existing, unmodified sensitive/temp-file mechanism (test #36);
    absent from the authorizer's `$target` (test #36); absent from a
    success response (test #36); absent from a denial response, and no
    temp file is created on denial (test #45, new).
11. No shell execution introduced (`GenericityTest`, re-run against
    `OperationParameterContract.php` too).
12. No `bin/v-*` script referenced from the HTTP layer
    (`GenericityTest`).
13. No credentials/secrets appear in responses or errors (tests #30,
    #36, #45).
14. No operation-specific bypass of `CommandAdapter` exists — every one
    of the seven operations goes through the identical
    `ExecuteRequestHandler::handle()` pipeline; there is no
    per-operation branch anywhere in this sprint's new source
    (`OperationParameterContract`/`ParameterNormalizer` both use a
    lookup table / `switch`, never an `if ($operation === ...)`
    execution branch).

## 10. Test Coverage

New/changed test files:

- `test/api/OperationParameterContractTest.php` — **new**, 10 tests:
  the declared contract for all seven operations, undeclared-operation
  behavior, and the `OperationAllowlist`↔`OperationParameterContract`
  no-drift consistency check.
- `test/api/ParameterNormalizerTest.php` — extended (already largely
  complete on disk before this session's edits) with one new test:
  the case-(c) "foreign-looking prefix is NOT rejected" regression.
- `test/api/ExecuteRequestHandlerTest.php` — extended with tests #43–#47
  (foreign-prefix-not-rejected integration test, the
  actor/target-cannot-be-influenced security test, the
  denial-creates-no-temp-file-and-no-password-leak test, and the two
  new parameter-contract-enforcement tests) on top of the pre-existing
  #31–#42 operation-exposure and table-driven cross-user-denial tests.
- `test/api/GenericityTest.php` — extended to also source-scan
  `OperationParameterContract.php`.
- `test/api/run_tests.php` — registers the new test class.

## 11. Regression Results

Three consecutive runs of each suite:

- `test/api/run_tests.php`: **92/92**, ×3.
- `test/auth/run_tests.php`: **62/62**, ×3 — unchanged (no auth file
  touched).
- `test/adapter/run_tests.php`: **198/198**, ×3 — unchanged (no adapter
  file touched).

`php -l` clean on every new/modified PHP file. `git diff --check`
clean.

## 12. Deferred Work

Unchanged from Sprint 1/2's own deferrals, plus (explicitly, per this
sprint's boundary): `database.list`/`database.get`, `backup.list`, rate
limiting, audit logging, idempotency, async jobs, Cloud Account/tenant/
roles, expiration/rotation, generic resource resolution beyond the one
`database.delete` rule.

## 13. Architectural Findings

- `OperationAllowlist` and `OperationParameterContract` are two
  independent, hand-maintained tables that must agree on their key set.
  A dedicated consistency test (`testNoDriftBetweenAllowlistAndContract`)
  now enforces this mechanically rather than by convention alone.
- `backup.schedule`'s `E_EXISTS` failure is classified `unknown` (not a
  more precise "failed, nothing mutated") purely because
  `CommandRegistry` declares no `known_post_mutation_exit_codes` for it
  — even though the underlying script's own source shows `E_EXISTS`
  firing pre-mutation. Improving this classification is a `CommandRegistry`
  data change (adding a symmetric "known **pre**-mutation exit codes"
  concept, which does not exist today), explicitly out of this sprint's
  scope — noted here as a real, source-verified precision gap for a
  future sprint, not silently fixed.

## 14. Known Limitations

Unchanged from Sprint 2 (§13 of the entry-point implementation doc):
literal `/api/v2/execute` path routing and `Authorization` header
stripping under some server configurations remain unverified against a
real deployment. `database.delete`'s self-prefix-only check accepts any
raw suffix that happens to look like `{some other user}_{something}` —
a deliberate, documented boundary (§6), not an oversight.

## 15. Final Verdict

**READY.** All seven operations exposed, tested, and documented; all
three regression suites green across three consecutive runs each; no
architecture file modified; no STOP condition encountered.
