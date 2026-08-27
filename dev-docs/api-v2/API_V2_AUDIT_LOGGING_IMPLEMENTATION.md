# API v2 — Audit Logging (Sprint 6)

## 1. Sprint scope

Add a minimal, security-conscious **audit trail** for
`POST /api/v2/execute` — not generic application logging. Every
security-relevant request (authentication failure, rate-limit
rejection, authorization denial, successful operation, Hestia operation
failure, validation failure, malformed JSON, unexpected exception,
unknown operation) produces exactly one audit event answering WHO
attempted WHAT, against WHICH TARGET, with WHAT RESULT, and WHEN. This
sprint does not add: request/correlation IDs exposed to the client,
audit log rotation infrastructure, an audit UI, external SIEM
integration, or credential/rate-limiter changes — see §17/§18 and the
task's own non-goals list.

## 2. Existing Hestia logging mechanisms investigated

Verified from source before writing any code:

- **`func/main.sh`'s `log_event()`/`log_history()`** (bash). `log_event`
  writes to `$HESTIA/log/system.log`/`error.log`. `log_history` writes
  structured `ID=... DATE=... MESSAGE=...` lines to
  `$HESTIA/data/users/<user>/history.log` (or `$HESTIA/log/activity.log`
  for `system`), with its own built-in 300-line truncate-on-write
  rotation. Both are bash-only, tied to `$HESTIA`/`$BIN` and a running
  Hestia CLI context.
- **`web/inc/helpers.php` / `web/inc/main.php`** — the legacy web panel
  calls `bin/v-log-action` and `bin/v-log-user-logout` via `exec()` to
  record panel activity, which itself calls `log_history()`.
- **Conclusion**: no PHP-native audit/logging primitive exists anywhere
  in `web/inc/`. The only existing mechanism reachable from PHP is
  `exec(bin/v-log-action ...)` — reusing it would require API v2 to
  shell out and reference a `bin/v-*` script by name, which is
  explicitly forbidden by this sprint's own genericity constraints (§10)
  and by Sprint 2-5's own established "CommandAdapter is the sole
  execution boundary" invariant. It would also mix API v2's own
  structured security events into `history.log`/`activity.log`, files
  designed for human-readable panel activity display, not a machine
  security audit trail — the task explicitly prefers a dedicated log
  instead (§3).
- No suitable, reusable, PHP-native primitive exists. A small,
  self-contained API-v2-specific audit layer was built from scratch,
  mirroring Sprint 5's own equivalent conclusion for rate limiting.

## 3. Audit requirements

See §1 above and the task's own §1/§2 verbatim requirements: record
security-relevant events; never log secrets; identify enough to answer
WHO/WHAT/WHICH TARGET/RESULT/WHEN; explicit safe-field allowlisting, not
blind parameter copying.

## 4. Audit event model

`web/inc/api/AuditEvent.php` — a small, explicit value object. Every
property is deliberately a value that is safe to log; the class
declares **no** property capable of holding a secret, a password, a raw
Authorization header, or a raw request body (mechanically verified —
see §15/GenericityTest below). Fields:

| Field | Type | Notes |
|---|---|---|
| `timestamp` | string | ISO 8601, UTC (`gmdate("c")`) |
| `eventType` | string | see §5 |
| `requestId` | string | see §8 |
| `attemptedCredentialId` | ?string | the Basic-auth id as extracted, regardless of validity |
| `credentialId` | ?string | only once authentication has actually succeeded |
| `user` | ?string | `actor.user`, only once authenticated |
| `clientIp` | ?string | REMOTE_ADDR, when supplied |
| `operation` | ?string | resolved operation, or the raw attempted string if never resolved |
| `target` | ?array | redacted, see §6/§8 |
| `httpStatus` | int | |
| `outcome` | string | the envelope's own `outcome` field |
| `success` | bool | the envelope's own `success` field |
| `errorCode` | ?string | the envelope's own `error.code` |
| `hestiaErrorCode` | ?string | the envelope's own `error.details.hestia_error_code`, when present |
| `durationMs` | ?int | wall-clock time inside `handle()` |

