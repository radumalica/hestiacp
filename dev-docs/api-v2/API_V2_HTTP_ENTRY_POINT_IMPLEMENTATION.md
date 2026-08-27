# API v2 — HTTP Entry Point Implementation

**Sprint 2 deliverable.** Implements the first real HTTP entry point for
API v2 (`POST /api/v2/execute`), strictly from
[`API_V2_HTTP_CONTRACT_DESIGN.md`](./API_V2_HTTP_CONTRACT_DESIGN.md),
using exactly one proving-ground adapter operation (`domain.get`).

## 1. Files Changed

New production source (`web/inc/api/`, namespace `Hestiacp\Api`):

- `ApiException.php` — internal control-flow exception carrying a stable
  error code, HTTP status, and safe message.
- `OperationAllowlist.php` — the fixed, explicit API v2 operation
  allowlist. Sprint 2: `["domain.get"]` only.
- `ParameterNormalizer.php` — the resource-identifier normalization seam
  (§11 of the contract); identity function for Sprint 2's one operation.
- `ResponseMapper.php` — translates `AdapterResult`/`ApiException` into
  the public JSON envelope + HTTP status.
- `ExecuteRequestHandler.php` — the full request pipeline: parse →
  authenticate → resolve operation → validate → normalize →
  `CommandAdapter::invoke()` → map response.
- `bootstrap.php` — manual-require bootstrap, mirroring
  `web/inc/adapter/bootstrap.php`'s own convention.

New production source (HTTP transport):

- `web/api/v2/index.php` — the thin HTTP entry point.

New tests (`test/api/`):

- `ResponseMapperTest.php` — 21 tests, direct unit tests of the full
  §14/§15/§16 mapping table against synthetic `AdapterResult` instances.
- `ExecuteRequestHandlerTest.php` — 33 tests, end-to-end pipeline tests
  covering every category Sprint 2 required (authentication, HTTP
  contract, authorization, execution, response).
- `GenericityTest.php` — 4 mechanical source-scan tests (no shell
  execution, no `bin/v-*` script-name reference, in both the class files
  and the HTTP entry point).
- `run_tests.php` — suite entry point.

**Not modified**: `CommandAdapter.php`, `CommandRegistry.php`,
`ParameterValidator.php`, `LockManager.php`, `SameUserAuthorizer.php`,
`AuthorizerInterface.php`, `AccessKeyValidator.php`,
`AccessKeyProvisioner.php`, `web/api/index.php`, any installer script,
sudoers. No genuine blocking defect was found in any of them — see §15
"Deviations" for the one place this implementation extends, rather than
modifies, the Sprint 1 contract.

## 2. Endpoint Structure

```
POST /api/v2/execute
Content-Type: application/json
Authorization: Basic base64(credential_id:secret)

{"operation": "domain.get", "params": {"user": "alice", "domain": "example.com"}}
```

Served from `web/api/v2/index.php` — matching `web/api/index.php`'s own
existing, unmodified convention of one `index.php` per API version
directory. **Known limitation**: making the literal path
`/api/v2/execute` (rather than `/api/v2/` or `/api/v2/index.php`)
resolve to this file is a webserver-configuration concern; no
nginx/Apache rewrite template exists for the legacy `/api/` endpoint
either, and none was created or modified here (out of this sprint's
scope — see §13).

The entry point script is a thin transport shim only: it reads
`$_SERVER`/`php://input`, constructs `ExecuteRequestHandler`, calls
`handle()`, and writes back `http_response_code($status)` +
`echo json_encode($envelope)`. It contains zero authentication,
validation, normalization, or execution logic — every one of those
responsibilities lives in `ExecuteRequestHandler`, which is fully unit
tested without any real HTTP server.

## 3. Authentication Flow

`ExecuteRequestHandler::authenticate()`:

1. Extract `Authorization: Basic <base64>` from the header string passed
   in (never reads `$_SERVER` directly — see §12 testability).
