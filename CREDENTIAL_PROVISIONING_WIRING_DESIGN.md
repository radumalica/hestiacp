# Credential Provisioning — Production Wiring Design

Design for the two things `CREDENTIAL_PROVISIONING_IMPLEMENTATION.md` §15
listed as still-deferred: (A) actually provisioning
`$HESTIA/data/api-credentials/` on a real installation, and (B) a
controlled way to create/revoke credentials before HTTP API v2 exists.
Written before any code, per this task's instruction.

---

## 1. Inspection Findings

### 1.1 Directory provisioning conventions

- **Fresh-install precedent** (`install/hst-install-ubuntu.sh` lines
  1352-1368, `install/hst-install-debian.sh` lines 1312-1326): data
  directories are created in one `mkdir -p` batch early in the installer,
  `chmod`ed at creation time, then `chown`ed to `hestiaweb:hestiaweb`
  **later**, at line 2493 (Ubuntu)/2485 (Debian) — specifically *after*
  `useradd hestiaweb` (line 1208) and after the Hestia service itself has
  started (`systemctl start hestia`, line ~2491). The existing
  `$HESTIA/data/adapter-locks` line sits right next to
  `chown hestiaweb:hestiaweb $HESTIA/data/sessions` at that same late
  point in both installer files — this is the exact location a new
  directory's ownership line belongs.
- **Upgrade-path precedent — and a gap worth noting**: `$HESTIA/data/sessions`
  received its OWN dedicated `chown -R hestiaweb:hestiaweb` line in
  **two** separate upgrade-version scripts
  (`install/upgrade/versions/1.9.0.sh` line 63,
  `install/upgrade/versions/1.9.2.sh` line 35) — i.e., existing
  installations upgrading across those versions get this ownership fix
  applied retroactively. `$HESTIA/data/adapter-locks`, by contrast, has
  **no** matching upgrade-version entry anywhere (grepped every file
  under `install/upgrade/versions/` for "adapter-locks" — zero matches).
  This means an existing Hestia installation upgrading from a
  pre-adapter version would never get `$HESTIA/data/adapter-locks`
  created at all, unless the installer's full directory-creation block
  happens to re-run (it does not, on an upgrade). This is a real,
  source-verified gap in prior work, not something this task is asked to
  fix for `adapter-locks` — but it is direct evidence that
  **this task's own new directory needs BOTH an installer entry AND an
  upgrade-version entry**, to avoid repeating that exact gap.
- **Current in-development version**: `package.json` declares
  `"version": "1.10.0~alpha"`, and `install/upgrade/versions/1.10.0.sh`
  already exists (containing unrelated, already-queued fixes for this
  release) — this is the correct upgrade-version file to extend, not a
  new one to create.
- **Owner/group-split precedent for a producer/consumer permission
  model** (`install/upgrade/versions/1.8.12.sh` lines 32-51):
  `chown -R hestiamail:www-data "$RC_CONFIG_DIR"` followed by
  `chmod 640 "$RC_CONFIG_DIR/config.inc.php"` — one service identity
  (`hestiamail`) owns for write, a *different* identity's group
  (`www-data`) is granted read-only access to specific files via the
  group bit. This is direct, existing precedent for the same shape of
  permission split this task needs (§2.1), not a novel pattern being
  introduced from nothing.
- **`$HESTIA/data/access-keys/`'s own ownership** (re-confirmed,
  `bin/v-add-access-key` lines 72-76): `chown root:root`, `chmod 750` —
  root-only, no group-read grant at all. This directory's readers are
  exclusively other root-invoked (or hestiaweb-via-`sudo`) `bin/v-*`
  scripts, never a PHP-FPM process reading the file directly — a
  materially different consumption model from `api-credentials/`, where
  `AccessKeyValidator` (running as `hestiaweb`, no `sudo`, no `bin/v-*`
  intermediary) must read files directly. This difference is exactly why
  `access-keys/`'s permission model cannot be copied verbatim (§2.1).

### 1.2 CLI conventions

- **Naming**: every credential-lifecycle command in this codebase follows
  `v-add-<noun>` / `v-delete-<noun>` (`v-add-access-key`,
  `v-delete-access-key`). No `v-create-*`/`v-revoke-*` naming exists
  anywhere — `v-add-*`/`v-delete-*` is the established verb pair.
