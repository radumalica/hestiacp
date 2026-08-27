# API v2 — Audit Logging Production Provisioning & Rotation (Sprint 7)

## 1. Sprint scope

Make Sprint 6's audit logging actually operational in production: provision
`/usr/local/hestia/data/api-v2-audit/` on fresh installs and upgrades
(correct ownership, permissions, idempotency), and establish a minimal,
safe rotation/retention mechanism. `AuditEvent`/`FileAuditLogger` are
touched only if a concrete defect is found during the mandated §9
review (none was — see §16 below); no new audit fields, no API/auth/
rate-limiter changes.

## 2. Existing installer conventions investigated

Read directly from source before writing anything:

- **`install/hst-install-ubuntu.sh`/`install/hst-install-debian.sh`**
  (identical in the relevant sections): a single `mkdir -p` line creates
  `$HESTIA/conf`, `$HESTIA/data/{ips,queue,users,firewall,sessions,
  adapter-locks,api-credentials}` together; `chmod` is applied to each
  immediately after (mode differs per directory: `750` for
  root-owned/read-only-ish dirs, `770` for `adapter-locks`/`sessions`,
  `2750` for `api-credentials`); `chown` to `hestiaweb`/`root:hestiaweb`
  happens **later**, after `systemctl start hestia` — i.e. only once the
  `hestiaweb` user (created earlier via `useradd hestiaweb`) definitely
  exists.
- **`$HESTIA/log`** (`/var/log/hestia`, a symlink) is created
  `mkdir -p` as root with `chmod 750` and **no `chown` to `hestiaweb`
  anywhere** — confirmed by searching the entire installer for
  `chown.*log` / `chown.*var/log/hestia`: zero matches. This reconfirms
  Sprint 6's own finding: the web process (`hestiaweb`) cannot write
  there.
- **`/usr/local/hestia/php/etc/php-fpm.conf`** (`src/deb/php/php-fpm.conf`
  in this repo) — read directly to confirm, rather than assume, which
  system identity actually executes API v2's PHP code:
  `user = hestiaweb`, `group = hestiaweb`. This is Hestia's own
  dedicated internal php-fpm pool (port 8083 panel), separate from the
  system-wide `www-data`/`hestiamail` pool used for hosted user sites
  (`install/deb/php-fpm/www.conf`, `user = hestiamail`) — confirming
  `hestiaweb` (not `www-data`, not `hestiamail`) is the correct runtime
  identity for this directory.
