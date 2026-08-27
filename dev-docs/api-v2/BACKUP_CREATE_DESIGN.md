# backup.create — Source-First Design Review

Design review only. No source code was modified to produce this document.
No registry entry was added. No operation was implemented. No tests were
added.

**Purpose**: determine whether `backup.create`, mapped onto Hestia's real
backup machinery, can be absorbed by the existing registry-driven
`CommandAdapter` architecture (proven so far by `domain.create` and
`domain.delete`) without special-case logic — or whether it exposes a
genuinely new concept the architecture cannot yet represent.

**Headline finding, stated up front because it changes almost every
section below**: the operation a user-facing "create a backup now"
button actually triggers in this codebase is **not** `bin/v-backup-user`.
It is `bin/v-schedule-user-backup` — a fast, synchronous, queue-appending
script. `bin/v-backup-user` is the long-running worker that a cron job
drains from that queue, five minutes later, completely detached from any
HTTP request. This is confirmed by tracing the real call chain, not
assumed from naming. See Part 0.

---

# Part 0 — The real call chain (read before anything else)

Traced end to end, source-verified:

1. `web/schedule/backup/index.php` line 9: `exec(HESTIA_CMD .
   "v-schedule-user-backup " . $user, ...)`. This is the **only** place
   in `web/` that the PHP UI triggers a user-initiated backup. It is
   synchronous, from the browser's perspective — the HTTP request waits
   for this exec() to return.
2. `bin/v-schedule-user-backup` (54 lines total) does exactly one
   mutating thing: `echo "$BIN/v-backup-user $user yes >> $log 2>&1" >>
   $HESTIA/data/queue/backup.pipe` (line 44) — it appends one line to a
   plain text file. Every other line in the script is either a `source`,
   a verification, or `log_event "$OK"; exit` (implicit exit 0).
3. `install/hst-install-ubuntu.sh` lines 2448-2453 install a crontab for
   the `hestiaweb` user: `*/5 * * * * sudo
   /usr/local/hestia/bin/v-update-sys-queue backup`.
4. `bin/v-update-sys-queue`, invoked with `backup`, does `bash
   $HESTIA/data/queue/backup.pipe > /dev/null 2>&1` (line 49) — it
   literally executes the queue file as a bash script, running every
   queued `v-backup-user ... ` line in sequence, entirely outside any
   HTTP request, cron-triggered, at most every 5 minutes.
5. Only at that point does `bin/v-backup-user` — the actual, long-running
   tar/gzip/mysqldump/remote-upload worker traced in Part 1 — ever run.

**Consequence for this review**: "map `backup.create` onto
`v-backup-user`" and "map `backup.create` onto `v-schedule-user-backup`"
are two different design questions with two different answers. This
document evaluates both, but the recommendation (Part 10) is unambiguous:
`backup.create` should be registered against `v-schedule-user-backup`,
matching what the real product already does today.

`v-backup-user-restic` (a second, parallel, restic-based implementation
— see Part 1's closing note) has its own `v-schedule-user-backup-restic`
following the identical schedule → queue → cron → worker pattern, but is
**not** wired into `web/schedule/backup/index.php` today; the legacy tar
path is the only one the current UI calls.

---

# Part 1 — `bin/v-backup-user`: the actual worker (801 lines, read in full)

## Contract

- **Arguments**: `USER NOTIFY` (`check_args '1' "$#" 'USER [NOTIFY]'`,
  line 141). `NOTIFY` is optional, defaults to `no` (`notify=${2-no}`,
  line 15) — controls whether a completion email + in-panel notification
  is sent (lines 788-793), not whether the backup itself runs.
- **No domain parameter** — it is unconditionally user-scoped. It backs
  up every web domain, DNS zone, mail domain, database, cron job, and
  arbitrary home-directory content the user owns, filtered only by that
  user's own `$USER_DATA/backup-excludes.conf` (sourced at lines 238-240
  and again, reset, at line 646 for the user-directories section) — there
  is no caller-supplied "which domain" argument at all, unlike
  `domain.create`/`domain.delete`.

## Validation order (lines 141-151, strictly before any mutation)

```
check_args → is_format_valid('user') → is_system_enabled(BACKUP_SYSTEM)
  → is_object_valid('user','USER',$user)
  → is_object_unsuspended (conditionally, only if POLICY_BACKUP_SUSPENDED_USERS != yes)
  → is_backup_enabled (checks $USER_DATA/user.conf's BACKUPS > 0, func/main.sh:338-343)
  → check_hestia_demo_mode
```

`is_backup_enabled` is a **new validation shape** relative to
`domain.create`/`domain.delete`: it is a per-user, per-feature toggle
read from `user.conf`, not a shape check on the caller's own argument. It
existed before this review (verified in `func/main.sh`) — `backup.create`
doesn't invent it, but it is the first operation examined so far where a
resource-scoped, user-configured "is this feature enabled for this
account" gate sits in the validation chain. `CommandAdapter` does not
need to know this exists — it is entirely internal to the script, exactly
like every other `check_result` call already is.

**Separately source-verified latent ambiguity, not created by this
review**: `check_hestia_demo_mode` (`func/main.sh:1866-1872`) does a bare
`exit 1` on its failure path — not `check_result`, no `log_event`, no
symbolic name. Exit code `1` is also `E_ARGS` (`func/main.sh:110`). A
caller cannot distinguish "wrong argument count" from "demo mode is on"
by exit code alone for **any** Hestia script that calls this function,
`v-backup-user`/`v-schedule-user-backup` included. Pre-existing, not
specific to backup, and out of scope to fix here — noted because Part 5
needs it.

## Mutation order and filesystem/DB changes, once validation passes

1. `check_backup_conditions` (line 157, and again at lines 262, 479, 554,
   673 — once per domain/database/directory iterated) — **this can
   block indefinitely**. Its body (`func/main.sh:1808-1817`) is a `while
   [ "$la" -ge "$BACKUP_LA_LIMIT" ]; do sleep 60; done` loop against
   `/proc/loadavg`. There is no timeout, no maximum wait, no escape
   hatch. If server load stays above the configured limit, the script
   simply never proceeds. This is the single most important fact this
   review surfaces — see Part 3.
