# API v2 Authentication Architecture Review

Analysis only. No PHP source was modified, no test was modified, no adapter
class (`CommandAdapter`, `AuthorizerInterface`, `SameUserAuthorizer`,
`LockManager`) was touched, and no new API endpoint or adapter operation
was added. This document is the direct continuation of
`API_V2_ARCHITECTURE_REVIEW.md` (which named "a real authentication
mechanism" as one of four REQUIRED-BEFORE-API-V2 items) and
`AUTHORIZATION_POLICY_IMPLEMENTATION.md` (which closed the authorization
half of that pair with `SameUserAuthorizer`). This document addresses
authentication: producing a trustworthy `actor.user` in the first place.

---

## 1. Existing Authentication Mechanisms

Traced directly from source. Four distinct mechanisms exist in the
repository today:

| Mechanism | Entry point | Identity produced | Where used |
|---|---|---|---|
| Session cookie (password + optional 2FA) | `web/login/index.php` → `$_SESSION["user"]` | Hestia username | Web panel (`web/inc/main.php`) |
| Legacy API password/hash | `web/api/index.php::api_legacy()` | Hestia username (must be `ROOT_USER`) | `web/api/index.php` "legacy" branch |
| Legacy API pre-hashed key file | `web/api/index.php::api_legacy()` else-branch, `v-check-api-key` | Whatever `v-check-api-key` resolves | Same file, `hash=` form, distinct from access keys (see caveat below) |
| Access key (id + secret) | `web/api/index.php::api_connection()` → `v-check-access-key` | Hestia username (`USER` field of the key file) | `web/api/index.php` "connection" branch |

**Caveat, stated explicitly because it is easy to conflate:** `api_legacy()`'s
`hash=` branch calls `v-check-api-key` against `$HESTIA/data/keys/`, which
is a **different, older mechanism** from the access-key system
(`v-check-access-key` against `$HESTIA/data/access-keys/`) that Section 2
reviews in depth. `bin/v-check-api-key` was not read in detail for this
document — it is legacy-path plumbing this review does not recommend
reusing regardless (see §10), so its exact contract was not traced further.
This is marked here so it is not silently conflated with the access-key
system.

**Session authentication** (`web/inc/main.php`, `web/login/index.php`):
password is checked via `crypt()` against a per-user salt+hash stored by
Hestia's own user store (`v-get-user-salt`, `v-check-user-hash`,
supporting `md5`, `sha-512`, `yescrypt`, `des` — `web/login/index.php`
lines ~146-173), optionally followed by a TOTP-style check
(`v-check-user-2fa`, line 271). On success, `$_SESSION["user"] = key($data)`
(line 319, `$data` from `v-list-user`). `web/inc/main.php` then binds the
session to the requesting IP (`user_combined_ip`, lines 39-58), enforces an
inactivity timeout (`INACTIVE_SESSION_TIMEOUT`, lines 99-117), and supports
admin impersonation via `$_SESSION["look"]` gated on
`$_SESSION["userContext"] === "admin"` (line 133).

**Password authentication** is therefore not a separate mechanism — it is
the credential session authentication is built on. There is no standalone
"check a password and get a token back" primitive; `v-check-user-hash`'s
contract is specific to the session-cookie login flow (temp-file hash
delivery, IP argument, no returned credential).

**Expiration, revocation, scopes, user binding — access keys specifically**
(the only access-control primitive with the URL-callable, non-session
shape API v2 would need): a key is bound to exactly one user
(`USER` field, `bin/v-add-access-key` line 85); it can be deleted
(`bin/v-delete-access-key`, unconditional `rm`); it has a scope field
(`PERMISSIONS`, resolved against files under `$HESTIA/data/api/*` via
`get_apis_commands()`, `func/main.sh` lines 2017-2042) that is real and
already at least partially implemented; but it has **no expiration** —
`bin/v-add-access-key` line 91 writes `EXPIRES_IN=''` with the source
comment `# TODO Index reserved for future implementation`, and no code
path in `func/main.sh` or any `bin/v-*-access-key` script reads
`EXPIRES_IN` back. This is a source-verified negative: the field exists in
the file format but is inert.

