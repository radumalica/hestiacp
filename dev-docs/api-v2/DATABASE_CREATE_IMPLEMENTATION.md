# Database Create Implementation

Implementation report for `database.create` — the first registry-driven
adapter operation to declare a real `sensitive => true, delivery =>
"temp_file"` password parameter, mapping to `bin/v-add-database`. Built on
top of the generic sensitive-parameter/temp-file mechanism reviewed in
`SENSITIVE_PARAMETER_REVIEW.md` and remediated in
`SENSITIVE_PARAMETER_REMEDIATION.md` (verdict: READY FOR DATABASE.CREATE).

`bin/v-add-database`, `func/db.sh`, `func/main.sh`, `LockManager.php`, and
sudoers were **not** modified. No new authorization model, no new result
model, no new secret-handling mechanism, and no HTTP API endpoint were
introduced.

## 1. Source-Derived Command Contract

Discovered by reading `bin/v-add-database` (114 lines) in full, plus every
`func/db.sh` and `func/main.sh` helper it calls — not inferred from the
operation name or from `domain.create`'s precedent.

**Argument definition** (`bin/v-add-database` lines 15-22):

```
user=$1
database=$2          # raw suffix as supplied
dbuser=$3            # raw suffix as supplied
password=$4
type=${5-mysql}
host=$6
charset=${7-UTF8MB4}
```

Internally: `database="$user"_"$2"` and `dbuser="$user"_"$3"` — Hestia
prefixes both the database and dbuser names with the owning username
before storing/using them. The adapter's caller-facing `database`/`dbuser`
parameters are the **raw, unprefixed suffix** (e.g. `"wordpress_db"`),
matching what a human CLI caller supplies as positional args 2/3 — not the
already-prefixed form Hestia builds internally.

**Required vs. optional**: `check_args '4' "$#" 'USER DATABASE DBUSER
DBPASS [TYPE] [HOST] [CHARSET]'` (line ~30) — only the first four
positional arguments are required; `type`/`host`/`charset` fall back to
their shell-parameter defaults shown above when omitted.

**Verifications section** (before any mutation): `is_system_enabled`,
`check_args`, `is_type_valid "$DB_SYSTEM" "$type"`, `is_format_valid
'user' 'database' 'dbuser' 'charset'` (**`dbpass` is absent from this
list** — see "Password Delivery Semantics"), `is_object_valid('user',
'USER', ...)`, `is_object_unsuspended`, `is_package_full('DB')`,
`is_object_new('db', 'DB', "$database")`, `is_object_new('db', 'DBUSER',
"$dbuser")`, `is_password_valid "$password" '1'`.

**Action section**: dispatches to `add_mysql_database()` or
`add_pgsql_database()` (`func/db.sh`) based on `$type`, appends to
`db.conf`, calls `increase_dbhost_values`/`increase_user_value`, logs via
`v-log-action`, exits.

**`add_mysql_database()`** (`func/db.sh` 304-371) — the only mutating path
this registry entry exercises (`type` is fixed to `"mysql"`, see
"Parameter Model"):

1. `mysql_connect` — can fail `E_PARSING` (12, malformed host config) or
   `E_CONNECT` (15, unreachable server). **Pre-mutation.**
2. `CREATE DATABASE ...` — the **only checked mutating statement**
   (`check_result $? "Unable to create database $database"`, line 314).
3. `CREATE USER` (×2), `GRANT` (×2), an md5-retrieval `SHOW` query — every
   one of these five statements redirects to `/dev/null` with **no
   `check_result` call** — none of them can fail the script.

Nothing after `add_mysql_database()` returns (`db.conf` append,
`increase_dbhost_values`, `increase_user_value`, `v-log-action`, the final
bare `exit`) can fail either — confirmed by reading the remainder of
`bin/v-add-database` line by line. See "Exit-Code Evidence" for the
consequence.

