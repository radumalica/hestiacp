# API v2 — HTTP Hardening & Error Semantics

**Sprint 4 deliverable.** Hardens the API v2 HTTP boundary and finalizes
its error/status semantics, without changing `CommandAdapter`,
`CommandRegistry`, `ParameterValidator`, `AuthorizerInterface`,
`SameUserAuthorizer`, `LockManager`, `bin/*`, or legacy `web/api/*`.

## 1. Sprint Scope

"Harden the API v2 HTTP boundary and finalize its error/status semantics
without changing the underlying adapter architecture." No new
operations, async jobs, idempotency, rate limiting, audit logging, Cloud
Account/tenant/roles, or credential expiration/rotation.

## 2. Existing Architecture Reviewed

Read in full before any change: `API_V2_HTTP_CONTRACT_DESIGN.md`,
`API_V2_HTTP_ENTRY_POINT_IMPLEMENTATION.md`,
`API_V2_OPERATION_EXPOSURE_IMPLEMENTATION.md`, `AccessKeyValidator.php`
and its test suite, `ResponseMapper.php`, `ExecuteRequestHandler.php`,
`web/api/v2/index.php`, and `test/api/*`. Sprints 1–3 had already
implemented the large majority of this sprint's requirements
(authentication/authorization uniformity, exception sanitization,
envelope validation, mutation-state fidelity) and tested them
extensively (92 pre-Sprint-4 API v2 tests). Sprint 4's job was
therefore genuinely an audit — find and close the remaining gaps, not
rebuild.

**Concrete gaps found before writing any code:**