## 2. Access-Key Deep Review

Full lifecycle, each step attributed to its exact source:

- **Creation** (`bin/v-add-access-key`): `access_key_id` = 20-char
  alphanumeric (`keygen 20`, matrix `0-9A-Za-z`, no special characters);
  `secret_access_key` = 40-char alphanumeric+special (`keygen 40 yes`,
  matrix adds `_-=`). Both generated with Bash `$RANDOM` (`func/main.sh`
  keygen, `bin/v-add-access-key` lines 28-44) — **`$RANDOM` is a 15-bit
  (0-32767) non-cryptographic PRNG**, not `/dev/urandom` or an equivalent
  CSPRNG. Collision-checked and regenerated if the id already exists
  (lines 78-82), but never checked for cryptographic unpredictability.
- **Storage** (`bin/v-add-access-key` lines 84-92): a plain shell
  `KEY='value'` config file at `$HESTIA/data/access-keys/<access_key_id>`,
  `chmod 640`. `SECRET_ACCESS_KEY` is written **and stored in plaintext**
  — there is no hash, no HMAC, nothing resembling `password_hash()`. Any
  process able to read that file (root, and whichever group owns the
  `access-keys` directory — `chmod 750`, `chown root:root` at directory
  creation, `bin/v-add-access-key` lines 72-76) recovers the literal
  secret.
- **Validation/lookup** (`func/main.sh::check_access_key_secret()`, lines
  1652-1675): `source_conf`'s the key file, then compares with a plain
  Bash `!=` string comparison (line 1669). **This is not a timing-safe
  comparison** — Bash string comparison short-circuits on the first
  differing byte, which is the textbook precondition for a timing side
  channel, though no attempt was made in this review to determine whether
  that channel is practically exploitable over a real network (marked
  UNKNOWN, not assumed either way, per §7).
- **Expiration**: none, as established in §1.
- **Revocation/deletion** (`bin/v-delete-access-key`): unconditional file
  removal, effective immediately (no cache, no in-memory session tied to a
  key — every check re-reads the file from disk).
