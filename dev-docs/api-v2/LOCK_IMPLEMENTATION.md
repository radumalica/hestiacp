# Lock Implementation

Implementation report for the per-user locking layer described in
`WRITE_OPERATION_DESIGN.md` and finalized by `LOCK_PERMISSION_REVIEW.md`.
This is infrastructure only — no mutating operation (`domain.create` or
otherwise) is registered or implemented in this pass. The lock is
exercised end-to-end only by a synthetic test-only registry entry
(`test/adapter/MutatingOperationTest.php`); `domain.get` and
`domain.list` remain read-only and never acquire it.

# Lock Architecture

`Hestiacp\Adapter\LockManager` (`web/inc/adapter/LockManager.php`)
implements `LockManagerInterface` (`acquire(string $user): bool`,
`release(): void`), mirroring the existing
`ProcessRunnerInterface`/`ProcOpenProcessRunner`/`FakeProcessRunner`
seam already used for subprocess execution. `CommandAdapter` depends
only on the interface; its constructor accepts an optional
`?LockManagerInterface $lockManager` (7th parameter, defaulting to
`new LockManager()`), so production code needs no changes to keep
using the real, flock-based implementation, while tests inject
`test/adapter/SpyLockManager.php`.

`CommandRegistry` entries now carry a minimal
`"mutation" => ["kind" => "read" | ...]` field. `CommandAdapter::invoke()`
reads `$entry["mutation"]["kind"] ?? "read"` once, immediately after
resolving the registry entry, and treats any value other than `"read"`
as mutating. Only `kind` is implemented — `WRITE_OPERATION_DESIGN.md`
Part 1's fuller 4-field proposal (`config_write`, `service_reload`,
`destructive`) is not added, since nothing in this codebase consumes
those fields yet (this task's "do not add unnecessary categories"
instruction).

# Lock Location

`$HESTIA/data/adapter-locks/<username>.lock`
(`LockManager::DEFAULT_LOCK_DIRECTORY` =
`/usr/local/hestia/data/adapter-locks/`) — never
`$HESTIA/data/users/<username>/`, which `LOCK_PERMISSION_REVIEW.md`
confirmed is inaccessible to the `hestiaweb` identity PHP-FPM actually
runs as (root:root or root:hestia, mode 750/770, no ACL grant found
anywhere in the installer).

`install/hst-install-ubuntu.sh` and `install/hst-install-debian.sh` were
each edited in exactly two places:

1. The directory-tree-building block: `$HESTIA/data/adapter-locks` added
   to the `mkdir -p` list, followed by `chmod 770
   $HESTIA/data/adapter-locks`, placed immediately after the existing
   `chmod 770 $HESTIA/data/sessions` line.
2. The "Starting Hestia service" block: `chown hestiaweb:hestiaweb
   $HESTIA/data/adapter-locks`, placed immediately after the existing
   `chown hestiaweb:hestiaweb $HESTIA/data/sessions` line.

This exactly mirrors the existing `$HESTIA/data/sessions` convention.
`$HESTIA/data/users` and its contents were not touched by either
installer edit — verified by `git diff --stat` (below) touching only
the two installer files, and by inspecting the diff directly.

# Lock Lifecycle

- **Creation**: the lock *file* for a given user is created lazily, on
  first `acquire($user)` call for that user, via `fopen($path, "c")` —
  not during user creation. There is no code path that pre-creates a
  lock file when a Hestia user is created.
- **Normal release**: `CommandAdapter::invoke()` acquires the lock (if
  the operation is mutating) strictly after validation and strictly
  before the underlying `v-*` process is spawned, and releases it in a
  `finally` block wrapping the process-execution step — guaranteeing
  release whether the process exits 0, exits non-zero, or the process
  runner throws.
- **Process death / crash / SIGKILL / reboot**: not handled by any
  application code, deliberately. `LockManager` uses a real kernel-level
  `flock(LOCK_EX)`, whose defining property is that the OS releases it
  automatically when the holding file descriptor closes — including on
  process death by any signal, including `SIGKILL`, and (trivially) on
  reboot, since the lock does not persist across a reboot at all. No
  stale-lock detection, PID file, or cleanup-on-crash code exists or is
  needed.
- **User deletion**: `bin/v-delete-user` was NOT modified. A lock file
  for a deleted user is left behind (orphaned) under
  `$HESTIA/data/adapter-locks/`. This is an accepted limitation for this
  pass — see "Known Limitations".

# Concurrency Model

Exactly one lock per Hestia user — no global lock, no per-domain or
per-resource lock. `CommandAdapter::invoke()`'s acquisition order:

1. Resolve the registry entry (`UNKNOWN_OPERATION` short-circuits here,
   no lock involved).
2. Reject unexpected parameters.
3. Validate required-parameter presence and per-type shape
   (`MISSING_PARAMETER`, `VALIDATION_FAILED`, etc. — all short-circuit
   here, no lock involved).
4. Build `argv` from the registry's `argument_order`
   (`REGISTRY_ERROR` on a malformed registry entry — no lock involved).
5. **Only if the resolved entry is mutating**: acquire the lock, keyed
   on the already-validated `user` parameter.