1. `ExecuteRequestHandler::decodeJson()` conflated two different
   problems into one error code: a genuine JSON *syntax* error (empty
   body, truncated JSON) and syntactically-valid-but-wrong-*shape* JSON
   (a bare scalar, the literal `null`, or a top-level array) both
   produced `MALFORMED_JSON`. Verified empirically
   (`json_decode("42")` returns `42` with `json_last_error() ===
   JSON_ERROR_NONE`) — the contract's own §10 explicitly distinguishes
   these two failure classes ("a client debugging a malformed-JSON
   failure needs a different fix... than a client debugging a
   validation failure"), so this was a real, fixable inconsistency, not
   a design question.
2. No application-level request-body size boundary existed.
3. No defense-in-depth existed *outside* `ExecuteRequestHandler::handle()`'s
   own try/catch — an exception during object construction in
   `web/api/v2/index.php`, or a `json_encode()` failure after `handle()`
   returns, would not have produced the sanitized JSON error shape this
   endpoint is supposed to always return.
4. The authentication-uniformity test suite compared only two failure
   reasons (`unknown id`, `wrong secret`) for byte-identical output; a
   revoked credential was never exercised end-to-end through the HTTP
   layer (only at the `AccessKeyValidator` unit level).
5. No test proved a genuine (non-degraded, non-unknown) `hestia_error`
   for a *read* operation maps to `outcome: "failed"` through the full
   HTTP pipeline, only via `ResponseMapper`'s own direct unit tests.

Everything else audited — authorization ordering, mutation-state
mapping, error vocabulary, secret non-disclosure, `GenericityTest`'s
shell/script-reference scan — was already correct and already tested;
Sprint 4 only added the missing regression coverage and documentation
consolidation for those, per Part H/I.

## 3. Final HTTP Contract

Unchanged from Sprint 1/2: `POST /api/v2/execute`,
`Authorization: Basic <id:secret>`, JSON request/response envelope.
Sprint 4 adds no new endpoint, method, or transport.

## 4. Request Validation

Full pipeline, in order (unchanged ordering, `assertBodySize` added
this sprint):

```
assertMethod (POST only)
  -> assertContentType (application/json only)
  -> assertBodySize (NEW — 64 KiB cap, before any parsing)
  -> decodeJson (syntax error -> MALFORMED_JSON; valid-but-wrong-shape -> VALIDATION_FAILED, NEW distinction)
  -> authenticate
  -> resolveOperation (allowlist)
  -> validateEnvelope (unknown top-level fields, actor rejection, params presence/shape, null-stripping)
  -> validateOperationParameters (Sprint 3's per-operation name contract)
  -> normalize
  -> CommandAdapter::invoke()
```

- **Method**: only `POST` accepted; anything else → `405 METHOD_NOT_ALLOWED`
  before the body is even read.
- **Content-Type**: must be exactly `application/json` (optional
  `charset`); missing or wrong → `415 UNSUPPORTED_MEDIA_TYPE`.
- **Body size**: bodies over 64 KiB are rejected (`413 PAYLOAD_TOO_LARGE`)
  before JSON parsing is attempted — see §Request Size below.
- **JSON body**: an empty string, truncated JSON, or any other syntax
  error → `400 MALFORMED_JSON`. A bare scalar (`42`, `"x"`, `true`), the
  literal `null`, or a top-level JSON array → `422 VALIDATION_FAILED`
  ("Request body must be a JSON object") — both are now distinguished
  (§2 finding 1).
- **Envelope**: only `operation`/`params` permitted at the top level;
  `params` required and must be a JSON object; a caller-supplied `actor`
  field is rejected as an unknown top-level key; a `null`-valued
  `params` field is stripped, treated as absent.
- **Operation names**: checked against the fixed `OperationAllowlist`;
  a raw `bin/v-*` script name or any non-allowlisted `CommandRegistry`
  operation is rejected identically (`404 OPERATION_NOT_ALLOWED`), never
  distinguished.

### Request Size

A 64 KiB application-level cap (`ExecuteRequestHandler::MAX_BODY_BYTES`)
is enforced in PHP, on `strlen($rawBody)`, before `json_decode()` is
ever called — implementable entirely within this repository, so it is
implemented, per this sprint's own instruction to prefer a local
enforcement point when one exists. This is **independent of, and does
not replace**, any webserver/php.ini limit (`post_max_size`,
`client_max_body_size`, etc.) — those remain a deployment concern
outside this repository and are **not** configured or assumed by this
change; a request could still be rejected earlier, by the webserver
itself, before ever reaching this PHP code, and that is correct,
expected layering, not a gap this sprint needed to close.

## 5. Authentication Failure Semantics

Unchanged mechanism (`AccessKeyValidator` untouched), extended test
coverage. All of the following produce a **byte-identical**
`401 AUTHENTICATION_FAILED` envelope (verified directly,
`testAuthenticationFailureUniformityTable`):

- missing `Authorization` header
- malformed header (wrong scheme, invalid base64, missing colon)
- unknown credential id
- wrong secret for a real id
- a credential that was valid and has since been revoked (its record
  file deleted) — newly exercised end-to-end through the HTTP layer
  this sprint

None of these ever reaches `CommandAdapter` (0 process-runner
invocations, asserted directly).

## 6. Authorization Failure Semantics

Unchanged mechanism (`SameUserAuthorizer` untouched). Cross-user access
(`actor.user !== target.user`) produces `403 AUTHORIZATION_DENIED` for
all seven exposed operations (Sprint 3's table-driven test, re-verified
green this sprint), and denial occurs before lock acquisition, process
execution, and — for `database.create` specifically — sensitive
temp-file creation (Sprint 3's dedicated test, re-verified). Neither the
target user's existence nor the target resource's existence is ever
disclosed by an authorization denial — the denial is identical
regardless of whether `bob` exists at all.

## 7. Error Vocabulary

The complete, final, API-owned vocabulary (no new codes invented beyond
the two Sprint 2 already added, `METHOD_NOT_ALLOWED` and — new this
sprint — `PAYLOAD_TOO_LARGE`, both narrowly justified wire-level
additions the original contract's own table didn't anticipate):

| `error.code` | HTTP status | `hestiaErrorCode` exposed? | `error.details` | Raw command output ever exposed? |
|---|---|---|---|---|
| `MALFORMED_JSON` | 400 | No | `null` | No |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | No | `null` | No |
| `PAYLOAD_TOO_LARGE` | 413 | No | `null` | No |
| `METHOD_NOT_ALLOWED` | 405 | No | `null` | No |
| `AUTHENTICATION_FAILED` | 401 | No | `null` | No |
| `OPERATION_NOT_ALLOWED` | 404 | No | `null` | No |
| `VALIDATION_FAILED` | 422 | No | `null` | No |
| `AUTHORIZATION_DENIED` | 403 | No | `null` | No |
| `LOCK_TIMEOUT` | 409 | No | `null` | No |
| `LOCK_UNAVAILABLE` | 503 | No | `null` | No |
| `UPSTREAM_COMMAND_FAILED` | 422 | **Yes — symbolic only** (e.g. `"E_NOTEXIST"`) | `{hestia_error_code}` | No — never raw stdout/stderr |
| `UNKNOWN_OUTCOME` | 207 | **Yes — symbolic only** | `{hestia_error_code}` | No |
| `INTERNAL_ERROR` | 500 | No | `null` | No |

**"NOT_FOUND"** — no distinct code was added. `OPERATION_NOT_ALLOWED`
already covers "this operation name is not publicly reachable" (an
API-level concept); a *resource* not existing (e.g. `domain.get` for a
domain that doesn't exist) surfaces as `UPSTREAM_COMMAND_FAILED` with
`error.details.hestia_error_code: "E_NOTEXIST"` — the symbolic code
already tells an API client precisely what happened without a new,
redundant top-level error code. Introducing a resource-specific
`NOT_FOUND` would require per-`hestiaErrorCode` branching inside
`ResponseMapper`, which is a real, deliberate architectural expansion
this sprint's "harden without changing architecture" mandate does not
call for — recorded here as a considered, explicit non-addition, not an
oversight.

**"HESTIA_ERROR"** — no single generic code was added either; the
existing split into `UPSTREAM_COMMAND_FAILED` (a definite, known
failure) and `UNKNOWN_OUTCOME` (a genuinely ambiguous mutation outcome)
is strictly more precise than one generic `HESTIA_ERROR` code would be,
and collapsing them into one would re-introduce exactly the
"unknown-treated-as-failed" ambiguity Part A explicitly forbids.

**Security guarantee, re-verified this sprint**: no response, at any
error code, ever contains a password, an access-key secret, a
filesystem path, a PHP stack trace, an exception message, a shell
command, an internal source path, or a raw command-line argument.
`error.message` is always one of a small set of fixed, generic strings
— never derived from `AdapterResult::$errorMessage`/`$stdout`/`$stderr`.
`error.details` carries only the fixed, symbolic `hestiaErrorCode` table
(`E_NOTEXIST`, `E_EXISTS`, ...), never free text.

## 8. HTTP Status Mapping

See §7's table — unchanged from Sprint 1/2/3 except the two additive
codes noted above (`PAYLOAD_TOO_LARGE`/413 is new this sprint;
`METHOD_NOT_ALLOWED`/405 was Sprint 2's own addition, re-confirmed here
as still correct and not redundant with anything else).

## 9. Mutation/Status Semantics

Re-verified, unchanged, for all seven operations (no `CommandAdapter`/
`CommandRegistry` change):

1. **`domain.create`/`domain.delete`**: exit code `20` (`E_RESTART`,
   declared `known_post_mutation_exit_codes`) → `succeeded_with_warning`/200
   — confirmed via `testDomainCreateOperationDegraded`/
   `testDomainDeleteOperationDegraded` (Sprint 3, still green) and this
   sprint's `testResponseEnvelopeShapeStabilityAcrossOutcomes`
   ("warning" scenario).
2. **`database.create`**: unchanged registry-derived classification
   (`confirmed` on exit 0; no `known_post_mutation_exit_codes`
   declared, so any non-zero exit is `unknown`, never a more specific
   guess).
3. **`database.delete`**: `confirmed` (HTTP 200) means only "the script
   exited 0" — it does **not** mean the `DROP DATABASE` statement was
   independently verified. `CommandRegistry`'s own docblock (unmodified,
   cited not reinterpreted) documents that `delete_mysql_database()`
   contains zero `check_result` calls, including on its own `DROP
   DATABASE`. A `200` response for `database.delete` is therefore only
   as reliable as "Hestia's own script believed it succeeded" — this
   sprint does not, and architecturally cannot, add a stronger
   guarantee without modifying the adapter or the underlying script,
   both explicitly out of scope.
4. **`backup.schedule`**: exit code `4` (`E_EXISTS`) has no
   `known_post_mutation_exit_codes` declared, so `CommandAdapter`
   classifies it `unknown` for every non-zero exit — `ResponseMapper`
   maps this to `outcome: "unknown"` / `207` / `UNKNOWN_OUTCOME`, never
   `"failed"` (`testBackupScheduleAlreadyScheduledUnknown`, still
   green). See §10.

`pending` remains defined in the contract but unused — no current
operation produces it; nothing in this sprint changes that.

## 10. `backup.schedule` `E_EXISTS` Limitation

Source analysis (re-confirmed, not re-derived): `E_EXISTS` fires in
`v-schedule-user-backup`'s Verifications section, strictly *before* the
one mutating line (the queue append). A precise implementation could
therefore classify this specific exit code as a definite pre-mutation
failure (`"failed"`, not `"unknown"`) — but doing so would require
`CommandRegistry` to gain a new, symmetric "known **pre**-mutation exit
codes" metadata concept, which does not exist today (only
`known_post_mutation_exit_codes` does). Adding that concept is an
adapter-metadata-model change, explicitly forbidden this sprint ("Do
NOT modify the adapter metadata model in this sprint merely to fix that
limitation"). Per this sprint's own instruction — "If the API cannot
safely distinguish failed from unknown, preserve unknown. Do not
manufacture certainty." — the API continues to report `unknown`/207/
`UNKNOWN_OUTCOME`, honestly reflecting that `CommandAdapter` itself
cannot currently prove anything more specific. Recorded as a real,
source-verified precision gap for a future sprint (Sprint 3's own
documentation already flagged this identically; Sprint 4 does not
re-decide it, only re-confirms the decision stands).

## 11. `database.delete` Confirmation Limitation

See §9 item 3. Restated for clarity per this sprint's explicit
documentation requirement: HTTP `200` for `database.delete` means "the
underlying script ran, and its own internal logic did not detect a
failure before choosing to exit 0" — it is **not** independent
confirmation that the `DROP DATABASE` SQL statement itself succeeded,
because the script's own source never checks that statement's result.
No API-level wording, status code, or `outcome` value manufactures a
stronger claim than the adapter itself is able to make.

## 12. Exception Sanitization

Two independent, layered safety nets, both new/hardened this sprint's
second one is genuinely new:

1. **`ExecuteRequestHandler::handle()`'s own `catch (\Throwable)`**
   (Sprint 2, re-verified unchanged): any exception not already a typed
   `ApiException` is mapped to a fixed `INTERNAL_ERROR`/500 envelope;
   `$exception->getMessage()` is never included.
2. **`web/api/v2/index.php`'s own outer `try`/`catch`** (NEW this
   sprint): defense-in-depth *above* #1 — covers an exception thrown
   during object construction (before `handle()` is ever called) or a
   `json_encode()` failure after `handle()` returns, both of which
   `ExecuteRequestHandler`'s own internal catch cannot see. Produces the
   identical sanitized envelope shape, with the same guarantee: no
   exception message, no stack trace, no class name.

No new logging framework was introduced (explicitly out of scope); any
exception remains observable through whatever PHP/webserver error-log
mechanism is already configured, unaffected by this sprint (`display_errors`
was already `0` for this script since Sprint 2; `log_errors` is a
deployment/php.ini concern this sprint does not touch).

**Regression proof**: `testUnexpectedExceptionDoesNotLeak` (Sprint 2,
still green) and the new `testUnexpectedExceptionDuringMutatingOperationDoesNotLeakParams`
(this sprint) — the latter specifically forces an exception whose raw
message embeds a plaintext password, and asserts the password never
appears anywhere in the JSON-encoded response.

## 13. Response Hardening

- `Content-Type: application/json; charset=utf-8` on every response,
  success or failure (unchanged, Sprint 2).
- `display_errors` off for this one script only (unchanged, Sprint 2) —
  no PHP warning/notice can corrupt the single JSON document this
  endpoint always returns.
- **New this sprint**: a `json_encode()` failure (e.g. the adapter's
  `parsedOutput` somehow containing non-UTF-8 bytes) no longer risks
  echoing `false`/an empty body under an already-committed success
  status — it now falls back to the same sanitized `INTERNAL_ERROR`/500
  shape.
- Exactly one envelope is ever emitted per request — verified
  structurally (`ExecuteRequestHandler::handle()` has exactly one
  `return` per code path, and `index.php` echoes exactly once) and by
  `testResponseEnvelopeShapeStabilityAcrossOutcomes`, which asserts the
  same fixed six-key envelope shape across seven different outcome
  scenarios (success, warning, unknown, validation error, authentication
  error, authorization error, internal error).
- No additional headers were added — global web security headers
  (`X-Content-Type-Options`, CSP, etc.) are out of this sprint's scope,
  per its own instruction not to "pretend to solve global web security
  headers."

## 14. Security Properties

Re-verified, all still true after this sprint's changes: no arbitrary
command execution, no caller-selected script/executable, no
caller-selected `actor.user`, no authentication/authorization/lock
bypass, no secret in logs or responses (including through an
unsanitized exception, §12), no filesystem-path leakage, no shell
execution or `bin/v-*` reference in the HTTP layer (`GenericityTest`,
re-run against every changed file this sprint).

## 15. Test Coverage

New tests this sprint, all in `test/api/ExecuteRequestHandlerTest.php`
(tests #48–#57): empty body, JSON scalar body (table-driven over three
scalar shapes), JSON `null` body, top-level JSON array body, oversized
body, missing `Content-Type`, a consolidated table-driven authentication-
uniformity test (7 failure reasons including a newly-exercised revoked
credential, asserting byte-identical envelopes), a response-envelope-
shape-stability test across 7 outcome scenarios, the read-operation
genuine-failure-outcome path through the full HTTP pipeline, and the
password-non-leak-through-exception regression. No existing test was
weakened or removed.

## 16. Regression Results

Three consecutive runs of each suite:

- `test/api/run_tests.php`: **102/102**, ×3 (92 pre-Sprint-4 + 10 new).
- `test/auth/run_tests.php`: **62/62**, ×3 — unchanged.
- `test/adapter/run_tests.php`: **198/198**, ×3 — unchanged.

`php -l` clean on every changed/new file. `git diff --check` clean.

## 17. Deferred Work

Unchanged from Sprints 1–3: `database.list`/`database.get`,
`backup.list`, rate limiting, audit logging, idempotency, async jobs,
Cloud Account/tenant/roles, expiration/rotation, new authentication
mechanisms, the `backup.schedule` pre-mutation-exit-code metadata gap
(§10).

## 18. Architectural Findings

- The `decodeJson()` MALFORMED_JSON/VALIDATION_FAILED conflation (§2
  finding 1) had existed, undetected, since Sprint 2 — no test had ever
  exercised a syntactically-valid-but-wrong-shape JSON body (only
  genuine syntax errors and objects). This is exactly the kind of gap a
  dedicated hardening sprint is for.
- `web/api/v2/index.php` had no defense-in-depth above
  `ExecuteRequestHandler`'s own try/catch, despite that class's own
  docblock already stating the file "contains no ... logic of its own"
  — true for business logic, but object *construction* itself could
  still theoretically throw and was previously unguarded.

## 19. Known Limitations

Unchanged from Sprint 2/3: literal `/api/v2/execute` path routing and
`Authorization` header stripping under some server configurations
remain dependent on webserver configuration outside this repository —
explicitly documented, not solved, per this sprint's own instruction.
The 64 KiB application-level body cap is a defense-in-depth
supplement to, never a substitute for, the webserver's own
`post_max_size`/equivalent limit, which this sprint does not configure.

## 20. Final Verdict

**READY.** All identified gaps closed; error/status semantics finalized
and documented; no adapter/auth/legacy file modified; all three
regression suites green across three consecutive runs each; no STOP
condition encountered.