2. Disk-space precheck (lines 179-190): computes `get_user_disk_usage()
   * 2` and compares against free space on `$BACKUP`; on failure, emails
   the user and calls `check_result "$E_LIMIT" ...` — this is genuinely
   **pre-mutation** (nothing has been written to `$tmpdir` or `$BACKUP`
   yet at this point).
3. `tmpdir=$(mktemp -p $BACKUP_TEMP -d)` (line 197) — creates a scratch
   directory. Failure here (`check_result "$E_NOTEXIST" ...`, line 203)
   is also still pre-mutation in the sense that nothing durable/user-
   visible has changed yet (a stray empty tmpdir is not a mutation of
   backed-up state).
4. Lines 206-695: copies Hestia system config, PAM entries, then for
   each of WEB / DNS / MAIL / DB / CRON / arbitrary user directories not
   excluded — creates per-item subdirectories under `$tmpdir`, `cp`s
   config, and for WEB/MAIL/user-dirs, pipes `tar` through `gzip` or
   `pzstd` into `$tmpdir`. For DB, runs `mysqldump`/`pg_dump` (via
   `dump_mysql_database`/`dump_pgsql_database` in `func/db.sh`, not
   re-traced line-by-line here as out of scope for the registry
   contract) then compresses the dump. **None of this touches
   `$USER_DATA` or any live Hestia state yet** — it is all writes into
   the disposable `$tmpdir`.
5. Line 697-699: if `zstd` mode, touch a marker file in `$tmpdir`.
6. Lines 713-723 — **the actual durable mutation begins here**: for
   each configured backend in `$BACKUP_SYSTEM` (comma-separated —
   `local`, `ftp`, `sftp`, `b2`, `rclone`, any subset, any order), calls
   the matching `*_backup` function from `func/backup.sh`. This is where
   `$tmpdir`'s contents actually become a durable, named backup archive.
7. Line 726: `rm -rf $tmpdir` — always runs after the backend loop,
   regardless of per-backend outcome (no per-backend early exit before
   this point for the common `local`/`ftp`/`sftp` paths — see Part 8 for
   the two backends, `b2` and `rclone`, that break this pattern).
8. Lines 758-784 — **Hestia bookkeeping mutation**, strictly after step
   6/7: dedupes then appends a new `BACKUP='...'` line to
   `$USER_DATA/backup.conf`, truncates that file to the last `$BACKUPS`
   entries (`tail -n $BACKUPS ... | mv -f`), `chmod`s the conf and log
   file, removes the just-processed line from `backup.pipe` (line 781 —
   this is redundant with the fact the whole file was already being
   executed as a script by `v-update-sys-queue`, but it's what the
   script does), and calls `update_user_value` to refresh
   `$U_BACKUPS`.
9. Lines 787-798: conditionally emails + creates an in-panel notification
   (only if `notify=yes` AND a log file exists), then unconditionally
   calls `v-log-action` twice and `log_event "$OK" "$ARGUMENTS"`.
10. Line 800: bare `exit` — implicit exit 0.

## Exit codes actually reachable, traced (not name-guessed)

| Exit code | Symbolic name | Where | Pre- or post- the "backup exists somewhere" mutation? |
|---|---|---|---|
| 1 | `E_ARGS` (via `check_args`) | line 141, before any `source_conf`/action | Pre |
| 3 | `E_NOTEXIST` | `is_object_valid` (unknown user), or `mktemp` failure (line 203) | Pre |
| 5 | `E_SUSPENDED` | `is_object_unsuspended`, conditional on policy | Pre |
| 8 | `E_LIMIT` | disk-space precheck (line 189), before `$tmpdir` is even created | Pre — genuinely nothing written anywhere yet |
| 11 | `E_DISABLED` | `is_system_enabled` (BACKUP_SYSTEM unset) or `is_backup_enabled` (BACKUPS<=0 for this user) | Pre |
| 13 | `E_DISK` | `local_backup`'s own disk-limit recheck (`func/backup.sh:32-38`), reached from the backend loop after `$tmpdir` is fully populated | **Post, and destructively so — see below, not "same as E_LIMIT"** |
| 12/15/16 | `E_PARSING`/`E_CONNECT`/`E_FTP` | remote-backend config/connection failures inside `ftp_backup`/`sftp_backup` (`func/backup.sh`) | **Ambiguous — see below** |
| 15 | `E_CONNECT` | `b2_backup`/`rclone_backup` upload failure, via a direct `check_result` call inside those functions (`func/backup.sh:467`, `523`, `539`) | **Definitely post-mutation for `local`, ambiguous for the remote copy itself — see Part 8** |
| 0 | (none — `E_OK`) | line 800, unconditional on the success path | Post — full success |

**The `ftp`/`sftp`/`b2`/`rclone` failure paths do not use `check_result`
uniformly**, and this matters for Part 5: `ftp_backup`/`sftp_backup` set
a local `errorcode` variable and `return` from the function (control
returns to the `for backup_type in ...` loop in `v-backup-user` itself,
lines 715-723), so the script **continues to the next backend and
eventually reaches the summary/bookkeeping section regardless** (lines
727-738 handle this: if `errorcode != 0` and `local` was among the
configured backends, it downgrades to "local succeeded, remote failed"
and keeps going to record the backup; only if `local` was **not** among
the configured backends does it `exit $error_code` — note the variable
name mismatch, `error_code` vs. the `errorcode` actually set by the
backend functions, meaning this branch's `exit $error_code` almost
certainly exits with an **empty/unset value**, i.e. bash's own exit-code
handling for `exit ''`, which is a bug in the underlying script, not
something the adapter can paper over). By contrast, `b2_backup` and
`rclone_backup` call `check_result` **directly**, which per
`func/main.sh:225-237` calls `exit $err_code` immediately — bypassing
the rest of `v-backup-user` entirely, including the bookkeeping section
(step 8 above) that registers the backup in `backup.conf`. **This means:
if `b2`/`rclone` is configured and its upload fails, a `local` backup
that already succeeded in the same run is never registered in
`backup.conf`, even though the archive file itself is sitting in
`$BACKUP` on disk.** This is a genuine, source-verified "the mutation
partially occurred, and the on-disk artifact and the recorded metadata
now disagree" scenario — a real answer to the task's "can mutation
partially occur" question, but not the strongest one traced. That one
is `E_DISK`, immediately below, and it is worse precisely because it
loses data rather than merely failing to record it.

