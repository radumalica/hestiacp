# Database Delete Implementation

Implementation report for `database.delete` — an **architecture generality
test**: does the existing registry-driven `CommandAdapter` pipeline
(resolve → validate → normalize → authorize → lock → execute → classify
mutation state → result) represent another real mutating database
operation without any new operation-specific code? Maps to
`bin/v-delete-database`.

`bin/v-delete-database`, `func/db.sh`, `func/main.sh`, `LockManager.php`,
and sudoers were **not** modified. No new authorization model, no new
result model, no HTTP API endpoint, and — for the first time in this
series — **no change to `CommandAdapter.php` or `ParameterValidator.php`
at all**. The only production edit in this task is the `database.delete`
entry added to `CommandRegistry.php`.

## 1. Source Contract

Discovered by reading `bin/v-delete-database` (78 lines) in full, plus
every `func/db.sh`/`func/main.sh` helper it calls — not inferred from
`database.create`'s own contract, which turns out to differ from this one
in a load-bearing way (see "Validation" below).

**Argument definition** (`bin/v-delete-database` lines 15-16):

```
user=$1
database=$2
```

Only **two** positional slots — both required (`check_args '2' "$#"
'USER DATABASE'`, line 32). There is **no `dbuser` argument at all**: the
script recovers `DBUSER` (and `TYPE`/`HOST`) internally via
`get_database_values()` → `parse_object_kv_list($(grep "DB='$database'"
$USER_DATA/db.conf))` (func/db.sh:442-444) — it looks the row up by the
database name it was given and pulls every other field off that row. The
adapter never supplies, validates, or even sees a dbuser for this
operation.

**Verifications section** (`bin/v-delete-database` lines 32-39, all
pre-mutation): `check_args`, `is_format_valid 'user' 'database'`,
`is_system_enabled "$DB_SYSTEM" 'DB_SYSTEM'`, `is_object_valid('user',
'USER', "$user")`, `is_object_valid('db', 'DB', "$database")`,
`check_hestia_demo_mode`.

**Action section**: `get_database_values` (loads `TYPE`, `HOST`, `DBUSER`,
`SUSPENDED` from the matched db.conf row) → `case $TYPE in mysql)
delete_mysql_database ;; pgsql) delete_pgsql_database ;; esac` → remove
the db.conf line (`sed -i "/DB='$database' /d" ...`) → decrement counters
(`decrease_dbhost_values`, `decrease_user_value`) → log
(`v-log-action`/`log_event`) → bare `exit`.

**`delete_mysql_database()`** (func/db.sh 529-548):

```
mysql_connect $HOST
query="DROP DATABASE `$database`"; mysql_query "$query"          # UNCHECKED
query="REVOKE ALL ON `$database`.* FROM `$DBUSER`@`%`" > /dev/null
query="REVOKE ALL ON `$database`.* FROM `$DBUSER`@localhost" > /dev/null
if [ dbuser has fewer than 2 db.conf rows ]; then
    DROP USER '$DBUSER'@'%'    > /dev/null
    DROP USER '$DBUSER'@'localhost' > /dev/null
fi
```

**Zero `check_result` calls anywhere in this function** — not one, and
notably not on `DROP DATABASE` itself, which is the strongest possible
contrast with `database.create`'s `add_mysql_database()` (whose one
checked statement, `CREATE DATABASE`, is exactly the mutation this
function's unchecked counterpart performs). `delete_pgsql_database()`
(func/db.sh 551-566) is the same shape: `REVOKE`, `DROP DATABASE`, and a
conditional `DROP ROLE`, all with output redirected to `/dev/null`, none
checked.

**Exit codes reachable, and where each one fires relative to the
mutating statement (`DROP DATABASE`, func/db.sh:532)**:

