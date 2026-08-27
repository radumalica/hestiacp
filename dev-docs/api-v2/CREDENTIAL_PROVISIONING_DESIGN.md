# Credential Provisioning Design

Design for the exact gap `ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md` §7/§14
named as not-yet-started: a component that actually writes
`api-credentials/<id>` records for `AccessKeyValidator` to read. Written
before any code, per this task's instruction; no source file is modified
by this document.

---

## 1. Inspection Findings

- **`bin/v-add-access-key`** (re-read for this task): generates
  `access_key_id` (20 chars, `keygen()`'s Bash `$RANDOM`-based matrix
  `0-9A-Za-z`) and `secret_access_key` (40 chars, same matrix plus
  `_-=`), collision-checks the id against
  `$HESTIA/data/access-keys/<id>` in a `while [[ -e ... ]]` loop
  (unbounded retries, not bounded), writes the record as five `echo >>`
  appends (lines 84-92, **not atomic** — a crash mid-write leaves a
  partial file with only some `KEY='value'` lines present; readers must
  tolerate that or fail unpredictably), then `chmod 640`. This is
  evidence *against* copying the legacy write strategy verbatim: the
  hardened provisioner should not reproduce the "several sequential
  writes, no atomicity" pattern.
- **`bin/v-delete-access-key`** (re-read): unconditional `rm` after an
  `is_object_valid` existence check; exits `E_NOTEXIST` if the key file
  is absent. Precedent for "revoke = delete the record," already the
  decision `ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md` §6 made for the
  validator's read side; this task's revoke operation mirrors it on the
  write side.
- **Existing PHP CSPRNG primitive already in use in this exact
  subsystem**: `random_bytes()` — used by `AccessKeyValidator::dummyHash()`
  itself (`web/inc/auth/AccessKeyValidator.php` line 142) and by
  `SameUserAuthorizerTest.php`/every adapter test's temp-directory-naming
  convention. This is not a new primitive being introduced; it is the
  same one already relied on one file away. `random_bytes()` has been a
  PHP core function since PHP 7.0 — no version gap exists (this
  repository's `web/inc/composer.json` declares no PHP version floor,
  and no file anywhere in `web/inc/` uses a PHP 8-only construct such as
  `readonly` properties, `enum`, or `match` — confirmed by grep — so this
  design deliberately avoids those too, for consistency, not because they
  are unavailable).
- **Atomic-file-creation primitine already in use in this codebase**:
  `CommandAdapter::writeSecureTempFile()` (`web/inc/adapter/CommandAdapter.php`
  lines 660-679) uses `tempnam()` (atomic creation with an
  unpredictable name, mode 0600, `@`-suppressed with `error_get_last()`
  diagnostics, explicit cleanup via `unlink()` on a subsequent write
  failure). `tempnam()` itself is not reusable here as-is (it always
  generates its own filename; this task needs to create a specific,
  caller-chosen id atomically) — but its surrounding idiom (error
  handling, cleanup-on-failure, defense-in-depth `chmod`) is reused.
  PHP's `fopen($path, 'x')` (`O_CREAT|O_EXCL`, single atomic syscall,
  fails immediately if the target already exists) is the correct
  primitive for "create this exact path, atomically, only if absent" —
  not used elsewhere in this codebase yet, but a standard, well-
  documented PHP primitive requiring no version floor beyond ancient PHP
  releases.
- **Install-time directory ownership convention**
  (`install/hst-install-ubuntu.sh` lines 1352-1368,
  `install/hst-install-debian.sh` lines 1312-1326): `$HESTIA/data/adapter-locks`
  (the closest existing analog — a PHP-adapter-owned, non-legacy data
  directory) is created with `mkdir -p`, `chmod 770`, and later
  `chown hestiaweb:hestiaweb` once that user exists — explicitly
  documented in-line as "holds ... files for the PHP Command Adapter ...
  deliberately separate from `$HESTIA/data/users`, which stays root-only
  ... mirroring the existing `$HESTIA/data/sessions` convention." This is
  direct, positive evidence that a PHP-FPM-owned (`hestiaweb`), non-root
  data directory *can* safely exist and be written by the PHP process —
  answering STOP condition 1 in the negative (no stop required) — but
  also confirms that creating and `chown`ing such a directory is an
  **installer change**, which `ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md`
  correctly left out of scope for the validator and which this task
  likewise does not perform (see §3 — the directory is never created in
  production by this task's code; tests exclusively use temp
  directories).
