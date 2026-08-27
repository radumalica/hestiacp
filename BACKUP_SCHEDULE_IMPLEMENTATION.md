# backup.schedule Implementation

Implements the operation `BACKUP_CREATE_DESIGN.md` recommended: not
`backup.create` mapped onto `bin/v-backup-user` (the long-running
worker, blocked per that document's Part 3/Part 10), but
`backup.schedule` mapped onto `bin/v-schedule-user-backup` (the fast,
synchronous script the real Hestia UI already calls). See that document
for the full source trace this implementation is built on; this
document records what was actually built and why no further
architecture was needed.

---

# Source Contract

`bin/v-schedule-user-backup` (54 lines, re-verified against source for
this implementation, not re-derived from the design document alone):

- **Arguments**: `USER` only (`check_args '1' "$#" 'USER'`, line 28).
  No optional arguments, no fixed/default arguments beyond that single
  positional slot — confirmed by reading the full script; there is no
  second argument of any kind.
- **Validation, strictly pre-mutation** (lines 28-36): `check_args` →
  `is_format_valid('user')` → `is_system_enabled($BACKUP_SYSTEM,
  'BACKUP_SYSTEM')` → `is_object_valid('user', 'USER', $user)` →
  `is_backup_enabled` → `is_backup_scheduled('backup')` →
  `check_hestia_demo_mode`.
- **Mutation** (the entire "Action" section, lines 38-44): exactly one
  line — `echo "$BIN/v-backup-user $user yes >> $log 2>&1" >>
  $HESTIA/data/queue/backup.pipe`. Nothing else. No filesystem tree
  changes, no `$USER_DATA/*.conf` writes, no service restarts, no
  subprocess beyond the shell's own redirect.
- **Exit codes**: `E_ARGS`(1)/`E_NOTEXIST`(3, unknown user)/
  `E_DISABLED`(11, `BACKUP_SYSTEM` unset or per-user backups disabled)/
  `E_EXISTS`(4, already scheduled, via `is_backup_scheduled`) — every
  one of these fires during the verification section, strictly before
  the one mutating line. Success is unconditional exit 0
  (`log_event "$OK" "$ARGUMENTS"; exit`, lines 51-53) with no further
  failure surface after the mutation.
- **What this script does NOT do**: it does not create a backup archive.
  It does not touch `$USER_DATA/backup.conf`. It does not run `tar`,
  `mysqldump`, or any remote-storage upload. Its entire observable
  effect is one more line in a shared queue file.

# Registry Mapping

Added to `web/inc/adapter/CommandRegistry.php`, following the exact
established shape:

```php
"backup.schedule" => [
    "script" => "v-schedule-user-backup",
    "argument_order" => ["user"],
    "parameters" => [
        "user" => ["type" => "username", "required" => true],
    ],
    "fixed_parameters" => [],
    "mutation" => ["kind" => "create"],
],
```

No `output_format`/`result_shape` (the script produces no structured
stdout — confirmed by source read). No `fixed_parameters` at all — the
first mutating operation in this series with zero registry-fixed
arguments, because the underlying script takes none beyond `user`. No
`known_post_mutation_exit_codes` — see Mutation Semantics below.
`ParameterValidator::isValidUsername()` is reused unchanged, exactly as
every prior operation already does — no new validator was needed.

# Validation Behavior

Identical, unmodified validation pipeline every prior operation already
uses: unexpected-parameter rejection, required-parameter check, then
`ParameterValidator::isValidUsername()` shape validation — all strictly
before authorization, locking, or execution. No backup-specific
validation code was written or was needed; `user` is validated exactly
as it already is for `domain.create`/`domain.delete`.

# Authorization Target

`target.user` — `v-schedule-user-backup`'s sole argument
(`user=$1`, line 14). Consistent with `domain.create`/`domain.delete`'s
existing precedent, the request flows through the same, unmodified
`AuthorizerInterface` seam, consulted after validation and before
locking/execution, defaulting to the same permissive `AllowAllAuthorizer`
when no authorizer is supplied. No new authorization policy was
designed or implemented; `CommandAdapter` contains no
`backup.schedule`-specific branch of any kind (verified — see
Architectural Constraint below).

# Locking Behavior

The existing per-user `LockManager`, unmodified, keyed on
`$target["user"]` — the identical mechanism `domain.create`/
`domain.delete` already use, with zero backup-specific locking logic
added anywhere. This closes a real, source-verified race:
`is_backup_scheduled`'s check (a `grep` against the queue file) and the
`echo >>` append are two separate operations with no lock between them
anywhere in `bin/v-schedule-user-backup` or `func/main.sh` — two
near-simultaneous adapter-routed calls for the same user could
otherwise both pass the "not already scheduled" check before either
appends, queuing duplicate work. `BackupScheduleTest::
testConcurrentCallsForSameUserAreSerialized` proves this is closed for
adapter-routed calls, using a real, temp-directory-backed `LockManager`
and a second, independent `LockManager` instance probing the same lock
key from inside the first call's process-runner — the identical
technique `DomainDeleteTest.php`'s own concurrency tests already use, no
new technique invented. As with every prior locking discussion in this
series, this protection is scoped to adapter-routed calls only — the
current PHP UI still calls `v-schedule-user-backup` directly via a raw
`exec()` in `web/schedule/backup/index.php`, entirely outside the
adapter, and remains as unprotected by this lock as it always was.

# Mutation Semantics

**`mutation_state: confirmed` for `backup.schedule` means "the backup
job was successfully queued," NOT "a backup archive exists."** This is
the single most important semantic fact about this operation, stated
here as plainly as `BACKUP_CREATE_DESIGN.md` Part 5 stated it, because
it is the reason the operation is named `backup.schedule` and not
`backup.create`: `domain.create`'s `confirmed` means the domain now
exists; this operation's `confirmed` means only that one line was
appended to `$HESTIA/data/queue/backup.pipe`. No backup exists yet, will
not exist for up to five minutes (the cron interval that drains that
queue), and will not be visible to any future `backup.list`-shaped
operation until `bin/v-backup-user` — a separate script this operation
never invokes — actually runs and completes. A caller that reads
`confirmed` as "my backup now exists" is wrong, and no documentation,
UI copy, or future API surface built on this operation should imply
otherwise.

No `known_post_mutation_exit_codes` are declared, and no new
`mutation_state` value was introduced. Traced in full above: every
non-zero exit `v-schedule-user-backup` can produce occurs strictly
before its one mutating line — there is no post-mutation exit code to
declare, unlike `domain.create`/`domain.delete`'s `E_RESTART`. This
means:

- exit `0` → `mutation_state: confirmed` (queued, per the caveat above)
- any non-zero exit → `mutation_state: unknown`, via the existing,
  unmodified, registry-driven classification in `CommandAdapter`
  (`known_post_mutation_exit_codes` absent for this entry → the
  `unknown` branch is the only reachable one)
- pre-execution rejection (validation/authorization/lock
  failure) → `mutation_state: not_attempted`, exactly as for every
  other mutating operation

`AdapterResult` was not modified. `CommandAdapter`'s classification
logic was not modified.

# Exit-Code Semantics

`E_EXISTS` (exit 4, "already scheduled") is propagated through the
existing, unmodified `CommandAdapter::HESTIA_EXIT_CODES` mapping —
`status: hestia_error`, `hestiaErrorCode: "E_EXISTS"`. This is not a
new code and required no adapter change; it was already in the exit-code
table from the locking pass, reused here as-is. `BackupScheduleTest::
testAlreadyScheduledPropagated` confirms this specific propagation, and
that its `mutation_state` correctly stays `unknown`, never `confirmed`.

# Retry / Idempotency Semantics

Effectively idempotent for adapter-routed callers, matching
`BACKUP_CREATE_DESIGN.md` Part 8's finding: a retry either queues
exactly one backup job or receives a clean, already-distinguished
`E_EXISTS` rejection — never a silent duplicate queue entry — because
(a) `is_backup_scheduled` is a genuine, source-verified pre-mutation
guard, and (b) the per-user lock (see Locking Behavior above) closes the
TOCTOU race that would otherwise let two adapter-routed calls both slip
past that guard. Note the guarantee is scoped to "one backup job of
*either* implementation at a time per user," not "one legacy backup job":
`is_backup_scheduled`'s `grep backup` match is a broad substring match
that also matches the restic scheduler's queue lines (see
`BACKUP_CREATE_DESIGN.md`), so a queued restic job blocks a legacy
schedule call for the same user and vice versa, both surfacing the same
`E_EXISTS`. This is a materially better retry story than
`bin/v-backup-user` itself has (not idempotent at all — every successful
run creates a new, independent archive) — one more concrete reason this
operation maps to the scheduler, not the worker.