| Code | Source | Fires |
|---|---|---|
| `E_ARGS` (1) | `check_args` too few args | Verifications |
| `E_INVALID` (2) | `is_format_valid` → `is_database_format_valid`/`is_user_format_valid` (func/main.sh 1206-1212) | Verifications |
| `E_DISABLED` (11) | `is_system_enabled "$DB_SYSTEM"` | Verifications |
| `E_NOTEXIST` (3) | `is_object_valid('user', ...)` or `is_object_valid('db', 'DB', "$database")` | Verifications |
| `E_ARGS` (1, literal `exit 1`) | `check_hestia_demo_mode` | Verifications |
| `E_PARSING` (12) | `mysql_connect()` — malformed host config (func/db.sh:43-47, a direct `exit`, not `check_result`) | **Inside** `delete_mysql_database()`, the very first statement (func/db.sh:530) — after Verifications, but still strictly before `DROP DATABASE` |
| `E_CONNECT` (15) | `mysql_connect()` — unreachable server (func/db.sh:73-84, direct `exit`) | Same as above |

**Every reachable non-zero exit fires strictly before the first mutating
statement.** Some fire during "Verifications," two (`E_PARSING`,
`E_CONNECT`) fire inside `delete_mysql_database()` itself but still
*before* `DROP DATABASE` runs. **There is no exit code this script can
produce after mutation begins** — see "Exit-Code Evidence" and the
important caveat under "Mutation Semantics" below, which is a different
and more serious finding than "no post-mutation exit code exists."

**Critical asymmetry with `database.create`, confirmed by source read,
not assumed**: `bin/v-add-database` prefixes the caller's raw suffix
internally (`database="$user"_"$2"`, bin/v-add-database:21) *before*
`is_format_valid` runs — so `database.create`'s `database` parameter is
the **raw suffix** (e.g. `"wordpress_db"`). `bin/v-delete-database`
performs **no such prefixing** (`database=$2`, verbatim) — its
`is_object_valid('db', 'DB', "$database")` greps `db.conf`'s `DB=` field
directly, and that field was written by `v-add-database` using the
already-prefixed value. **`database.delete`'s `database` parameter must
therefore be the full, prefixed name** (e.g. `"admin_wordpress_db"`), not
the raw suffix `database.create` accepts for the identically-named
parameter. This is a real, source-verified inconsistency in the public
API surface, not an adapter defect — see "Limitations."

**Idempotency**: not idempotent. `is_object_valid('db', 'DB',
"$database")` rejects a nonexistent database with `E_NOTEXIST`,
pre-mutation, before `get_database_values`/`delete_mysql_database` ever
run. Deleting a database that doesn't exist is never silently treated as
a successful no-op.

**Lock target**: `user` — the same per-user model every other mutating
operation uses; no new granularity is required (see "Locking").

**Authorization target**: `{user, database}` — both non-sensitive, no
scrubbing needed at all (this operation has no password, no sensitive
parameter of any kind).

**Output**: no JSON mode, no `format` argument, confirmed by full source
read — same as every other operation in this registry so far.

## 2. Registry Entry

```php
"database.delete" => [
    "script" => "v-delete-database",
    "argument_order" => ["user", "database"],
    "parameters" => [
        "user"     => ["type" => "username",      "required" => true],
        "database" => ["type" => "database_name",  "required" => true],
    ],
    "fixed_parameters" => [],
    "mutation" => ["kind" => "delete"],
],
```