## 5. Event vocabulary

Deliberately small, and deliberately **not** a second, parallel
taxonomy: `AuditEvent::eventTypeFor(bool $success, ?string $errorCode)`
returns `"OPERATION_SUCCEEDED"` on success, or the API's own existing
`error.code` value on failure — the exact same, already-small,
already-reviewed vocabulary `ResponseMapper`/`ApiException` already use
(Sprints 1-5). Table-driven mapping (verified end-to-end by
`AuditLoggerTest::testOutcomeTableMapsToExpectedEventType`, plus four
dedicated tests for the two rows the table doesn't cover):

| # | Outcome | event_type | outcome | http_status |
|---|---|---|---|---|
| 1 | malformed JSON | `MALFORMED_JSON` | failed | 400 |
| 2 | invalid request shape | `VALIDATION_FAILED` | failed | 422 |
| 3 | missing/malformed authentication | `AUTHENTICATION_FAILED` | failed | 401 |
| 4 | invalid credentials | `AUTHENTICATION_FAILED` | failed | 401 |
| 5 | rate limited | `RATE_LIMITED` | failed | 429 |
| 6 | unknown operation | `OPERATION_NOT_ALLOWED` | failed | 404 |
| 7 | invalid operation parameters | `VALIDATION_FAILED` | failed | 422 |
| 8 | authorization denied | `AUTHORIZATION_DENIED` | failed | 403 |
| 9 | successful read | `OPERATION_SUCCEEDED` | succeeded | 200 |
| 10 | successful mutation | `OPERATION_SUCCEEDED` | succeeded | 200 |
| 11 | mutation succeeded with warning | `OPERATION_SUCCEEDED` | succeeded_with_warning | 200 |
| 12 | Hestia operation failure | `UPSTREAM_COMMAND_FAILED` (or `UNKNOWN_OUTCOME`/`LOCK_TIMEOUT`/`LOCK_UNAVAILABLE`) | failed/unknown | 422/207/409/503 |
| 13 | unexpected internal exception | `INTERNAL_ERROR` | failed | 500 |

Rows 2 and 7 intentionally share one `event_type` — the API itself
already collapses shape and per-operation parameter validation into one
`VALIDATION_FAILED` error code (Sprints 2/3); the audit vocabulary does
not invent a finer distinction the API's own response contract doesn't
make. Rows 9-11 share `OPERATION_SUCCEEDED`; `outcome` (a separate
field, already present verbatim from the envelope) is what distinguishes
a plain success from a degraded one — inventing a third event type for
"succeeded with warning" would only duplicate information already on
the event.

## 6. Safe-to-log fields

Per-operation target allowlist —`web/inc/api/AuditTargetRedactor.php`,
its own explicit, hardcoded table (deliberately **not** derived from
`OperationParameterContract::allowedParameters()` by filtering — see
that class's own docblock for why a future contract change must never
silently become loggable without a deliberate, reviewed change here):

| Operation | Safe target fields |
|---|---|
| `domain.get` | `user`, `domain` |
| `domain.list` | `user` |
| `domain.create` | `user`, `domain` |
| `domain.delete` | `user`, `domain` |
| `backup.schedule` | `user` |
| `database.create` | `user`, `database`, `dbuser` |
| `database.delete` | `user`, `database` |

`AuditTargetRedactor::redact()` is only ever called AFTER
`ParameterNormalizer::normalize()` has already run — the params it
reads are exactly what `CommandAdapter::invoke()` itself receives, never
raw/unvalidated caller input. Consequently, `database.delete`'s
`database` field is already the normalized, user-prefixed identifier
(e.g. `alice_wordpress_db`), not the raw public suffix the caller
supplied — this was a deliberate choice: it is exactly the identifier
the adapter itself will act on, so recording it is at least as safe as
recording the (also-logged) `domain`/`database` values for every other
operation, and it is far more useful for incident review than the
un-prefixed suffix alone (`AuditLoggerTest::testDatabaseDeleteTargetIsNormalized`).

## 7. Sensitive fields explicitly excluded

Never reach `AuditEvent` in any form:

- `database.create`'s `password` — absent from `AuditTargetRedactor`'s
  own allowlist for that operation; `AuditLoggerTest::testDatabaseCreateTargetRedaction`
  asserts the target contains `user`/`database`/`dbuser` and that
  `array_key_exists("password", $target)` is false.
- The Authorization header's raw value / the Basic-auth secret half —
  `AuditEvent` never receives either; only the already-split credential
  id (`attemptedCredentialId`/`credentialId`) is ever passed in.
  `AuditLoggerTest::testAuthorizationHeaderNeverInAuditEvent` /
  `testSecretNeverInAuditEvent` verify this end-to-end.
- The raw request body — `AuditEvent` never receives `$rawBody` or the
  decoded `$body` at all; only `$operation` (a single string) and the
  already-redacted `$target` ever flow into it.
  `AuditLoggerTest::testRawBodyNeverInAuditEvent` verifies an arbitrary
  extra body field never surfaces.
- Structurally guaranteed, not just behaviorally: `AuditEvent.php`'s own
  source is scanned by
  `GenericityTest::testAuditEventModelDeclaresNoSensitiveFields()` to
  confirm it declares no `$secret`/`$password`/`$authorizationHeader`/`$rawBody`
  property at all.

## 8. Request/correlation ID

No existing request/correlation ID was found anywhere in API v2 before
this sprint. `CommandAdapter::invoke()` does generate its own
`commandId` (`bin2hex(random_bytes(16))`, already surfaced in the
response as `meta.command_id`), but it is scoped to one adapter
invocation and simply does not exist for any request that never reaches
`invoke()` — authentication failure, rate limiting, malformed JSON, an
unknown operation. None of those are auditable by `command_id` alone.

Sprint 6 introduces its own, separate request id: generated as the
literal first statement inside `ExecuteRequestHandler::handle()`
(`bin2hex(random_bytes(16))` by default, injectable via the
constructor's `?callable $requestIdGenerator`, mirroring
`CommandAdapter`'s own `$idGenerator` convention), server-side,
unpredictable, never derived from or equal to any caller-supplied value,
and included on every audit event regardless of outcome
(`AuditLoggerTest::testEventsCarryUniqueRequestId`).

**Not returned in the HTTP response this sprint** — a deliberate,
documented scope decision, not an oversight. Exposing it would mean
threading a new value through `ResponseMapper`'s three static builder
methods (`success()`/`failure()`/`fromApiException()`), which are
currently pure, stateless translators of `AdapterResult`/`ApiException`
with zero HTTP-request-scoped state; changing that shape was judged out
of proportion to what this sprint needs (a request id that appears in
every audit event, which is the actual, stated requirement). See §17
Known limitations.

## 9. Audit pipeline position

One single observation point: `ExecuteRequestHandler::handle()`'s
existing try/catch — unchanged in what it catches or how it classifies
failures — now assigns its result into a local `$result` variable
instead of returning directly from each branch. Immediately after the
try/catch (before the final `return $result;`), `recordAudit()` builds
one `AuditEvent` from `$result` plus whatever HTTP-layer context was
captured along the way, and hands it to the injected `AuditLogger`. This
guarantees exactly one audit event per `handle()` call, from any exit
path, without moving or weakening a single existing security check:

- Rate-limit checks still run exactly where Sprint 5 put them (pre-auth
  first, authenticated right after `authenticate()` succeeds) — audit
  logging observes their outcome afterward, never before.
- `authenticateWithCredentials()` (renamed from `authenticate()` purely
  to accept already-extracted credentials, so the attempted credential
  id is available for audit even on failure — see the method's own
  docblock) still performs the exact same check, in the exact same
  order, unchanged.
- Authorization still happens exactly where it always has — entirely
  inside `CommandAdapter::invoke()`, before locking, both completely
  untouched by this sprint.
- `AuditTargetRedactor::redact()` is called once, right before
  `$this->adapter->invoke()`, from the exact params about to be passed
  to it — never earlier, never from unvalidated data.

`web/api/v2/index.php`'s own outer `try/catch` (construction failure,
`json_encode()` failure) is genuinely un-auditable by this design and is
deliberately not audited — no `ExecuteRequestHandler` instance, and
therefore no audit event, is ever constructed on that path. This is
consistent with that file's own pre-Sprint-6 docblock describing itself
as pure transport wiring with zero substantive logic of its own.

## 10. Storage/sink design

`web/inc/api/FileAuditLogger.php` — the real, production `AuditLogger`.
**Follows `web/inc/adapter/LockManager.php`'s own established
convention exactly** (confirmed by reading that class's docblock and
constructor before writing this one): a fixed, documented production
path (`/usr/local/hestia/data/api-v2-audit/audit.log` — a sibling of
`$HESTIA/data/adapter-locks/`, following the same naming shape), which
this class **never creates itself**. If the directory does not exist —
as it does not in this repository's test environment, since no
installer has run — every write fails with `AuditWriteException`, and
`ExecuteRequestHandler`'s documented fail-open policy (§13) takes over
silently.

**Why not `$HESTIA/log`** (`/var/log/hestia`), where `system.log`/
`error.log`/`auth.log`/`backup.log` already live? Confirmed from
`install/hst-install-ubuntu.sh`: that directory is created
`mkdir -p /var/log/hestia` as root, with `chmod 750` and no `chown` to
the web process identity anywhere in the installer — it stays root-only.
The PHP process (running as the web server's own identity, e.g.
`hestiaweb`) cannot write there without an installer change, which is
out of this sprint's scope. `$HESTIA/data/adapter-locks/`, by contrast,
is explicitly `chown`'d to `hestiaweb:hestiaweb` and `chmod 770`
specifically so `LockManager`'s own PHP code can write to it directly —
`$HESTIA/data/api-v2-audit/` mirrors that same, already-proven pattern.

**Why not a self-created directory** (the choice Sprint 5 made for its
rate-limit counters)? A security audit trail is meant to persist and be
reviewed — `sys_get_temp_dir()` can be cleared by the OS
(`systemd-tmpfiles`, reboot), silently discarding audit history. That
trade-off was acceptable for Sprint 5's disposable, resettable rate
counters; it is not the right default for a record meant to answer "who
did what" after the fact. See §14/§17 for the consequence of this
choice: **audit logging is fully implemented and tested, but inert in
production until `/usr/local/hestia/data/api-v2-audit/` is
provisioned** — exactly the same "safe design, deferred activation"
shape `LockManager` itself already has for any deployment where the
installer hasn't run.

One append-only file, one JSON object per line (`toArray()` →
`json_encode()`).

## 11. Permissions/ownership

Not provisioned by this sprint (no installer file was modified — see
§10/§19). Documented, intended production permissions, mirroring the
installer's own `$HESTIA/data/adapter-locks` precedent exactly:
directory `hestiaweb:hestiaweb`, `0770`; the audit file itself created
by `FileAuditLogger` and `chmod`'d `0600` on first creation (tightened
from the `0660` shape `install/hst-install-ubuntu.sh` uses for
`/var/log/hestia/*.log`, since — unlike those files — no second process
identity ever needs read access to this one).