- **`install/upgrade/versions/1.10.0.sh`** — the current, actively
  maintained upgrade script (confirmed via `git log --oneline -- 
  install/upgrade/versions/1.10.0.sh`: its most recent entries are this
  same project's own prior commits, most recently `995374955 feat:
  Implement Access Key Management System`, which is what added the
  existing `$HESTIA/data/api-credentials` provisioning block this
  sprint's own block mirrors). It already contains exactly the pattern
  this sprint needs: an idempotent `mkdir -p` + `chown` + `chmod` block,
  safe to re-run on every upgrade.
- **`install/deb/logrotate/hestia`** — copied to `/etc/logrotate.d/hestia`
  by **both** the fresh-install path (`cp -f
  $HESTIA_INSTALL_DIR/logrotate/hestia /etc/logrotate.d/hestia`) and the
  upgrade path (`1.10.0.sh`'s own "Updating logrotate conf for Hestia"
  step, itself added by an earlier, unrelated commit — `ed2196916 fix:
  update Hestia logrotate configuration during upgrade`). One existing
  stanza rotates `/var/log/hestia/*.log` monthly, keeping 12, with a
  `postrotate` that restarts `hestia.service`. `$HESTIA_INSTALL_DIR` is
  `$HESTIA/install/deb` on **both** Ubuntu and Debian — confirmed there
  is only one logrotate source file to edit, not two.
- No RPM installer exists in this repository (`install/hst-install-*.sh`
  lists only `-ubuntu.sh`/`-debian.sh`/`.sh` itself) — Debian/Ubuntu are
  the only supported install targets, both updated identically.
- No cron-based or daemon-based log-management mechanism was found
  anywhere for `$HESTIA/log`; `logrotate` (a standard, already-relied-on
  system package, not a new dependency) is the sole existing mechanism.

## 3. Production directory

`/usr/local/hestia/data/api-v2-audit/` — matches
`FileAuditLogger::DEFAULT_AUDIT_DIRECTORY`, unchanged from Sprint 6.
Sibling of `$HESTIA/data/adapter-locks/`. Not under `$HESTIA/log`
(root-only, confirmed §2) and not a newly-invented location.

## 4. Ownership

`hestiaweb:hestiaweb` (set only in the post-`systemctl start hestia`
`chown` block, once that user exists — mirrors `adapter-locks` exactly,
confirmed the correct runtime identity in §2). **Not**
`root:hestiaweb`/setgid like `api-credentials` — that pattern exists
specifically because `api-credentials` is written by root-run
`bin/v-add-api-credential`/`bin/v-delete-api-credential` *and* read by
`hestiaweb`; the audit directory has exactly one writer and one reader,
both the same identity (`hestiaweb`, via `FileAuditLogger`), so there is
no cross-identity access requirement to design for. The task's own
instruction ("do NOT blindly copy permissions from api-credentials...
determine the minimum required access from the actual PHP/API execution
context") is why this sprint does not reuse that shape.

## 5. Permissions

Directory: `0700` (owner-only — **not** `0770` like `adapter-locks`).
`adapter-locks`'s `770` grants read/write to the entire `hestiaweb`
*group*, which is an acceptable exposure for lock files (they reveal
nothing beyond which usernames currently hold a lock). Audit records are
explicitly different: the task's own scope statement says they "contain
security-sensitive metadata and potentially user/domain/database
identifiers." There is no legitimate second reader in the `hestiaweb`
group for this directory, so `0700` is the actual minimum required
access, not merely a more-cautious default.

File (`audit.log`): `0600`, unchanged from Sprint 6's own
`FileAuditLogger::write()` (`chmod 0600` on first creation) and now also
explicitly the mode the rotation stanza's `create` directive applies
(§9) — both paths agree.

**Symlink handling**: not specially guarded, and this sprint concluded
that is correct, not an oversight — see §13.

## 6. Audit file lifecycle

**Created lazily, on first write — not pre-created by the installer.**
Decision, not a default-of-convenience (the task explicitly forbids
choosing this "merely for convenience"):

- `FileAuditLogger::write()` already opens with `fopen($path, "ab")`
  (create-if-missing) and already `chmod`s to `0600` on first creation
  (Sprint 6, unchanged this sprint) — the behavior this sprint needs
  already exists.
- Pre-creating it in the installer would require the installer to also
  set ownership/mode on that *file* (not just the directory), doubling
  the surface that must stay in sync with `FileAuditLogger`'s own
  `0600` constant, for zero benefit — the directory's `0700` mode is
  already sufficient for `hestiaweb` to create the file itself, and
  it's the same identity either way.
- Race-safety (§9 of the task): `fopen(..., "ab")`'s underlying
  `open(O_CREAT|O_APPEND)` is atomic at the OS level for creation; two
  PHP-FPM worker processes racing to create the file on their first
  concurrent write can each safely proceed (`flock(LOCK_EX)` inside
  `write()` — unchanged since Sprint 5/6 — still fully serializes the
  actual appends, so no interleaved/partial line is possible either
  way). The narrow, genuinely racy part — both processes computing
  `$isNewFile = !file_exists($this->path)` as `true` and both later
  calling `chmod(0600)` — is idempotent and harmless (chmod-ing an
  already-`0600` file twice changes nothing). **Crucially, this race
  can never become a security gap**: the containing directory is
  already `0700`, owned solely by `hestiaweb` — no other local user can
  read the file regardless of what its own mode briefly is between
  creation and the `chmod` call, because they cannot traverse into the
  directory at all. This is the concrete reasoning that makes lazy
  creation race-safe, not merely "probably fine."

## 7. Fresh-install provisioning

`install/hst-install-ubuntu.sh` and `install/hst-install-debian.sh`
(identical edits in both, confirmed the pre-existing `adapter-locks`/
`api-credentials` blocks are themselves already duplicated verbatim
across both files — this is this repository's own established, accepted
pattern, not something this sprint introduces):

1. `$HESTIA/data/api-v2-audit` added to the existing multi-line
   `mkdir -p` command (alongside `adapter-locks`/`api-credentials`).
2. `chmod 700 $HESTIA/data/api-v2-audit` added immediately after the
   existing `adapter-locks`/`api-credentials` `chmod` lines, each with
   an explanatory comment matching that section's own established
   comment style.
3. `chown hestiaweb:hestiaweb $HESTIA/data/api-v2-audit` added to the
   existing post-`systemctl start hestia` `chown` block, immediately
   after the `adapter-locks`/`api-credentials` lines.

Runs exactly once per fresh install, identically to every neighboring
directory it was added alongside — no new behavior pattern introduced.

## 8. Upgrade provisioning

`install/upgrade/versions/1.10.0.sh` — a new block added immediately
after the existing `api-credentials` provisioning block, same shape:

```bash
echo "[ * ] Creating API v2 audit log directory"
mkdir -p "$HESTIA/data/api-v2-audit"
chown hestiaweb:hestiaweb "$HESTIA/data/api-v2-audit"
chmod 700 "$HESTIA/data/api-v2-audit"
```

**Idempotency**: `mkdir -p` is a no-op if the directory already exists
(never errors, never truncates); `chown`/`chmod` unconditionally
re-apply the same target state every time, which is simultaneously (a)
the first-time provisioning step for an installation that predates this
sprint and (b) a self-healing repair step for an installation where
ownership/permissions were ever altered — exactly mirroring how
`api-credentials`'s own block already serves both purposes. Running
`1.10.0.sh` any number of times produces the same final state; existing
files inside the directory (i.e. `audit.log` and its content) are never
touched by `mkdir -p`/`chown`/`chmod` — verified empirically by
`AuditProvisioningTest::testProvisionPreservesExistingAuditLog`/
`testRepeatedProvisioningNeverLosesRecords` (§14) against the identical
sequence of operations.

## 9. Rotation mechanism

A second `logrotate` stanza added to the **same**, already-wired
`install/deb/logrotate/hestia` file (copied to `/etc/logrotate.d/hestia`
by both the fresh-install and upgrade paths already, per §2 — this
sprint needed zero new copy-wiring for that reason):

```
/usr/local/hestia/data/api-v2-audit/*.log {
    rotate 12
    monthly
    missingok
    notifempty
    create 0600 hestiaweb hestiaweb
}
```

Deliberately a **separate** stanza from `/var/log/hestia/*.log`, not
merged into it: different path, different owner (`hestiaweb`, not
`root`), and — most importantly — **no `postrotate` block**. The
existing stanza's `postrotate` restarts `hestia.service` because
`system.log`/`nginx-error.log`/etc. are written by long-running
processes that hold a file descriptor open across the rotation and need
a reload signal to reopen the (now-renamed) path. `FileAuditLogger`
opens and closes `audit.log` **once per write** (unchanged since Sprint
6) — there is no long-lived handle to invalidate, so a bare
rename-then-recreate rotation (logrotate's `create` directive: rename
the current file aside, create a fresh, correctly-permissioned empty
one) is safe with nothing to signal. Verified against the actual
rename/create sequence by
`AuditProvisioningTest::testSimulatedRotationPreservesBothFiles`/
`testRotatedFileHasSecurePermissions` (§14).

Syntax-verified with the real `logrotate` binary
(`logrotate -d install/deb/logrotate/hestia`): the file parses
correctly; the only reported error (`unknown user 'hestiaweb'`) is
purely environmental (this test/dev sandbox has no `hestiaweb` system
user) and does not indicate a configuration mistake — the pre-existing
first stanza parsed and evaluated correctly in the same run, confirming
this sprint's addition does not break it.

## 10. Retention policy

**Reuses the repository's own existing convention** — the task
explicitly requires this over guessing a compliance period, and one
already exists: `monthly` / `rotate 12` (~12 months), the exact same
values `/var/log/hestia/*.log`'s own pre-existing stanza already uses.
This is an **operational default**, not a legal/compliance retention
requirement — nothing in this repository documents the latter for any
existing log, and this sprint does not invent one for the audit log
either. No archival, no external export — both explicitly out of scope
per the task's own non-goals list.

## 11. Concurrency behavior

Unchanged from Sprint 6 (`FileAuditLogger` was not modified — §16):
`fopen("ab")` → `flock(LOCK_EX)` → `fwrite()` → `flock(LOCK_UN)` →
`fclose()` per write, still concurrency-safe. This sprint adds no new
concurrency concern — rotation only ever happens via `mv`/`create`
semantics (§9), which do not run concurrently with a PHP write in any
way that could interleave with `flock`'s own critical section
(`rename()` on the same filesystem is atomic with respect to any
process's already-open file descriptor; a PHP process with the old
inode already open via `fopen()` keeps writing to that same, now-unlinked-by-name
inode until it closes it, which happens at the end of that one
`write()` call — the very next `write()` call's fresh `fopen()` picks up
the newly-created file by name, exactly as intended).

## 12. Failure behavior

Unchanged from Sprint 6: fail-open, unconditionally (§13 of the Sprint 6
doc, re-verified intact by re-running the full `AuditLoggerTest` suite
this sprint — §15 below). Provisioning failure (e.g. an operator runs
neither the installer nor `1.10.0.sh`) simply means the directory
doesn't exist, `FileAuditLogger` throws `AuditWriteException` on every
write, and `ExecuteRequestHandler`'s existing fail-open handling
discards it silently — exactly Sprint 6's own already-documented,
already-tested "inert until provisioned" behavior, now finally resolved
for real deployments by this sprint's provisioning work.

## 13. Security properties

- **Minimum-access permissions**: `0700`/`hestiaweb:hestiaweb` on the
  directory, `0600` on the file — no group or world access anywhere in
  the chain (§4/§5).
- **No arbitrary user can replace the directory or file**: only
  `hestiaweb` (and root) has any access to
  `/usr/local/hestia/data/api-v2-audit/` at all; a non-`hestiaweb`,
  non-root local user cannot create, rename, or delete anything inside
  it, `0700` on the parent directory being the actual enforcement point
  (Unix directory permissions govern create/rename/unlink of entries
  within it, independent of any individual file's own mode).
- **Symlink following was deliberately not specially guarded.** `fopen()`
  follows symlinks by default and PHP has no portable flag to disable
  that. This was evaluated, not overlooked: exploiting it would require
  an attacker to first place a symlink inside
  `/usr/local/hestia/data/api-v2-audit/` — which requires write access
  to that directory, which only `hestiaweb` (the very process running
  `FileAuditLogger`) and root already have. There is no reachable
  attacker capability this would defend against that isn't already
  equivalent to "the `hestiaweb` account itself is compromised," at
  which point symlink protection on one log file is not the relevant
  control. Adding a `realpath()`/`lstat()` check would be complexity
  with no corresponding threat-model benefit — consistent with §9's own
  instruction to modify `FileAuditLogger` only for a concrete defect,
  and none was found here.
- **No secrets in rotation metadata or filenames**: rotated filenames
  are exactly `audit.log.1`..`audit.log.12` (logrotate's own numeric
  suffixing, unrelated to any request/credential data) — never derived
  from event content. `AuditProvisioningTest::testNoSecretsOnDiskAcrossFullLifecycle`
  verifies no secret/password/Authorization-header value appears
  anywhere in either the rotated-aside or current file after a full
  provision → write → rotate → write cycle.
- **Rotation cannot corrupt concurrent writes** (§11) and **does not
  require changing `FileAuditLogger`'s security model** — both
  requirements from the task's own §5 are satisfied by the "open/close
  per write, no held handle" property that already existed before this
  sprint.

## 14. Test coverage

New: `test/api/AuditProvisioningTest.php`, 9 tests. Explicit scope note
at the top of that file (also stated here): this suite cannot execute
the real installer shell scripts (root-only, operate on real
`/usr/local/hestia` paths) and cannot verify a `chown` to the real
`hestiaweb` system user (that account does not exist in this test
environment). What it verifies instead, against real temporary
directories using the exact numeric mode constants cross-checked
against the installer source: directory/file permission bits, idempotent
re-provisioning never destroying existing records, and
`FileAuditLogger`'s actual behavior across a simulated install → write →
rotate → write lifecycle (using the same rename+create sequence
logrotate itself performs). The installer/upgrade shell scripts
themselves are verified by `bash -n` (§15) plus the targeted source
inspection in §2 — the correct verification method here, per the task's
own instruction not to test shell scripts via string matching when
(as here) no safe executable path exists for them in this environment.

| Requirement | Test |
|---|---|
| correct directory path/permissions | `testProvisionAbsentDirectory` |
| idempotent provisioning | `testProvisionExistingDirectoryIsIdempotent`, `testRepeatedProvisioningNeverLosesRecords` |
| existing audit.log preserved | `testProvisionPreservesExistingAuditLog` |
| audit records survive provisioning/upgrade | `testProvisionPreservesExistingAuditLog`, `testRepeatedProvisioningNeverLosesRecords` |
| rotated files preserve secure permissions | `testRotatedFileHasSecurePermissions` |
| concurrent writes don't corrupt records | `testConcurrentWritesAfterProvisioningAreNotCorrupted` |
| no secrets in audit records | `testNoSecretsOnDiskAcrossFullLifecycle` (+ full Sprint 6 `AuditLoggerTest` suite, re-run unmodified) |
| no credential secret / Authorization header / raw body in rotated files | `testNoSecretsOnDiskAcrossFullLifecycle` |

Sprint 6's full `AuditLoggerTest` (24 tests) and `GenericityTest` were
re-run unmodified as part of the full suite (§15) — both still pass,
confirming fail-open behavior, security ordering, and the no-shell-exec/
no-`bin/v-*`/no-secret-logging guarantees all remain intact.

## 15. Regression results

Full suite, run 3 consecutive times each:

| Suite | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| `php test/api/run_tests.php` | 156 passed, 0 failed | 156 passed, 0 failed | 156 passed, 0 failed |
| `php test/auth/run_tests.php` | 62 passed, 0 failed | 62 passed, 0 failed | 62 passed, 0 failed |
| `php test/adapter/run_tests.php` | 198 passed, 0 failed | 198 passed, 0 failed | 198 passed, 0 failed |

(156 total, up from the pre-Sprint-7 baseline of 147 — 9 new
`AuditProvisioningTest` tests, every pre-existing test unmodified and
still passing.) `php -l` was run against every modified/new PHP file —
zero syntax errors. `bash -n` was run against all three modified shell
scripts (`install/hst-install-ubuntu.sh`, `install/hst-install-debian.sh`,
`install/upgrade/versions/1.10.0.sh`) — zero syntax errors.
`git diff --check` — zero whitespace errors. `install/deb/logrotate/hestia`
was additionally verified with the real `logrotate -d` binary (§9).

## 16. Known limitations

- **Not verified against a real installation.** No root access, no real
  Hestia install, and no real `hestiaweb` system user exist in this
  development/test environment — `chown hestiaweb:hestiaweb` and the
  installer scripts' actual execution were verified by source inspection
  and `bash -n` only, never by running them. This is the same category
  of limitation Sprint 6 already had for `FileAuditLogger` itself
  ("inert until provisioned"); this sprint narrows it to "provisioning
  code is written, syntax-checked, and behaviorally mirrored in
  executable tests, but not exercised end-to-end on a real target."
- **RPM/other distributions are out of scope** — this repository
  currently ships only Ubuntu/Debian installers (§2); if a future RPM
  installer is ever added, it will need the equivalent provisioning
  block.
- **No automated verification that `logrotate` itself actually runs**
  this stanza on a real system (cron/systemd-timer scheduling of
  `logrotate` itself is outside this repository, and untouched).
- **Retention (`rotate 12`/`monthly`) is fixed, not operator-configurable**
  — matches the existing `/var/log/hestia/*.log` stanza's own
  convention, which is likewise not configurable through Hestia's own
  config system today.

## 17. Deferred work

- End-to-end verification on a real (or containerized) Hestia
  installation, once such an environment is available.
- Making retention configurable, if the existing `/var/log/hestia/*.log`
  stanza ever gains that capability (this sprint intentionally mirrors
  it rather than diverging).
- An RPM installer equivalent, if/when this repository adds one.

## 18. Architectural findings

- **The upgrade script is a single, growing, per-target-version file**
  (`1.10.0.sh`), already accumulating multiple unrelated fixes/features
  from many separate commits — confirmed by `git log` showing entries
  from Node.js repo bumps, SSH config, Dovecot, phpMyAdmin, Exim, and
  this project's own prior API v2 credential-directory work all living
  in the same file. This sprint's own addition follows that same,
  already-established convention rather than introducing a new
  mechanism.
- **Only `$HESTIA/data/*` is available to a PHP-side feature needing a
  writable directory without an installer change of its own** — this
  sprint is the second time this exact constraint (first identified for
  `FileAuditLogger` in Sprint 6, referenced from `LockManager` in an
  earlier sprint still) has shaped a design decision. Any future
  HTTP-layer feature wanting local, PHP-writable persistent storage will
  need either a new `$HESTIA/data/api-v2-*` sibling directory (this
  sprint's approach, now with provisioning) or a broader installer
  change.
- **`install/deb/logrotate/hestia` already supports multiple, differently-owned
  stanzas in one file**, and is already re-copied on every upgrade
  unconditionally — this is a reusable pattern for any future Hestia
  log needing rotation, without requiring new install/upgrade wiring
  each time (only a new stanza in this one file).

## 19. Final verdict

Sprint 7 is complete. No STOP condition was triggered: a safe
installer/upgrade location was identified and verified from source
(§2-§8); the existing `logrotate` infrastructure integrates cleanly via
an additional, independent stanza in the already-copied config file,
requiring no new daemon, cron job, or external dependency (§9);
provisioning never exposes the audit log to an unprivileged user (§5/§13);
rotation requires no change to `FileAuditLogger`'s security model (§9/§11);
none of the installer changes touch or alter any unrelated existing
logging behavior (`/var/log/hestia/*.log`'s own stanza, `chmod`/`chown`
lines for every other directory, are byte-for-byte unchanged — verified
via `git diff`); production permissions were established directly from
source (the real `hestiaweb` php-fpm pool identity, §2) rather than
assumed. `AuditEvent`/`FileAuditLogger` were reviewed in focus (§16 of
this doc corresponds to the task's own §9) and found to already handle
every scenario asked about (creation race, disk-full, directory
disappearing, permission changes, malformed pre-existing content,
symlinks) safely — **left completely unmodified**, per the task's own
instruction to change them only for a concrete discovered defect. All
147 pre-existing tests pass unmodified; 9 new dedicated tests were
added; all three suites are stable across 3 consecutive runs
(156/62/198).

**Readiness**: audit logging is now provisioned on both fresh installs
and upgrades of this repository's own installer/upgrade scripts, with a
minimal, source-verified, syntax-checked rotation mechanism integrated
into the existing `logrotate` infrastructure — resolving Sprint 6's own
headline caveat. The only remaining gap is end-to-end validation on a
real target system, which no environment available to this sprint could
provide; everything achievable through source review, static
verification, and behaviorally-equivalent executable tests has been
done.