2. `base64_decode(..., true)` (strict mode — rejects invalid alphabet
   characters).
3. Split on the first `:`; reject if absent or the id half is empty.
4. Call `AccessKeyValidator::authenticate($id, $secret)` **unchanged** —
   no modification, no new parameter, no wrapper logic duplicating what
   it already does.
5. A `null` return (unknown id, wrong secret, malformed record, revoked
   credential — `AccessKeyValidator`'s own collapsed contract) and every
   extraction failure above (missing header, non-`Basic` scheme,
   undecodable base64, missing colon, empty id) all produce the
   **identical** `ApiException("AUTHENTICATION_FAILED", 401, "Authentication failed.")`
   — verified byte-for-byte identical by test #6
   (`testAuthFailureUniform`).

Authentication happens strictly before operation resolution — verified
by test #7 (`testAuthFailureNoAdapterInvocation`): the process runner
records zero invocations on any authentication failure.

## 4. Actor Construction

`actor = ["user" => $authenticatedUser]` — built in exactly one place
(`authenticate()`'s return value), read from nowhere else, and never
merged with or overridden by any part of the request body. See §15
"Deviations" for the precise, tested meaning of "caller cannot supply
actor.user" this implementation enforces.

## 5. Allowlist Design

`OperationAllowlist::ALLOWED_OPERATIONS = ["domain.get"]` — a fixed
class constant, not derived from `CommandRegistry`, the filesystem, or
caller input. `ExecuteRequestHandler` additionally accepts an optional
constructor parameter overriding this list, used **only** by
`test/api/ExecuteRequestHandlerTest.php`'s lock-acquisition test (§20 of
the test list) to exercise a synthetic mutating operation registered via
`CommandRegistry`'s own pre-existing `$additionalOperations` test-only
extension point — `web/api/v2/index.php` never passes this argument, so
production behavior is exactly the fixed one-operation allowlist.
Verified: `database.create` (a real, registered `CommandRegistry`
operation) and `v-add-web-domain` (a real script name) are both rejected
with `OPERATION_NOT_ALLOWED`/404, never reaching `CommandAdapter` (tests
`testAllowlistCannotBeBypassed`, `testArbitraryScriptNameRejected`).

## 6. Parameter Handling

`ExecuteRequestHandler::validateEnvelope()` enforces, at the HTTP
envelope layer only:

- Only `operation` and `params` may appear at the top level — any other
  key (including a caller-supplied `actor`) is `VALIDATION_FAILED`/422.
- `params` must be present and must be a JSON object (not an array/list)
  — `VALIDATION_FAILED`/422 otherwise.
- A `null`-valued `params` field is stripped before reaching
  `CommandAdapter`, so it is treated as "not provided," matching
  `SensitiveParameterTest.php`'s own established convention (§10 of the
  contract).

Everything else — required/unexpected parameter names, string shape,
username/domain validity — is `CommandAdapter`'s own, single,
unduplicated responsibility (`ParameterValidator`, unmodified). No
second validation ruleset was written.

`ParameterNormalizer::normalize()` is the resource-identifier seam
(§11). For Sprint 2's one operation it is the identity function (domain
identifiers are already 1:1 per the contract's own table); the
per-operation `switch` exists as an explicit, already-wired extension
point for a future operation like `database.delete`'s `{user}_`
prefixing, without needing this class's shape to change.

## 7. CommandAdapter Integration

`web/api/v2/index.php` constructs exactly one `CommandAdapter` from its
own, real, unmodified `CommandRegistry` and `ProcOpenProcessRunner`,
using every one of `CommandAdapter`'s own constructor defaults —
including its default `SameUserAuthorizer`. Nothing about locking or
authorization policy is decided in the API layer; `CommandAdapter`'s own
existing ordering (resolve → validate → normalize → authorize → lock →
execute) is exercised unmodified, end to end, through this new caller.