**`is_type_valid` operational hazard (advisor-flagged, documented here
rather than worked around):** `is_type_valid "$DB_SYSTEM" "$type"`
(`bin/v-add-database` ~line 52, `func/main.sh` 331-335) checks the
requested `$type` against the *host's configured* `DB_SYSTEM` value, not
against a fixed allowlist. Fixing `type => "mysql"` in this registry entry
is therefore a **hard deployment assumption, not just a narrowed slice of
functionality**: on a Hestia host provisioned pgsql-only (`DB_SYSTEM` does
not include `"mysql"`), every single `database.create` call will fail with
`E_INVALID` — a total non-function on that class of host, not a degraded
path. This is materially different from `domain.create`'s fixed
`restart => "yes"`, which is valid on every host regardless of
configuration. Any deployment of this operation must first confirm the
target host's `DB_SYSTEM` includes `mysql`.

`add_pgsql_database()` (`func/db.sh` 374-397) checks **no** query result at
all — an even weaker guarantee than the mysql path — which is one more
reason pgsql support is deliberately excluded from this slice rather than
mirrored in.

## 2. Registry Entry

```php
"database.create" => [
    "script" => "v-add-database",
    "argument_order" => ["user", "database", "dbuser", "password", "type", "host", "charset"],
    "parameters" => [
        "user"     => ["type" => "username",      "required" => true],
        "database" => ["type" => "database_name",  "required" => true],
        "dbuser"   => ["type" => "db_username",     "required" => true],
        "password" => ["type" => "secret", "required" => true, "sensitive" => true, "delivery" => "temp_file"],
    ],
    "fixed_parameters" => [
        "type" => "mysql", "host" => "", "charset" => "UTF8MB4",
    ],
    "mutation" => ["kind" => "create"],
],
```

No `known_post_mutation_exit_codes` key (see "Exit-Code Evidence"). No
`output_format`/`result_shape` key — `bin/v-add-database` has no JSON
output mode, confirmed by full source read (no `format` argument, no
`case $format in json)` branch anywhere in the script).

## 3. Argument Order

`["user", "database", "dbuser", "password", "type", "host", "charset"]` —
a direct, unreordered mirror of `bin/v-add-database`'s own positional
contract (`$1` through `$7`). `argv[0]` is the resolved script path, so
the generated process argv is exactly 8 elements: `[scriptPath, user,
database, dbuser, <temp-file-path>, "mysql", "", "UTF8MB4"]`.

## 4. Parameter Metadata

**Public (caller-supplied), all four required:**

| Parameter | Type | Validator | Approximates |
|---|---|---|---|
| `user` | `username` | `ParameterValidator::isValidUsername()` (unchanged) | `is_user_format_valid()` |
| `database` | `database_name` (new) | `ParameterValidator::isValidDatabaseName()` (new) | `is_database_format_valid()`, func/main.sh 1206-1212 |
| `dbuser` | `db_username` (new) | `ParameterValidator::isValidDatabaseUsername()` (new) | `is_dbuser_format_valid()`, func/main.sh 1222-1231 |
| `password` | `secret` (new) | `ParameterValidator::isValidSecret()` (new) | no format check exists in source — see below |

`isValidDatabaseName()`/`isValidDatabaseUsername()` validate the RAW,
caller-supplied suffix, which is intentionally a **looser** bound than
`bin/v-add-database`'s own length checks (`is_database_format_valid`'s
64-char limit, `is_dbuser_format_valid`'s 33-char limit) — those run
against the user-prefixed value the script builds internally
(`"$user"_"$2"`), a longer string than this validator ever sees. The
script itself remains the sole authoritative check for the true, prefixed
length; this validator only ever rejects a strict subset of what the
script would also reject, never something the script would accept.

**Internal/fixed (never caller-controlled):**

| Registry-fixed value | Value | Why |
|---|---|---|
| `type` | `"mysql"` | The script's own default (`${5-mysql}`); pgsql deliberately excluded from this slice — see "Command Contract" for the `is_type_valid` deployment hazard this fixes into place. |
| `host` | `""` | Triggers `get_next_dbhost()`'s own first/weight auto-selection (`func/db.sh` 218-247) — the same host Hestia would pick for any other database this user creates. |
| `charset` | `"UTF8MB4"` | The script's own default (`${7-UTF8MB4}`). |