> **Superseded by Sprint 7** (see
> `dev-docs/api-v2/API_V2_AUDIT_LOGGING_PRODUCTION_IMPLEMENTATION.md`
> §5): on actual implementation, the directory was provisioned `0700`,
> not `0770` — Sprint 7 determined the `adapter-locks` group-access
> shape isn't justified here (no legitimate second reader exists in the
> `hestiaweb` group for security-sensitive audit records), so every
> `0770` reference on this page is historical intent, not the shipped
> permission.

## 12. Concurrency behavior

Each write is one `fopen("ab")` → `flock(LOCK_EX)` → `fwrite()` →
`flock(LOCK_UN)` → `fclose()` cycle — the same pattern
`web/inc/api/FilesystemRateLimitStore.php` already established in
Sprint 5, applied here to a pure append rather than a read-modify-write.
`AuditLoggerTest::testConcurrentWritesDoNotCorruptRecords` writes 50
events sequentially against one `FileAuditLogger` instance/directory and
asserts every resulting line is independently valid, parseable JSON —
true multi-process concurrent-write safety relies on `flock()`'s own
OS-level guarantee, the same reasoning
`API_V2_RATE_LIMITING_IMPLEMENTATION.md` §10 already gives for its own
equivalent test, not re-verified empirically with real concurrent
processes here either.