`ExecuteRequestHandler` calls `CommandAdapter::invoke()` exactly once
per request and performs no process execution, shell command
construction, or `bin/v-*` script-name resolution of its own — verified
mechanically by `GenericityTest.php` (source-scanned for
`exec`/`shell_exec`/`system`/`passthru`/`proc_open`/`popen`/backtick and
for any `v-[a-z]` script-name-shaped substring, across every file in
`web/inc/api/` and `web/api/v2/index.php`).

## 8. Authorization Flow

Unmodified: `CommandAdapter`'s own `SameUserAuthorizer` (its default)
receives exactly the `actor`/`target` this layer constructs.
`testAuthenticatedUserBecomesActor` proves the authenticated identity
(not a caller-supplied `params.user` value) is what
`SameUserAuthorizer` actually compares — authenticating as `alice` and
requesting `params.user = "bob"` is denied (`AUTHORIZATION_DENIED`/403),
which could only happen if `actor.user` is genuinely `"alice"`.
`testAuthorizationDenialPreventsExecution` and
`testAuthorizationDenialPreventsLockAcquisition` confirm zero process
invocations and zero lock-manager `acquire()` calls on denial — the
latter using a synthetic mutating test operation (§5 above), since
Sprint 2's real allowlisted operation is read-only and never reaches
lock acquisition under any outcome.

## 9. Response Mapping

`ResponseMapper::fromAdapterResult()` implements the full §13-§16
mapping table:

- `status === "ok"` → `outcome: "succeeded"`, `200`.
- `adapterErrorCode`: `AUTHORIZATION_DENIED`→403,
  `LOCK_TIMEOUT`→409, `LOCK_UNAVAILABLE`→503,
  `{MISSING,UNEXPECTED}_PARAMETER`/`UNKNOWN_PARAMETER_TYPE`/`VALIDATION_FAILED`→`VALIDATION_FAILED`/422,
  everything else (`UNKNOWN_OPERATION`, `REGISTRY_ERROR`,
  `TEMP_FILE_UNAVAILABLE`)→`INTERNAL_ERROR`/500 (never surfaced
  verbatim).
- `hestia_error`, `mutationState === null` (read-only failure) →
  `UPSTREAM_COMMAND_FAILED`/422 — the read-only extension of §15's
  mutating-operation row (contract doesn't separately enumerate this
  case; documented as this implementation's own necessary completion,
  §15 below).
- `mutationState === "confirmed_degraded"` → `succeeded_with_warning`/200.
- `mutationState === "unknown"` → `outcome: "unknown"`/207, **never**
  `"failed"` (tested explicitly, `testUnknownNeverFailed`).

`error.details` carries only the symbolic `hestia_error_code` (e.g.
`"E_NOTEXIST"`) when present — never raw stdout/stderr/exit code/message
text (verified: `testErrorDetailsSymbolicOnly` asserts the raw
`errorMessage` string never appears anywhere in the JSON-encoded
envelope).

## 10. Error Handling

Two, and only two, catch clauses in `ExecuteRequestHandler::handle()`:

1. `catch (ApiException $e)` — every expected, precisely classified
   failure (auth, allowlist, envelope validation).
2. `catch (\Throwable $e)` — anything else. Mapped to a single, fixed
   `INTERNAL_ERROR`/500. **`$e->getMessage()` is never included** in the
   response — verified by `testUnexpectedExceptionDoesNotLeak`, which
   forces `ProcessRunnerInterface::run()` to throw
   `new \RuntimeException("boom at /var/secret/internal-path-should-never-leak")`
   and asserts neither that path nor the exception class name appears
   anywhere in the JSON-encoded response.

This is not "silently converting a programmer bug into a fake
operational failure" — `INTERNAL_ERROR` is §14's own designated
catch-all, explicitly including "a genuine unexpected exception at the
HTTP layer itself." No `ApiException` is ever thrown with a
more-specific code from inside this second catch block.

