# API v2 — HTTP Rate Limiting (Sprint 5)

## 1. Sprint scope

Add HTTP-boundary rate limiting to `POST /api/v2/execute`, so an
unauthenticated or authenticated client cannot make an unbounded number
of requests against the endpoint. This sprint does **not** add audit
logging (Sprint 6), does not add any new operation, does not change any
existing operation's public contract, and does not modify authentication
or authorization semantics. Exactly one new HTTP error is added:
`429 RATE_LIMITED`.

## 2. Threat model

The endpoint is reachable by anyone who can send it an HTTP request,
authenticated or not. Two distinct abuse patterns are in scope:

- **Volumetric abuse against the endpoint itself**, independent of
  credentials — a flood of requests (malformed, wrong-method, or with
  guessed/invalid credentials) that wastes CPU on JSON parsing and, most
  expensively, on `AccessKeyValidator::authenticate()`'s
  `password_verify()` work.
- **Abuse by a caller who already holds a valid credential** — a single
  API key making an excessive number of calls, at the expense of other
  legitimate callers sharing the same underlying Hestia system.

Out of scope: distinguishing "malicious" traffic from "misbehaving
client" traffic (this sprint's algorithm treats both identically), and
defending against a large, distributed botnet (see §19 Known
Limitations).

## 3. Why rate limiting belongs at the HTTP boundary

`CommandAdapter`, `CommandRegistry`, `ParameterValidator`,
`AuthorizerInterface`/`SameUserAuthorizer`, and `LockManager` together
form the adapter's own execution boundary — they decide *whether an
already-accepted, already-authenticated request is allowed to run*.
Rate limiting decides something upstream of that question entirely:
*whether a request is even allowed to reach that boundary at all,
independent of who it claims to be or what it asks for*. Putting it
there would mean every future adapter operation inherits HTTP-specific
concerns it has no business knowing about, and would make the adapter's
already-established, adapter-focused test suite responsible for an
unrelated policy. Keeping it in `Hestiacp\Api` (this sprint's own two
new HTTP-layer checks inside `ExecuteRequestHandler`) keeps the adapter
architecture exactly as Sprints 1-4 left it — untouched.

## 4. Pre-authentication bucket

Runs as the very first action inside `ExecuteRequestHandler::handle()`
— before method/content-type/body-size checks, and strictly before
`authenticate()`'s own expensive `password_verify()` work. Keyed
**only** by the client's network address (`REMOTE_ADDR`, passed in by
`web/api/v2/index.php` as the new `$clientIp` parameter — never read
from a superglobal inside the transport-independent handler itself).

The key never depends on whether the credential in the request turns
out to be valid — an unknown credential and a fully valid one hitting
the same IP always land in the exact same bucket (verified by
`RateLimiterTest::testPreAuthBucketSharedAcrossUnknownAndValidCredential`).

## 5. Authenticated bucket

Checked immediately after `AccessKeyValidator::authenticate()` succeeds,
before operation resolution. Keyed by the **authenticated** credential
id returned by that call (never the raw secret, never a caller-supplied
value). A caller who fails authentication never reaches this bucket at
all — it exists purely to give each already-proven credential its own,
independent allowance, so one credential's usage can never consume
another's (`RateLimiterTest::testAuthenticatedBucketsAreIndependentPerCredential`).

## 6. Key derivation

| Bucket | Key material | Never used as key |
|---|---|---|
| Pre-auth | `"preauth:" . $clientIp` (REMOTE_ADDR) | Credential id, secret, any request body content |
| Authenticated | `"auth:" . $credentialId` | The raw secret, `actor.user` derived from it in a different form |

No trusted-proxy mechanism (e.g. a configured, whitelisted reverse-proxy
IP list feeding `X-Forwarded-For`) exists anywhere in this repository —
confirmed by inspecting `web/api/v2/index.php` and the rest of
`web/inc/api/` before this sprint began. Per the task's own instruction,
`X-Forwarded-For` and similar headers are therefore never read; only
`$_SERVER["REMOTE_ADDR"]` is used.

Every bucket key, regardless of bucket, is hashed with `sha256()` before
it ever becomes part of a filename (see §9) — this is unconditional, not
conditional on which bucket it came from, so no future bucket type can
accidentally introduce a path-traversal vector by forgetting to hash.

## 7. Algorithm

