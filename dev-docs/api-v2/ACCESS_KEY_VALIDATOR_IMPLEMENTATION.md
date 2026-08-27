# AccessKeyValidator Implementation

Implements the exact next step named in `API_V2_AUTHENTICATION_DESIGN.md`
§13: a standalone, HTTP-independent `AccessKeyValidator` with
`authenticate(string $id, string $secret): ?string`. This document is
written before any code, per that task's instruction, then updated with
final findings/verification at the end.

---

## 1. Inspection Findings (before coding)

Re-confirmed directly from source (no findings taken on faith from the
prior design document without a fresh check):

- **Legacy storage format** (`bin/v-add-access-key` lines 84-92): one
  file per key at `$HESTIA/data/access-keys/<access_key_id>`, `chmod 640`,
  shell `KEY='value'` lines (`SECRET_ACCESS_KEY`, `USER`, `PERMISSIONS`,
  `COMMENT`, `TIME`, `DATE`, `EXPIRES_IN` (always `''`), `IP` (always
  `''`)). Parsed by `source_conf` (a Bash `source` of the file) — this is
  a **Bash-`source`-based format, not something safe or idiomatic to
  parse from PHP** (there is no PHP-side parser for it anywhere in the
  repository; every reader is a Bash script).
- **`v-check-access-key`** (`bin/v-check-access-key`): the sole
  authentication entry point for this mechanism today. Confirmed single
  caller: `web/api/index.php::api_connection()` (grepped `v-check-access-key`
  across `bin/`, `web/`, `plugins/` — one call site, matching
  `API_V2_AUTHENTICATION_DESIGN.md` §2's own finding).
- **Key generation** (`bin/v-add-access-key` lines 28-46): `keygen()`
  using Bash `$RANDOM` (non-CSPRNG, 15-bit). No other script generates a
  key. Nothing in this task creates access keys — see §6 below.
- **`PERMISSIONS` handling** (`func/main.sh` lines 1699-1753,
  1968-2042): a comma-separated list of names, each resolved against
  `$HESTIA/data/api/<name>` files declaring `COMMANDS`/`ROLE`. This is a
  real scope mechanism, but per the task's explicit instruction 8
  ("existing permission/scoping semantics should not be expanded in this
  task") and `API_V2_AUTHENTICATION_DESIGN.md` §6 (scopes explicitly
  deferred to a future `AuthorizerInterface` implementation, not the
  authentication layer), `AccessKeyValidator` does not read, store, or
  return `PERMISSIONS`/scope data in any form. Its contract is
  `authenticate(): ?string`, nothing more.
- **Deletion/revocation** (`bin/v-delete-access-key`): unconditional file
  removal, no soft-disable flag exists anywhere in the current format.
- **Expiration** (`bin/v-add-access-key` line 91,
  `API_V2_AUTHENTICATION_DESIGN.md` §1): `EXPIRES_IN=''` is written at
  creation and never read back by any script. Confirmed by grep: no
  `EXPIRES_IN` reference anywhere outside that one write line. A source-
  verified dead field. See §5 below for the decision this task makes
  about it.
- **Configuration paths/constants worth reusing**: `LockManager.php`
  (`web/inc/adapter/LockManager.php` lines 29-47) establishes the
  project's existing convention for a filesystem-backed, DI'd storage
  location: a `public const DEFAULT_*_DIRECTORY` class constant, a
  constructor parameter defaulting to it, and `rtrim(..., "/") . "/"`
  normalization. `AccessKeyValidator` mirrors this convention exactly
  (see §2) rather than inventing a new one.
- **PHP coding conventions**: `web/inc/adapter/*.php` — `declare` no
  strict_types (none of the existing adapter files declare it, so this
  file does not either, for consistency), tabs for indentation, `final
  class`, PHPDoc-heavy docblocks explaining *why*, namespace
  `Hestiacp\Adapter`. No composer PSR-4 autoload entry exists for this
  namespace either (`web/inc/composer.json` reviewed — only third-party
  runtime dependencies are declared); every consumer manually
  `require_once`s what it needs, exactly as `web/inc/adapter/bootstrap.php`
  does. This task follows the same manual-require pattern rather than
  adding a PSR-4 entry, since doing so is out of scope and unnecessary for
  one class.
- **Existing password-hashing precedent**: `password_hash()`/
  `password_verify()` with `PASSWORD_DEFAULT` are already used elsewhere
  in this codebase for unrelated features (`web/reset/index.php` lines
  34-35, 171; `web/reset/mail/index.php` lines 87-90) — this is not a new
  primitive being introduced to the project, it is reuse of an existing,
  established pattern, exactly as the task instructed ("use PHP's
  password hashing/verification primitives where they fit the design
  rather than inventing a custom cryptographic scheme").
- **Existing filesystem-backed credential-storage abstraction**: none
  exists that is reusable as-is. `LockManager` is filesystem-backed but
  stores no credential data (it is a pure `flock()` mutex). The legacy
  access-key file format (above) is Bash-`source`-shaped and therefore
  not a safe target for a PHP reader/writer without either re-implementing
  a shell-config parser in PHP (fragile, and exactly the kind of custom
  parsing this task should avoid) or changing that format's semantics in
  place (explicitly prohibited by this task unless the design requires
  it — it does not; see §2).

## 2. Storage Format Decision

**A new, small storage abstraction is introduced — the legacy format is
not modified, extended, or reused for parsing.** Per the task's own
framing: "If the existing format cannot support hashed secrets safely
without breaking compatibility, document that clearly and create the
smallest new storage abstraction necessary." The existing format's
concrete problem is not merely "it stores a plaintext secret" (a field
could, in principle, be repurposed to hold a hash) — it is that the
format itself is Bash-`source`-parsed, single-purpose, and has no PHP
reader anywhere in the codebase. Reusing it would mean either writing a
new PHP parser for an ad hoc shell-config dialect (introducing exactly
the kind of custom, fragile parsing surface this task should minimize) or
literally shelling out to `source_conf` from PHP (which reintroduces
`exec()` and CLI-argument-passed secrets — the two things the design
review flagged and this task explicitly forbids). Neither is compatible
with "free of shell execution" / "free of `exec()`".

**Chosen format:** one JSON file per credential, named by its id, under a
new, dedicated directory:

```
$HESTIA/data/api-credentials/<id>
```

```json
{
    "user": "admin",
    "secret_hash": "$2y$10$....................................................."
}
```

- `user` — the Hestia username this credential authenticates as (mirrors
  the legacy format's `USER` field, one credential → one user, per this
  task's instruction 7).
- `secret_hash` — the output of `password_hash($secret, PASSWORD_DEFAULT)`.
  Never the plaintext secret.

JSON was chosen over re-deriving a shell-config-shaped format because
`json_decode`/`json_encode` are PHP built-ins with no parsing code for
this class to own, get wrong, or need to test — the same reasoning that
led `SENSITIVE_PARAMETER_DESIGN.md`'s temp-file mechanism to reuse an
existing convention rather than invent one, applied here to storage
instead of argv delivery.

**Why a new directory (`api-credentials/`) instead of writing hardened
records into the existing `access-keys/` directory:** mixing hardened
(hashed, JSON) and legacy (plaintext, shell-sourced) records in the same
directory, distinguished only by content shape, would make every reader
(this class, and any future `v-check-access-key`-based code) responsible
for disambiguating formats before it could even validate a credential —
a foot-gun that a distinct directory avoids entirely for the cost of one
new constant. This directory does not exist yet on disk; nothing in this
task creates it in production (see §6 — generation is explicitly out of
scope), so no real deployment is affected by this decision. It is
documented here as the path the class defaults to, for whenever a future
task implements generation/provisioning.

**No production migration.** Per the task's explicit instruction, no
existing access key is migrated, converted, or dual-read in this task.
The two mechanisms (legacy `access-keys/`, new `api-credentials/`) are
completely independent; nothing in this task makes `AccessKeyValidator`
aware that the legacy directory exists.

## 3. Authentication Algorithm

```
authenticate(id, secret):
    if id == "" or secret == "":
        return null

    record = readCredentialRecord(id)   # null if: id contains "/" (path-
                                         # traversal guard), file absent,
                                         # unreadable, or not valid JSON
                                         # decoding to an array

    hash = record.secret_hash if record has a valid non-empty string
           secret_hash field, else a process-lifetime dummy bcrypt hash
           (constant-time-shape decoy, see §4)

    verified = password_verify(secret, hash)

    if record is null, or !verified, or record.user is missing/empty/
       non-string:
        return null

    return record.user
```

`password_verify()` is PHP's own, already-used-elsewhere (§1) primitive
for exactly this comparison; it performs the hash comparison in constant
time internally (this is documented PHP behavior, not a property this
class re-implements) — satisfying "timing-safe comparison" and "use PHP's
password hashing/verification primitives ... rather than inventing a
custom cryptographic scheme" directly, without a hand-rolled `hash_equals()`
call over raw bytes.

## 4. Existence-Oracle Mitigation

The task's SECURITY section explicitly requires "invalid credentials do
not disclose credential existence." A naive implementation
(`if (record === null) return null early, else password_verify(...)`)
would make an unknown id return in roughly file-stat time, while a known
id with a wrong secret returns in roughly bcrypt-verify time (single-digit
milliseconds) — a measurable difference. `AccessKeyValidator` always calls
`password_verify()` against *some* valid-shaped bcrypt hash, whether or
not a real record was found, using a lazily-computed, process-lifetime
"dummy hash" (`password_hash(random_bytes, PASSWORD_DEFAULT)`) when no
real `secret_hash` is available. This is a best-effort mitigation, not a
formal guarantee — filesystem stat/read time still varies slightly
between "file present" and "file absent," and this task does not attempt
to eliminate that residual channel (doing so would mean normalizing disk
I/O timing, which is infrastructure-level work far outside "do not
over-engineer this"). This is marked explicitly rather than silently
assumed solved.

## 5. Expiration Decision

**Expiration enforcement is intentionally NOT implemented in this task,**
and — a stronger decision than mere non-enforcement — **the storage
schema in §2 does not include an `expires_at` (or equivalent) field at
all.**

Reasoning: `API_V2_AUTHENTICATION_DESIGN.md` §1 identified the legacy
`EXPIRES_IN` field as a source-verified example of exactly the anti-
pattern this task should not repeat — a field that exists in a stored
record's schema but that no code path ever reads back, silently implying
a guarantee ("this key expires") that is not actually enforced anywhere.
Adding an unenforced `expires_at` field to the new hardened schema now
would reproduce that same anti-pattern in the new format instead of
fixing it. `API_V2_AUTHENTICATION_DESIGN.md` §12 also explicitly lists
"expiration enforcement" under "what should remain deferred."

**What must happen later:** when expiration enforcement is actually
built, the storage schema (§2) and `AccessKeyValidator::authenticate()`
must change together, in the same task, so the field is never present
without being read. That follow-up task would add an `expires_at` field
(a Unix timestamp or ISO-8601 string, nullable for "does not expire") and
a clock dependency to `AccessKeyValidator`'s constructor (mirroring how
`CommandAdapter` already injects `?callable $clock` for exactly this kind
of testability — `web/inc/adapter/CommandAdapter.php` constructor), so
tests can control "now" without depending on real time, the same
technique `DatabaseCreateTest`/`DatabaseDeleteTest`/etc. already use for
deterministic `AdapterResult::$startedAt` values.

Because the current schema has no `expires_at` field at all, "an expired
credential" is not a constructible state in this task's test suite.
Test 9 (§7) instead verifies the closely related, actually-decided
property: an unrecognized/extra field in a stored JSON record (including
one named `expires_at`, simulating a future or hand-edited record) is
silently ignored rather than causing an error or a bypass — i.e., the
class is forward-compatible with a future schema addition without
needing to understand it yet, and does not treat an extra field's mere
presence as either a pass or fail signal today.

## 6. Revocation/Disabled Decision

**Revocation is modeled identically to the legacy mechanism: deleting the
credential's record file.** No separate `disabled`/`suspended` boolean is
added to the schema. This mirrors `bin/v-delete-access-key`'s own
unconditional-`rm` semantics exactly (§1), requires no new field, and
avoids expanding the permission/scoping surface — consistent with the
task's instruction 8. A record that has been revoked is, from
`AccessKeyValidator`'s point of view, indistinguishable from an id that
never existed — both fall through `readCredentialRecord()` returning
`null` and both produce the same external `null` result, exactly as
`API_V2_AUTHENTICATION_DESIGN.md` §8's "Revoked credentials" category
already anticipated ("indistinguishable from 'never existed' in the
*current* access-key implementation").

## 7. Secret Generation

**Not implemented in this task**, per the task's own instruction ("do not
put credential generation into the validator itself unless the existing
architecture clearly requires it" — it does not; `AccessKeyValidator`'s
contract is read-only verification). No script, class, or CLI tool that
creates an `api-credentials/` record is added.

**Next integration step, explicitly deferred:** a small, separate
generation component (analogous to `bin/v-add-access-key`, but PHP-side
and CSPRNG-based — `random_bytes()`/`bin2hex()`, mirroring
`SameUserAuthorizerTest.php`'s own use of `random_bytes()` for
unpredictable test directory names) that produces an id + secret pair,
computes `password_hash($secret, PASSWORD_DEFAULT)`, and writes the
`{user, secret_hash}` JSON record to `api-credentials/<id>`. This is
intentionally left for a dedicated future task rather than bundled here,
consistent with "do not over-engineer this" / "credential management UI"
and "credential migration" being explicitly out of scope.

## 8. Files Added

- `web/inc/auth/AccessKeyValidator.php` (new file, new directory,
  namespace `Hestiacp\Auth` — deliberately not `Hestiacp\Adapter`: this
  class has no dependency on, and is not part of, the adapter's operation
  pipeline; giving it its own namespace/directory makes that independence
  structural, not just documented).
- `test/auth/AccessKeyValidatorTest.php`, `test/auth/run_tests.php` (new
  directory, mirroring `test/adapter/`'s existing `MiniTest`-based
  convention — reusing `test/adapter/MiniTest.php`'s `MiniTest` class and
  `assertTrue`/`assertFalse`/`assertEquals` functions directly via
  `require_once`/`use function`, rather than duplicating that tiny,
  generic, already-shared test runner. This is test-infrastructure reuse
  only; no production coupling to the adapter is introduced).

No file under `web/inc/adapter/` or `test/adapter/` is modified by this
task.

## 9. Security Properties Verified

Mechanically verified, not just asserted, via `test/auth/AccessKeyValidatorTest.php`
tests 12-14 and manual re-inspection of the final source:

- **No plaintext secret stored**: the only value ever written by this
  task's tests as `secret_hash` is `password_hash()`'s own output; the
  class never writes a credential record at all (§7 — generation is out
  of scope), so it cannot itself introduce plaintext storage.
- **No plaintext secret in returned structures**: `authenticate()`'s
  return type is `?string`, and that string is always `$record["user"]`
  — never the secret, never the hash. Test 12 confirms the secret
  substring never appears in the returned value.
- **No secret written to logs**: the class contains no logging call of
  any kind (no `error_log`, no `echo`, no `file_put_contents` other than
  test fixtures writing their own input records) — confirmed by reading
  the final source in full.
- **No shell command contains the secret**: the class performs zero
  subprocess/shell invocation at all (test 13, mechanical source grep for
  `exec(`/`shell_exec(`/`proc_open(`/`passthru(`/`system(`/`popen(`/
  backtick-in-code).
- **`password_verify()`/`password_hash()` used correctly**: hashing uses
  `PASSWORD_DEFAULT` (the same convention already used elsewhere in this
  codebase, §1); verification is always `password_verify($secret, $hash)`
  — never a manual comparison of hash bytes, never `==`/`!=` against the
  stored value. Test 8 specifically proves a plaintext value stored where
  a hash was expected is rejected, ruling out any accidental
  string-comparison fallback path.
- **Comparison is timing-safe**: delegated entirely to `password_verify()`,
  which PHP implements as constant-time by design. Test 4's/§4's
  existence-oracle mitigation (always calling `password_verify()` against
  a valid-shaped hash, real or dummy) additionally narrows the
  found-vs-not-found timing gap, documented in §4 as best-effort, not a
  formal guarantee.
- **Invalid credentials do not disclose existence**: the public contract
  is a single `?string` return with no distinguishable failure reason
  (verified structurally — there is exactly one `return null;`-reachable
  outcome shape for every failure branch in `authenticate()`).

## 10. Tests Added

`test/auth/AccessKeyValidatorTest.php` (19 tests) + `test/auth/run_tests.php`
(entry point). Covers all 14 categories the task listed, several split
into sub-cases for precision (6a-6e for the distinct "malformed record"
shapes):

1. valid id + valid secret → username
2. valid id + wrong secret → null
3. unknown id → null
4. empty id → null
5. empty secret → null
6. malformed record: invalid JSON, JSON array (not object), missing
   `secret_hash`, missing `user`, non-string `user`
7. hashed-secret verification (proves `password_hash`/`password_verify`
   round-trip, not a literal string match)
8. a plaintext value stored as `secret_hash` is never accepted
9. an unrecognized/future field (including a past-dated `expires_at`) is
   silently ignored, per §5's deferral decision
10. a revoked (deleted) credential behaves exactly like an unknown id
11. multiple credentials/users cannot cross-authenticate
12. no plaintext secret appears in `authenticate()`'s return value
13. no shell execution anywhere in the class's source
14. no HTTP/session superglobal reference anywhere in the class's source
15. (added beyond the task's list, since it directly serves the SECURITY
    section's spirit) a path-traversal-shaped id is rejected outright

## 11. Full Test-Suite Result

- `php test/auth/run_tests.php` × 3 consecutive runs: **19 passed, 0
  failed** each time.
- `php test/adapter/run_tests.php` × 3 consecutive runs: **198 passed, 0
  failed** each time — unchanged from before this task; no adapter test
  was added, removed, or modified.

## 12. Genericity Checks

Mechanical re-inspection of `web/inc/auth/AccessKeyValidator.php`'s final
source (grep for forbidden constructs, then manual read of the whole
file):

- No `$_POST`/`$_GET`/`$_SESSION`/`$_SERVER`/`$_COOKIE`/`$_REQUEST`
  reference (the two doc-comment hits for "CommandAdapter"/
  "AuthorizerInterface" are prose explaining what this class deliberately
  does *not* depend on, and the one hit for `v-delete-access-key` is a
  doc-comment citation of the legacy script this class's revocation
  semantics were modeled after — neither is a code dependency).
- No reference to `CommandAdapter`, `AuthorizerInterface`, or any
  adapter class as an actual import/type/call — confirmed by the same
  grep and by the file's `use` statements (there are none; the file
  declares only its own namespace).
- No operation-specific logic (`domain.*`, `database.*`, `backup.*`) and
  no reference to any `bin/v-*` script by name in executable code.
- `web/inc/adapter/CommandAdapter.php` was not touched by this task — the
  only modifications visible in `git status` to that file (and to
  `test/adapter/*`) are the pre-existing, already-reported uncommitted
  changes from the prior `SameUserAuthorizer`/authorization-policy task,
  unchanged by anything done here. `git status --short` after this task's
  work shows exactly two new paths beyond that pre-existing set:
  `web/inc/auth/` and `test/auth/` (plus this document and its
  predecessor's `.md` siblings, all untracked, all documentation).

## 13. Compatibility Concerns with the Legacy Access-Key Mechanism

None that block this task, and none introduced by it:

- The two mechanisms are fully independent — different directories
  (`data/access-keys/` vs. `data/api-credentials/`), different formats
  (Bash-`source`-shaped vs. JSON), different readers (`v-check-access-key`
  vs. `AccessKeyValidator`). A legacy key and a hardened credential can
  coexist with zero interaction.
- The `PERMISSIONS`/scope model that exists for legacy keys has no
  equivalent in the new schema (§1, item 8 of the task) — this is a
  known, deliberate capability gap versus the legacy mechanism, not an
  oversight. Any API v2 caller relying on scoped credentials cannot be
  served by this class alone yet; that gap is inherited from
  `API_V2_AUTHENTICATION_DESIGN.md` §6's own explicit deferral of
  `scopes` to a future `AuthorizerInterface` implementation.
- No admin-equivalent semantics (§7 of `API_V2_AUTHENTICATION_DESIGN.md`
  — an admin-owned legacy key can impersonate any user) exist in the new
  schema either; every hardened credential is a plain 1:1 id→user
  mapping with no elevated-privilege concept. This is a narrowing, not a
  compatibility break, and is consistent with "existing permission/scoping
  semantics should not be expanded in this task."

## 14. Remaining Work Before Authentication Can Be Wired Into API v2

In the order `API_V2_AUTHENTICATION_DESIGN.md` §13/§5 already implied:

1. **Credential generation/provisioning** (§7 above) — a PHP-side
   equivalent of `bin/v-add-access-key` that actually writes
   `api-credentials/<id>` records using a CSPRNG. Not started.
2. **Wiring `AccessKeyValidator::authenticate()`'s result into an
   `actor` array** (`{"user": $result}`) at whatever future HTTP entry
   point API v2 gets — explicitly not done in this task, per its own
   instructions.
3. **The HTTP entry point itself** — a new, `CommandRegistry`-mediated
   route, structurally separate from `web/api/index.php`
   (`API_V2_AUTHENTICATION_DESIGN.md` §10) — does not exist yet.
4. **Expiration enforcement**, if and when actually decided to be needed
   — requires the schema + clock-injection change described in §5,
   bundled together in one task so the field is never dead on arrival.
5. **API error-vocabulary decisions** (`API_V2_ARCHITECTURE_REVIEW.md`
   §11, `API_V2_AUTHENTICATION_DESIGN.md` §8) — how the six
   authentication outcome categories map to actual HTTP responses, still
   an open, deliberately deferred decision.

## 15. STOP/READY Verdict

**READY.** No ambiguity was found in the repository's access-key storage
model that required stopping before coding. The one genuine design
question this task's brief flagged as needing a decision — whether the
hardened validator should enforce expiration now — was resolved by
direct textual evidence already present in `API_V2_AUTHENTICATION_DESIGN.md`
§1/§12 (the legacy field is a documented dead stub, and deferral is
explicitly listed as the correct choice), not by guessing; §5 above
records that reasoning. `AccessKeyValidator` is implemented, independently
unit-tested (19/19, ×3), does not touch `CommandAdapter`,
`AuthorizerInterface`, `SameUserAuthorizer`, `LockManager`, the
`CommandRegistry`, any `bin/v-*` script, or sudoers, and introduces no
HTTP endpoint. The full adapter suite remains at 198/198. Nothing was
committed.