**Why this parameter set and not a 1:1 mirror of all seven CLI slots**:
matches `domain.create`'s own established precedent (minimal public model,
not "expose everything the script accepts"). The one real production
caller, `web/add/db/index.php`, lets a human choose `type`/`host`/
`charset` via form fields — a deliberately more permissive UI than this
registry entry offers. Fixing all three to the script's own built-in
defaults, rather than mirroring the UI's flexibility, is a documented
narrowing, not an oversight; exposing any of them later is additive
(moves from `fixed_parameters` to `parameters`) and does not touch
`CommandAdapter.php`.

## 5. Password Delivery Semantics

`bin/v-add-database` applies **zero format validation** to `DBPASS`:
`is_format_valid` is called there as `is_format_valid 'user' 'database'
'dbuser' 'charset'` (line ~50) — `'dbpass'` is conspicuously absent. The
only function that touches the password afterward,
`is_password_valid($password, '1')` (`func/main.sh` 625-644), is a
temp-file-*dereference*, not a character/length check: when its second
argument is `'1'`, it treats `$password` as a path, requires that path to
begin with the literal prefix `/tmp/`, reads the file's first line as the
real password, and overwrites the variable in place.

This is exactly the generic `sensitive => true, delivery => "temp_file"`
mechanism already built and hostile-reviewed
(`SENSITIVE_PARAMETER_DESIGN.md`, `SENSITIVE_PARAMETER_REVIEW.md`,
`SENSITIVE_PARAMETER_REMEDIATION.md`): `CommandAdapter` writes the
plaintext password to a `tempnam("/tmp", self::TEMP_FILE_PREFIX)` file at
mode `0600`, puts the **temp-file path** (never the plaintext) into the
password's argv slot, and removes the file in a `finally` block regardless
of success, `hestia_error`, or lock timeout. `database.create` required
**zero new code in `CommandAdapter.php`** for this — only the registry
metadata (`sensitive`/`delivery` keys) and `ParameterValidator::
isValidSecret()`, the loosest possible shape check (`is_string($value) &&
$value !== ""`), matching Hestia's own near-total absence of password
format validation. The one restriction it does apply (rejecting an empty
string) is a convention match with this file's other validators, not a
source-derived Hestia rule — documented as such in the method's own
docblock.