- **PHP-shebang `bin/v-*` scripts already exist** — not a novel
  introduction: `bin/v-generate-password-hash` and `bin/v-quick-install-app`
  both use `#!/usr/local/hestia/php/bin/php` instead of `#!/bin/bash`.
  `v-generate-password-hash` (the simpler, more directly comparable one —
  a small, single-purpose, stateless PHP script, not
  `v-quick-install-app`'s heavier Symfony-console-style tool) establishes
  the relevant convention: read `$argv` directly, validate with a plain
  `if (empty(...))` check, `fwrite(STDERR, ...)` plus `exit(1)` on
  failure, plain `echo` on success — **no `func/main.sh` sourcing, no
  `check_args`/`E_*` vocabulary at all**, because those are bash-specific
  conventions this script category never had access to in the first
  place.
- **Requiring project PHP code from `bin/`**: `bin/v-quick-install-app`
  lines 24-25 use `require_once __DIR__ . "/../web/inc/vendor/autoload.php"`
  /`"/../web/src/init.php"` — `__DIR__`-relative requires reaching into
  `web/`, confirming `bin/` and `web/` are siblings under `$HESTIA` and
  this is the established way for a `bin/` PHP script to reach
  `web/inc/` code.
- **Output format convention**: `bin/v-list-access-key` (lines 24-54)
  establishes the exact shape to mirror — `shell` (aligned
  `KEY:   value` lines), `json` (uppercase keys), and a third `plain`
  format (`echo $access_key_id:$SECRET_ACCESS_KEY`, line 53) — a single
  colon-joined `id:secret` line, which is also exactly the shape
  `web/api/index.php` lines 370-381 already parse back out of a `hash=`
  parameter (`explode(":", ...)`, expects a 20-char/40-char split). This
  `plain` format is the most directly reusable convention for "display
  the one-time secret in a form the admin can immediately copy."
- **Exit codes**: bash `bin/v-*` scripts use `func/main.sh`'s `E_*`
  vocabulary (`E_ARGS=1`, `E_INVALID=2`, `E_NOTEXIST=3`, ...,
  confirmed by reading `func/main.sh` lines 110-129). The one directly
  comparable **PHP**-shebang precedent, `v-generate-password-hash`, uses
  neither `func/main.sh` nor this vocabulary — it is a plain binary
  `exit(1)` on any failure, `exit(0)` (implicit) on success. Since this
  task's new scripts are PHP-shebang, not bash, and have no
  `func/main.sh` sourced, the evidenced, applicable convention is
  `v-generate-password-hash`'s plain binary exit code, not the bash
  `E_*` taxonomy — reusing `E_*` symbolic *values* without the
  surrounding bash machinery that gives them meaning would be
  cargo-culting a convention this script category doesn't actually share.

### 1.3 Privilege/sudo conventions

- **`install/common/sudo/hestiaweb`** (full file, 5 lines):
  `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` — `hestiaweb` may
  `sudo` **any** `bin/v-*` script as root, without a password, no
  per-script allowlist. This is how `web/api/index.php` and
  `web/inc/main.php` execute `bin/v-*` scripts as root today
  (`HESTIA_CMD = "/usr/bin/sudo /usr/local/hestia/bin/"`).
- **The actual privilege boundary for every existing `bin/v-*` script is
  not the sudoers file or any file-execute-permission gate at all** — it
  is the **target data each script writes**. `v-add-access-key` "is"
  root-only in practice only because `$HESTIA/data/access-keys/` is
  `chown root:root`, `chmod 750`: a non-root, non-`sudo`-elevated
  invocation fails with a filesystem permission error, not because the
  script itself checks who is calling it. No `bin/v-*` script in this
  repository performs its own caller-identity check (grepped for
  `id -u`/`whoami`/`EUID` across `bin/` — none found in scripts relevant
  to this task). Authorization, for this entire CLI ecosystem, **is**
  filesystem ownership; there is no second, independent access-control
  layer anywhere underneath it.
- **This is the direct, evidenced answer to the CRITICAL AUTHORIZATION
  QUESTION**: the CLI does not need a *new* authorization mechanism
  wired in — it needs the *same* mechanism every other administrative
  `bin/v-*` script already relies on, applied correctly to the new
  directory. §2.1 below is that application.

## 2. Design Decisions

### 2.1 Directory Ownership — the resolved privilege boundary

**`chown root:hestiaweb`, `chmod 2750` (rwxr-s---) on
`$HESTIA/data/api-credentials/`.** Not `hestiaweb:hestiaweb` (which
`ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md`/`CREDENTIAL_PROVISIONING_DESIGN.md`
left as an open detail for this exact task to resolve), and not
`root:root` (which would block `AccessKeyValidator` — running as
`hestiaweb`, no `sudo`, no `bin/v-*` intermediary — from reading
credential files at all, defeating the entire mechanism).

Reasoning, directly from §1's evidence:

- **Owner `root`, group `hestiaweb`** mirrors `install/upgrade/versions/1.8.12.sh`'s
  own `hestiamail:www-data`-style producer/consumer split (§1.1) — one
  identity owns for write, a different identity's group gets read via
  the group bit. This is not a novel pattern.
- **`750`** (owner: rwx, group: r-x, other: none) lets `root` create,
  delete, and rename files; lets the `hestiaweb` group list the
  directory and open files inside it by name; grants nothing to anyone
  else.
- **The `2` (setgid) bit** is the detail that makes this actually work
  end to end, and is not present anywhere else in this codebase's
  installer (confirmed by grep, §1.1) — called out explicitly rather
  than silently added. Without it, a file created by a `root`-owned
  process (this task's CLI, run as `root`) would take `root`'s own
  primary group (typically `root`) as the new file's group, **not**
  `hestiaweb` — meaning `AccessKeyValidator` (reading as `hestiaweb`)
  would be unable to read a credential file's `secret_hash` field even
  with a correctly-owned directory. Setting the setgid bit on the
  *directory* makes every file subsequently created inside it inherit
  the directory's group (`hestiaweb`) automatically, regardless of the
  creating process's own group — standard, well-documented POSIX
  behavior, not a custom mechanism this task invents.
- **Individual credential file mode changes from `0600` to `0640`** (a
  small, deliberate change to the already-implemented
  `AccessKeyProvisioner::createRecordAtomically()`, §5) — `0600` (owner-
  only) would leave `AccessKeyValidator` unable to read the file at all
  even with correct directory/group setup; `0640` (owner rw, group r)
  grants exactly the read access `AccessKeyValidator` needs and nothing
  more (no group-write, no world-access).

**Net effect — the actual privilege model**: only a process running as
`root` (a real administrator with shell access, exactly the same
population that can already run `v-add-access-key` successfully) can
create or revoke a credential. `AccessKeyValidator`, running as
`hestiaweb` during ordinary PHP-FPM request handling, can read (validate
against) existing credentials but cannot create, modify, or delete them.
This is enforced entirely by the filesystem — no new authorization class,
no `SameUserAuthorizer` involvement, no role/admin concept added
anywhere. It is the exact same *kind* of boundary every other
administrative `bin/v-*` script already relies on (§1.3), applied with
one additional, explicitly-justified detail (setgid) that this
specific producer/consumer split requires and `access-keys/`'s
root-only-no-reader model never needed.

### 2.2 Why not wire this through SameUserAuthorizer

Per this task's explicit instruction, not attempted, and here is why it
would be wrong even if attempted: `SameUserAuthorizer` answers "does
`actor.user` equal `target.user`" for an *already-authenticated,
already-normalized* adapter operation request
(`AUTHORIZATION_POLICY_IMPLEMENTATION.md`). Credential provisioning has
no `actor` in that sense at all — the CLI is invoked directly by a human
or system process with real OS-level privilege, not by an authenticated
HTTP principal flowing through `CommandAdapter::invoke()`. Forcing this
operation through `SameUserAuthorizer` would require inventing a
fictional `actor`/`target` pair with no real authentication behind it
(exactly the kind of "invented" authorization the task prohibits) for a
security property (root-only execution) the filesystem already provides
for free, and correctly, today.

### 2.3 Installer / Upgrade Integration Points

- **`install/hst-install-ubuntu.sh`** and **`install/hst-install-debian.sh`**:
  add `$HESTIA/data/api-credentials` to the existing `mkdir -p` batch
  (§1.1's first block), and add its `chown root:hestiaweb`/
  `chmod 2750` lines immediately next to the existing
  `chown hestiaweb:hestiaweb $HESTIA/data/adapter-locks` line (§1.1's
  second location) — same file, same section, consistent with how
  `adapter-locks` itself was added.
- **`install/upgrade/versions/1.10.0.sh`**: add the equivalent
  `mkdir -p`/`chown`/`chmod` block (idempotent — safe to re-run), so
  existing installations upgrading to 1.10.0 get the directory without
  requiring a fresh install. This directly closes the class of gap §1.1
  identified for `adapter-locks` (which has no such entry), rather than
  repeating it for a second directory.

### 2.4 CLI Command Design

Two new PHP-shebang `bin/v-*` scripts, naming mirrored from
`v-add-access-key`/`v-delete-access-key` (§1.2):

- **`bin/v-add-api-credential USER [FORMAT]`** — creates a credential for
  `USER`, prints the id/secret/user exactly once, in the requested
  `FORMAT` (`shell` default, `json`, `plain` — mirroring
  `v-list-access-key`'s exact three-format convention, §1.2). Exit `0` on
  success, `1` on any failure (invalid user, storage unavailable,
  collision exhaustion) — mirroring `v-generate-password-hash`'s plain
  binary exit convention, the only directly-comparable PHP-shebang
  precedent (§1.2), not the bash `E_*` taxonomy this script category
  never had access to.
- **`bin/v-delete-api-credential CREDENTIAL_ID`** — revokes the given
  credential id. Exit `0` if a credential was found and removed, `1`
  otherwise (malformed id, or no such credential) — same binary
  convention, informative `STDERR` text distinguishes the two failure
  shapes without inventing a new exit-code vocabulary.

**Both scripts are thin shims over a new, injectable, unit-testable
class** — `web/inc/auth/AccessKeyCli.php` (`Hestiacp\Auth\AccessKeyCli`)
— per this task's explicit instruction to test the wrapper via
dependency injection rather than shelling out. `AccessKeyCli` takes an
`AccessKeyProvisioner` in its constructor, exposes `create(string $user,
string $format): CliOutcome` and `revoke(string $id): CliOutcome`, and
performs **zero** business logic of its own beyond argument-shape
validation already redundant with `AccessKeyProvisioner`'s own checks
(kept only for a friendlier CLI error message before ever calling into
the provisioner) and output formatting — all generation, hashing, schema,
and filesystem work stays inside `AccessKeyProvisioner`, unchanged and
un-duplicated. `CliOutcome` is a small value object (`$exitCode`,
`$stdout`, `$stderr`) so tests can assert on exact output without capturing
real process STDOUT/STDERR or calling `exit()` (which would kill the test
runner). The actual `bin/v-*` files reduce to: parse `$argv`, construct
`AccessKeyCli`, call the relevant method, `fwrite()` its `$stdout`/`$stderr`,
`exit($outcome->exitCode)` — nothing else.

### 2.5 Secret Delivery

`AccessKeyCli::create()` returns the plaintext secret in its `CliOutcome::$stdout`
exactly once, formatted per the requested output format (§2.4). Nothing
in `AccessKeyCli` or the `bin/v-add-api-credential` shim logs it, writes
it to a second location, or includes it in any exception path — it flows
directly from `AccessKeyProvisioner::create()`'s own
`CredentialCreationResult::$secret` (already never persisted,
`CREDENTIAL_PROVISIONING_IMPLEMENTATION.md` §3) into one formatted
string, printed once, and then the `CredentialCreationResult`/`CliOutcome`
objects go out of scope. This is verified by test (§ "Tests," item 5).

## 3. STOP Condition Check

None triggered:

- **Installer/upgrade integration point**: established with direct
  repository evidence (§1.1, §2.3) — not ambiguous.
- **Privilege boundary**: established with direct repository evidence
  (§1.3, §2.1) — the filesystem-ownership model every other
  administrative `bin/v-*` script already relies on, applied correctly
  to this directory's actual read/write population. Not ambiguous, not
  invented.
- **`AccessKeyProvisioner`'s existing API is sufficient** for a thin CLI
  wrapper without redesign — `create(string $user): CredentialCreationResult`
  and `revoke(string $id): bool` already expose exactly what a CLI needs;
  no constructor or method signature change is required (only the
  already-discussed `0600` → `0640` file-mode constant, an internal
  detail, not an API change).
- **The secret can be delivered safely** using the established `shell`/
  `json`/`plain` output-format convention (§1.2/§2.4) — no gap found.
- **No change to the legacy access-key system** is required — this task
  touches none of `bin/v-add-access-key`, `bin/v-delete-access-key`,
  `bin/v-check-access-key`, or `$HESTIA/data/access-keys/`.
- **No existing Hestia data permissions are weakened** — `$HESTIA/data/users`,
  `$HESTIA/data/access-keys/`, and every other existing directory are
  untouched by this task; the new `root:hestiaweb`/`2750` directory is
  *more* restrictive than `adapter-locks`'/`sessions`' own
  `hestiaweb:hestiaweb`/`770` model, not less.

**Implementation proceeds.**