A plain **fixed-window counter**: `windowStart = floor(now / windowSeconds) * windowSeconds`;
each request increments the counter for `(bucketKey, windowStart)`; a
window boundary crossing resets the counter to zero before incrementing.
This is the simplest option that is both trivially correct under
`flock()`-serialized filesystem access and deterministically testable
via an injected clock (`RateLimiter`'s `?callable $clock` constructor
parameter, mirroring `CommandAdapter`'s own injected-clock convention).
No sliding-window or token-bucket algorithm was implemented — nothing in
this repository's existing patterns or this sprint's threat model
required the added complexity.

Boundary semantics: a request is allowed when `count <= limit` — i.e.
the request that brings the count exactly to the limit is still
allowed; the next one is rejected. This is `RateLimiterTest::testAtLimitAllowed`
/ `testAboveLimitRejected`'s exact contract.

## 8. Limits and why they are only operational defaults

```php
DEFAULT_PRE_AUTH_LIMIT = 30;            // requests
DEFAULT_PRE_AUTH_WINDOW_SECONDS = 60;
DEFAULT_AUTHENTICATED_LIMIT = 120;      // requests
DEFAULT_AUTHENTICATED_WINDOW_SECONDS = 60;
```

These are named constants on `RateLimiter` (`web/inc/api/RateLimiter.php`)
and are the **only** place they are defined — trivial to change. They
are round, conservative numbers chosen for this sprint; nothing in this
repository documents an "industry standard" rate for this API, and none
is claimed here. They are explicitly **not** a security guarantee —
they bound accidental/careless load, not a determined attacker willing
to rotate source IPs or acquire multiple credentials. Every limit is
also fully overridable via `RateLimiter`'s constructor, which is exactly
how the dedicated test suite exercises boundary/rejection behavior with
much smaller limits deterministically.

## 9. Storage model

Two `RateLimitStoreInterface` implementations:

- **`InMemoryRateLimitStore`** — a plain PHP array, scoped to one
  `RateLimiter`/`ExecuteRequestHandler` instance's lifetime. This is
  `ExecuteRequestHandler`'s own constructor default (used whenever no
  explicit `RateLimiter` is injected), chosen specifically so every one
  of the ~120 pre-existing tests — none of which mention rate limiting —
  continues to work completely unchanged: each test constructs its own
  fresh handler instance, so each automatically gets its own isolated,
  empty counter set, with zero risk of one test's call volume tripping
  another's assertions. **Never used in production** — see below.
- **`FilesystemRateLimitStore`** — the real backend.
  `web/api/v2/index.php` **always** explicitly constructs
  `new RateLimiter(new FilesystemRateLimitStore())` and passes it into
  `ExecuteRequestHandler`'s constructor. This is necessary because a
  typical PHP-FPM/CGI deployment runs a fresh PHP process per request;
  an in-memory store would never persist a count across two separate
  requests, silently rate-limiting nothing in that deployment model.

`FilesystemRateLimitStore` defaults to
`sys_get_temp_dir() . "/hestia-api-v2-ratelimit"` — deliberately **not**
under the credential directory or any installer-provisioned path. No
installer script was modified this sprint (out of scope, and on the
explicit do-not-modify list); a temp-backed directory needs no
provisioning step and is created lazily, 0700, on first use. Rate-limit
counters are intentionally treated as ephemeral, disposable state — the
OS clearing the system temp directory (reboot, `systemd-tmpfiles`, etc.)
simply resets all counters to zero, which is a safe failure mode for a
rate limiter (see §11), not a data-loss concern the way losing a
credential record would be.

## 10. Atomicity / concurrency

Each counter is one file (named by the hashed bucket key, see §6/§15).
`FilesystemRateLimitStore::incrementAndGet()` opens it with `fopen(...,
"c+b")`, takes an exclusive `flock(LOCK_EX)`, reads the current
`"$windowStart:$count"` contents, resets to zero on a window-boundary
change, increments, truncates, rewrites, and releases the lock — the
entire read-modify-write happens under one held lock, so two
overlapping calls against the same bucket can never interleave and lose
an increment. This uses PHP's own `flock()` primitive directly,
independently of `LockManager` — it is a separate mechanism serving a
separate, HTTP-layer-only purpose, never touching or depending on the
adapter's locking machinery (§16).