## 13. Failure semantics

**Fail-open, unconditionally.** No source-verified evidence in this
repository establishes a hard security/compliance requirement for
guaranteed, uninterruptible audit persistence (no existing Hestia
logging mechanism is fail-closed either — `log_event`/`log_history`
simply `echo >> $log`, with no error handling at all). Per the task's
own explicit default preference, an `AuditWriteException` from
`AuditLogger::write()` is caught inside `ExecuteRequestHandler::recordAudit()`
and silently discarded — the already-fully-computed `[$httpStatus,
$envelope]` result is returned completely unchanged either way.
Audit-write failure can therefore never become an availability
dependency for API v2 (`AuditLoggerTest::testAuditWriteFailureIsFailOpen`).

**Detectability of failure was deliberately NOT implemented this
sprint.** The task allows a safe signal "if the existing architecture
supports it" — none was found that is both safe (no path, no exception
message, no per-event content) and low-noise. The default `AuditLogger`
(`FileAuditLogger` at its production path) fails on **every** call in
this repository's own test environment (the path is never provisioned
there) — over 100 test invocations per run. Any `error_log()`-based
signal, however minimal, would therefore spam every single test run
rather than only a genuine production failure, and there is no cheap
way to distinguish "test environment, expected" from "production,
genuine fault" from inside this class. This is stated explicitly here,
per the task's own instruction, rather than inventing an assumption;
see §17/§18.