**Newly-live exposure window (advisor-flagged):** temp-file creation
happens during argv construction, which runs *before* lock acquisition
(`CommandAdapter.php`'s existing, unmodified locking pass). Under lock
contention, the plaintext password now sits on disk at `0600` for the
full lock-wait window, not merely for the duration of the underlying
process's execution. `SENSITIVE_PARAMETER_REVIEW.md` flagged this ordering
as INFORMATIONAL when no operation carried a real secret yet;
`database.create` is the first operation where this window is populated
with a real, caller-supplied secret. Cleanup still holds on every path
(success, `hestia_error`, `LOCK_TIMEOUT`, `LOCK_UNAVAILABLE`) — this is a
documented exposure-window widening, not a cleanup gap. See "Security
Considerations" for the full accounting, alongside the pre-existing F-3
crash caveat.

## 6. Authorization Target

Built entirely from the three non-sensitive, normalized registry
parameters: `{"user": ..., "database": ..., "dbuser": ...}`. `password`
is excluded from `AdapterResult::target`, from the value passed to
`AuthorizerInterface::authorize()`, and from process argv (in plaintext
form) by the same generic sensitive-parameter scrubbing already proven for
every other sensitive field — no `database.create`-specific authorization
code exists anywhere. Authorization denial is checked before temp-file
creation and before lock acquisition (tests 16-17), matching the existing,
unmodified ordering.

## 7. Lock Target

`target["user"]` — the same per-user `LockManager` model every other
mutating operation uses. No architectural mismatch was found; no
`LockManager` change was needed or made.

## 8. Mutation-State Semantics

The existing four-value model (`not_attempted` / `confirmed` /
`confirmed_degraded` / `unknown`), unmodified:

| Condition | `mutation_state` |
|---|---|
| Validation/authorization/lock failure (before execution) | `not_attempted` |
| Exit `0` | `confirmed` |
| Any non-zero exit (including `E_EXISTS`) | `unknown` — no `known_post_mutation_exit_codes` are declared, so nothing upgrades to `confirmed_degraded` |

`database.create` declares an **empty** `known_post_mutation_exit_codes`
array — a deliberate, source-verified omission, not an oversight (see
"Exit-Code Evidence").

## 9. Exit-Code Evidence

**No post-mutation exit code exists for the mysql path.** Source-verified:
`CREATE DATABASE` (`func/db.sh` 312-314) is both the only checked mutating
statement AND the first one `add_mysql_database()` performs — everything
before it (`mysql_connect`) is pre-mutation, and everything after it
(unchecked `CREATE USER`×2, `GRANT`×2, unchecked `SHOW`, `db.conf` append,
counter increments, logging, final `exit`) cannot fail the script. This is
the same posture as `backup.schedule` (which also declares no
`known_post_mutation_exit_codes`), unlike `domain.create`/`domain.delete`
(which declare `E_RESTART` — a real post-mutation failure point in a
different script).

**`check_result` mislabeling hazard (advisor-flagged, live and
undocumented until this report):** `check_result $? "Unable to create
database $database"` (`func/db.sh` 314) passes **no explicit `$3`**, so
`err_code=$1` — the **raw mysql/mariadb client exit code**, passed
through verbatim by `mysql_query()` (`func/db.sh` 110-122). Every other
mutating operation's `check_result` calls examined so far
(`domain.create`, `domain.delete`) pass an explicit symbolic `$E_*` code.
Here, if the mysql client returns exit code `1`,
`CommandAdapter::HESTIA_EXIT_CODES[1]` labels the result `"E_ARGS"` — a
consumer of `hestiaErrorCode` would see "bad arguments" for what was
actually a failed `CREATE DATABASE`. This is a real mislabeling risk
inherited entirely from `bin/v-add-database`'s own source, not introduced
by the adapter, and not one this task adds a workaround for (doing so
would require the adapter to special-case exit-code semantics for this
one script — exactly the kind of operation-specific logic the task
prohibits). Documented here so a future API v2 consumer of
`hestiaErrorCode` knows not to trust the E_* label blindly for this
operation's failure path.

`E_DB` (17) only fires inside `mysql_dump()`/`psql_dump()` — a different,
backup/dump code path, confirmed irrelevant to `v-add-database`'s own
execution.

## 10. Idempotency / Duplicate Behavior

Investigated directly from source, not assumed. `is_object_new('db',
'DB', "$database")` and `is_object_new('db', 'DBUSER', "$dbuser")` both
run in the Verifications section (before Action), both producing
`E_EXISTS` (exit 4) for a duplicate database or dbuser name — strictly
**before** `mysql_connect`, before `CREATE DATABASE`, before any mutation.
**Not idempotent**: duplicate creation is explicitly rejected pre-mutation
and never silently turned into success. The adapter surfaces this
faithfully as `status = hestia_error`, `hestiaErrorCode = "E_EXISTS"`,
`mutationState = "unknown"` — not a special "already exists" success, and
not a more specific "definitely no mutation happened" state either (the
adapter's generic classification has no such state; it only ever upgrades
via `known_post_mutation_exit_codes`, which this entry does not declare).
This is the same posture `domain.create` already established for its own
`E_EXISTS` case.

## 11. Security Considerations

- **No new injection surface.** argv is still built via the same
  array-form `proc_open()` path already proven immune to shell
  metacharacter interpretation. The three fixed values (`type`, `host`,
  `charset`) are compile-time string literals in `CommandRegistry.php`,
  never derived from caller input.
- **Password never in plaintext argv, target, authorizer input, or
  registry metadata as a value** — proven by tests 7-10.
- **Widened exposure window under lock contention** (see "Password
  Delivery Semantics" above) — the plaintext password now sits on disk at
  `0600` for the full lock-wait duration when locking contends, not just
  during process execution. Cleanup still holds on every path. Accepted,
  documented risk, consistent with `SENSITIVE_PARAMETER_REVIEW.md`'s F-1
  baseline framing: this does not make exposure worse than omitting
  `sensitive` entirely, it makes a previously-hypothetical window real.
- **PHP-crash residual risk applies unchanged** — per
  `SENSITIVE_PARAMETER_DESIGN.md`'s "Scope of this guarantee" section
  (added for F-3): normal control-flow cleanup (including
  `database.create`'s own success/failure/lock-timeout paths) is
  guaranteed; a hard PHP process termination bypasses the `finally` block
  and leaves cleanup dependent on host `/tmp` lifecycle. No new
  crash-recovery mechanism was added for this operation.
- **`is_type_valid` deployment hazard** (see "Command Contract") is an
  operational-correctness issue, not a security one, but is noted here
  for completeness: a misconfigured host makes every call fail, it does
  not make any call unsafe.
- **No privilege escalation, no unsafe environment inheritance, no lock
  path manipulation, no parameter confusion** — all proven the same way
  `domain.create`'s equivalent claims were proven (see
  `DOMAIN_CREATE_IMPLEMENTATION.md` "Security" for the identical
  reasoning, which applies here unchanged since none of that machinery
  was touched).
- **No generic exec/runRaw mechanism was introduced.** `CommandAdapter`'s
  public surface is unchanged: `invoke(operation, params, actor)` remains
  the only entry point.

## 12. Test Coverage

`test/adapter/DatabaseCreateTest.php` — 23 tests, all using
`FakeProcessRunner` or a purpose-built anonymous-class probe runner (no
real subprocess, no real Hestia installation, no root, no real database
server).

| # | Test | Proves |
|---|---|---|
| 1 | `testRegistered` | registry resolves `database.create` → `v-add-database`, `mutation.kind = "create"` |
| 3 | `testRequiredParametersEnforced` | all four required params enforced |
| 4 | `testUnknownParameterRejected` | `type` (fixed) supplied as a caller param is rejected |
| 5a-c | `testInvalidDatabaseNameRejected` / `testInvalidDbUserRejected` / `testEmptyPasswordRejected` | type validation for all three new types |
| 2+B | `testGeneratedArgv` | argv is exactly `[script, user, database, dbuser, <tmp-path>, "mysql", "", "UTF8MB4"]`, 8 elements |
| 6 | `testPasswordMetadataDeclared` | registry declares `sensitive => true, delivery => "temp_file"` |
| 7 | `testPasswordAbsentFromTarget` | password absent from `AdapterResult::target` and its JSON encoding |
| 8 | `testPasswordAbsentFromAuthorizerTarget` | password absent from the authorizer's target input |
| 9 | `testPasswordAbsentFromArgv` | plaintext password never appears anywhere in argv |
| 10 | `testArgvContainsLiteralTmpPath` | password's argv slot begins with literal `/tmp/`, uses the adapter's generic `hstadapter` prefix |
| 11+12 | `testTempFileExistsWithSecurePermissionsDuringExecution` | temp file exists with exact content + `0600` perms during execution |
| 13 | `testTempFileRemovedAfterSuccess` | temp file removed after success |
| 14 | `testTempFileRemovedAfterCommandFailure` | temp file removed after a non-zero exit |
| 15 | `testTempFileRemovedWhenLockAcquisitionFails` | a lock-acquisition-time probe (advisor-hardened, see below) proves the temp file **already exists at the moment `acquire()` is called**, then confirms it is gone afterward — proving creation-before-lock and cleanup-after, not merely equal before/after counts |
| 16 | `testAuthorizationDenialBeforeTempFileCreation` | authorization denial happens before temp-file creation |
| 17 | `testAuthorizationDenialBeforeLockAcquisition` | authorization denial happens before lock acquisition |
| 18 | `testLockAcquiredForCorrectUser` | lock acquired for `target["user"]`, released after success |
| 19 | `testExitZeroIsConfirmed` | exit `0` → `mutationState = "confirmed"` |
| 20 | `testNoKnownPostMutationExitCodes` | registry declares an empty `known_post_mutation_exit_codes` array — the faithful adaptation of "one test per source-verified post-mutation exit code" when source proves there are zero such codes, same posture as `backup.schedule` |
| 21 | `testPreMutationFailureIsUnknown` | `E_EXISTS` (duplicate) → `mutationState = "unknown"`, consistent with `domain.create`'s own `E_EXISTS` precedent |
| 22 | `testResultFieldsPopulatedCorrectly` | operation/target/status/actor fields, `resultShape`/`parsedOutput` both `null` |
| 23 | `testCommandAdapterContainsNoDatabaseSpecificLogic` | zero case-insensitive occurrences of `"database.create"`, `"v-add-database"`, `"dbuser"` anywhere in `CommandAdapter.php`'s raw source |

**Test 15's strengthening (advisor-caught):** the original version only
asserted `before === after` on a `glob()` count, which is satisfied
equally by "temp file never created" and "temp file created then
removed" — it could not distinguish the two, so it did not actually prove
what its name claimed. Fixed by adding a local anonymous
`LockManagerInterface` probe whose `acquire()` method captures the glob
count at the moment it is invoked (i.e., after argv/temp-file
construction, before the lock outcome is known); the test now asserts
`before + 1 === duringAcquire` in addition to the original
`before === after`, which only "created before locking, cleaned up
after" can satisfy. `SpyLockManager.php` (shared by other test files) was
**not** modified — this probe is local to `DatabaseCreateTest.php`.

**Full-suite result**: `php test/adapter/run_tests.php` — **174 passed, 0
failed**, run 3 consecutive times after this strengthening, all three
identical.

## 13. Files Changed

- `web/inc/adapter/ParameterValidator.php` — added `isValidDatabaseName()`,
  `isValidDatabaseUsername()`, `isValidSecret()`.
- `web/inc/adapter/CommandAdapter.php` — constructor's `$typeValidators`
  data table extended with three entries (`database_name`, `db_username`,
  `secret`) pointing at the new validators. No branching logic added; no
  `"database.create"`/`"v-add-database"`/`"dbuser"` string appears
  anywhere in the file (test 23 enforces this).
- `web/inc/adapter/CommandRegistry.php` — added the `database.create`
  entry.
- `test/adapter/DatabaseCreateTest.php` — new, 23 tests.
- `test/adapter/run_tests.php` — wired in `DatabaseCreateTest`.
- `DATABASE_CREATE_IMPLEMENTATION.md` — this document.

No `v-*` script, sudoers file, `LockManager.php`, or unrelated adapter
operation was changed. No HTTP API endpoint was added.

## 14. CommandAdapter Remains Generic

`CommandAdapter.php`'s only change in this task is a **data-table
addition** — three more `[type => [class, method]]` entries in the
constructor's `$typeValidators` array, the exact same mechanism by which
`domain`, `username`, etc. were registered for earlier operations. This is
not an `if ($operation === "database.create")` or `if ($scriptPath ===
"v-add-database")` branch; it is the same generic dispatch table every
other type already goes through. `database.create`'s entire behavior —
argument order, required/fixed parameters, sensitive/delivery handling,
lock target, mutation classification — is expressed as registry data
consumed by pre-existing, already-generic `CommandAdapter` code. Test 23
enforces this mechanically: it reads `CommandAdapter.php`'s raw source and
asserts zero occurrences of `"database.create"`, `"v-add-database"`, or
`"dbuser"` (case-insensitive substring match). The one method-naming
collision this check caught during development
(`isValidDbUser` → renamed to `isValidDatabaseUsername`, see "Deviations
From The Design" in the final report) is itself evidence the check is
doing real work, not a rubber stamp.

## Known Limitations / Natural Follow-Ups

- **pgsql not supported** — `type` is fixed to `"mysql"`; `add_pgsql_database()`
  checks no query result at all, an even weaker guarantee, deliberately
  excluded from this slice.
- **`host`/`charset` not caller-configurable** — matches `domain.create`'s
  own "minimal parameter model" precedent, diverging from the more
  permissive real UI caller (`web/add/db/index.php`).
- **No idempotency added** — a duplicate `database`/`dbuser` name fails
  with the underlying script's own `E_EXISTS` (`mutationState = "unknown"`),
  not a special "already exists" success.
- **`check_result` mislabeling risk on the one checked mutating
  statement** — see "Exit-Code Evidence" — inherited from
  `bin/v-add-database`'s own source, not fixed by this task.
- **No API v2, no migration** — `web/add/db/index.php` remains unmodified
  and continues to call `v-add-database` directly via `exec()`, entirely
  outside this adapter's locking boundary, matching `domain.create`'s
  already-documented "legacy bypass, unresolved by design" limitation.