# Scheduled vs. Completed

Restated once more, explicitly, because it is easy to lose in a fast
skim of the fields above: **"scheduled" and "completed" are not the same
event, and this operation only ever reports the former.**
`backup.schedule` returning `status: ok` / `mutation_state: confirmed`
tells a caller "the request to back this user up has been durably queued
and will be processed by the next cron cycle" — it does not, and
structurally cannot, tell a caller "the backup now exists," "the backup
succeeded," or even "the backup has started running." No field on this
operation's `AdapterResult` answers those questions; nothing in this
implementation claims otherwise. Answering them is the job of a future,
separate, read-oriented operation (a `backup.list`-shaped one, reading
`$USER_DATA/backup.conf` after the worker has actually run) — explicitly
out of scope here, per the task's instruction, and not designed in this
document.

# Why `bin/v-backup-user` Is Intentionally NOT Invoked Directly

Restated from `BACKUP_CREATE_DESIGN.md` Part 3/Part 10, because it is
the load-bearing decision behind this entire implementation:
`bin/v-backup-user` has an unbounded, timeout-free load-average wait
loop (`check_backup_conditions`, `func/main.sh:1808-1817`) that could
hold a `CommandAdapter`-issued `proc_open()` call — and the per-user
lock along with it — open indefinitely under load; no fan-out model for
its internally-determined list of domains/mail/databases; at least one
source-verified destructive partial-mutation path (`local_backup`'s
`E_DISK` exit, which can delete a prior backup during retention
rotation and then fail before writing the replacement); and no
asynchronous "job" concept `AdapterResult` can represent. None of these
are reasons the worker can never be registered — they are reasons
today's synchronous, single-process `CommandAdapter` architecture
cannot yet absorb it without new abstractions this task was explicitly
told not to build. `backup.schedule` sidesteps every one of these
by construction: its own contract (traced above) has no unbounded wait,
no fan-out, no partial-mutation ambiguity, and completes in
sub-second time, exactly like `domain.create`/`domain.delete`.