**`local_backup`'s `E_DISK` exit is a destructive partial mutation, not
a pre-mutation rejection — re-checked carefully because the table above
first (incorrectly) classified it as equivalent to `E_LIMIT`.** The
actual order inside `local_backup` (`func/backup.sh:11-38`) is:
(1) lines 13-27, retention rotation — `rm -f` the oldest existing backup
archive(s) once the count at `$BACKUP` reaches `$BACKUPS`; **then**
(2) lines 31-38, the disk-space recheck that can `check_result "$E_DISK"`
→ exit 13; **then, only if that check passes**, (3) line 42, `tar -cf`
the new archive. Rotation happens *before* the disk check, which happens
*before* the new archive is written. A run that reaches this function,
has old backups to rotate, and then fails the disk check exits having
**deleted a prior backup and created no replacement** — strictly fewer
backups than before the call, worse than doing nothing. This is a purely
destructive partial mutation with no compensating artifact anywhere
(unlike the `b2`/`rclone` case above, which at least leaves a usable,
if unrecorded, `local` archive on disk). It is the single strongest
example this review found, and it is entirely internal to
`func/backup.sh` — nothing an adapter registering `backup.create`
against `v-schedule-user-backup` would ever reach (Part 3 establishes
why), but material to Part 3's "why `v-backup-user` isn't ready" case
and worth carrying forward if that mapping is ever revisited.

## Destructive behavior

- **Retention rotation is destructive by design**: `local_backup`
  (`func/backup.sh:11-48`) unconditionally `rm -f`s the oldest backup
  archives once the count at `$BACKUP` reaches `$BACKUPS` (the user's
  configured retention limit), *before* writing the new one (lines
  13-27). The `ftp`/`sftp`/`b2`/`rclone` backends each independently
  perform the equivalent remote-side deletion. This is expected,
  intentional product behavior (bounded retention), not a defect — but
  it means **every successful `backup.create` call, once retention is
  full, deletes prior backups as a side effect**, which is relevant to
  how any future API surface should document this operation's blast
  radius.
- Nothing in `v-backup-user` deletes web/mail/DB/domain data itself —
  the destructive surface is confined to old *backup archives*, never
  the live, backed-up state.

## Idempotency / retry safety

**Not idempotent, and not safely retryable in the "retry = no-op if
already done" sense** — but also not unsafe to retry in the "corrupts
state" sense. Traced concretely:

- Every successful run computes `backup_new_date=$(date +"%Y-%m-%d_%H-%M-%S")`
  (line 709) fresh, and always appends a **new** `BACKUP='...'` entry to
  `backup.conf` (line 772) rather than checking for or overwriting an
  existing one. Calling `v-backup-user $user` twice in a row, both times
  successfully, produces **two distinct backup archives** and two
  `backup.conf` entries (until retention rotation prunes the older one).
- A retry after a **failure** is safe in the sense that nothing is left
  half-registered in `backup.conf` for the failed attempt (the
  bookkeeping section only runs after the mutation section completes, or
  — per the `b2`/`rclone` gap above — is skipped along with everything
  after it), but it is **not free**: a retry re-runs the entire
  tar/gzip/mysqldump pipeline from scratch; there is no checkpointing.
- **What a future API v2 needs to know about retry safety, stated
  plainly**: calling this operation twice is not "the second call does
  nothing" — it is "the second call creates a second, independent
  backup, consuming the same disk-space/bandwidth/time cost as the
  first, and, once retention fills up, causes an *older* backup to be
  rotated away sooner than it otherwise would have been." A caller
  (or a future UI) that retries on timeout without checking whether the
  first attempt actually landed risks silently reducing effective
  retention.
- `v-schedule-user-backup` (Part 2) has a genuinely different, and
  simpler, idempotency story — see below.

## Hestia's multiple backup implementations (task explicitly asked)

Confirmed, by direct source inspection of `bin/`: there are **two
independent, parallel, non-shared backup implementations** —
`v-backup-user` (tar/gzip, arbitrary `local`/`ftp`/`sftp`/`b2`/`rclone`
backends, `func/backup.sh`) and `v-backup-user-restic` (restic-based,
per-user encrypted repo under a single configured `$REPO`, incremental
snapshots, gated by a *different* validation call —
`is_incremental_backup_enabled`, not `is_backup_enabled` — and by
`is_object_unsuspended` unconditionally rather than policy-gated). They
share the same `USER NOTIFY` argument shape and the same
schedule-queue-cron dispatch pattern via their own
`v-schedule-user-backup-restic`, but are otherwise separate code paths
with separate config, separate per-user state (`$USER_DATA/restic.conf`
vs. `$USER_DATA/backup.conf`), and separate list/download/delete
sibling commands (`v-list-user-backup-restic`,
`v-download-backup`/restic equivalents, etc.). **Only the legacy path is
wired into the current PHP UI** (`web/schedule/backup/index.php` calls
`v-schedule-user-backup`, never the `-restic` variant) — confirmed by
grepping all of `web/` for both script names.

---

# Part 2 — `bin/v-schedule-user-backup`: the recommended mapping target (54 lines, read in full)

## Contract