- **Association with a user**: one key → exactly one `USER` field, set at
  creation from the caller-supplied `user` argument, immutable
  thereafter (no `v-*-access-key` script updates an existing key's user).
- **Comparison/security properties**: plaintext-at-rest, non-CSPRNG
  generation, non-timing-safe comparison. All three are source-verified
  facts, not inferred.
- **Current API usage**: exclusively `web/api/index.php::api_connection()`
  (§1 table) — no other caller of `v-check-access-key` exists in the
  repository (`bin/`, `web/`, `plugins/` grepped; only the one call site).
- **Error behavior**: `bin/v-check-access-key`'s `abort_missmatch()`
  callback (lines 57-71) normalizes every failure to `E_PASSWORD` (except
  an explicit `E_FORBIDEN` short-circuit) and, critically, **logs every
  failed attempt to `$HESTIA/log/auth.log`** in a `... failed to login`
  format that `install/deb/fail2ban/filter.d/hestia.conf`'s
  `failregex = .* <HOST> failed to login` matches — meaning fail2ban, when
  installed and enabled (`install/deb/fail2ban/jail.local`), already
  provides IP-based brute-force throttling for access-key failures, for
  free, as an OS-level side effect of this logging convention. This is a
  genuinely load-bearing finding for §7.

**Can the existing mechanism safely serve as API v2's authentication
primitive, as-is?** No. The scope/revocation/user-binding model is sound
and reusable; the credential's own generation and storage are not
defensible for a credential meant to be transmitted over HTTP by
non-trusted external callers. See §4 and §12 for what "reuse" should
actually mean given this finding.

## 3. Identity Model

`actor.user` must be a plain Hestia username string, exactly as
`SameUserAuthorizer` and every existing adapter test already assume — no
change to `AuthorizerInterface`'s `array $actor` shape is required to
carry it; the interface already accepts an arbitrary array.

The distinction this review holds firmly throughout: **authentication**
answers "who is making this HTTP request, and do I trust that claim?" —
it consumes a credential (password, session cookie, access key) and
produces a *principal*. **Authorization** answers "is this principal, once
already trusted, allowed to touch this specific target?" — that is
`SameUserAuthorizer`'s entire job, already implemented, already tested,
and explicitly out of scope for this document to touch again. The
translation step in between — principal → `actor.user` — is new surface
this document scopes but does not build.

## 4. API v2 Credential Model

| | A. Hestia access keys (as-is) | B. New API token system | C. PHP session reuse | D. Legacy password/hash reuse |
|---|---|---|---|---|
| Security | Plaintext-at-rest, non-CSPRNG id/secret, non-timing-safe compare (§2) | Can fix all three from day one (hash-at-rest, CSPRNG, `hash_equals()`) | Cookie theft = full session; no separate credential to scope/rotate | Sends a full password (or a hash-format-specific proof) per request; worst credential-exposure surface of the four |
| Implementation complexity | Lowest — mechanism, storage, and a UI already exist | Low-medium — new storage/validation script(s), no new UI paradigm | Medium — CSRF/cookie handling doesn't map cleanly onto a stateless API | Highest to do safely — reimplements login's crypt-format branching per request |
| Backward compatibility | Full — no format change | Full — additive, existing keys keep working via legacy `web/api/index.php` | N/A — sessions are browser-scoped by nature | Full, but for reasons that are a liability (§10) |
| Revocation | Immediate (file delete) | Immediate (design-dependent, likely also immediate) | Immediate (`session_destroy`) but requires the browser to hold the cookie | No standing credential to revoke — password rotation is the only lever |
| Rotation | Manual delete+recreate, no in-place rotation today | Can be designed with rotation from day one | N/A | Manual password change, high blast radius (breaks the panel login too) |
| Machine-to-machine use | Designed for exactly this | Designed for exactly this | Poor fit — cookies assume a browser | Poor fit — no client should hold a live admin password |
| User binding | Already 1:1 (§2) | Would be 1:1 by design | 1:1, but tied to browser session lifetime, not a durable credential | 1:1, tied to the account itself |
| Auditability | `auth.log` entries already exist per attempt (§2) | Can inherit the same logging convention | Session login already logged via `v-log-user-login`/`v-log-user-logout` | Same login-log path as session auth |
| Cloud Account/Connect compatibility | Storage format (flat file, single `USER`) has no room for a tenant/account field without a schema change | New system, free to include a `principal`/`account` distinction from the start | Sessions are inherently server-local; awkward to represent a tenant across a Cloud Connect boundary | Same ceiling as A |
| SameUserAuthorizer compatibility | Direct — `USER` field maps straight to `actor.user` | Direct, by construction | Direct — `$_SESSION["user"]` maps straight to `actor.user`, already used exactly this way by the legacy panel | Direct, but see complexity/security rows |
| Operational UX | Existing key-management UI already in the panel | New UI needed (or extend the existing key UI) | Zero new UX — but wrong shape for a machine client | No new UX, but actively bad practice to ask API clients to hold passwords |

**Not chosen on preference.** Option A fails §2's security findings for
direct external exposure; Option C is architecturally the wrong shape for
a stateless, machine-callable API (and reusing it would re-couple API v2
to the same cookie/CSRF/session-store machinery this whole effort exists
to get out from under); Option D actively reintroduces the worst part of
the *existing* legacy path this project is trying to leave behind (§10).
Option B is not "a new idea invented for elegance" — it is Option A's
already-correct external contract (id + secret over HTTPS, resolved to a
user) with its three concretely broken internals (§2) fixed, which is
the smallest change that survives the security review. See §12.

## 5. Trust Boundary

Recommended pipeline, matching the one given in the task:

```
HTTP request
  → authenticate            (NEW: a small, dedicated authentication component)
  → construct actor          (NEW: {user: <resolved principal>})
  → normalize request        (existing responsibility of whatever calls CommandAdapter::invoke())
  → CommandAdapter::invoke()
    → resolve → validate → normalize → authorize (SameUserAuthorizer) → lock → execute
```

Authentication does **not** belong inside `CommandAdapter`, for the same
reason `SameUserAuthorizer`'s own design already established: `invoke()`
receives `$actor` as a plain, already-resolved array — it has never taken
a credential, and putting credential-checking behind that boundary would
mean `CommandAdapter` learning about HTTP headers, POST bodies, or key
files, which directly contradicts its existing, tested contract (`invoke()`
takes `array $actor = []`, not a request object) and would make the class
untestable the way `SameUserAuthorizerTest.php`'s Part A currently is —
a pure function with no I/O.

It also does not belong "in the HTTP/API layer" in the sense of being
inlined ad hoc into a router — `web/api/index.php` already shows what that
looks like at scale (four different credential shapes hand-rolled into one
394-line file, §10) and that outcome is precisely what this review is
trying to avoid repeating in v2. The correct home is a **dedicated
authentication component**, analogous in spirit to `AuthorizerInterface`
itself: a small, named seam whose only job is `credential → principal`,
called once by whatever HTTP entry point API v2 eventually gets, before
`CommandAdapter` is ever touched. This mirrors `AuthorizerInterface`'s own
precedent — a narrow interface, injected, testable in isolation, with zero
knowledge of any specific operation.

## 6. Actor Model

| Field | Status | Reasoning |
|---|---|---|
| `user` | **Required now** | The only field `SameUserAuthorizer` reads; already the sole normalized field in every current adapter test. |
| `acting_as` | **Already exists, unused by any policy yet** | Carried in the contract since `AUTHORIZATION_POLICY_IMPLEMENTATION.md` §11; a future delegation/support-impersonation policy would read it, not this authentication work. |
| `auth_type` | **Reserve for later, do not implement** | Useful for audit ("was this actor authenticated via an access key or a session?") and for a future policy that treats machine callers differently from interactive ones — but no current policy consumes it, so adding it now is speculative surface. |
| `credential_id` | **Reserve for later, do not implement** | Useful for per-credential audit trails and for the future revocation/rotation UX (§4) — again, no current consumer. |
| `scopes` | **Defer — do not implement, and be explicit about why** | The existing access-key `PERMISSIONS` mechanism (§1-2) is a real, coarse-grained scope model, but `AuthorizerInterface`/`SameUserAuthorizer` were deliberately built with zero scope vocabulary (`AUTHORIZATION_POLICY_IMPLEMENTATION.md` §3). Adding `scopes` to `actor` today would either go unused (dead weight) or tempt a second authorization mechanism to grow inside the authentication layer, duplicating `SameUserAuthorizer`'s job. If scoped credentials are wanted later, the correct integration point is a *new* `AuthorizerInterface` implementation that reads `actor.scopes`, not a silent capability added to the actor shape now. |
| `tenant` / `cloud_account` | **Explicitly do NOT add now — the prematurely-coupling case** | This is the one field that would actively harm the architecture if added speculatively: neither Cloud Account nor Cloud Connect exist yet, so any concrete shape chosen today is a guess, and `AuthorizerInterface`'s whole value (per `API_V2_ARCHITECTURE_REVIEW.md` §8) is that it doesn't need to know about tenancy at all — a future authorizer implementation can resolve tenant context from `actor.user` (or a session-store lookup) internally, entirely inside its own `authorize()` implementation, with zero interface or actor-shape change. Adding a `tenant` field now would be designing Cloud Account's data model inside the authentication layer, which is exactly the kind of future-problem-solving the prior task (§8) was explicitly told not to do, and this one shouldn't either. |

**Minimum actor shape for API v2's first slice: `{ "user": "<string>" }`.**
`acting_as` stays present-but-unused (it already is). Nothing else is
added to the shape by this review.

## 7. Access-Key Security Review

| Property | Finding | Source |
|---|---|---|
| Plaintext storage | **Confirmed** — `SECRET_ACCESS_KEY` written and read as plain text, no hash | `bin/v-add-access-key` line 84; `func/main.sh` line 1667-1669 |
| Hashing | **Absent** | Same as above — no `password_hash`/HMAC/digest anywhere in the access-key path |
| Timing-safe comparison | **Absent** — plain Bash `!=` | `func/main.sh` line 1669 |
| Predictable identifiers | **Partially — the generator is not cryptographically strong** (`$RANDOM`, 15-bit PRNG), though the *secret* half is 40 characters from a ~64-symbol alphabet, which dominates the entropy budget even if `$RANDOM`'s statistical quality is weak. Practical brute-forceability of the secret specifically was **not** established either way in this review — marked UNKNOWN, not assumed broken. | `bin/v-add-access-key` lines 28-44 |
| Secrets in logs | **Not observed** — `auth.log`'s failure line logs `access_key_id`, not the secret (`v-check-access-key` line 59); `abort_missmatch` never echoes the secret | `bin/v-check-access-key` lines 57-71 |
| Secrets in command arguments | **Confirmed** — the secret is passed as a literal CLI argument to `v-check-access-key` via `exec()` from `web/api/index.php` (line 274), meaning it is visible to any other local process able to read `/proc/<pid>/cmdline` for the duration of that `sudo`/exec call. This is the same class of exposure `SENSITIVE_PARAMETER_DESIGN.md` already identified and mitigated (via temp-file delivery) for the adapter's own `password`/`secret` parameters — the access-key path predates and does not use that mitigation. | `web/api/index.php` line 274 |
| Transport assumptions | **UNKNOWN from source** — nothing in `web/api/index.php` enforces HTTPS or rejects plaintext HTTP; TLS termination is an infrastructure/webserver-config concern outside this file, not verifiable from the PHP source alone. | — |
| Brute-force protection | **Exists, but is external and optional** — `fail2ban`'s `hestia` filter/jail (`install/deb/fail2ban/filter.d/hestia.conf`, `jail.local`) matches the exact `... failed to login` string `abort_missmatch` writes to `auth.log`, giving IP-based throttling for free *if fail2ban is installed and the jail enabled* — not guaranteed on every deployment, and not an application-layer control API v2 can rely on unconditionally. | `install/deb/fail2ban/filter.d/hestia.conf`; `bin/v-check-access-key` line 59 |
| Rate limiting (application-layer) | **Absent** — no per-key or per-IP request counter anywhere in the access-key check path itself | `func/main.sh` lines 1652-1753 reviewed in full, no such logic present |
| Rotation | **Absent** — no `v-*-access-key` script updates an existing secret in place; rotation today means delete+recreate, which changes the `access_key_id` too | `bin/` directory listing, §1 |
| Revocation | **Present and immediate** — `bin/v-delete-access-key`, unconditional file removal, every check re-reads from disk | §2 |
| Privilege escalation | **One concrete finding**: `check_access_key_cmd()` (`func/main.sh` lines 1743-1746) grants a key whose `USER` equals `$ROOT_USER` an unrestricted `user_arg_position="0"`, i.e. an admin-owned key can operate as any user regardless of its declared `PERMISSIONS` scope. This is consistent with Hestia's existing admin-is-omnipotent model elsewhere (the panel's own `userContext === "admin"` impersonation, §1) and is not a bug introduced by the access-key mechanism — but it means an API-v2 design that reuses admin-owned access keys inherits full impersonation power tied to a single, plaintext, non-rotatable secret, which raises the stakes of every other finding in this table specifically for admin keys. | `func/main.sh` lines 1743-1746 |