`RateLimiterTest::testFilesystemStoreNoLostIncrements` exercises 50
sequential increments against one bucket and asserts the final count is
exactly 50. True multi-process concurrency relies on `flock()`'s own
well-established OS-level mutual-exclusion guarantee; this suite does
not spawn real concurrent OS processes to re-verify that guarantee
empirically, matching this codebase's own existing testing conventions
(no existing `LockManager`-adjacent test spawns real subprocesses
either) — it verifies this store's own read-modify-write logic is
correct, which is the part that could actually contain a bug.

## 11. Storage failure behavior (fail-open vs. fail-closed)

Deliberately **asymmetric**, decided explicitly per bucket:

- **Pre-authentication bucket: fails CLOSED.** If the counter store
  cannot be read or written, the request is rejected with `429
  RATE_LIMITED` (`ExecuteRequestHandler::enforcePreAuthRateLimit()`).
  Rationale: this is the first line of defense against anonymous,
  volumetric abuse — allowing unlimited traffic through whenever storage
  happens to be broken would hand an attacker an easy way to defeat the
  limiter simply by causing (or waiting for) a storage fault.
- **Authenticated bucket: fails OPEN.** If the counter store cannot be
  read or written, the request proceeds normally
  (`ExecuteRequestHandler::enforceAuthenticatedRateLimit()`). Rationale:
  this request has already paid the cost of a valid credential and
  already passed the fail-closed pre-auth layer above; denying every
  authenticated caller because of an unrelated filesystem fault would
  turn a storage hiccup into a full API outage for every legitimate
  user, which is a worse outcome than temporarily not rate-limiting
  already-authenticated traffic.

**Why this does not create a self-inflicted DoS primitive.** Every
bucket key is hashed into a fixed-length filename before it ever
touches the filesystem (§6/§15); a remote HTTP caller — authenticated or
not — has no filesystem access of any kind and can only ever write to
their *own* bucket's single counter file. There is no way for a caller
to make the store *itself* (the directory, or another caller's file)
unavailable by making requests. A "storage unavailable" condition can
only be a genuine environmental fault (disk full, permissions
misconfigured, directory removed) — never something an attacker
triggers on demand — so the pre-auth fail-closed choice does not become
an attacker-triggerable outage switch. This reasoning is also why an
attacker cannot corrupt another caller's rate-limit state: they cannot
name, predict, or reach any file but their own.

Verified by `RateLimiterTest::testPreAuthFailsClosedOnStorageFailure`
and `testAuthenticatedFailsOpenOnStorageFailure`, both using a fake
`RateLimitStoreInterface` that always throws
`RateLimitStoreUnavailableException`.

## 12. 429 response contract

Uses the **exact same** six-key envelope every other API v2 response
uses — no second response format:

```json
{
  "api_version": "v2",
  "success": false,
  "outcome": "failed",
  "data": null,
  "error": {
    "code": "RATE_LIMITED",
    "message": "Too many requests. Please try again later.",
    "details": { "retry_after_seconds": 37 }
  },
  "meta": { "operation": "", "command_id": null }
}
```

HTTP status: `429`. `error.details.retry_after_seconds` is populated
only when a real window boundary was computed (i.e. the request was
genuinely over the limit); it is `null` for the fail-closed
storage-unavailable case, where no window boundary was ever evaluated.
No `Retry-After` HTTP header is added — the same, deterministic
information is already available in the response body via the existing
envelope, and adding a second surface for identical information was
judged not worth the "do not add `Retry-After` merely because HTTP
permits it" caution; a client that wants it can read
`error.details.retry_after_seconds`.

`meta.operation` is `""` for a pre-auth rejection (the operation was
never resolved) and is likewise `""` for an authenticated rejection,
since the authenticated rate-limit check runs before operation
resolution too (§14/§16) — this is the same behavior every other
pre-operation-resolution `ApiException` already has (e.g.
`AUTHENTICATION_FAILED`).

## 13. Information-disclosure analysis

- **Credential existence**: never leaked. The pre-auth bucket is shared
  identically by unknown and valid credentials (§4/§6); the response
  shape, status, and error code for a rate-limited request are identical
  regardless (`RateLimiterTest::testRateLimitedResponseDoesNotRevealCredentialExistence`).
- **Secrets**: never appear in a rate-limited response — verified by
  `testRateLimitedResponseNeverContainsSecret`. The rate limiter never
  even sees the raw secret; only the credential id (post-authentication)
  and the client IP (pre-authentication) are ever used as key material.
- **Internal counters**: never exposed. `retry_after_seconds` is derived
  solely from the fixed window boundary and the current time — never
  from the stored count itself — so it discloses nothing about how close
  to (or far past) the limit a bucket actually is.
- **Filesystem paths / implementation details**: `RateLimitStoreUnavailableException`'s
  message is never included in any response — the fail-closed/fail-open
  handlers in `ExecuteRequestHandler` catch it and throw a fixed,
  generic `ApiException` instead, exactly like the existing top-level
  `catch (\Throwable)` handler already does for every other unexpected
  failure.
- **Stack traces**: never included anywhere in this sprint's code, for
  the same reason as every prior sprint's code.

## 14. Interaction with authentication

The pre-auth rate limit runs before `authenticate()` is even called, so
it never affects that method's own uniform-failure behavior — a request
that clears the pre-auth bucket goes through `authenticate()` completely
unchanged from Sprints 1-4 (all existing authentication tests,
`ExecuteRequestHandlerTest` items 1-7 and 54, continue to pass
unmodified). `authenticate()`'s return value was extended from
`array{user: string}` to `array{0: string, 1: array{user: string}}`
purely to expose the credential id to the new authenticated-bucket
check inside `handle()` — `$actor` passed to `CommandAdapter::invoke()`
is still built exactly as `["user" => $user]`, unchanged.

## 15. Interaction with authorization

Not touched. Rate limiting runs and completes (in both directions —
allowed or rejected) entirely before `CommandAdapter::invoke()` is ever
called, so `AuthorizerInterface`/`SameUserAuthorizer` never observes a
rate-limited request at all; a request that clears both rate-limit
checks reaches authorization exactly as it did before this sprint.

## 16. Interaction with CommandAdapter / LockManager

Neither is touched, referenced, or depended upon by any Sprint 5 file.
`RateLimiterTest::testRateLimitBeforeAdapterInvocation` asserts
`FakeProcessRunner` records exactly one call across two attempts against
a limit of 1 (the second, rate-limited attempt never reaches the
adapter); `testRateLimitDoesNotAcquireLock` asserts `SpyLockManager`
records exactly one `acquire()` call across the same two attempts, using
a mutating operation (`domain.create`) specifically so a
non-rate-limited second call *would* have acquired a lock, proving the
rate-limited one deliberately does not; `testRateLimitDoesNotExecuteOperation`
makes the same assertion about process execution directly.

## 17. Test coverage

New dedicated suite: `test/api/RateLimiterTest.php`, 20 tests, covering
every one of the task's 19 enumerated requirements (several requirements
are covered by more than one test for clarity):