- **Arguments**: `USER` only (`check_args '1' "$#" 'USER'`, line 28). No
  `NOTIFY` argument — the scheduler always queues `v-backup-user $user
  yes` (line 44, `yes` hardcoded), so notification-on-completion is not
  a caller-controlled parameter of *this* script at all.
- User-scoped only, same as `v-backup-user` — no domain argument.

## Validation order (lines 28-36, strictly before the one mutating line)

```
check_args → is_format_valid('user') → is_system_enabled(BACKUP_SYSTEM)
  → is_object_valid('user','USER',$user)
  → is_backup_enabled
  → is_backup_scheduled('backup')
  → check_hestia_demo_mode
```

`is_backup_scheduled` (`func/main.sh:352-360`) is the interesting new
one: `grep " $user " $HESTIA/data/queue/backup.pipe | grep backup` — if
a queue entry already mentions this user and the string `backup`, it
calls `check_result "$E_EXISTS" "$1 is already scheduled"` (exit code
4). **This is the operation's actual idempotency guard**, and it is a
clean, source-verified, pre-mutation check — confirmed by the PHP
caller's own handling: `web/schedule/backup/index.php` lines 20-24
special-case `$return_var == 4` into "An existing backup task is already
running, please wait for it to complete."

**The match is broader than "this user's legacy backup," worth flagging
precisely because it affects the idempotency story**: the second `grep
backup` matches the literal substring `backup` anywhere in the queued
line, and the restic scheduler (`v-schedule-user-backup-restic`) queues
`$BIN/v-backup-user-restic $user yes >> $log 2>&1` — which also contains
`backup`. So a queued restic backup for a user blocks that user's legacy
`v-schedule-user-backup` call (and vice versa), both returning the same
`E_EXISTS`/exit-4 rejection, even though they are two independent
systems with independent config and independent retention. This is a
pre-existing cross-implementation coupling in `is_backup_scheduled`
itself, not something a `backup.create`/`backup.schedule` registry entry
would introduce or could avoid — noted because Part 8 characterizes this
check's idempotency guarantee, and the guarantee is "one backup job of
either kind at a time per user," not "one legacy backup job at a time."

## Mutation (the entire "Action" section, lines 38-44)

Exactly one line: `echo "$BIN/v-backup-user $user yes >> $log 2>&1" >>
$HESTIA/data/queue/backup.pipe`. Nothing else. No filesystem tree
changes, no database access, no service restarts, no subprocess beyond
the `echo`/redirect itself.

## Exit codes, traced