## 8. API Error Semantics (design only, not implemented)

Six conceptually distinct authentication outcomes, deliberately kept
separate from HTTP status codes here — `API_V2_ARCHITECTURE_REVIEW.md`
§5 already established the principle of not inventing status codes
without semantic reasoning, and that reasoning is a first-release decision
this document defers, not one to make as a side effect of an
authentication-only review:

1. **Missing credentials** — no credential of any recognized shape was
   presented at all.
2. **Malformed credentials** — a credential of a recognized shape was
   presented but fails structural validation (e.g. wrong length/charset —
   mirrors `is_secret_access_key_format_valid`'s existing regex check,
   `func/main.sh` line 1643).
3. **Invalid credentials** — structurally valid but does not match any
   real principal (wrong secret for a real key id, wrong password for a
   real user).
4. **Revoked credentials** — was once valid, has since been deleted
   (indistinguishable from "never existed" in the *current* access-key
   implementation, since deletion is unconditional file removal with no
   tombstone — a future implementation could choose to distinguish these
   if that distinction is ever judged worth the storage cost).
5. **Expired credentials** — not reachable today (§1's `EXPIRES_IN`
   finding), but reserved as a distinct category for whenever expiration
   is actually implemented, so it doesn't get silently folded into
   "invalid" later.