## 14. Security properties

- Never blocks the API response, ever (§13).
- Never bypasses, weakens, reorders, or duplicates authentication,
  rate limiting, or authorization — it is a pure observer positioned
  strictly after each of those has already completed (§9).
- Never acquires the adapter lock and never spawns a process — mechanically
  verified (`AuditLoggerTest::testAuditLoggingNeverAcquiresLock`,
  `testAuditLoggingNeverSpawnsAProcess`) and structurally guaranteed
  (`AuditLogger`'s own single method has no access to `LockManager` or
  any `ProcessRunnerInterface`).
- Never references a `bin/v-*` script name or performs shell execution —
  covered by `GenericityTest`'s existing mechanical scan, extended to
  every new file (§15).
- Declares no property capable of holding a secret even in principle
  (§7).
- No filesystem path, stack trace, or the audit subsystem's own
  exception message is ever included in the API response — verified by
  `AuditLoggerTest::testAuditWriteFailureDoesNotLeakIntoResponse`.

## 15. Test coverage

New dedicated suite: `test/api/AuditLoggerTest.php`, 24 tests, plus one
new `GenericityTest` check and a new `SpyAuditLogger` test double
(mirroring `SpyLockManager`/`SpyAuthorizer`'s own established pattern —
never touches a real file). Coverage against the task's own required
list:

| Requirement | Test(s) |
|---|---|
| exactly one event on success | `testSuccessGeneratesExactlyOneEvent` |
| expected event on failure | `testFailureGeneratesExpectedEvent` |
| authentication failure event | `testAuthFailureGeneratesEvent` |
| rate-limit rejection event | `testRateLimitedGeneratesEvent` |
| authorization denial event | `testAuthorizationDenialGeneratesEvent` |
| malformed request event | `testMalformedJsonGeneratesEvent` |
| unknown operation event | `testUnknownOperationGeneratesEvent` |
| unexpected exception event | `testUnexpectedExceptionGeneratesEvent` |
| request/correlation id present, unique | `testEventsCarryUniqueRequestId` |
| authenticated user recorded when available | `testAuthenticatedUserRecordedWhenAvailable` |
| credential secret never appears | `testSecretNeverInAuditEvent` |
| Authorization header never appears | `testAuthorizationHeaderNeverInAuditEvent` |
| database.create password never appears | `testDatabaseCreatePasswordNeverInAuditEvent` |
| raw request body never appears | `testRawBodyNeverInAuditEvent` |
| no path/stack trace leak on write failure | `testAuditWriteFailureDoesNotLeakIntoResponse` |
| cannot cause command execution | `testAuditLoggingNeverSpawnsAProcess` |
| cannot bypass authorization | `testAuditLoggingNeverBypassesAuthorization` |
| cannot acquire the adapter lock | `testAuditLoggingNeverAcquiresLock` |
| fail-open/fail-closed behavior | `testAuditWriteFailureIsFailOpen` |
| concurrent writes don't corrupt records | `testConcurrentWritesDoNotCorruptRecords` |
| table-driven outcome coverage (§7 of the task) | `testOutcomeTableMapsToExpectedEventType` (11 of 13 rows; the remaining 2 — rate limited, unexpected exception — are covered by their own dedicated tests above instead, since both need bespoke collaborators that don't fit the table's shared shape) |
| target redaction (§8 of the task) | `testDatabaseCreateTargetRedaction`, `testDatabaseDeleteTargetIsNormalized`, `testNoTargetForPreParamsFailures` |

`GenericityTest`'s `API_SOURCE_FILES` list was extended with all five
new `web/inc/api/*.php` files — its existing "no shell execution"/"no
bin/v-* reference" checks now cover them unchanged, and one new check
(`testAuditEventModelDeclaresNoSensitiveFields`) was added — no existing
rule was weakened.

## 16. Regression results

Full suite, run 3 consecutive times each, immediately after
implementation:

| Suite | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| `php test/api/run_tests.php` | 147 passed, 0 failed | 147 passed, 0 failed | 147 passed, 0 failed |
| `php test/auth/run_tests.php` | 62 passed, 0 failed | 62 passed, 0 failed | 62 passed, 0 failed |
| `php test/adapter/run_tests.php` | 198 passed, 0 failed | 198 passed, 0 failed | 198 passed, 0 failed |

(147 total, up from the pre-Sprint-6 baseline of 122 — 24 new
`AuditLoggerTest` tests + 1 new `GenericityTest` check, with every
pre-existing test unmodified and still passing unchanged.) `php -l` was
run against every new/modified PHP file — zero syntax errors.
`git diff --check` — zero whitespace errors. Mechanical source scans for
`exec(`/`shell_exec(`/`proc_open(`/`passthru(`/`system(`/`popen(` across
every new audit file — zero matches.

## 17. Known limitations

- **Inert in production until provisioned** — **resolved by Sprint 7**
  (`dev-docs/api-v2/API_V2_AUDIT_LOGGING_PRODUCTION_IMPLEMENTATION.md`):
  `FileAuditLogger`'s default directory
  (`/usr/local/hestia/data/api-v2-audit/`) is not created by anything in
  *this* sprint (Sprint 6); until an installer change provisions it,
  every write fails and fail-open means no event is ever actually
  recorded on a real deployment. Sprint 7 adds exactly that installer
  change (fresh-install and upgrade), provisioned `0700` rather than the
  `0770` this doc originally described — see §11's superseded note
  above.
- **No log rotation** — **resolved by Sprint 7**, which adds a
  dedicated `logrotate` stanza. `install/deb/logrotate/hestia` only
  covers `/var/log/hestia/*.log`, which this audit log deliberately does
  not live under (§10) — confirmed by reading that file directly, not
  assumed. Prior to Sprint 7, `audit.log` would have grown unbounded.
  `AuditLoggerTest::testConcurrentWritesDoNotCorruptRecords` confirms
  writes themselves are safe; it says nothing about unbounded growth.