Every verification listed above runs strictly before the one mutating
line — there is **no possible non-zero exit after the queue append
happens**. The script's only success path is `log_event "$OK"
"$ARGUMENTS"; exit` (lines 51-53, implicit 0). This is a substantially
**cleaner** exit-code profile than `v-backup-user`'s: every failure is
unambiguously pre-mutation, and success is unconditional and immediate.

**One source-verified gap, stated for completeness, not treated as a
blocker**: the `echo ... >>` mutation itself is never checked for
success (`$?` is not inspected). If the redirect fails — e.g. `$HESTIA`
filesystem is full or read-only — the script still proceeds to
`log_event "$OK"` and exits 0, claiming success despite the queue entry
never having been written. This mirrors the exact same class of gap
already noted for `v-backup-user`'s bookkeeping step, and is a
pre-existing property of the underlying script, not something this
review is recommending the adapter work around.

## Idempotency / retry safety

**Substantially simpler and safer than `v-backup-user`'s.** Calling
`v-schedule-user-backup $user` while a queue entry for that user's
backup already exists is rejected outright (`E_EXISTS`, exit 4) —
*true* idempotency in the sense that a retry against an
already-scheduled state is a no-op (from the adapter's perspective: a
clean rejection, not a second queued job). Calling it again *after* the
queue has been drained (i.e., after the 5-minute cron cycle has already
run `v-backup-user` and removed the line) behaves like any fresh
request — it queues a new backup, which is the intended, expected
behavior of "the user asked for another backup."

## What this script does NOT do

It does not create a backup. It does not touch `$USER_DATA/backup.conf`.
It does not touch any domain, mail, or database state. Its entire
observable effect is "one more line appended to a shared queue file
that a cron job will eventually execute." This is the fact that makes
it a clean fit for `CommandAdapter`'s synchronous, single-process,
bounded-duration execution model — see Part 3.

---

# Part 3 — Comparison with `domain.create`/`domain.delete`; does `backup.create` expose a new concept?

Answered separately for each candidate mapping, because the answer
differs sharply.

## If mapped to `v-schedule-user-backup` (recommended)

**No new concept.** Side by side:

| | `domain.create` | `domain.delete` | `backup.create` → `v-schedule-user-backup` |
|---|---|---|---|
| Argument arity | 2 required + 4 fixed | 2 required | 1 required, 0 fixed |
| Optional arguments | none (all 4 non-user/domain args are registry-fixed, not caller-optional) | none | none |
| Output model | none (no stdout JSON) | none | none |
| Exit-code behavior | non-zero possible both pre- and post-mutation (`E_RESTART` after) | same | **every non-zero exit is pre-mutation only** — strictly simpler |
| Mutation semantics | single durable write (web.conf + vhost files), verified complete before the one post-mutation exit code | single durable delete | single durable append to a queue file — no fan-out, no filesystem tree changes |
| Locking scope | per-user (`$target["user"]`) | per-user | per-user (`$target["user"]`) — same key, same reasoning |
| Asynchronous behavior | none — the script's own work IS the mutation | none | **the script's mutation is "ask for async work to happen later"** — the async part (Part 1's `v-backup-user`) is a different script this operation never touches |
| Background jobs | n/a | n/a | scheduled, not spawned, by this operation — no adapter awareness of the job needed |
| Progress semantics | n/a | n/a | none — "queued" is the entire signal; polling/progress is `backup.list`'s job (a future, separate operation), not `backup.create`'s |
| Filesystem-heavy behavior | writes ~1 config line + vhost files | deletes a directory tree | **one line appended to one file** — lighter than either domain operation |
| External storage | none | none | none — this script never touches `$BACKUP` or any remote backend at all |
| Long-running execution | sub-second | sub-second | **sub-second** — confirmed by tracing: no loop, no subprocess besides the shell's own redirect |

Every dimension the task asked to check for lands on "same shape as
already-proven operations" or "strictly simpler." `backup.create` mapped
this way is architecturally boring — which is itself the useful finding:
it is further evidence the registry-driven model generalizes, not a
reason to skip the check.

## If mapped to `v-backup-user` directly (not recommended)

**Multiple genuinely new concepts, none of which the current
`CommandAdapter` can represent without changes:**

1. **Unbounded blocking inside the adapter's own request.**
   `check_backup_conditions`'s load-average `while ...; sleep 60; done`
   loop (Part 1) has no timeout. `CommandAdapter`'s `ProcessRunnerInterface`
   has no timeout mechanism today (confirmed: `ProcOpenProcessRunner`
   simply waits for the process to exit) — a `proc_open()`'d
   `v-backup-user` call under load would hold the per-user lock and the
   PHP request thread open indefinitely. This is not a hypothetical:
   Part 1 traced the exact loop.
2. **No representable "job" concept.** A backup that takes several
   minutes (tar + mysqldump + remote upload of a user's entire hosting
   footprint) run synchronously inside an HTTP-request-driven
   `proc_open()` call is a fundamentally different execution shape than
   `domain.create`/`domain.delete`'s sub-second scripts. `AdapterResult`
   has no notion of "accepted, still running" distinct from "completed" —
   every existing operation's result is the final result.
3. **Fan-out the adapter has never modeled.** `v-backup-user` iterates
   an internally-determined list of domains/mail accounts/databases
   (Part 1, lines 249-397 etc.) — the registry's `argument_order`/
   `parameters` model has no way to expose or validate that internal
   fan-out; today's registry entries all describe a single, flat target.
4. **Ambiguous partial-mutation exit codes** that Part 1 traced in
   detail (the `b2`/`rclone` `check_result`-triggered early exit after a
   `local` backup already succeeded) — a real "the recorded state and
   the on-disk state disagree" scenario, which is a materially harder
   case than anything `domain.create`/`domain.delete` produced, and
   would need its own dedicated analysis before any
   `known_post_mutation_exit_codes` declaration could be made in good
   conscience (see Part 5).

None of this is a reason `backup.create` "can't" eventually reach
`v-backup-user`'s work — it's a reason that mapping needs a job/async
model this document is explicitly told not to design (task Part 9
instruction: don't design future features now). It is a reason to
recommend the other mapping today.

---

# Part 4 — Locking

Evaluated against the `v-schedule-user-backup` mapping (the recommended
one) — Part 3 already established the `v-backup-user` mapping isn't
ready regardless of locking.

- **Does `backup.create` mutate the same user-scoped state as
  `domain.create`/`domain.delete`?** No — it mutates a shared,
  system-wide file (`$HESTIA/data/queue/backup.pipe`), not any
  per-user `$USER_DATA/*.conf` file. This is a different resource than
  what the per-user lock currently protects for domain operations
  (`web.conf`/vhost files), but the *locking key* the design already
  uses — `$target["user"]` — is still the right serialization boundary:
  the resource being protected is "has this user already got a backup
  queued," which is inherently a per-user question even though the
  storage happens to be a shared file.
- **Could `backup.create` race with `domain.create`/`domain.delete`?**
  No observable conflict found: `v-schedule-user-backup` never reads or
  writes anything `domain.create`/`domain.delete` touch
  (`web.conf`, vhost files, `dns.conf`, etc.), and vice versa. Different
  users' backup-queue entries and domain mutations are already
  independent; a same-user domain mutation running concurrently with a
  same-user backup schedule is not observed to corrupt anything — worst
  case, per Part 1's own "not idempotent" analysis, the eventual backup
  captures the domain in whichever state it happened to be in when
  `v-backup-user` actually ran (5 minutes later, decoupled from this
  operation entirely).
- **Could two `backup.create` calls for the same user race?** **Yes —
  a real, source-verified TOCTOU window**, already present in the
  system today, independent of any adapter: `is_backup_scheduled`
  (the `grep`-based check) and the `echo >>` append (the mutation) are
  two separate operations with no lock between them anywhere in
  `v-schedule-user-backup` or `func/main.sh`. Two near-simultaneous
  calls could both pass the "not already scheduled" check before either
  appends, resulting in two queued lines for the same user — which the
  cron-drained queue would then execute back to back (two full backup
  runs, per Part 1's "not idempotent" findings: two archives, not
  corruption, but wasted work and accelerated retention rotation).
- **Could `backup.create` for user A interfere with user B?** No —
  `is_backup_scheduled`'s grep is scoped to `" $user "`
  (space-delimited, matching only that user's own queue lines), and the
  append itself is a single `echo >>` write, well under `PIPE_BUF`, so
  concurrent appends from different users' calls do not corrupt each
  other's lines (POSIX `O_APPEND` semantics guarantee atomicity for
  writes under `PIPE_BUF`, and a single queue line is far smaller than
  that). This holds regardless of what lock scope the adapter uses.
- **Does the command itself already serialize anything?** No — as shown
  above, `is_backup_scheduled` + the append is check-then-act with no
  lock in between, at the Hestia script level.
- **Is the existing per-user lock sufficient?** **Yes.** Applying
  `CommandAdapter`'s existing per-user `LockManager` (keyed on
  `$target["user"]`, exactly as `domain.create`/`domain.delete` already
  do) to `backup.create` would **close** the TOCTOU race identified
  above — for adapter-routed calls specifically, which is the same
  "adapter-routed, not system-wide" caveat every prior locking
  discussion in this series has already carried (per
  `ADAPTER_ARCHITECTURE_CHECKPOINT.md`/`DOMAIN_DELETE_IMPLEMENTATION.md`
  Section 9: legacy, non-adapter-routed callers — i.e. the current PHP
  UI calling `v-schedule-user-backup` directly via `exec()` — remain
  unprotected). No different lock scope is required; this is a
  strict improvement over today's unprotected direct-CLI path, not a
  gap the adapter would introduce.
- **Is a different lock scope required?** No — same per-user key as
  already implemented, no new `LockManager` concept needed. (Not
  modified here, per instruction — analysis only.)

---

# Part 5 — Mutation state

Evaluated against `v-schedule-user-backup` (the recommended mapping).

**No `known_post_mutation_exit_codes` should be declared for this
operation**, and the reasoning is the opposite of `domain.create`/
`domain.delete`'s: those two each have exactly one exit code
(`E_RESTART`) that is source-verified to occur *only* after the mutation
is durably complete. `v-schedule-user-backup`, by contrast, has **no
exit code that occurs after its mutation at all** — Part 2 already
traced this: `is_backup_scheduled` and every other verification run
strictly before the single `echo >>` line, and the only code path after
that line is the unconditional `log_event "$OK"; exit` (implicit 0).
There is no non-zero exit reachable post-mutation to declare as
"known post-mutation" — the field would have nothing correct to contain.

This means `backup.create`'s `mutation_state` behaves exactly like the
`unknown`-only baseline every registry entry gets by default: `confirmed`
on exit 0, `unknown` on any non-zero exit (which, per the trace above,
will in practice always mean "the request was rejected before the queue
append happened" — the classification is *correct* even though it's the
less-specific of the two labels, because there is no more-specific truth
to express here). Declaring the field with an empty array, or omitting
it entirely, are behaviorally identical (per
`CommandRegistryValidationTest::testEmptyListAccepted`,
already covered) — omitting it is preferred as the simpler, more
honest choice: it's not "we checked and there's nothing," it's "there is
structurally nothing to declare for this operation."

**A naming/semantics concern that deserves stating plainly, not buried**:
for `domain.create`, `mutation_state: confirmed` means the domain now
exists. For this mapping, `confirmed` means only "a line was appended to
a queue file" — no backup exists yet, will not exist for up to five
minutes (the cron interval), and will not appear in a future
`backup.list` until the queue actually drains and `v-backup-user`
finishes. The four-state model this series built exists specifically so
`mutation_state` tells the truth about whether the requested mutation
happened; for this registry entry, `confirmed` truthfully describes
*scheduling*, not backup creation, and a caller that reads `confirmed`
as "my backup now exists" would be wrong. This is not a defect in the
classification (it is exactly correct for what the script actually
does) — it is a naming question for the *operation itself*. **This
review recommends the operation be named `backup.schedule`, not
`backup.create`**, so the name matches what `mutation_state: confirmed`
actually asserts; `backup.create` should be reserved for whatever
operation eventually maps to the backup actually existing (either
`v-backup-user` directly, once Part 3's blocking gaps are closed, or a
future polling/webhook-driven completion signal layered on top of this
scheduling call). This is a registry-contract/naming recommendation,
not an implementation — the remaining sections of this document keep
using `backup.create` only because that is the name the task specified;
Part 10's registry design should be read as applying to whichever name
is ultimately chosen.

**Explicitly not evaluated here, and flagged as needing its own,
separate design pass if `v-backup-user` itself is ever registered**: the
`b2`/`rclone` partial-mutation gap traced in Part 1 (`check_result`
short-circuiting past the bookkeeping step after a `local` backup
already landed). That is a real candidate for a *future*
`confirmed_degraded`-shaped classification, but it involves a
genuinely ambiguous case (the archive exists on disk; the metadata that
would let `backup.list` discover it does not) that deserves dedicated,
skeptical analysis, not a default answer inherited from this document.

---

# Part 6 — Authorization target

**`target.user`**, verified from source, same shape as
`domain.create`/`domain.delete`:

- `v-schedule-user-backup`'s only argument is `USER` (`user=$1`, line
  14); `v-backup-user`'s first argument is likewise `USER` (line 14).
  Neither script takes a domain, database, or any other
  sub-resource identifier — the entire operation is scoped to one
  Hestia user account.
- `is_object_valid('user', 'USER', $user)` (both scripts) is the
  existing shape/existence check already reused by every prior
  operation via `ParameterValidator` — no new validator needed.

**Is `actor.user == target.user` sufficient for ordinary self-service?**
Source supports yes, for the same reason it already holds for
`domain.create`/`domain.delete`: nothing in either script consults
`$actor` at all (it doesn't exist as a concept at the Hestia CLI layer —
confirmed, this is CommandAdapter's own construct, not something
`v-schedule-user-backup` receives or checks). The question this review
was asked to verify, not design, is answered identically to the prior
two operations: a self-service policy comparing `actor.user` to
`target.user` is a coherent, minimal starting point *for the
authorization seam that already exists* (`AuthorizerInterface`,
`MUTATION_AND_AUTHORIZATION_DESIGN.md`) — but, per that document's own
scope boundary, no actual policy is designed or implemented here. The
default remains `AllowAllAuthorizer` until a real policy is supplied,
exactly as it does for the two operations already registered.

---

# Part 7 — Result / output model

**`v-schedule-user-backup`** (recommended mapping): no stdout output on
success at all (traced: the only output-producing lines in the entire
script are the `check_args` usage-string echo on the argument-count
failure path, and whatever `check_result` itself prints via `echo
"Error: $2"` on any verification failure — nothing on the success path).
No JSON, no structured payload, nothing to parse. This is the **same
shape** `domain.create`/`domain.delete` already have — no
`output_format`/`result_shape` field is needed, matching those two
entries' precedent exactly. `AdapterResult`'s existing model (status,
exit code, stdout/stderr passthrough, no `parsedOutput`) is sufficient
as-is; **no missing abstraction identified.**

**`v-backup-user`** (if ever mapped directly): stdout is a
human-readable, timestamped progress log (the `tee -a $BACKUP/$user.log`
lines throughout Part 1's trace) — not structured, not intended for
machine parsing, and interleaved with per-domain/per-database
processing that has no natural "one JSON object" shape. If this script
is ever registered, `AdapterResult`'s current `parsedOutput`/
`result_shape` model would not represent it meaningfully as-is — but
this is moot given Part 3's finding that this mapping isn't ready for
other, more fundamental reasons first.

---

# Part 8 — Retry / idempotency (summary; full trace in Parts 1 and 2)

- **`v-schedule-user-backup` (recommended mapping)**: calling it twice
  while a queue entry for that user already exists is a clean rejection
  (`E_EXISTS`, exit 4) — **idempotent in the practically useful sense**:
  a caller that retries on a transient failure or a UI double-click will
  either queue exactly one backup or get a clear, already-distinguished
  "already scheduled" error, never a silent duplicate (modulo the
  TOCTOU race in Part 4, which the adapter's existing per-user lock
  closes for adapter-routed calls). Calling it again after the
  previously-queued backup has already been drained and run is
  indistinguishable from a fresh request, which is correct — the user
  is asking for another backup, and gets one.
- **`v-backup-user` (the actual worker, not directly mapped)**: **not**
  idempotent — every successful run creates a new, timestamped archive
  and `backup.conf` entry; retrying after success creates a second,
  independent backup, not a no-op. Retrying after failure is safe from a
  bookkeeping-corruption standpoint (nothing is half-registered) but not
  free (full re-run, no checkpointing) — and, per Part 1's `b2`/`rclone`
  finding, a failure partway through a multi-backend run can leave a
  `local` archive on disk that is never recorded, which a retry would
  not clean up or reconcile on its own.
- **What API v2 needs to know, stated for the record**: retry safety for
  "schedule a backup" and "perform a backup" are two different
  guarantees. The former is genuinely safe to retry blindly (worst case:
  a clear, already-distinguished rejection). The latter is not — a
  retry policy for that operation, whenever it's designed, needs to
  account for duplicate-archive creation and the partial-mutation gap
  identified in Part 1, not just treat non-zero exit as "safe to retry."

---

# Part 9 — Cloud/S3 relevance

Strictly separated, as instructed, into source facts and future product
implications. No feature design here.

## Facts from source

- `$BACKUP_SYSTEM` (from `hestia.conf`) is a **comma-separated list**,
  not a single choice — `v-backup-user`'s backend loop (Part 1, lines
  715-723) runs `local`, `ftp`, `sftp`, `b2`, `rclone`, any subset,
  every configured one, every run. Multi-destination backup already
  exists as a first-class concept in the legacy implementation.
- `rclone` is a real, already-integrated backend
  (`rclone_backup`/`rclone_download`/`rclone_delete`, `func/backup.sh`
  lines 510-579), configured via `$HESTIA/conf/rclone.backup.conf`
  (`HOST`/`BPATH`) and driven with plain `rclone copy`/`rclone lsf`/
  `rclone deletefile` calls against whatever remote `rclone` has been
  configured to talk to at the OS level — the Hestia-side code itself is
  backend-agnostic and does not care which storage protocol is behind
  `$HOST`.
- **All remote-backend configuration is system-wide, not per-user or
  per-tenant.** `ftp_backup`/`sftp_backup`/`b2_backup`/`rclone_backup`
  each `source_conf` a single file under `$HESTIA/conf/` (e.g.
  `ftp.backup.conf`, `b2.backup.conf`) — confirmed by direct read of
  `func/backup.sh`. There is no per-user or per-account remote-storage
  credential model anywhere in this code path. Every user on the
  server, if `BACKUP_SYSTEM` includes a remote backend, backs up to the
  *same* configured remote destination (distinguished only by
  per-user filename prefixes, e.g. `$user.$backup_new_date.tar`).
- The restic-based implementation (`v-backup-user-restic`, Part 1's
  closing note) uses a **single, server-wide `$REPO`** (from
  `$HESTIA/conf/restic.conf`, line 52) with **per-user subpaths**
  (`"${REPO%/}/$user"`, line 58) and a **per-user encryption key**
  (`$USER_DATA/restic.conf`, generated fresh per user, line 55) — this
  is architecturally closer to a per-tenant model than the legacy
  system-wide-config backends, but still shares one repo location
  across all users on a server, not one location per user/account.

## Future product implications (explicitly not designed here)

- `rclone` (the external tool, not anything in this source tree) is
  widely known to support S3 and S3-compatible remotes as one of its
  many backend types. If that holds for whatever `rclone` version and
  configuration a given server has installed, S3-shaped remote storage
  may already be reachable today through the existing `rclone` backend
  without any new Hestia-side code — but that is a claim about `rclone`
  itself, not something this document verified from this repository's
  source, and should be confirmed against `rclone`'s own docs/config
  before being relied on.
- A "Cloud Account" / per-tenant remote-storage model, if built, cannot
  reuse the legacy `ftp`/`sftp`/`b2`/`rclone` backends' configuration
  layer as-is — that layer is fundamentally single-tenant
  (system-wide config file per backend type). It would need either a
  new, per-account credential/destination model layered above these
  scripts, or a different backend entirely.
- The restic implementation's per-user-subpath-under-one-repo pattern is
  a closer starting point for a multi-tenant story than the legacy
  backends are, but "closer" is not "sufficient" — evaluating whether it
  actually meets a Cloud Account model's isolation/security requirements
  is out of scope for this document and would need its own review.
- Marketplace/extension-provided backup destinations are not
  representable by anything traced in this document — nothing here
  should be read as evidence for or against that direction.

---

# Part 10 — Final Verdict

# SOURCE CONTRACT

**`bin/v-schedule-user-backup`** (the recommended mapping target):
- Arguments: `USER` (required, positional 1). No optional arguments.
- Validation, strictly pre-mutation: `check_args` → `is_format_valid` →
  `is_system_enabled(BACKUP_SYSTEM)` → `is_object_valid(user)` →
  `is_backup_enabled` → `is_backup_scheduled` → `check_hestia_demo_mode`.
- Mutation: exactly one line, `echo "<worker-invocation> >> <log>" >>
  $HESTIA/data/queue/backup.pipe`. No filesystem tree changes beyond
  this single append. No database/config mutation of any per-user
  `.conf` file. No service restarts.
- Exit codes: `E_ARGS`(1)/`E_NOTEXIST`(3, unknown user)/`E_DISABLED`(11,
  feature off)/`E_EXISTS`(4, already scheduled) — all pre-mutation, all
  already-known symbolic names, no new ones. Success is unconditional
  exit 0 with no further failure surface after the mutating line.
- Not destructive. Not idempotent in the "second call is a no-op" sense,
  but effectively idempotent in the "second call is either a fresh,
  correct request or a clean, already-distinguished rejection" sense,
  because of `is_backup_scheduled`'s pre-mutation guard.
- User-scoped only; no domain parameter, no fan-out visible at this
  script's level (the fan-out happens later, inside the worker this
  script merely schedules).
- Neither this script nor its worker knows about "local vs. remote vs.
  S3" as a caller-facing choice — that is entirely a server-wide
  `hestia.conf`/`*.backup.conf` setting, invisible to this operation's
  contract.

(`bin/v-backup-user`, the actual worker, has a far more complex and
currently-not-ready-to-register contract — see Part 1/Part 3 in full;
not restated here because it is not the recommended registration
target.)

# REGISTRY DESIGN

Design only — not implemented. Proposed `backup.create` entry, mapped to
`v-schedule-user-backup`:

```
"backup.create" => [
    "script" => "v-schedule-user-backup",
    "argument_order" => ["user"],
    "parameters" => [
        "user" => ["type" => "username", "required" => true],
    ],
    "fixed_parameters" => [],
    "mutation" => ["kind" => "create"],
    // no known_post_mutation_exit_codes — see MUTATION MODEL below
],
```

No `output_format`/`result_shape` needed (matches `domain.create`/
`domain.delete`'s precedent — no stdout to parse on success). No fixed
parameters needed at all — this is the first mutating operation examined
in this series with a single required argument and zero registry-fixed
ones, simpler than either prior entry.

# LOCKING

**Current per-user locking is sufficient.** No new lock scope needed.
Applying it (keyed on `$target["user"]`, identical to
`domain.create`/`domain.delete`) closes a real, source-verified TOCTOU
race in `is_backup_scheduled` that exists in the system today,
independent of the adapter — a net improvement, not a new requirement
the operation imposes. `LockManager` itself needs no changes.

# MUTATION MODEL

**Leave `known_post_mutation_exit_codes` absent.** Traced in full: every
non-zero exit `v-schedule-user-backup` can produce occurs strictly
before its one mutating line; there is no post-mutation exit code to
declare. Default `unknown`-on-any-nonzero-exit behavior is already
correct for this operation — not a gap, a match.

# AUTHORIZATION TARGET

**`target.user`**, verified from source (`v-schedule-user-backup`'s sole
argument). `actor.user == target.user` is a coherent basis for a future
self-service policy, consistent with `domain.create`/`domain.delete`'s
existing precedent — no policy designed or implemented here; the
existing `AllowAllAuthorizer` default applies unchanged if/when this
operation is registered.

# RESULT MODEL

**`AdapterResult` is already sufficient, unchanged.** No stdout to
parse on success, no new status needed, no new field needed — identical
shape to `domain.create`/`domain.delete`.

# RETRY SAFETY

Effectively idempotent for adapter purposes: a retry either queues
exactly one backup or receives a clean, already-distinguished `E_EXISTS`
rejection (exit 4), never a silent duplicate queue entry — provided the
adapter's existing per-user lock is applied, which closes the one real
race identified (Part 4). This is a materially better retry story than
`v-backup-user` itself has (Part 8) — another point in favor of mapping
`backup.create` to the scheduler, not the worker.

# NEW ABSTRACTIONS

**None required for the recommended mapping.** `backup.create` →
`v-schedule-user-backup` fits the existing registry/argument/
locking/authorization/result model exactly as `domain.create`/
`domain.delete` already do — confirmed dimension-by-dimension in
Part 3's comparison table.

(Listed for completeness, not as requirements of *this* operation: if
`v-backup-user` — the actual worker — is ever proposed as a direct
registry entry in a future review, that mapping would expose at least
two abstractions the current architecture genuinely lacks — an
execution-timeout/cancellation mechanism for `ProcessRunnerInterface`
(to bound `check_backup_conditions`'s unbounded load-average wait), and
some notion of an asynchronous "job" result distinct from
`AdapterResult`'s current always-final-result model, needed for
fan-out, multi-minute work. Neither is needed for `backup.create` as
recommended here, and neither should be built speculatively now.)

# VERDICT

For `bin/v-backup-user` — the script this review was asked to
investigate as the `backup.create` candidate — the verdict is
**BLOCKED**. Not on missing evidence: the source was traced in full
(Part 1). It is blocked on missing architecture — an
execution-timeout/cancellation mechanism for `ProcessRunnerInterface`
(`check_backup_conditions`'s unbounded load-average wait, Part 3 item 1)
and an asynchronous job/result model (Part 3 items 2-4) — neither of
which exists in `CommandAdapter` today, and neither of which this
document was asked to design.

This review's recommendation, however, is **READY FOR IMPLEMENTATION**
— specifically, and only, for the same product capability ("create a
backup now") mapped instead to `bin/v-schedule-user-backup`, which is
what the real UI already calls today (Part 0). The architecture that
survived `domain.create` and `domain.delete` absorbs that mapping with
zero special-case logic, using the exact same registry shape, locking
mechanism, authorization seam, and result model already in place —
subject to the naming question raised in Part 5 (`backup.schedule` vs.
`backup.create`, since `mutation_state: confirmed` for this mapping
means "queued," not "backup exists").