6. **Authenticated but unauthorized** — already fully designed and
   implemented: this is exactly `SameUserAuthorizer`'s
   `AUTHORIZATION_DENIED` outcome (`AUTHORIZATION_POLICY_IMPLEMENTATION.md`
   §3, §8), which this document does not re-litigate. The only new
   requirement authentication introduces here is that categories 1-5 must
   each be distinguishable *from* category 6 — a caller who successfully
   authenticates as themselves but is denied for touching someone else's
   target must never be confused, in logs or in the eventual response
   shape, with a caller who never proved who they were at all.

## 9. Compatibility with SameUserAuthorizer

```
credential (access-key id+secret, or a future hardened token)
   ↓  [authentication component — NEW, this doc's proposed seam]
authenticated principal (a bare Hestia username, already resolved,
                          already trusted — nothing downstream re-verifies it)
   ↓
actor.user = principal
   ↓  [caller-supplied, from the request being handled — e.g. a domain
   ↓   or database operation's own "user" parameter]
target.user
   ↓
SameUserAuthorizer::authorize()  →  actor.user === target.user ?
```

No ambiguity or privilege-escalation path was found in this chain, given
two preconditions that the review explicitly calls out because they are
the chain's actual security-load-bearing points:

- The authentication component must be the **only** place `actor.user`
  is ever set from external input. If any future code path constructs
  `actor` from a client-supplied field (e.g. trusting a `user` value in
  the POST body instead of the resolved principal), `SameUserAuthorizer`
  would be trivially bypassable — not because the policy is wrong, but
  because it would be handed a forged `actor.user` matching an attacker-
  chosen `target.user`. This is precisely the class of bug
  `AuthorizationTest.php`'s `testAuthorizerCannotInfluenceExecutionViaReceivedArrays`
  already guards against *inside* `CommandAdapter` — the equivalent
  discipline has no test yet at the authentication boundary because that
  boundary does not exist yet. This is the concrete requirement for
  whatever component is built next: it must derive `actor.user` from the
  credential, never from an unauthenticated request field.