Both types (`username`, `database_name`) already existed —
`database_name`'s validator was added for `database.create` and is reused
here **without modification**. No `output_format`/`result_shape` key (no
JSON mode). No `known_post_mutation_exit_codes` key (see "Exit-Code
Evidence").

## 3. Argument Model

`["user", "database"]` — a direct, unreordered mirror of
`bin/v-delete-database`'s own two-slot positional contract. Generated
process argv is exactly 3 elements: `[scriptPath, user, database]`. No
`dbuser`, `type`, `host`, or `charset` slot exists on this script at all
— unlike `database.create`, there was nothing to decide whether to expose
or fix; the script's contract is already minimal.

## 4. Validation

`user` → `ParameterValidator::isValidUsername()` (unchanged, reused as-is
from every other operation). `database` → `ParameterValidator::
isValidDatabaseName()` (added for `database.create`, reused as-is here —
**no new validator method was written for this task**).

**This reuse is an exact match, not an approximation** — a meaningful
difference from `database.create`'s own use of the same validator.
`database.create`'s caller-facing value is a raw suffix, shorter than the
prefixed string `is_database_format_valid()` actually checks inside the
script, so that reuse was documented as a deliberately *looser* bound.
Here, the caller-facing value *is* the exact string `is_database_format_
valid()` checks (both operate on the full, prefixed name) — so
`isValidDatabaseName()`'s shape check (exclude-char class, `< 64` length)
is not an approximation for `database.delete`, it is the same check
Hestia's own script performs, on the same string.

## 5. Authorization

Target is built entirely from the two normalized, non-sensitive
parameters: `{"user": ..., "database": ...}`. There is no sensitive
parameter on this operation at all — no scrubbing behavior is exercised,
and none is needed. Authorization denial is checked before lock
acquisition (test 9), matching the existing, unmodified ordering used by
every other mutating operation.

## 6. Locking

`target["user"]` — the existing per-user `LockManager` model, unchanged.
No mismatch was found; `LockManager.php` was not touched.

## 7. Mutation Semantics

The existing four-state model (`not_attempted` / `confirmed` /
`confirmed_degraded` / `unknown`), unmodified:

| Condition | `mutation_state` |
|---|---|
| Validation/authorization/lock failure (before execution) | `not_attempted` |
| Exit `0` | `confirmed` |
| Any non-zero exit (including `E_NOTEXIST`) | `unknown` — no `known_post_mutation_exit_codes` declared |

**Important caveat, source-verified, not represented by any exit-code
metadata because the model has no way to represent it without inventing a
new state (which this task prohibits inventing):** because `DROP
DATABASE` itself is **unchecked** (see "Source Contract"), a real failure
of that specific statement — the query erroring out *after*
`mysql_connect` already succeeded — does **not** stop the script. It
proceeds to `REVOKE`, conditionally `DROP USER`, remove the `db.conf`
line, decrement both counters, log success via `v-log-action`, and exit
`0`. `mutation_state = "confirmed"` on exit `0` therefore means "the
script completed its full sequence and believed it succeeded," not "the
database was verifiably dropped." This is a strictly worse failure mode
than the `check_result` mislabeling documented for `database.create`
(`DATABASE_CREATE_IMPLEMENTATION.md` section 9): there, a real failure got
the *wrong label*; here, a real failure can get reported as **success**.

A second, related and much rarer edge: `case $TYPE in mysql) ... ;;
pgsql) ... ;; esac` has **no default branch**. If `TYPE` is anything other
than `mysql`/`pgsql`, no SQL runs at all, and the script *still* removes
the db.conf line, decrements counters, and exits `0` — the same
"bookkeeping succeeds regardless of whether the underlying operation did"
structure. In practice `TYPE` is always one of the two values
`v-add-database`'s own `is_type_valid` gate enforces at creation time, so
this branch is not normally reachable, but it is the same class of gap.

**No new mutation state was created to represent this.** Per this task's
explicit instruction ("do not create new mutation states"), the correct
response is to document the gap between "script exited 0" and "the drop
actually took effect," not to invent a `confirmed_but_maybe_not` state or
special-case this script's exit code inside `CommandAdapter`. `confirmed`
remains an accurate label for what the adapter can actually observe (the
process's own exit code) — it is Hestia's own script, not the adapter,
that under-checks its one mutating statement.

## 8. Exit-Code Evidence

See the table under "Source Contract." Restated precisely: **no post-
mutation exit code exists** because every reachable non-zero exit —
`E_ARGS`, `E_INVALID`, `E_DISABLED`, `E_NOTEXIST` (Verifications), plus
`E_PARSING`/`E_CONNECT` (inside `delete_mysql_database()`'s
`mysql_connect()` call) — fires strictly before `DROP DATABASE`, the
first and only mutating statement. This conclusion is correct and
source-verified; it is a distinct fact from the "confirmed" caveat in
section 7 above, which concerns what happens when the mutating statement
itself silently fails with no checked exit code at all — a scenario this
table cannot capture because, by construction, it produces exit `0`, not
a non-zero code.

## 9. Idempotency / Nonexistent Behavior

`is_object_valid('db', 'DB', "$database")` (func/main.sh:377-397,
bin/v-delete-database:36) fails `E_NOTEXIST` for a database that doesn't
exist in `$USER_DATA/db.conf` — during "Verifications," strictly before
`get_database_values`/the case/esac dispatch/any mutation. **Not
idempotent**: deleting a nonexistent database is explicitly rejected, not
silently treated as a successful no-op. The adapter surfaces this
faithfully: `status = hestia_error`, `hestiaErrorCode = "E_NOTEXIST"`,
`mutationState = "unknown"` (tests 11-13) — the same posture already
established for `database.create`'s own `E_EXISTS` case and
`domain.create`'s `E_EXISTS` case, just the inverse condition (missing
vs. duplicate).

## 10. Security Considerations

- **No new injection surface.** argv is still built via the same array-
  form `proc_open()` path already proven immune to shell metacharacter
  interpretation. There are no fixed parameters at all for this
  operation, so there is nothing for a compile-time literal to protect
  against caller override.
- **No sensitive data of any kind** — this operation has no password, no
  secret, nothing to scrub from target/authorizer/argv. The generic
  sensitive-parameter mechanism (reviewed/remediated for
  `database.create`) is simply not exercised here, which is itself
  evidence it composes correctly with an operation that doesn't need it.
- **No privilege escalation, no unsafe environment inheritance, no lock
  path manipulation, no parameter confusion** — proven the same way as
  every prior operation's equivalent claims (see
  `DOMAIN_CREATE_IMPLEMENTATION.md` "Security" for the identical,
  unchanged reasoning).
