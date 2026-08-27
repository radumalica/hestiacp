# Credential Provisioning — Production Wiring Implementation

Implements `CREDENTIAL_PROVISIONING_WIRING_DESIGN.md`. Read that document
first for the full reasoning — this one records what was actually built
and verified.

## 1. Files Changed

**New:**
- `web/inc/auth/AccessKeyCli.php` — thin, injectable CLI wrapper.
- `web/inc/auth/CliOutcome.php` — `{exitCode, stdout, stderr}` value object.
- `bin/v-add-api-credential`, `bin/v-delete-api-credential` — PHP-shebang shims.
- `test/auth/AccessKeyCliTest.php` — 17 new tests.
- `CREDENTIAL_PROVISIONING_WIRING_DESIGN.md`, this document.

**Modified:**
- `install/hst-install-ubuntu.sh`, `install/hst-install-debian.sh` — add
  `$HESTIA/data/api-credentials` to the directory-creation batch and its
  `chmod 2750`/`chown root:hestiaweb` lines, mirroring `adapter-locks`'
  existing two-part structure exactly.
- `install/upgrade/versions/1.10.0.sh` — new idempotent block creating/
  owning/moding the same directory for existing installations.
- `web/inc/auth/AccessKeyProvisioner.php` — one-line change,
  `chmod($path, 0600)` → `chmod($path, 0640)` (§3), with an explanatory
  comment.
- `test/auth/run_tests.php` — registers the new test class.

**Untouched, verified by `git diff`**: `bin/v-add-access-key`,
`bin/v-delete-access-key`, `bin/v-check-access-key`,
`$HESTIA/data/access-keys/` (no reference anywhere in the new code),
`web/inc/adapter/CommandAdapter.php`, `AuthorizerInterface.php`,
`SameUserAuthorizer.php`, `web/api/index.php`, `web/add/`, `web/delete/`.

## 2. Installer/Upgrade Integration