# Why No New Adapter Abstraction Was Required

Verified directly, not just asserted: `web/inc/adapter/CommandAdapter.php`
was not modified by this implementation at all (confirmed — see Final
Verification below: `git diff` touches only `CommandRegistry.php` and
`run_tests.php`). Every mechanism `backup.schedule` needed —
registry-driven script/argument resolution, parameter validation,
the authorization seam, per-user locking, generic exit-code
classification — already existed, proven by `domain.create`/
`domain.delete`, and absorbed this third, structurally different
operation (single argument, zero fixed parameters, zero post-mutation
exit codes, a "queue append" mutation rather than a "config file write"
or "directory delete" one) as pure registry data. This is itself
evidence the registry-driven design generalizes rather than having been
tuned to fit exactly two prior examples.

# Tests Performed and Results

`test/adapter/BackupScheduleTest.php` (16 tests, all passing), covering
every item the task required at minimum:

1. Successful scheduling (`testSuccessfulScheduling`)
2. Correct script resolution, `backup.schedule` → `v-schedule-user-backup`
   (`testScriptResolution`)
3. Correct argument, `[script, user]` (`testGeneratedArgv`)
4. Authorization denial before lock acquisition (`testDenialBeforeLock`)
5. Authorization denial before process execution (`testDenialBeforeProcess`)
6. Per-user lock actually acquired (`testLockAcquiredForUser`)
7. `E_EXISTS`/exit 4 propagated correctly (`testAlreadyScheduledPropagated`)
8. Non-zero exit never claims `confirmed` (`testNonZeroExitNotConfirmed`)
9. Real, production registry accepts the new operation without throwing
   (`testRegistryConstructionAcceptsOperation`)
10. No `known_post_mutation_exit_codes` present
    (`testNoKnownPostMutationExitCodes`)
11. Two concurrent adapter-routed calls for the same user are serialized
    by the real per-user lock, proven via the same real-flock-probe
    technique `DomainDeleteTest.php` already established
    (`testConcurrentCallsForSameUserAreSerialized`)

Plus, matching this series' established coverage baseline for every
prior mutating operation: unexpected/missing-parameter rejection,
validation-failure-never-acquires-lock, and lock-released-after-success/
-failure.

**`bin/v-backup-user` is never invoked, mocked, or referenced by any
test in this file** — per the task's explicit instruction, the adapter's
tested contract ends at the queue append; whether the queued worker
eventually runs correctly is outside this operation's synchronous
contract and outside this test file's scope.

**Full suite result**: 124 tests total (108 pre-existing + 16 new),
**124 passed, 0 failed**, confirmed across three consecutive runs of
`php test/adapter/run_tests.php`, no flaky behavior observed.

No existing test was weakened, modified, or rewritten to accommodate
this operation.