- **`ParameterValidator::isValidUsername()`** (`web/inc/adapter/ParameterValidator.php`
  lines 32-45): the existing, source-grounded username-shape check
  (mirrors `func/main.sh`'s `is_user_format_valid()`: ASCII-only,
  1-30 chars, `^[[:alnum:]][-.\_[:alnum:]]{0,28}[[:alnum:]]$` for
  multi-char, `^[a-zA-Z0-9]$` for single-char). Reusable in principle —
  but see §2's decision on whether to import it.
- **PHP coding conventions** (re-confirmed for this task): tabs, `final
  class`, `namespace Hestiacp\Adapter`-per-directory, manual
  `require_once` (no PSR-4 entry), small single-purpose exception classes
  extending `\RuntimeException` for mechanism failures
  (`LockUnavailableException`) vs. `\InvalidArgumentException` for
  caller-input-shape failures (`CommandRegistry` construction, grepped
  four call sites). This task's new classes follow the same conventions,
  in a new `Hestiacp\Auth` namespace matching `AccessKeyValidator`'s own.

## 2. Design Decisions

### 2.1 Credential Identity

- **Format**: 40 lowercase hex characters — `bin2hex(random_bytes(20))`,
  160 bits of entropy.
- **Generated server-side**: always; no caller-supplied id is accepted
  anywhere in this component's public contract.
- **Character set**: `[0-9a-f]` only — filesystem-safe by construction
  (no path separators, no non-ASCII, no shell metacharacters), needs no
  additional escaping to become a filename.
- **Uniqueness**: not "guaranteed" by entropy alone (no random scheme
  can promise that) — guaranteed **operationally** by the atomic-creation
  primitive in §2.3: a candidate id is only ever considered "claimed"
  once `fopen($path, 'x')` actually succeeds against that exact path,
  which is a single, race-free filesystem operation. Entropy (160 bits)
  makes a real collision between two independently generated ids
  astronomically unlikely (this is the same reasoning
  `AUTHORIZATION_POLICY_IMPLEMENTATION.md`/`SameUserAuthorizerTest.php`
  already rely on implicitly for temp-directory names); the retry loop
  in §2.4 is defense-in-depth for that near-zero case, not the actual
  correctness mechanism.

### 2.2 Secret Generation

- `bin2hex(random_bytes(32))` — 64 hex characters, 256 bits of entropy,
  the same CSPRNG primitive already used one file away (§1). This is
  deliberately more entropy than the legacy mechanism's 40-character,
  non-CSPRNG secret (`API_V2_AUTHENTICATION_DESIGN.md` §2) — not chosen
  arbitrarily, but because there is no reason to under-provision entropy
  when `random_bytes()` makes 256 bits exactly as cheap as 128.
- The secret is held in a local PHP variable for the minimum time needed
  to (a) compute its `password_hash()` and (b) place it into the
  returned result object (§2.6) — never written to any file, never
  logged, never placed in an exception message (§2.7, verified by test).
  PHP has no language-level way to force early zeroing of a string's
  memory (unlike e.g. `sodium_memzero()` for libsodium-backed values,
  which this task does not introduce — no such extension dependency
  exists elsewhere in this codebase); this is documented as a known,
  unavoidable limitation of implementing this in plain PHP, not silently
  ignored.

### 2.3 Storage / Atomicity

**Exact schema — unchanged from `AccessKeyValidator`'s existing
contract, not renegotiated:**

```json
{ "user": "admin", "secret_hash": "$2y$10$..." }
```

Directory: `AccessKeyValidator::DEFAULT_CREDENTIAL_DIRECTORY` — the
provisioner imports and reuses this exact constant (not a copy-pasted
string literal) so the two classes structurally cannot drift apart on
where credentials live. This is the one intentional coupling between the
two `Hestiacp\Auth` classes; it is not a dependency on HTTP, sessions,
CommandAdapter, or AuthorizerInterface, so it does not violate this
task's independence requirements.

**Atomic creation strategy**: `fopen($path, "xb")` — `O_CREAT | O_EXCL`,
a single atomic kernel syscall. This was chosen over the alternative
"write to a temp file, then `rename()` into place" strategy specifically
**because `rename()` is not collision-safe on POSIX**: `rename()`
unconditionally replaces an existing destination file with no error and
no atomic "only if absent" mode. Using temp+rename for this use case
would silently let a second, independently generated (and, per §2.1,
near-impossible but not impossible) colliding id **overwrite** a
just-created credential with no detection at all — a real correctness
regression, not merely a style choice. `fopen(..., "xb")` fails
immediately and detectably on collision instead, which is exactly the
property §2.4's retry logic depends on.