- `install/hst-install-ubuntu.sh` and `install/hst-install-debian.sh`:
  `$HESTIA/data/api-credentials` added to the existing `mkdir -p` batch
  (installer directory-tree section); `chmod 2750` set alongside the
  existing `adapter-locks`/`sessions` `chmod` lines; `chown root:hestiaweb`
  added immediately after the existing
  `chown hestiaweb:hestiaweb $HESTIA/data/adapter-locks` line (post-`useradd
  hestiaweb`, post-service-start, matching that line's exact placement).
- `install/upgrade/versions/1.10.0.sh` (the current in-development
  version, confirmed via `package.json`'s `"1.10.0~alpha"`): a new,
  idempotent `mkdir -p`/`chown`/`chmod` block, so an installation
  upgrading from any pre-1.10.0 version also gets the directory —
  deliberately closing the exact gap `CREDENTIAL_PROVISIONING_WIRING_DESIGN.md`
  §1.1 found in `adapter-locks`'s own history (which received no such
  upgrade-version entry and would silently never exist on an upgraded,
  pre-adapter installation).

## 3. Runtime Directory Permissions

`$HESTIA/data/api-credentials/`: **owner `root`, group `hestiaweb`, mode
`2750`** (setgid, `rwxr-s---`).

- `root` (owner): full read/write/create/delete — the only identity that
  can provision or revoke a credential.
- `hestiaweb` (group, via setgid inheritance): read + directory traversal
  only (`r-x`) — cannot create, rename, or delete credential files.
  `AccessKeyValidator`, which runs as `hestiaweb` during ordinary
  PHP-FPM request handling with no `sudo`/`bin/v-*` intermediary, needs
  exactly this and nothing more.
- Individual credential files: `0640` (owner `rw`, group `r`) — changed
  from the credential-provisioning task's original `0600` specifically
  so the `hestiaweb` group can read `secret_hash`; the setgid bit on the
  parent directory makes every file's group automatically `hestiaweb`
  regardless of which identity (in practice, always `root`) created it.

**Why this location/model, in one sentence**: it reuses the exact
filesystem-ownership privilege boundary every other administrative
`bin/v-*` script in this codebase already relies on
(`CREDENTIAL_PROVISIONING_WIRING_DESIGN.md` §1.3), refined with one
explicitly-justified addition (setgid) that this specific
write-as-root/read-as-hestiaweb split needs and no prior directory in
this codebase happened to need.

## 4. CLI Command(s)

- **`bin/v-add-api-credential USER [FORMAT]`** — creates a credential.
  `FORMAT` is `shell` (default), `json`, or `plain` — mirroring
  `v-list-access-key`'s exact three-format convention. Exit `0` on
  success, `1` on any failure.
- **`bin/v-delete-api-credential CREDENTIAL_ID`** — revokes a credential.
  Exit `0` if removed, `1` if the id was malformed or not found.

Both are ~30-line shims: parse `$argv`, construct
`new AccessKeyCli(new AccessKeyProvisioner())`, call the relevant method,
write the returned `CliOutcome`'s `stdout`/`stderr`, `exit($exitCode)`.
Neither script contains any credential-management logic of its own —
verified both by direct reading and by `AccessKeyCliTest.php` test 17
(mechanical grep for `random_bytes(`/`password_hash(`/`password_verify(`/
`fopen(` inside `AccessKeyCli.php` itself, all absent).

## 5. Privilege Model

**The CLI is root-only by filesystem consequence, exactly like every
other administrative `bin/v-*` script in this codebase — not by any new
authorization check.** Neither script inspects its caller's identity;
`$HESTIA/data/api-credentials/`'s `2750`/`root:hestiaweb` mode (§3) is
what actually prevents a non-root invocation from succeeding (a `write()`
inside a directory the calling process's effective UID/GID cannot write
to fails with a filesystem permission error). This is the same mechanism
that makes `v-add-access-key` root-only today (its own
`$HESTIA/data/access-keys/` is `root:root`, `750`) —
`CREDENTIAL_PROVISIONING_WIRING_DESIGN.md` §1.3 confirmed by direct grep
that **no** existing `bin/v-*` script performs its own caller-identity
check anywhere in this repository; filesystem ownership is the only
privilege boundary this entire CLI ecosystem has ever had.

**`SameUserAuthorizer` is deliberately not involved** — per the task's
explicit instruction and `CREDENTIAL_PROVISIONING_WIRING_DESIGN.md` §2.2:
that class answers an authorization question for an already-authenticated
adapter-operation `actor`; credential provisioning has no such `actor` at
all (it is invoked with real OS-level privilege, not through
`CommandAdapter::invoke()`), so wiring it through `SameUserAuthorizer`
would mean fabricating a fictional actor/target pair for a property the
filesystem already enforces correctly.

## 6. Secret Delivery Model

`AccessKeyCli::create()` returns the plaintext secret exactly once, in
its `CliOutcome::$stdout` string, formatted per the requested `FORMAT`.
The corresponding `bin/v-add-api-credential` shim writes that string to
`STDOUT` exactly once and exits — nothing re-reads, re-displays, or
persists it afterward. Verified by test:
`testSecretOnlyInStdout` confirms the plaintext secret is absent from the
credential file's raw on-disk bytes and that a successful creation
produces empty `stderr` (no channel for the secret to leak into
alongside the intended one).

## 7. Revocation Model

`bin/v-delete-api-credential CREDENTIAL_ID` → `AccessKeyCli::revoke()` →
`AccessKeyProvisioner::revoke()` → file deletion, unchanged from
`CREDENTIAL_PROVISIONING_IMPLEMENTATION.md` §9. `AccessKeyCli` adds no
revocation logic of its own beyond formatting the boolean result into an
exit code and a confirmation/error message. Verified end-to-end against
the real `AccessKeyValidator`: `testRevokedCredentialFailsValidation`
creates a credential via the CLI, confirms it authenticates, revokes it
via the CLI, and confirms `AccessKeyValidator::authenticate()`
subsequently returns `null`.

## 8. Test Coverage

`test/auth/AccessKeyCliTest.php` — 17 tests: delegation (not
reimplementation) of both `create()` and `revoke()` to
`AccessKeyProvisioner` (tests 1, 8 — verified by confirming the real
filesystem side effects only `AccessKeyProvisioner` can produce); all
three output formats (2-4); secret-leakage checks (5); early rejection of
empty arguments before the provisioner is ever called (6, 11); malformed-
user rejection routed through the provisioner's own validation, not
duplicated (7); revoke success/failure/nonexistent-id behavior (9, 10);
full end-to-end integration against the real, unmodified
`AccessKeyValidator` for both creation and revocation (12, 13); absence
of any legacy `data/access-keys/`/`v-check-access-key` reference (14);
mechanical source checks for HTTP/session/`CommandAdapter`/
`AuthorizerInterface`/`SameUserAuthorizer` coupling (15), shell execution
(16), and duplicated provisioning primitives (17).

## 9. Full Test Results

- `php test/auth/run_tests.php` × 3 consecutive runs: **62 passed, 0
  failed** each time (19 `AccessKeyValidatorTest` + 26
  `AccessKeyProvisionerTest` + 17 new `AccessKeyCliTest`).
- `php test/adapter/run_tests.php` × 3 consecutive runs: **198 passed, 0
  failed** each time — unchanged; no adapter file touched by this task.
- `bash -n` on all three modified/extended shell files (`hst-install-ubuntu.sh`,
  `hst-install-debian.sh`, `1.10.0.sh`): no syntax errors.
- `php -l` on every new/modified PHP file: no syntax errors.
- Manual end-to-end smoke test (temp directory, not the real installation
  path): `AccessKeyCli::create()` → real credential file written →
  `AccessKeyValidator::authenticate()` succeeds → `AccessKeyCli::revoke()`
  → `AccessKeyValidator::authenticate()` returns `null`. Confirmed
  working before the automated test suite was even run.

## 10. Genericity / Security Verification

- `git diff` on the installer/upgrade files shows **only** the
  `api-credentials`-related lines added — no unrelated line touched.
- `git status` confirms zero changes to `bin/v-add-access-key`,
  `bin/v-delete-access-key`, `bin/v-check-access-key`, or any file under
  `web/api/`, `web/add/`, `web/delete/`.
- `web/inc/adapter/CommandAdapter.php`, `AuthorizerInterface.php`, and
  `SameUserAuthorizer.php` are unmodified by this task (the diffs visible
  in `git status` for `web/inc/adapter/*` are entirely pre-existing,
  already-reported uncommitted state from prior tasks in this session —
  confirmed by comparing the file list against the state recorded at the
  start of this task).
- Mechanical grep confirms `AccessKeyCli.php` contains, in actual code
  (not doc-comments), zero references to HTTP superglobals, sessions,
  `CommandAdapter`, `AuthorizerInterface`, `SameUserAuthorizer`, or any
  of `random_bytes()`/`password_hash()`/`password_verify()`/`fopen()`
  (i.e., no duplicated provisioning primitive).
- No secret appears in any exception message, log call, or second file —
  re-verified for the new CLI layer specifically (test 5), on top of the
  equivalent guarantee already established and re-tested for
  `AccessKeyProvisioner` itself.

## 11. Architectural Issues Discovered

- **A real, source-verified gap in prior work**: `$HESTIA/data/adapter-locks`
  has no corresponding upgrade-version script entry anywhere under
  `install/upgrade/versions/` — only the fresh-installer path creates it.
  An existing Hestia installation upgrading from a pre-adapter version
  would never receive this directory. This task does not fix that gap
  (out of scope — it belongs to whichever task originally introduced
  `adapter-locks`), but flags it here as a discovered, real issue worth a
  future small follow-up, and deliberately avoided repeating the same
  gap for `api-credentials/` by adding both an installer entry and an
  `install/upgrade/versions/1.10.0.sh` entry.
- **No other architectural issues found.** `AccessKeyProvisioner`'s
  existing public API needed no redesign; `AccessKeyValidator` needed no
  change at all (only the file-mode constant inside
  `AccessKeyProvisioner` changed, and only because the *directory's* new
  production permission model — decided in this task — requires it).

## 12. What Was Deliberately NOT Implemented

Per this task's own explicit constraints: no `web/api/v2/` directory, no
`web/api/index.php` modification, no `web/add/`/`web/delete/`
modification, no HTTP authentication or middleware, no Cloud Account, no
roles/admin authorization concept, no expiration, no credential rotation,
no `AuthorizerInterface` change, no `CommandAdapter` architecture change,
no new adapter operation, no legacy `data/access-keys/` reuse or
modification, no plaintext credential storage anywhere, nothing
committed.

## 13. Remaining Blockers Before HTTP API v2

Unchanged from `CREDENTIAL_PROVISIONING_IMPLEMENTATION.md` §15/
`API_V2_AUTHENTICATION_DESIGN.md` §13 — this task did not remove or add
to that list, it only made the credential mechanism itself operable on a
real installation:

1. Wiring `AccessKeyValidator::authenticate()`'s result into an `actor`
   array at whatever future HTTP entry point API v2 gets.
2. The HTTP entry point itself — a new, `CommandRegistry`-mediated route,
   structurally separate from `web/api/index.php`.
3. API error-vocabulary/HTTP-status-code decisions (deliberately deferred
   again in this task, §6/§7 above — no code is assigned to any CLI
   failure beyond the plain `0`/`1` exit convention).
4. Expiration and rotation (unchanged, still deferred, still no schema
   field or in-place-update path).
5. Any decision about who/what is allowed to invoke the eventual HTTP
   endpoint — orthogonal to and unaffected by this task's root-only CLI
   privilege model, which only governs the pre-HTTP, administrator-
   operated provisioning path.

## 14. READY / NOT READY Verdict

**READY** for the next step (an actual HTTP API v2 entry point, or
further CLI/admin-UI wiring), within this task's own explicit scope: the
credential system is now provisionable on a real Hestia installation —
directory creation is covered for both fresh installs and upgrades, the
privilege boundary is established with direct repository evidence and no
invented authorization concept, and the CLI layer is fully tested via
dependency injection with zero shell-invocation dependency. No STOP
condition was encountered. HTTP API v2 itself remains **not implemented**
— this task is production wiring for the authentication credential
mechanism only, exactly as scoped.
