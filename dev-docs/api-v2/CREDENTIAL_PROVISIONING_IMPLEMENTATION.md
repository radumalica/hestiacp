# Credential Provisioning Implementation

Implements `CREDENTIAL_PROVISIONING_DESIGN.md`'s design as
`AccessKeyProvisioner` + `CredentialCreationResult` +
`CredentialProvisioningException`. Read that document first — this one
records what was actually built and verified, not the reasoning behind
each choice (which lives in the design doc, referenced by section below).

## 1. Source/Repository Evidence

Summarized from `CREDENTIAL_PROVISIONING_DESIGN.md` §1 (full detail
there): `bin/v-add-access-key`'s non-atomic, sequential-`echo`-append
write strategy and unbounded collision-retry loop were identified as
patterns *not* to copy; `install/hst-install-{ubuntu,debian}.sh`'s
`$HESTIA/data/adapter-locks` precedent (mkdir, `chmod 770`,
`chown hestiaweb:hestiaweb`) confirmed a PHP-FPM-owned, non-legacy data
directory is an established, safe pattern in this codebase;
`random_bytes()` and `password_hash()`/`password_verify()` were
confirmed already in active use one file away
(`web/inc/auth/AccessKeyValidator.php`); `CommandAdapter::writeSecureTempFile()`
supplied the error-handling/cleanup-on-failure idiom this implementation
follows; `ParameterValidator::isValidUsername()` supplied the exact
source-grounded username-shape rule (deliberately duplicated, not
imported — see §2).

## 2. Chosen Credential ID Format

40 lowercase hex characters, `bin2hex(random_bytes(20))` — 160 bits of
entropy, generated server-side only, filesystem-safe by construction.
Uniqueness is enforced operationally by atomic file creation (§6), not
merely assumed from entropy. See `CREDENTIAL_PROVISIONING_DESIGN.md`
§2.1.

## 3. Secret Generation

64 lowercase hex characters, `bin2hex(random_bytes(32))` — 256 bits of
entropy. Held in memory only for the duration of `create()`; never
written to disk, logged, or included in any exception message (verified
by test — §13, item 16). PHP has no language-level secure-zeroing
primitive for plain strings; this is a documented, accepted limitation
(`CREDENTIAL_PROVISIONING_DESIGN.md` §2.2), not a gap this task
attempted to close.

## 4. Storage Schema

Unchanged from `AccessKeyValidator`'s existing contract:

```json
{ "user": "admin", "secret_hash": "$2y$10$..." }
```