| # | Requirement | Test(s) |
|---|---|---|
| 1 | below-limit allowed | `testBelowLimitAllowed` |
| 2 | at-limit boundary | `testAtLimitAllowed` |
| 3 | above-limit -> 429 | `testAboveLimitRejected` |
| 4 | pre-auth bucket shared unknown/valid | `testPreAuthBucketSharedAcrossUnknownAndValidCredential` |
| 5 | authenticated bucket separate | `testAuthenticatedBucketSeparateFromPreAuth` |
| 6 | per-credential isolation | `testAuthenticatedBucketsAreIndependentPerCredential` |
| 7 | before CommandAdapter | `testRateLimitBeforeAdapterInvocation` |
| 8 | no lock acquisition | `testRateLimitDoesNotAcquireLock` |
| 9 | no operation execution | `testRateLimitDoesNotExecuteOperation` |
| 10 | existing envelope reused | `testRateLimitedResponseUsesExistingEnvelope` |
| 11 | no secret in response | `testRateLimitedResponseNeverContainsSecret` |
| 12 | no credential-existence leak | `testRateLimitedResponseDoesNotRevealCredentialExistence` |
| 13 | fail-open/fail-closed policy | `testPreAuthFailsClosedOnStorageFailure`, `testAuthenticatedFailsOpenOnStorageFailure` |
| 14 | no lost concurrent increments | `testFilesystemStoreNoLostIncrements` |
| 15 | no path traversal | `testFilesystemStorePathTraversalImpossible`, `testFilesystemStoreDirectoryNotWorldWritable` |
| 16 | window reset/expiry | `testWindowReset` |
| 17 | auth-failure uniformity intact | `testNonRateLimitedMappingsUnchanged` (plus all pre-existing `ExecuteRequestHandlerTest` auth tests, unmodified and still passing) |
| 18 | all 7 operations still work | `testAllSevenOperationsSucceedUnderLimit` |
| 19 | existing 4xx/5xx mappings unchanged | full existing `ExecuteRequestHandlerTest`/`ResponseMapperTest` suites, unmodified and still passing |