- §7's admin-key finding is the one existing privilege-escalation path,
  and it is orthogonal to this chain: an admin-owned key already
  authenticates as `ROOT_USER`-equivalent per Hestia's existing model, so
  `actor.user` for such a key legitimately can equal any `target.user` —
  that is Hestia's pre-existing admin semantics working as designed, not
  a defect introduced by wiring `SameUserAuthorizer` to it.

## 10. Legacy API Separation

`web/api/index.php`'s `cmd`/`arg1..arg13` passthrough (`api_legacy()` and
`api_connection()`, both terminating in `exec($cmdquery, ...)` against a
caller-supplied `$hst_cmd`, validated only by a shape regex
`^[a-zA-Z0-9_-]+$`, not an allowlist) is the exact anti-pattern
`API_V2_ARCHITECTURE_REVIEW.md` §9/§14 already flagged as CRITICAL and
directed API v2 away from. Bolting API v2 authentication onto that file
specifically — rather than building a new, `CommandRegistry`-mediated
entry point — would inherit three problems simultaneously, all
independent of whichever credential this document recommends:

1. **No registry boundary.** Any authenticated caller can name *any*
   `bin/v-*` script that exists on disk (`elseif [ ! -e "$BIN/$cmd" ]`,
   `func/main.sh` line 1727, mirrored in the PHP file's own regex check)
   — including scripts `CommandRegistry` deliberately never exposes. This
   directly contradicts the adapter's entire reason for existing: a
   closed, reviewed set of operations instead of "the whole `bin/`
   directory."
2. **No structured result.** `api_connection()` echoes raw stdout/stderr
   and maps a Hestia exit code to an HTTP code with `exit_code_to_http_code()`
   — there is no `AdapterResult`, no mutation-state classification, none
   of the work `MUTATION_AND_AUTHORIZATION_DESIGN.md` and the six
   operation-implementation documents already did.
3. **Authorization is currently ad hoc and duplicated per branch**
   (`api_connection()`'s own hand-rolled `$key_user != $root_user &&
   $user_arg_position > 0 && ...` check, lines 302-312) rather than
   flowing through one seam. Adding a *second*, authentication-only
   concern on top of that file would mean API v2 ends up with two
   independent, divergent authorization implementations (this file's ad
   hoc check, and `SameUserAuthorizer`) instead of one.

The security boundary API v2 should establish instead is the one this
document and its predecessors already assume throughout: HTTP layer →
authenticate → construct `actor` → `CommandAdapter::invoke()` (which
itself enforces `resolve → validate → normalize → authorize → lock →
execute` against the closed `CommandRegistry` set). `web/api/index.php`
continues to exist as the legacy v1 surface (per
`API_V2_ARCHITECTURE_REVIEW.md` §10's "Strategy A: coexist temporarily")
but API v2 must be a structurally separate entry point from day one, not
a new authentication check layered into the same file.

## 11. Cloud Account / Cloud Connect Future

The chain `credential → principal → tenant/cloud account → actor →
authorization policy` is compatible with everything recommended in this
document **without any `AuthorizerInterface` change**, for the same
reason `API_V2_ARCHITECTURE_REVIEW.md` §8 already established: the
interface's `array $actor` parameter is opaque to `CommandAdapter` itself
— it is only ever read by whichever `AuthorizerInterface` implementation
is injected. A future Cloud Account-aware authorizer could:

- Read `actor.user` exactly as `SameUserAuthorizer` does today, for the
  base case.
- Additionally resolve `actor.user` against an external tenant/account
  store (looked up by the authorizer implementation itself, not passed in
  by the authentication component) to decide cross-account authorization
  questions `SameUserAuthorizer` was never meant to answer.

This works cleanly *because* §6 recommended against adding a `tenant`
field to `actor` now — the authorization policy, not the actor shape, is
the correct place for tenant-awareness to eventually live, exactly
mirroring how `SameUserAuthorizer` itself needed no interface change to
exist. The authentication component recommended in §12 produces
`actor.user`; it does not need to know Cloud Account exists at all for
that to remain true later.

## 12. Recommendation

**Recommended authentication primitive: a hardened evolution of the
existing access-key model (Option B in §4) — not a brand-new credential
concept, and not verbatim reuse of the current file format.** Keep the
external contract Hestia operators and any existing tooling already
understand (an id + a secret, presented together, resolving to exactly
one Hestia user, scoped by an existing `PERMISSIONS` convention) — fix
the three concretely broken internals §2/§7 found: generate both halves
with a cryptographically strong RNG, store the secret hashed (not
plaintext) at rest, and compare it with a timing-safe function
(`hash_equals()` in PHP, or equivalent) instead of a lexical `!=`. This is
not a new design — it's the same shape with its known defects closed, and
it is explicitly *not* Option A (verbatim reuse) or Option D (password
reuse), for the reasons in §4 and §10.

**Where authentication belongs:** a dedicated authentication component,
consulted once at the HTTP boundary, strictly before `CommandAdapter` is
ever touched — never inside `CommandAdapter`, never folded into
`web/api/index.php`'s existing branches. See §5.

**Minimum actor shape:** `{ "user": "<string>" }`. `acting_as` stays as
already defined and unused; no other field is added. See §6.

**What should remain deferred:** expiration enforcement, in-place
rotation, `scopes`-aware authorization (a future `AuthorizerInterface`
implementation's job, not the authentication component's), `auth_type`/
`credential_id` actor fields, and — most importantly — anything
resembling a `tenant`/`cloud_account` concept. All five are real, named,
and explicitly not built here.

**Why this is the smallest correct architecture:** it changes exactly one
thing this review is actually responsible for — how a credential becomes
a trusted `actor.user` — while reusing every piece of already-validated
machinery on both sides of it: `CommandRegistry`'s closed operation set,
`CommandAdapter`'s existing pipeline, and `SameUserAuthorizer`'s existing,
tested policy. It does not touch `AuthorizerInterface`, does not invent a
second credential system where an adequate one already exists in spirit,
and does not pre-build any Cloud Account plumbing that has no consumer
yet.

## 13. Implementation Boundary

The exact next implementation task — small, independently testable, not
implemented in this document:

**Build a standalone `AccessKeyValidator` (or equivalently named) PHP
class implementing a narrow interface — e.g.
`authenticate(string $keyId, string $secret): ?string` returning a Hestia
username or `null`** — that validates an access key using the *hardened*
storage/comparison model from §12, independent of any HTTP router, any
`CommandAdapter` wiring, or any new adapter operation. Its test suite
should prove, entirely offline (no real Hestia installation, mirroring
every existing adapter test's `FakeProcessRunner` convention): a correct
id+secret pair resolves to the right username; a wrong secret is
rejected; a nonexistent id is rejected; comparison is demonstrably
timing-safe (or at minimum uses `hash_equals()`, not `==`/`!=`); the
stored secret is never in plaintext form on disk in the test fixture. This
task should explicitly **not** wire the result into `CommandAdapter::invoke()`'s
`$actor` parameter yet, and should explicitly **not** touch
`web/api/index.php` or add any HTTP endpoint — that wiring is a distinct,
subsequent task once this validator exists and is independently proven.

## 14. Stop Conditions

None found that should halt API v2 implementation *before* authentication
is built — the architecture underneath (registry, adapter, authorization
policy) remains sound and this review surfaced no contradiction in it.
Two findings are significant enough to flag as conditions that **should
block reusing the access-key mechanism verbatim** (i.e., they are stop
conditions specifically against Option A of §4, not against API v2 as a
whole):

1. Plaintext secret storage (§2, §7) — reusing the current on-disk format
   as-is for a credential meant to be exposed to non-trusted external
   callers would carry that plaintext-at-rest property into API v2's
   attack surface.
2. Non-timing-safe comparison (§2, §7) — same reasoning; a network-facing
   authentication check comparing secrets with `!=` is a category of bug
   worth fixing before, not after, external exposure.

Both are addressed by §12's recommendation (harden, don't reuse verbatim)
and are not blockers to *this* document's conclusions — they are the
reason those conclusions look the way they do.

## 15. Verification

- `php test/adapter/run_tests.php`: **198 passed, 0 failed** (unchanged
  from the count at the start of this task — no test was added, removed,
  or modified).
- `git status --short`: only this new file
  (`API_V2_AUTHENTICATION_DESIGN.md`) is untracked as a result of this
  task; every previously-tracked file shows no new modification from this
  task specifically (pre-existing uncommitted modifications from prior
  tasks remain exactly as they were).
- No PHP source file under `web/inc/adapter/` was modified.
- No test file under `test/adapter/` was modified.
- No new HTTP endpoint, route, or `web/api/*` file was added or changed.
- No `CommandRegistry` operation was added.