- **No failure detectability mechanism** — see §13 for why none was
  implemented this sprint rather than a noisy or unsafe one.
- **No multi-host aggregation.** The audit log is a single local file on
  whichever host serves the request. If API v2 is ever deployed across
  more than one host, each host's audit trail is independent — a
  shared/aggregated view would need a separate mechanism, explicitly out
  of scope per the task's own non-goals list.
- **Request id is not returned to the client** — deliberate, see §8.

## 18. Deferred work

- The installer/upgrade change to provision
  `/usr/local/hestia/data/api-v2-audit/` (`hestiaweb:hestiaweb`,
  `0770`) — required before audit logging is active in any real
  deployment. This alone is the most important deferred item from this
  sprint.
- A logrotate stanza (or in-process rotation) for `audit.log`.
- A safe, low-noise mechanism to detect/alert on repeated audit-write
  failure in production (distinct from the test environment's own
  universal, expected failure — see §13).
- Exposing the request id in the response envelope/a header, if a
  future need for client-side support correlation emerges (§8).
- External SIEM integration, an audit UI, multi-host aggregation — all
  explicitly out of scope per the task's own non-goals list.

## 19. Architectural findings

- **`ApiException` is `final`** (confirmed while designing Sprint 5's
  429 response and re-confirmed here) — `AuditEvent`/`AuditLogger` were
  therefore designed as a completely independent seam rather than
  attempting to piggyback on the exception hierarchy.
- **No PHP-native logging primitive exists anywhere in this codebase**
  (§2) — every existing mechanism is bash-only or reachable from PHP
  only via `exec(bin/v-log-action)`, which this sprint's own genericity
  rules forbid. This confirms Sprint 5's own equivalent finding for rate
  limiting was not a one-off: API v2's HTTP layer is, by design, fully
  decoupled from the bash-side operational tooling, and every
  HTTP-layer-only concern this project adds (rate limiting, now audit
  logging) has had to be built from scratch for exactly that reason.
- **`$HESTIA/log` vs `$HESTIA/data`**: confirmed from the installer that
  only `$HESTIA/data/*` subdirectories are ever `chown`'d to the web
  process identity; `$HESTIA/log` stays root-only. Any future
  HTTP-layer feature wanting to write a file directly (without shelling
  out) will hit the same constraint this sprint did, and will need
  either a new `$HESTIA/data/api-v2-*` sibling directory (this sprint's
  approach) or an installer change granting broader access — worth
  keeping in mind for the planned web-server hardening workstream if it
  ever needs PHP-side file writes of its own.

## 20. Final verdict

Sprint 6 is complete: implemented, tested, documented. No STOP condition
was triggered — safe audit storage design does not require an installer
change (only its *activation* in a real deployment does, and fail-open
exists precisely so that gap is never an availability risk); no
sensitive parameter was exposed to the logging layer; `CommandAdapter`'s
security ordering was never touched; the public API v2 contract did not
change materially (no new response field, no behavior change for any
existing request). All pre-existing tests pass unmodified (147/62/198,
stable across 3 consecutive runs each); 24 new dedicated tests plus 1
new genericity check were added.

**Readiness**: the code is correct and safe as shipped, but audit
logging will record **nothing** in a real deployment until
`/usr/local/hestia/data/api-v2-audit/` is provisioned by a future,
explicitly separate installer change — this is a known, load-bearing
limitation stated here deliberately rather than left implicit, and
should be the very next follow-up item.

> **Update — Sprint 7**: that follow-up item is done. See
> `dev-docs/api-v2/API_V2_AUDIT_LOGGING_PRODUCTION_IMPLEMENTATION.md`
> for fresh-install/upgrade provisioning (directory mode `0700`, not the
> `0770` this page describes — see §11's note) and log rotation. Audit
> logging is no longer inert once that sprint's installer/upgrade
> changes are applied.