- **The "confirmed but maybe not dropped" gap (section 7) is an
  operational-honesty issue, not an injection/authorization/privilege
  issue** — it does not let an unauthorized caller do anything; it means
  an authorized, successful-looking deletion can occasionally not
  actually have deleted the database on the SQL server, while db.conf and
  the adapter's own bookkeeping both believe it did.
- **No generic exec/runRaw mechanism was introduced.** `CommandAdapter`'s
  public surface is unchanged: `invoke(operation, params, actor)` remains
  the only entry point.

## 11. Test Coverage

`test/adapter/DatabaseDeleteTest.php` — **15 test functions covering the
16 required items** (item 12 "duplicate/nonexistent behavior" and item 13
"any source-verified post-mutation failure" are covered together by one
function, `testNonexistentDatabaseIsUnknown`, since source proves there is
no post-mutation failure to test separately — the faithful adaptation
when the answer to "does one exist" is "no," same posture as
`database.create`'s own test 20). 174 pre-existing + 15 new = **189**.

| # | Test | Proves |
|---|---|---|
| 1 | `testRegistered` | registry resolves `database.delete` → `v-delete-database`, `mutation.kind = "delete"` |
| 3 | `testRequiredParametersEnforced` | both required params enforced |
| 4 | `testUnknownParameterRejected` | `dbuser` (a real `database.create` param, not part of this contract) is rejected |
| 5 | `testInvalidDatabaseNameRejected` | malformed database name rejected before execution |
| 2 | `testGeneratedArgv` | argv is exactly `[script, user, database]`, 3 elements, database passed verbatim (no prefixing) |
| 6 | `testNormalizedTarget` | target contains exactly `{user, database}`, no other keys |
| 7 | `testAuthorizerTarget` | authorizer receives the same normalized target |
| 8 | `testLockAcquiredForCorrectUser` | lock acquired for `target["user"]`, released after success |
| 9 | `testAuthorizationDenialBeforeLockAcquisition` | denial happens before lock acquisition, before process spawn |
| 10 | `testExitZeroIsConfirmed` | exit `0` → `mutationState = "confirmed"` |
| 11 | `testHestiaErrorExecution` | exit `3` → `status = hestia_error`, `hestiaErrorCode = "E_NOTEXIST"` |
| 12+13 | `testNonexistentDatabaseIsUnknown` | `E_NOTEXIST` (nonexistent database) → `mutationState = "unknown"`; not idempotent |
| 14 | `testNoKnownPostMutationExitCodes` | registry declares an empty `known_post_mutation_exit_codes` array |
| 15 | `testResultFieldsPopulatedCorrectly` | operation/target/status/actor fields, `resultShape`/`parsedOutput` both `null` |
| 16 | `testCommandAdapterContainsNoDatabaseDeleteSpecificLogic` | zero occurrences of `"database.delete"`/`"v-delete-database"` in **both** `CommandAdapter.php` and `ParameterValidator.php` — the latter check exists specifically because this task, unlike `database.create`, added no new validator, so this file proves that file wasn't touched with anything operation-specific either |

All tests use `FakeProcessRunner` or a small anonymous-class probe runner
— no real subprocess, no real Hestia installation, no root, no real
database server.

**Full-suite result**: `php test/adapter/run_tests.php` — **189 passed, 0
failed**, run 3 consecutive times, all identical.

## 12. Architectural Fit

**Yes — represented entirely through existing registry metadata and the
existing generic pipeline, with zero new code in `CommandAdapter.php` or
`ParameterValidator.php`.** This is the strongest genericity result in
the series so far:

- `database.create` needed 3 new type validators plus a
  `sensitive`/`delivery` registry declaration.
- `domain.delete`/`backup.schedule` needed only a registry entry, reusing
  existing types.
- `database.delete` needed only a registry entry, reusing existing types
  — **and** it is the first operation whose public parameter model (2
  required params, no fixed params, no sensitive params) is simpler than
  every prior mutating operation's.

No STOP condition was triggered. No abstraction mismatch was found in
resolve/validate/normalize/authorize/lock/execute/classify/result. The one
genuine architectural finding this task surfaced — the unchecked `DROP
DATABASE` producing a possible false `confirmed` — is not an adapter
abstraction gap; it's a fact about what `bin/v-delete-database` itself
guarantees, which the adapter faithfully (if not maximally informatively)
reports via the existing `confirmed` state. The task's own Phase 5
instruction — use the existing four-state model, do not invent a new one
even if you find a gap — was followed rather than worked around.

## 13. Limitations

- **`database` must be the full, prefixed name, not the raw suffix
  `database.create` accepts for the identically-named parameter.** A
  caller must either already know the prefixed form or derive it as
  `"{user}_{suffix}"` before calling `database.delete` — the adapter does
  not translate between the two forms, since doing so would be
  operation-specific logic bridging two operations' otherwise-independent
  contracts. A future API v2 layer should decide explicitly whether to
  hide this asymmetry from its own callers (e.g. by accepting a raw
  suffix and prefixing it before calling the adapter) — that decision
  belongs at the API layer, not in `CommandAdapter`.
- **`mutation_state = "confirmed"` does not prove the database was
  actually dropped** when `DROP DATABASE` itself silently fails after a
  successful `mysql_connect` — see "Mutation Semantics." No fix was
  applied; none is possible without either modifying
  `bin/v-delete-database` (prohibited) or inventing a new mutation state
  (prohibited).
- **No dbuser cleanup visibility.** The script conditionally drops the
  dbuser if it has fewer than 2 db.conf rows referencing it — this detail
  is entirely internal to `bin/v-delete-database` and not exposed via any
  adapter result field (there was never a `dbuser` parameter to report
  on for this operation in the first place).
- **No API v2, no migration.** `web/delete/db/index.php` remains
  unmodified and continues to call `v-delete-database` directly via
  `exec()`, entirely outside this adapter's locking boundary — the same
  already-documented "legacy bypass" limitation every prior operation in
  this series carries.

## 14. READY / NOT READY FOR API V2 INTEGRATION

**READY, with the same caveats already on record for
`domain.create`/`domain.delete`/`database.create`** (no HTTP endpoint
exists yet; `web/delete/db/index.php` remains an unmigrated legacy bypass)
**plus two new ones specific to this operation**: (1) any API v2 consumer
must be told explicitly that `database.delete`'s `database` parameter is
the prefixed name, not the raw suffix `database.create`'s `database`
parameter uses — presenting both under one REST resource without
resolving this would be a real, user-facing bug; (2) `mutation_state =
"confirmed"` for this operation is a slightly weaker guarantee than for
`domain.create`/`domain.delete` (whose one post-mutation risk is at least
a *checked* `E_RESTART`) — an API v2 consumer that treats `confirmed` as
"definitely happened" should know that for this specific operation it
means "the script exited 0," and the script's own `DROP DATABASE` is
unchecked.