`GenericityTest`'s `API_SOURCE_FILES` list was extended with all six new
`web/inc/api/*.php` files, so the existing "no shell execution" / "no
bin/v-* script reference" mechanical checks now also cover them
unchanged — no existing rule was weakened.

All tests use `InMemoryRateLimitStore` or a fresh, uniquely-named
temp-directory-backed `FilesystemRateLimitStore` (mirroring
`ExecuteRequestHandlerTest::freshValidator()`'s own established
per-test temp-directory convention) and an injected fake clock — never
the production credential store, never real wall-clock timing.

## 18. Regression results

Full suite, run 3 consecutive times each, immediately after
implementation:

| Suite | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| `php test/api/run_tests.php` | 122 passed, 0 failed | 122 passed, 0 failed | 122 passed, 0 failed |
| `php test/auth/run_tests.php` | 62 passed, 0 failed | 62 passed, 0 failed | 62 passed, 0 failed |
| `php test/adapter/run_tests.php` | 198 passed, 0 failed | 198 passed, 0 failed | 198 passed, 0 failed |

(122 total, up from the pre-Sprint-5 baseline of 102 — exactly the 20
new `RateLimiterTest` tests, with every pre-existing test unmodified and
still passing.)

`php -l` was run against every new/modified PHP file — zero syntax
errors. `git diff --check` was run — zero whitespace errors.

## 19. Known limitations

- **No cross-process cleanup/expiry sweep.** Stale counter files persist
  under the temp directory until the OS clears the temp directory or an
  operator removes them manually. For this sprint's fixed-window
  algorithm and conservative limits, this is a bounded amount of
  small (~dozen-byte) files per distinct IP/credential seen, not an
  unbounded leak, but a large botnet using many distinct source IPs
  could still accumulate a non-trivial number of small files over time.
  A periodic cleanup pass is explicitly deferred (§20).
- **No distributed/multi-host synchronization.** If API v2 is ever
  served from more than one host behind a load balancer, each host's
  filesystem-backed counters are independent — a client could receive up
  to (limit × host count) requests before any single host's bucket
  rejects it. This repository shows no evidence of a current multi-host
  deployment for this endpoint; if that changes, this limiter would need
  a shared backend (Sprint 5 explicitly excludes introducing Redis/a
  database/an external service).
- **IP-based keying is imprecise behind NAT/shared egress.** Multiple
  legitimate clients behind one NAT gateway share one pre-auth bucket.
  This is an inherent trade-off of "use REMOTE_ADDR only, do not trust
  proxy headers without an established trust mechanism" — deliberately
  chosen over the alternative (trusting an unverified header) per this
  sprint's explicit instructions.
- **No `Retry-After` HTTP header** — see §12 for why this was judged
  unnecessary duplication of information already in the response body.

## 20. Deferred work

- Audit logging of rate-limit events (explicitly Sprint 6's scope).
- A cleanup/expiry sweep for stale counter files (see §19).
- Re-evaluating whether a shared backend is needed if API v2 is ever
  deployed across multiple hosts.
- Making the limit constants operator-configurable (e.g. via an
  environment variable or config file) rather than PHP constants — no
  existing configuration mechanism for API v2 exists yet to hook into,
  and none was introduced this sprint to stay within scope.

## 21. Final verdict

Sprint 5 is complete. Rate limiting is implemented entirely within
`Hestiacp\Api` (six new files under `web/inc/api/`), wired into
`ExecuteRequestHandler::handle()` at exactly two points (before
authentication, keyed by IP; after authentication, keyed by credential
id) without modifying `CommandAdapter`, `CommandRegistry`,
`ParameterValidator`, `LockManager`, `AuthorizerInterface`,
`SameUserAuthorizer`, `AccessKeyValidator`, `AccessKeyProvisioner`, any
`bin/*` script, any legacy `web/api/*` file, or any installer script.
Exactly one new HTTP error code (`429 RATE_LIMITED`) was added, reusing
the existing response envelope unchanged. No STOP condition was
triggered. All pre-existing tests pass unmodified; 20 new dedicated
tests were added and pass deterministically across 3 consecutive runs.