`web/api/v2/index.php` additionally sets `ini_set("display_errors", "0")`
so a PHP warning/notice cannot itself corrupt the single JSON response
this endpoint always returns — scoped to this one script only.

## 11. Security Properties

Every item from Sprint 2's own 20-point security requirement list is
satisfied:

- **No arbitrary command execution / no caller-selected script or
  executable**: `operation` is checked against a fixed allowlist before
  anything else is inspected; `CommandRegistry` (unmodified) is the only
  code that ever resolves an operation name to a script path.
  `GenericityTest` mechanically confirms zero shell-execution functions
  and zero `bin/v-*` references anywhere in the new source.
- **No caller-selected `actor.user`**: `actor` is constructed in exactly
  one place, from `AccessKeyValidator::authenticate()`'s return value
  only; a request body `actor` field is rejected outright as an unknown
  top-level key (tests #14/#15).
- **No authentication/`SameUserAuthorizer`/`LockManager` bypass**: every
  request that reaches `CommandAdapter` goes through its complete,
  unmodified `invoke()` pipeline; there is no second, alternate
  execution path anywhere in this new code.
- **No raw secret in logs/responses**: `test/api/`'s
  `testSecretNeverInResponse` confirms the real secret never appears in
  either a failed or successful response's JSON encoding; no `error_log`/
  `syslog`/similar call was added anywhere in this sprint's new code.
- **No filesystem-path leakage**: `error.details` carries only the fixed
  `HESTIA_EXIT_CODES` symbolic name table (`AdapterResult::$hestiaErrorCode`),
  never a raw message string.
- **No mutation endpoint this sprint**: `OperationAllowlist` contains
  exactly one, read-only operation.
- **No changes to legacy `web/api/index.php`, `web/add/`, `web/delete/`,
  installer scripts, or sudoers.**

## 12. Tests

- `test/api/run_tests.php`: **57/57**, run 3 consecutive times.
- `test/auth/run_tests.php`: **62/62**, run 3 consecutive times — unchanged.
- `test/adapter/run_tests.php`: **198/198**, run 3 consecutive times — unchanged.

`ExecuteRequestHandlerTest` is deliberately HTTP-transport-independent —
`handle()` takes plain scalar arguments (method, content type,
Authorization header value, raw body string), never `$_SERVER`/
`php://input` directly, so every test in this suite runs as a plain PHP
function call: a fresh temp credential directory per test (matching
`AccessKeyValidatorTest`'s convention) and `FakeProcessRunner`/
`ThrowingProcessRunner` (matching every existing `test/adapter/*Test.php`
file's convention) — no real HTTP server, no real Hestia installation,
no sudo access, no `bin/v-*` scripts required.

## 13. Known Limitations

- **Literal `/api/v2/execute` path**: not guaranteed without a
  webserver-level rewrite this sprint did not add (see §2). The endpoint
  is reachable at whatever URL the panel's existing server configuration
  maps to `web/api/v2/index.php` — the same situation
  `web/api/index.php` has always been in.
- **`Authorization` header stripping**: some Apache/PHP-FPM
  configurations strip the `Authorization` header before PHP ever sees
  it under `$_SERVER["HTTP_AUTHORIZATION"]`. `web/api/v2/index.php`
  falls back to `$_SERVER["REDIRECT_HTTP_AUTHORIZATION"]` (the common
  workaround), but this was not verified against a real HestiaCP nginx
  deployment in this sprint (no HTTP server was started; only the
  request-handling class itself was tested, per its own transport-
  independent design in §12).
- **Only `domain.get` is exposed.** All six other registered operations
  remain unreachable via API v2 until a future sprint adds each to
  `OperationAllowlist` and, where needed, `ParameterNormalizer`.

## 14. Deferred

Everything Sprint 1's own §5 (Non-Goals) and §21 (Open Questions) already
deferred remains deferred: per-operation `data` payload shapes for the
other six operations, rate limiting, audit logging, API versioning
beyond `v2`, `Idempotency-Key`, async job semantics, Cloud Account/
tenant/roles/scopes. Nothing in Sprint 2 required revisiting any of
these.

## 15. Deviations from API_V2_HTTP_CONTRACT_DESIGN.md

**One deviation, fully documented, not a STOP condition.**

§8/§10 of the contract state, in prose, that a caller-supplied `user`/
`actor` field "anywhere... in `params`" must be rejected. Read
literally, this would reject `params.user` itself — but every one of
`CommandRegistry`'s seven operations, `domain.get` included, declares a
**required** `user` parameter (the resource owner, a target concept
`SameUserAuthorizer` exists specifically to compare against the
authenticated actor). A literal implementation of that sentence would
make every registered operation permanently uncallable through API v2,
which cannot have been the contract's intent — no operation would ever
be reachable, and the contract's own worked examples (§6, §10) show
`params.user` being sent for exactly this purpose.

The contract's own §22 acceptance criterion #3 states the real,
non-contradictory, testable requirement precisely: *"actor.user is never
derived from anything but `AccessKeyValidator::authenticate()`'s return
value... a `user`/`actor` field inside `params` has zero effect on the
resulting `actor`."* This implementation satisfies exactly that: `actor`
is built solely from the authenticated credential and is never read
from, or merged with, the request body at any point (§4 above) — a
`params.user` value is simply the operation's own, pre-existing target
parameter, handled by `SameUserAuthorizer` exactly as it always has been.
A literal `actor` field anywhere in the request is still rejected (via
the envelope's fixed `operation`/`params`-only top-level schema); a
`params.actor` key for an operation that doesn't declare one is still
rejected (via `CommandAdapter`'s own existing `UNEXPECTED_PARAMETER`
check) — both without this implementation needing an
operation-specific field-name denylist. Sprint 2's own task text confirms
this reading is correct: every one of its concrete instructions says
"actor.user... present in the request payload," never "`params.user`,"
and its own required test #17 is phrased as "authenticated identity is
the only source of actor.user" — exactly what is implemented and tested
(`testParamsUserCannotOverrideActor`).

Two small, additive vocabulary items not present in §14/§15's tables,
both necessary and neither conflicting with anything the contract does
define:

- **`METHOD_NOT_ALLOWED`/405** for a non-`POST` request — the contract's
  own §6 assumes a POST-only endpoint as a given but never actually
  defines the rejection code for violating that assumption.
- **`UPSTREAM_COMMAND_FAILED`/422 for a read-only operation's
  `hestia_error`** — §16's own table enumerates this code for a
  *mutating* operation only; a failed read has no mutation-state
  ambiguity to preserve (nothing was ever going to be "mutated"), so
  reusing the same code/status here is a direct, non-conflicting
  extension of the existing table to a case it does not separately
  spell out, not a new architectural decision.

## 16. Acceptance Criteria (§22) — Verification

1. ✅ No request reaches `CommandAdapter::invoke()` without a successfully
   authenticated actor (test #7).
2. ✅ `operation` is checked against the fixed allowlist before deep
   validation (tests #12, #23, #24, #25).
3. ✅ `actor.user` is never derived from anything but
   `AccessKeyValidator::authenticate()` (test #17).
4. ✅ Every response conforms to the §13 envelope, `error.code` drawn only
   from the fixed vocabulary (`ResponseMapperTest`, all 21 cases).
5. ✅ `mutationState: unknown` never produces `outcome: "failed"`
   (`testUnknownNeverFailed`).
6. — `backup.schedule` is not exposed this sprint; not applicable yet.
7. ✅ No response/error.details ever contains a secret, shell command,
   stack trace, or filesystem path (tests #29, #30,
   `testErrorDetailsSymbolicOnly`).
8. ✅ `AccessKeyValidator`, `CommandAdapter`, `CommandRegistry`,
   `LockManager`, `SameUserAuthorizer`, `AuthorizerInterface` are
   byte-for-byte unmodified.