6. Spawn the underlying process.
7. Release the lock (`finally`).

Read-only operations never call `acquire()`/`release()` at all — proven
by `MutatingOperationTest::testReadOnlyDoesNotLock` (test A).

Two mutating operations for the *same* user contend on the same lock
file and are serialized; two mutating operations for *different* users
proceed fully concurrently, since each locks a distinct file. Proven
against the real `LockManager` (not a mock) with actual separate OS
processes in `LockManagerTest::testCrossProcessSameUserSerialized` (test
C) and `testCrossProcessDifferentUsersConcurrent` (test D), using a
subprocess fixture (`test/adapter/fixtures/lock_holder.php`) synchronized
via a sentinel file rather than a fixed sleep guess.

# Timeout Semantics

`LockManager::acquire()` polls `flock($handle, LOCK_EX | LOCK_NB)` every
20ms (`DEFAULT_POLL_INTERVAL_MICROSECONDS`) until either it succeeds or
a deadline (`DEFAULT_TIMEOUT_SECONDS` = 10s, configurable per instance)
elapses, at which point it returns `false` — PHP has no native
blocking-flock-with-timeout primitive, so this poll loop is the
mechanism. A `false` return is ordinary, expected contention, not an
exception.

`CommandAdapter` maps a `false` return to an `adapter_error` result with
`adapter_error_code = "LOCK_TIMEOUT"`, `status = "adapter_error"`, and
`mutation_state = "not_attempted"` — and, critically, the underlying
`v-*` process is never spawned in this path (proven by
`MutatingOperationTest::testLockTimeoutNeverExecutes`, test J, which
asserts `count($runner->calls) === 0`).

# Error Semantics

Two distinct failure modes, deliberately not conflated:

- **Ordinary contention** (another operation currently holds the lock):
  `LockManager::acquire()` returns `false` after its timeout elapses.
  Mapped to `adapter_error_code = "LOCK_TIMEOUT"`.
- **Locking mechanism failure** (e.g. the lock directory does not exist
  or is not writable by the current process — an operational problem,
  not routine contention): `LockManager::acquire()` throws
  `LockUnavailableException`. `CommandAdapter` catches this specific
  exception type and maps it to `adapter_error_code = "LOCK_UNAVAILABLE"`.
  Any other exception type is NOT caught here and propagates.

Neither path ever labels a mutating operation's result
`"partial_failure"`. Per `WRITE_OPERATION_DESIGN.md` Part 4/Part 5, the
adapter implements exactly three `mutation_state` values:

- `not_attempted` — rejected before the underlying process was ever
  spawned, for ANY reason (unknown operation, validation failure,
  `LOCK_TIMEOUT`, `LOCK_UNAVAILABLE`, ...). Only set when the resolved
  operation is mutating; `null` for read-only operations and for
  rejections that occur before the registry entry itself is resolved
  (`UNKNOWN_OPERATION` — mutation status of an unknown operation is
  unknowable).
- `confirmed` — the underlying process was spawned and exited `0`.
- `unknown` — the underlying process was spawned and exited non-zero.
  Deliberately not a more specific guess: the adapter cannot generally
  know how much of a multi-step `v-*` script's work completed before it
  failed (see `WRITE_OPERATION_DESIGN.md` Part 5's trace of
  `bin/v-add-web-domain`).

A process-runner exception (e.g. `proc_open()` itself failing) is
**not** swallowed into any `AdapterResult` — it propagates to the
caller unchanged, exactly as `CommandAdapter` already behaved before
this change for any unexpected failure. The lock is still guaranteed
released in this case (`finally`), proven by
`MutatingOperationTest::testLockReleasedAfterException` (test H), which
uses `test/adapter/ThrowingProcessRunner.php` and asserts
`$lockManager->releaseCalls === 1` after catching the propagated
exception.

# Security

- **No sudo subprocess for locking**: the lock is acquired and released
  entirely within the PHP-FPM process executing
  `CommandAdapter::invoke()` — never via a shelled-out helper — which is
  what allows the lock to span the *entire* critical section (argv
  construction through subprocess completion), not just the `v-*`
  script's own runtime.
- **Username safety, defense in depth**: `LockManager::lockFilePath()`
  first requires `ParameterValidator::isValidUsername($user)` to pass
  (the SAME validator `CommandAdapter` already applies to the `user`
  parameter before any mutating operation reaches the locking step), then
  independently re-applies `basename()` and rejects if the result differs
  from the input or is empty/`.`/`..`. Proven directly against the real
  `LockManager` — not a mock — by `LockManagerTest::testInvalidUsernameRejected`
  (test I), which asserts both that `../../etc/passwd`-shaped and similar
  inputs throw `InvalidArgumentException`, and that no lock file is
  created anywhere as a side effect of a rejected attempt.
- **No new privilege boundary crossed**: `$HESTIA/data/adapter-locks` is
  owned `hestiaweb:hestiaweb` mode `770` — the same identity and mode as
  the existing `$HESTIA/data/sessions` directory PHP-FPM already writes
  to today. Root-run `v-*` scripts (invoked via the existing `sudo`
  mechanism, unchanged by this pass) never need access to this directory
  at all; only the PHP-FPM process does.