`AccessKeyProvisioner`'s default credential directory is
`AccessKeyValidator::DEFAULT_CREDENTIAL_DIRECTORY` — imported directly
(constructor default parameter), not copy-pasted, so the two classes
cannot structurally drift on where credentials live. Verified by test
21 (`testDefaultDirectoryMatchesValidator`, via `ReflectionClass` on the
constructor's default parameter value).

## 5. Permissions

- **Credential file**: `chmod 0600` after write (owner-only) — stricter
  than the legacy access-key file's `640`, deliberately: no known,
  evidenced second reader exists for `secret_hash`, and `0600` mirrors
  `CommandAdapter`'s own existing convention for sensitive temp files.
- **Credential directory**: not created by this task in production (see
  §14) — `CREDENTIAL_PROVISIONING_DESIGN.md` §3 documents the intended
  future `chmod 770`/`chown hestiaweb:hestiaweb` convention (mirroring
  `adapter-locks`) for whenever an installer task actually provisions
  `$HESTIA/data/api-credentials/`. Every test creates and `chmod`s its
  own temp directory; none touches the real path.

## 6. Atomic Write Strategy

`fopen($path, "xb")` — `O_CREAT | O_EXCL`, a single atomic syscall that
fails immediately and detectably if the target path already exists.
Chosen over a temp-file-then-`rename()` strategy specifically because
`rename()` is not collision-safe on POSIX (it silently overwrites an
existing destination) — using it here would let a colliding id clobber
an existing credential undetected, which `fopen(..., "xb")` categorically
cannot do. On success, the complete JSON record is written in one
`fwrite()` call; both the return value and the written byte count are
checked (`$written !== strlen($record)`) before considering the write
successful; `fclose()`'s own return value is checked too. Any failure at
either step triggers `@unlink($path)` before the exception propagates —
mirroring `CommandAdapter::writeSecureTempFile()`'s own cleanup-on-failure
idiom. Verified by test 15 (`testNoPartialFileOnWriteFailure`, confirming
the on-disk file is always either absent or a single, complete, valid
JSON document — never observed partial).

The one residual, explicitly-documented risk window
(`CREDENTIAL_PROVISIONING_DESIGN.md` §2.3): between `fopen(..., "xb")`
succeeding and `fwrite()`/`fclose()` completing, a concurrent
`AccessKeyValidator::authenticate()` call against that exact,
just-claimed id would see an empty/partial file, which `json_decode()`
fails to parse as a valid record — a fail-closed (deny), not fail-open
(accept), outcome, and self-healing the instant the write completes.

## 7. Collision Handling

Bounded retry: up to 5 attempts, a fresh candidate id generated each
time. A `fopen()` failure is treated as a collision (retry) only when
`file_exists($path)` confirms the target is actually occupied; any other
`fopen()` failure (directory missing, permission denied) is immediately
reported as `CredentialProvisioningException::storageUnavailable()`,
never retried as if it were a mere collision. Exhausting all 5 attempts
throws `CredentialProvisioningException::collisionExhausted()`. Verified
by tests 10, 11, 11b (`testNoOverwriteOnCollision`,
`testCollisionRetrySucceeds`, `testCollisionRetryExhaustion`), each using
an injected deterministic id generator (constructor DI, mirroring
`CommandAdapter`'s own `?callable $idGenerator` precedent) rather than
depending on real randomness to exercise this path.

## 8. Lifecycle

| Operation | Status |
|---|---|
| Create | Implemented — `AccessKeyProvisioner::create(string $user): CredentialCreationResult`. |
| Revoke | Implemented — `AccessKeyProvisioner::revoke(string $id): bool`. |
| Rotate | **Not implemented** — no source evidence required it now; see `CREDENTIAL_PROVISIONING_DESIGN.md` §2.5 for the shape a future implementation should take (revoke+create, never an in-place secret swap under the same id). |

## 9. Revocation Behavior

`revoke($id)` deletes the credential record file if present and returns
`true`; returns `false` (does not throw) if no record exists for a
well-formed id — mirroring `bin/v-delete-access-key`'s
exists-then-delete semantics without importing its `E_NOTEXIST`
exit-code vocabulary. A malformed id (empty, or shaped like a
path-traversal attempt — `basename($id) !== $id`) throws
`\InvalidArgumentException` immediately, before any filesystem access —
this is treated as a caller-programming error, not an
authentication-boundary case requiring existence-hiding (the caller
already legitimately holds the id it is revoking). Verified end-to-end
against the real, unmodified `AccessKeyValidator` by test 18
(`testRevokedCredentialFailsValidation`): a credential created by
`AccessKeyProvisioner`, successfully authenticated, then revoked, is
confirmed to fail `AccessKeyValidator::authenticate()` afterward — proof
this component's write side and the previously-shipped read side agree.

## 10. Security Properties Verified

- **No plaintext secret persisted**: only `password_hash()`'s output is
  ever written to a credential file (test 7,
  `testStoredRecordExcludesPlaintextSecret` — checks the raw file bytes,
  not just the decoded `secret_hash` field, for the plaintext substring).
- **Hash verifies the returned secret**: test 8
  (`testStoredHashVerifiesReturnedSecret`) — `password_verify()` against
  the actual stored hash.
- **No secret in exceptions**: test 16
  (`testSecretNeverInExceptionMessages`) — captures the real generated
  secret via an injected generator, forces a failure, asserts the
  exception message never contains it.
- **No shell execution / no HTTP-session dependency**: tests 22-23,
  mechanical source grep (same technique already established for
  `AccessKeyValidator`).
- **Path traversal**: rejected in both directions — `create()` never
  accepts a caller-supplied id at all (always self-generated), and
  `revoke()` rejects any id where `basename($id) !== $id` before touching
  the filesystem (tests 13a/13b).
- **Symlink attacks**: not specifically exercised by a dedicated test (no
  practical, deterministic way to construct one portably in this test
  environment), but structurally mitigated by `O_EXCL`'s own semantics —
  `fopen($path, "xb")` fails if `$path` already exists, including as a
  symlink, rather than following it and overwriting whatever it points
  to. This is standard, documented POSIX `open(O_CREAT|O_EXCL)` behavior,
  not a property this implementation adds itself. Marked here explicitly
  as relying on primitive-level behavior rather than an implementation-
  level test, consistent with this task's own "mark UNKNOWN rather than
  guess" standard from the earlier authentication design review.
- **TOCTOU during creation**: analyzed in full in §6/design doc §2.3 —
  the one residual window is fail-closed, not fail-open.
- **Overwrite protection**: never overwrites an existing file under any
  circumstance (tests 10, 11, 19) — `fopen(..., "xb")` cannot overwrite by
  construction.
- **Predictable IDs/secrets**: both are `random_bytes()`-derived (160/256
  bits respectively) — no non-CSPRNG source anywhere in this component.
- **Credential enumeration**: `create()` never accepts a caller-chosen id
  to probe against, and `revoke()`'s `\InvalidArgumentException` vs.
  `false`-return distinction only distinguishes "malformed id" from
  "well-formed but absent," never leaking anything about *other*
  credentials' existence — no enumeration surface was found or
  introduced.

## 11. Error Model (as implemented)

| Condition | Exception |
|---|---|
| Invalid user | `\InvalidArgumentException` |
| Invalid id passed to `revoke()` | `\InvalidArgumentException` |
| Storage unavailable | `CredentialProvisioningException::storageUnavailable()` |
| Collision exhaustion | `CredentialProvisioningException::collisionExhausted()` |
| Atomic write failure | `CredentialProvisioningException::writeFailed()` (partial file `unlink()`ed first) |

No HTTP status code is assigned to any of these — deliberately deferred,
per this task's instruction and `API_V2_AUTHENTICATION_DESIGN.md` §8's
own prior deferral of the analogous authentication-outcome-to-HTTP
mapping.

## 12. Concurrency Model

No global lock. Safety comes entirely from `fopen($path, "xb")`'s atomic,
single-syscall "create exclusively" semantics — whichever of two
concurrent creators reaches a given candidate path first wins it
outright; the other observes the failure and retries with a freshly
generated id (§7). Test 19
(`testConcurrentShapedCreationNeverOverwrites`) proves this mechanism
directly: two provisioner instances are forced (via injected id
generators) to race for the identical candidate id; the "loser" is shown
to fall through to its own retry rather than corrupt or overwrite the
"winner"'s already-written record. This is a mechanism-level proof, not a
real multi-process test (impractical to make deterministic and fast in
an offline unit suite) — `CREDENTIAL_PROVISIONING_DESIGN.md` §2.9
explains why no source evidence supports introducing a `LockManager`-style
lock for this problem shape.

## 13. Tests

`test/auth/AccessKeyProvisionerTest.php` — 26 tests, covering all 19
categories the task requested (several split further for precision) plus
4 additional tests (20-23: end-to-end integration with the real
`AccessKeyValidator`, default-directory-constant reuse, and the two
mechanical genericity checks already established for `AccessKeyValidatorTest.php`).
Registered alongside the existing 19 `AccessKeyValidatorTest` tests in
the shared `test/auth/run_tests.php` entry point.

## 14. Known Limitations

- No secure in-memory zeroing of the plaintext secret between generation
  and return (§3) — a plain-PHP limitation, not attempted.
- The one TOCTOU window during write (§6) is fail-closed but not fully
  eliminated — closing it would require a read/write coordination
  protocol this task's constraints (no modification to
  `AccessKeyValidator`) do not permit building.
- Symlink-attack resistance relies on documented `O_EXCL` kernel
  behavior, not a dedicated executable test (§10).
- No rate limiting or audit logging of credential creation/revocation
  events — out of scope per this task's "do not over-engineer" list, and
  not built.

## 15. Deferred Work

Explicitly not built in this task, per its own instructions:

- Credential rotation (§8).
- Any authorization decision about *who* may call `create()`/`revoke()`
  (`CREDENTIAL_PROVISIONING_DESIGN.md` §2.8) — belongs to a future
  service/authorization layer.
- Production installer changes to actually create
  `$HESTIA/data/api-credentials/` with the ownership/permissions
  documented in `CREDENTIAL_PROVISIONING_DESIGN.md` §3.
- Wiring `AccessKeyProvisioner` into any CLI tool, admin UI, or HTTP
  endpoint.
- Expiration (unchanged decision from `ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md`
  §5 — still deferred, still no schema field).

## 16. Verification

- `php test/auth/run_tests.php` × 3 consecutive runs: **45 passed, 0
  failed** each time (19 pre-existing `AccessKeyValidatorTest` + 26 new
  `AccessKeyProvisionerTest`).
- `php test/adapter/run_tests.php` × 3 consecutive runs: **198 passed, 0
  failed** each time — unchanged; no adapter file touched by this task.
- `git status --short` after this task: only `web/inc/auth/` (three new
  files) and `test/auth/` (two new/modified files) changed beyond the
  pre-existing, already-reported uncommitted state from prior tasks. No
  `bin/v-*` script, no sudoers file, no `web/api/*` file, no
  `web/inc/adapter/*` file touched.
- Mechanical genericity grep confirms zero code-level references to
  HTTP superglobals, `CommandAdapter`, `AuthorizerInterface`,
  Cloud Account, roles, or any specific adapter operation anywhere in
  `AccessKeyProvisioner.php`, `CredentialCreationResult.php`, or
  `CredentialProvisioningException.php` (the one grep hit is a doc-comment
  sentence explicitly stating what this class does *not* depend on).