**Residual TOCTOU window, stated explicitly rather than assumed away**:
between `fopen(..., "xb")` succeeding (the file now exists, zero bytes)
and the subsequent `fwrite()`/`fclose()` completing, a concurrent
`AccessKeyValidator::authenticate()` call reading that exact id would see
an empty or partial file. `json_decode()` on empty/partial content
returns `null`/fails to decode to an array, which `AccessKeyValidator::readCredentialRecord()`
already treats as "no record" — i.e., the failure mode during this
microsecond window is **fail-closed** (a real, brand-new credential
would transiently be rejected as if it didn't exist yet, never accepted
as valid before it is fully written). This is judged acceptable: it is a
narrower and safer risk than the alternative (silent overwrite via
`rename()`), it self-heals as soon as the write completes, and closing it
entirely would require either a read-lock protocol between the validator
and the provisioner (which the validator's existing, already-shipped,
already-tested implementation does not have and this task must not
modify — constraint 3) or filesystem-level atomic rename tricks with
their own overwrite hazard (above). Documented, not silently accepted.

### 2.4 Collision Handling

Bounded retry loop, **5 attempts**: generate a fresh candidate id (§2.1),
attempt `fopen($path, "xb")`; if it fails specifically because the target
already exists, discard the candidate and try again with a newly
generated id; if it fails for any other reason (directory missing,
permission denied), that is a storage-availability failure, not a
collision, and is not retried (see §2.7). If all 5 attempts collide, a
`CredentialProvisioningException` is thrown naming the exhaustion
explicitly. Five is chosen as a generous, clearly-defensive bound given
§2.1's actual collision probability — this retry loop is not expected to
ever exhaust in real operation; it exists to fail loudly and
deterministically rather than loop unboundedly (unlike the legacy
`while [[ -e ... ]]` loop's unbounded retry) if entropy or storage were
ever somehow degraded.

### 2.5 Credential Lifecycle

| Operation | Status |
|---|---|
| Create | Implemented this task (`AccessKeyProvisioner::create()`). |
| Revoke/delete | Implemented this task (`AccessKeyProvisioner::revoke()`) — a thin, strictly-validated wrapper around deleting the record file, mirroring `bin/v-delete-access-key`'s semantics exactly (§1). |
| Rotation | **Not implemented.** No source evidence requires it now (constraint: "do not implement rotation yet unless the design evidence strongly requires it" — it does not; nothing in `API_V2_AUTHENTICATION_DESIGN.md` or this task's own brief treats rotation as a current blocker). When built, the correct shape is almost certainly "revoke the old id, create a new one" (never an in-place secret swap under the same id — that would reintroduce exactly the kind of read/write race §2.3 already documents a fail-closed answer for, deliberately, not one that should be reopened by a same-id in-place update). |

### 2.6 Returned Representation

**A dedicated value object, not a generic array**:
`CredentialCreationResult` — three public, typed properties (`$id`,
`$secret`, `$user`), constructed once by `AccessKeyProvisioner::create()`,
handed to the caller, and never stored anywhere by this component itself.
A generic array was rejected because a typed object makes "this specific
value is the one-time plaintext secret" a property of the *type system*
(a reviewer or future maintainer sees `CredentialCreationResult::$secret`
and immediately knows its sensitivity/one-time nature from its
declaration site, the same way `AdapterResult`'s own typed properties
document each field's meaning at the declaration, not by convention
alone) rather than a bare array key a caller could typo, log wholesale
(`json_encode($genericArray)`), or accidentally merge into an unrelated
structure. This mirrors `AdapterResult`'s own existing precedent
(`web/inc/adapter/AdapterResult.php`) for "a small, public-property,
non-`readonly` (matching this codebase's PHP-7.4-compatible style, §1),
final class" over a bare array.

### 2.7 Error Model

| Condition | Exception |
|---|---|
| Invalid user (empty, non-ASCII, fails the shape check) | `\InvalidArgumentException` — mirrors `CommandRegistry`'s own convention for caller-input-shape errors (§1). |
| Invalid id passed to `revoke()` (empty, contains a path separator, `basename()` mismatch) | `\InvalidArgumentException` — this is a caller-programming error (revoke is only ever called with an id the caller already legitimately holds), not an authentication-boundary case, so — unlike `AccessKeyValidator::authenticate()` — there is no requirement to hide "this id is malformed" behind a uniform failure value here. |
| Storage unavailable (credential directory missing, not writable) | `CredentialProvisioningException` (new, extends `\RuntimeException`, mirrors `LockUnavailableException`'s "mechanism failure, not ordinary contention" precedent). |
| Credential id collision exhaustion (§2.4) | `CredentialProvisioningException`. |
| Atomic write failure (`fopen` succeeds, `fwrite`/`fclose` does not) | `CredentialProvisioningException`; the partially-created file is `unlink()`ed before the exception propagates, mirroring `CommandAdapter::writeSecureTempFile()`'s own cleanup-on-failure idiom (§1). |
| Invalid *existing* credential record encountered during `revoke()` | Not applicable — revoke only ever deletes by id; it never parses record content, so a malformed existing record cannot affect it either way. |

No exception message anywhere in this component interpolates the
plaintext secret — verified by test (§ "Tests," item 16). HTTP status
codes are deliberately not decided here, per this task's own instruction
and `API_V2_AUTHENTICATION_DESIGN.md` §8's already-stated deferral of that
mapping to a future API-layer decision.

### 2.8 Authorization Boundary

Not decided here, by design. `AccessKeyProvisioner` has no notion of
"who is allowed to call `create()`/`revoke()`" — it is a narrow
credential-management primitive that trusts its caller completely, the
same way `LockManager::acquire()` trusts that `CommandAdapter` has
already authorized the request before ever calling it. Whatever
eventually calls this class (an admin CLI tool, a future authenticated
API v2 management endpoint, etc.) is responsible for deciding whether the
current actor may provision a credential for the given user — that
decision explicitly belongs to a future service/authorization layer, not
this component, per this task's constraint 10.

### 2.9 Concurrency

Two simultaneous `create()` calls (from two separate PHP processes, e.g.
two concurrent PHP-FPM workers) do not collide in practice (§2.1's 160
bits of entropy), and even in the near-impossible case that they generate
the same candidate id, `fopen($path, "xb")`'s atomicity guarantees
exactly one of them wins that specific path; the other observes the
failure and retries with a fresh id (§2.4) — no data is ever silently
overwritten. No global lock is introduced: `LockManager` exists for a
different problem (serializing *mutating operations against the same
Hestia user* through the adapter pipeline) and nothing about credential
provisioning shares that problem shape — two different callers
provisioning two different (or even the same) user's credentials are
not stepping on each other's state the way two concurrent `domain.create`
calls for the same user would be. No source evidence found anywhere in
this repository suggesting a lock is needed here; per this task's own
instruction, one is not introduced.

## 3. Directory / Permissions (documented, not created by this task)

Following the `adapter-locks` precedent (§1) for whenever a future
installer task actually provisions `$HESTIA/data/api-credentials/` in
production:

- **Directory**: `chmod 770`, `chown hestiaweb:hestiaweb` — identical to
  `adapter-locks`'s own convention, since the same PHP-FPM process
  (`hestiaweb`) is both the sole writer (`AccessKeyProvisioner`) and sole
  reader (`AccessKeyValidator`).
- **Individual credential file**: `chmod 0600` (owner-only) — stricter
  than the legacy access-key file's `640` (§1), and deliberately so:
  unlike the legacy mechanism, there is no known, evidenced second
  consumer (group member) that legitimately needs to read a
  `secret_hash` value, and `0600` mirrors `CommandAdapter::writeSecureTempFile()`'s
  own already-established convention for exactly this class of sensitive
  file (`SENSITIVE_PARAMETER_DESIGN.md`).

**This task creates no directory in production and modifies no installer
script** — `AccessKeyProvisioner`'s default credential directory constant
is imported from `AccessKeyValidator` (§2.3) exactly as that class
already defines it (a path that does not exist on disk in this
repository, by design — `ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md` §2
already established this). Every test uses a fresh temp directory,
created and `chmod`ed by the test itself, never the real path.

## 4. STOP Condition Check

None triggered:

- The proposed directory (`api-credentials/`) has a direct, positive
  install-time precedent (`adapter-locks`) proving a `hestiaweb`-owned,
  non-root PHP-FPM-writable data directory is a supported pattern in this
  codebase — not a novel or unsafe request.
- `random_bytes()` and `password_hash()`/`password_verify()` are already
  in active use in this exact subsystem (§1) — no PHP version gap.
- Atomic credential creation **is** guaranteed by `fopen($path, "xb")`,
  a standard single-syscall PHP primitive — no filesystem limitation
  found.
- `AccessKeyValidator`'s existing storage contract (JSON, `{user,
  secret_hash}`, directory constant) is internally consistent with this
  provisioning design — this task writes exactly the shape that class
  already reads, unchanged.
- No change to Hestia's existing privilege boundary is required — this
  component runs entirely inside the same PHP-FPM (`hestiaweb`) process
  space `AccessKeyValidator` already assumes, with no `sudo`, no
  privileged file access, no interaction with root-owned data.
- No credential-management authorization decision is required to
  implement this *primitive* safely — per §2.8, that decision is
  explicitly out of this component's scope and belongs to whatever calls
  it.

**Implementation proceeds.**