- **`data/users` untouched**: no permission or ownership change was made
  to `$HESTIA/data/users` or anything under it, in either installer file
  or anywhere else — confirmed by `git diff --stat` below.

# Tests

`php test/adapter/run_tests.php` — **40 passed, 0 failed** (re-run twice
to check for flakiness in the timing-sensitive subprocess tests; both
runs passed identically).

| Requirement | Test | File |
|---|---|---|
| A | read-only operation does not acquire a lock | `MutatingOperationTest.php` |
| B | mutating operation acquires the correct per-user lock | `MutatingOperationTest.php` |
| C | two concurrent mutating ops, same user, are serialized (real subprocesses) | `LockManagerTest.php` |
| D | two concurrent mutating ops, different users, run concurrently (real subprocesses) | `LockManagerTest.php` |
| E | lock timeout prevents acquisition | `LockManagerTest.php` |
| F | lock released after successful execution | `MutatingOperationTest.php` |
| G | lock released after command failure (non-zero exit) | `MutatingOperationTest.php` |
| H | lock released after adapter/subprocess exception | `MutatingOperationTest.php` |
| I | invalid username cannot escape the lock directory | `LockManagerTest.php` |
| J | lock timeout / lock-unavailable return adapter-level errors and never execute `v-*` | `MutatingOperationTest.php` |
| K | existing `domain.get`/`domain.list` tests remain green | `CommandAdapterTest.php`, `DomainListTest.php` (unmodified, still passing) |

Additional coverage beyond the required list: `mutation_state` values
(`confirmed`/`unknown`/`not_attempted`) at the `CommandAdapter` level,
and direct `LockManager` behavior (acquire-after-release,
release-without-acquire idempotency).

Test C/D use a real subprocess fixture
(`test/adapter/fixtures/lock_holder.php`) rather than two in-process
`LockManager` instances, specifically to prove genuine cross-process OS
contention. They synchronize via a sentinel file the holder touches
immediately after acquiring the lock (polled by the parent test, not
guessed via a fixed sleep) to avoid timing-based flakiness on
"is the lock held yet"; the subsequent measurement of *how long* the
contender then waits necessarily carries real-world timing tolerance
(assertions use `>=`/`<` bounds with margin, not exact durations), which
is an accepted, documented trade-off of testing genuine OS-level
concurrency rather than a design flaw.

No test in the automated suite requires root or a real Hestia
installation; all use either `FakeProcessRunner`/`ThrowingProcessRunner`/
`SpyLockManager` or a temporary directory under `sys_get_temp_dir()`,
never `$HESTIA/data/adapter-locks` itself.

# Known Limitations

- **Legacy bypass, unresolved by design**: this lock only serializes
  operations routed through `CommandAdapter::invoke()`. Every existing
  direct PHP caller (`web/inc/main.php`, `web/api/index.php`) and every
  direct CLI invocation of a `bin/v-*` script continues to run entirely
  outside this lock, exactly as today. This implementation does not
  claim to fix, and does not fix, any of the pre-existing concurrency
  races documented in `ARCHITECTURE_REVIEW.md` (e.g. `is_package_full()`
  check-then-act, `increase_user_value()`/`decrease_user_value()`
  lost updates) for any caller other than a future adapter-routed
  mutating operation.
- **Orphaned lock files on user deletion**: `bin/v-delete-user` is
  unmodified. A `<username>.lock` file for a deleted user remains under
  `$HESTIA/data/adapter-locks/` indefinitely. This is accepted as
  harmless for this stage: the file is empty, costs negligible disk
  space, and if the username is ever reused, `LockManager` simply reopens
  and re-locks the same (already-existing) file — `fopen($path, "c")`
  does not care whether the file pre-existed. Cleaning this up (e.g. from
  `bin/v-delete-user`) is an explicit non-goal of this task and is left
  for whenever the first mutating operation is actually implemented.
- **`$lockWaitMs` not populated**: `AdapterResult::$lockWaitMs` remains
  `null` in all cases. It is reserved for a future lock-wait-duration
  metric (`ARCHITECTURE_ADAPTER_DESIGN.md` section 6/12) that this pass
  does not implement, distinct from the locking mechanism itself, which
  is implemented and tested.
- **No audit persistence**: unchanged from the prior vertical slice —
  still not implemented.
- **Timeout is on lock acquisition only**: once a mutating operation's
  lock is acquired and the underlying `v-*` process is spawned, there is
  no timeout on the process itself running arbitrarily long — unchanged
  from the prior slice's existing "no timeouts/cancellation" limitation.

# Next Step

Implement the first real mutating operation, `domain.create` →
`bin/v-add-web-domain`, now that both prerequisites it was blocked on are
resolved: result semantics (`WRITE_OPERATION_DESIGN.md`, this pass's
`mutation_state` implementation) and per-user locking (this document).
`domain.create` itself remains explicitly unimplemented as of this
document — no registry entry, no code path, no modification to
`bin/v-add-web-domain` or `func/main.sh` exists anywhere in this branch.
