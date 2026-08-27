# API v2 — HTTP Contract Design

**Sprint 1 deliverable.** Design and contract only — no HTTP endpoint,
router, controller, service class, middleware, or serializer is
implemented in this sprint. Sprint 2 must be implementable strictly from
this document without further architectural decisions.

No production PHP source, `bin/v-*` script, `CommandAdapter`,
`CommandRegistry`, `LockManager`, `SameUserAuthorizer`, or
`AccessKeyValidator` was modified to produce this document.

---

## 1. Executive Summary

The adapter layer (`CommandAdapter`/`CommandRegistry`/`SameUserAuthorizer`/
`LockManager`) and the authentication layer (`AccessKeyValidator`/
`AccessKeyProvisioner`) are both implemented, tested (auth 62/62, adapter
198/198), and production-wired (credential directory provisioning,
`bin/v-add-api-credential`/`bin/v-delete-api-credential`). Neither has an
HTTP caller yet. This document defines the exact contract a future HTTP
entry point must implement: endpoint shape, authentication transport,
actor construction, the operation allowlist, resource-identifier
normalization, the public response envelope, the internal-to-public
mutation-state mapping, HTTP status codes, and the security boundaries
API v2 must never cross. Every decision is grounded in either existing
repository evidence or an explicit, reasoned choice where evidence alone
does not decide the question — the two are always distinguished.

## 2. Current Architecture

```
HTTP request                              (does not exist yet)
  ↓
authenticate                              AccessKeyValidator::authenticate(id, secret): ?string
  ↓
actor { user: ... }                       constructed by the future API layer, NOT by AccessKeyValidator
  ↓
API v2 request validation / normalization (does not exist yet — this document's subject)
  ↓
CommandAdapter::invoke($operation, $params, $actor)
  ↓ resolve → validate → normalize → authorize → lock → execute
SameUserAuthorizer                        actor.user === target.user
  ↓
LockManager                               per-user flock, mutating ops only
  ↓
validated bin/v-* command
```

Registered operations (`CommandRegistry`, confirmed by direct read):
`domain.get`, `domain.list`, `domain.create`, `domain.delete`,
`backup.schedule`, `database.create`, `database.delete` — seven, all
already implemented, tested, and unmodified by this sprint.

`AdapterResult` (confirmed field-by-field from
`web/inc/adapter/AdapterResult.php`): `operation`, `resolvedCommand`,
`commandId`, `status` (`ok`|`adapter_error`|`hestia_error`|`timeout`|
`cancelled` — only the first three are ever actually constructed today),
`exitCode`, `hestiaErrorCode`, `adapterErrorCode`, `errorMessage`,
`stdout`, `stderr`, `parsedOutput`, `startedAt`, `finishedAt`,
`durationMs`, `lockWaitMs` (always `null`, reserved), `actor`, `target`,
`resultShape`, `mutationState`.

`adapterErrorCode` vocabulary (confirmed exhaustively by grep of
`CommandAdapter.php`): `UNKNOWN_OPERATION`, `MISSING_PARAMETER`,
`UNEXPECTED_PARAMETER`, `UNKNOWN_PARAMETER_TYPE`, `VALIDATION_FAILED`,
`AUTHORIZATION_DENIED`, `REGISTRY_ERROR`, `TEMP_FILE_UNAVAILABLE`,
`LOCK_UNAVAILABLE`, `LOCK_TIMEOUT`. This is the complete set — no other
value is ever assigned.

## 3. API v1 Problems

Direct evidence from `web/api/index.php` (full file re-read for this
sprint):

- **Arbitrary command execution**: `$hst_cmd` (`api_legacy()` line 167,
  `api_connection()` line 250) is validated only by
  `preg_match('/^[a-zA-Z0-9_-]+$/', $hst_cmd)` — a *shape* check, not an
  allowlist. Any string matching that shape reaches
  `exec($cmdquery, ...)` (line 198/330) if the corresponding file exists
  under `$BIN`. There is no closed set of permitted operations.
- **Shell command construction from caller input**: `$cmdquery =
  HESTIA_CMD . escapeshellcmd($hst_cmd)`, then each `arg1..arg13` is
  `quoteshellarg()`-appended and the whole string is passed to `exec()`.
  Escaping mitigates injection but does not close the "arbitrary command
  name" problem above.
- **Ad hoc, duplicated authorization**: `api_connection()`'s own
  hand-rolled check (`$key_user != $root_user && $user_arg_position > 0
  && $hst_cmd_args["arg{$user_arg_position}"] != $key_user`, lines
  302-312) — a second, independent authorization mechanism that has
  nothing to do with `SameUserAuthorizer` and would directly conflict
  with it if API v2 were bolted onto this file.
- **No structured result**: raw stdout/stderr is echoed; a Hestia exit
  code is mapped to an HTTP code via `exit_code_to_http_code()`
  (`web/inc/helpers.php` — read in full for this sprint, see §15) with no
  `AdapterResult`, no mutation-state classification.
- **No registry boundary**: any `bin/v-*` script that exists on disk is
  reachable, not the seven operations `CommandRegistry` deliberately
  exposes.

**API v2 must not reproduce any of these five properties. §9 defines the
allowlist model that replaces the first and last; §12 defines the
response envelope that replaces the third; §13 defines validation that
does not depend on shell escaping at all, because no shell command is
ever constructed from caller input.**

## 4. API v2 Goals

- A closed, explicit set of operations — never a caller-named script.
- Authentication strictly before any adapter interaction.
- A trusted `actor` the caller cannot forge or override.
- Resource identifiers presented consistently to the caller, with any
  Hestia CLI quirk (§11) normalized by the API layer, never by
  `CommandAdapter`/`CommandRegistry`.
- A stable, versioned response envelope that never leaks
  `CommandAdapter`'s internal vocabulary verbatim.
- Honest mutation semantics — `unknown` is never silently reported as
  `failed`, and `pending` (queued-not-completed) is never silently
  reported as `succeeded`.

## 5. Non-Goals (This Sprint)

No HTTP endpoint/router/controller/service class/middleware/parser/
serializer/operation-registry implementation; no expiration; no
credential rotation; no Cloud Account/tenant/roles/scopes/admin
impersonation; no async job subsystem; no `Idempotency-Key`
implementation; no change to `CommandAdapter`, `CommandRegistry`,
`LockManager`, `SameUserAuthorizer`, or `AccessKeyValidator`; no change to
`web/api/index.php`. This document is the contract those future layers
must satisfy.

## 6. HTTP Endpoint Shape

**Single entry point, operation named in the request body — not one path
per resource, and not a caller-supplied command name.**

```
POST /api/v2/execute
Content-Type: application/json
Accept: application/json

{
  "operation": "domain.create",
  "params": { "user": "admin", "domain": "example.com" }
}
```

- **One HTTP method (`POST`) for every operation**, including reads
  (`domain.get`, `domain.list`). Rejected alternative: REST-style
  per-resource paths (`GET /api/v2/domains/{id}`, `POST
  /api/v2/domains`) mapping HTTP verbs onto CRUD. Rejected because the
  adapter's own operation set is not CRUD-symmetric today
  (`API_V2_ARCHITECTURE_REVIEW.md`, moved to this directory, §"Resource
  Model": `database.delete` exists with no corresponding `database.get`/
  `database.list`; `backup.schedule` has no REST verb that means
  "schedule") — forcing a REST shape onto an intentionally
  non-CRUD-symmetric operation set would require inventing HTTP-verb
  semantics for operations that do not have a natural one, exactly the
  kind of speculative design this project has consistently avoided
  elsewhere (per every prior sprint's "do not solve future problems"
  discipline). A single `operation` field, matching `CommandAdapter::invoke()`'s
  own first argument 1:1, needs no such invention and scales to a
  non-CRUD operation with zero new endpoint-shape decisions.
- **`operation` is a string looked up against the explicit allowlist in
  §9** — never a path segment, never used to construct a filename, class
  name, or `bin/v-*` script name directly (§14).
- **Request `Content-Type` must be `application/json`**; anything else is
  rejected before body parsing (§13). No `multipart/form-data`, no
  `application/x-www-form-urlencoded` — unlike `web/api/index.php`, which
  accepts either `$_POST` or a raw JSON body (lines 357-367) as a
  historical accommodation this project does not need to carry forward.
- **Response `Content-Type` is always `application/json; charset=utf-8`**,
  even for error responses — no bare-text `"Error: ..."` lines
  (`web/api/index.php`'s own convention, rejected here for the reasons in
  §12).
- **JSON envelope**: defined in §12.
- **Naming**: `resource.verb` (`domain.create`, `database.delete`) —
  reused verbatim from `CommandRegistry`'s own existing operation-name
  convention, not reinvented. The HTTP layer's `operation` string is
  identical to the string already passed into `CommandAdapter::invoke()`
  — no translation table between an "API operation name" and an
  "adapter operation name" is needed, because they are the same
  namespace by design (§9).

## 7. Authentication Contract

**Transport: the standard `Authorization` header, `Basic` scheme —
`Authorization: Basic base64(credential_id:secret)`.**

Evaluated against the alternatives the task requires considering:

| Transport | Verdict | Reasoning |
|---|---|---|
| Query string | **Rejected — mandatory** | Query strings are logged by web servers, proxies, and browser history by default; this is the one option this task explicitly forbids, and correctly — `AccessKeyProvisioner`'s own secret is 256 bits of entropy specifically so it is not guessable, and a query-string transport would routinely write it to access logs regardless of that entropy. |
| Custom header (e.g. `X-API-Key: id:secret`) | Rejected | Works, but reinvents a transport HTTP already has a registered, well-understood mechanism for. No repository evidence favors a custom header, and using the standard mechanism means any existing HTTP client library's built-in Basic-auth support works without custom code. |
| JSON body field | Rejected | Couples the credential to whichever operation-specific body schema is otherwise in use (§6); every request handler would need to know to strip an authentication field out of `params` before it ever reaches `CommandAdapter`, an easy place to introduce exactly the "caller-controlled actor" bug §16 warns against. Keeping the credential entirely out of the body means the body-to-`params` path (§13) never has to reason about authentication at all. |
| `Authorization: Basic` | **Chosen** | HTTP-native (RFC 7617), universally supported by HTTP clients without custom code, transported over TLS (not logged in URLs), and maps 1:1 onto the existing `AccessKeyValidator::authenticate(string $id, string $secret): ?string` shape — `id` is the Basic-auth username, `secret` is the Basic-auth password. No change to `AccessKeyValidator`'s signature or semantics is required. |

**Repository evidence does not strongly favor one HTTP-native option
over another** (`web/api/index.php` uses neither Basic auth nor a bearer
token — it uses POST-body fields, exactly the legacy pattern this
document is replacing) — this is the one area of §7 that is a reasoned
choice rather than a repository-mandated one, stated plainly per this
document's own evidence-vs-decision discipline.

**Exact extraction behavior:**

1. If the `Authorization` header is absent or does not parse as
   `Basic <base64>` → **missing credential**.
2. If it parses but the decoded value does not contain exactly one `:`
   separating a non-empty id from a (possibly empty) secret → **malformed
   credential**.
3. Otherwise, call `AccessKeyValidator::authenticate($id, $secret)`
   unchanged. A `null` return is **invalid credential** — this
   necessarily also covers a *revoked* credential, because
   `AccessKeyValidator` cannot and must not distinguish "never existed"
   from "revoked" (`ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md` §6, moved to
   this directory — existence-non-disclosure is already a tested,
   load-bearing property of that class and this contract does not
   reopen it).

**All three failure cases (missing, malformed, invalid/revoked) collapse
to the same public error code and the same HTTP status** (§10 —
`AUTHENTICATION_FAILED`, `401`) — deliberately, for the same
existence-non-disclosure reason `AccessKeyValidator` itself already
enforces internally. Distinguishing "malformed" from "invalid" at the
HTTP layer would leak information `AccessKeyValidator`'s own contract
was built specifically to hide (`API_V2_AUTHENTICATION_DESIGN.md` §8,
moved to this directory, already establishes this principle for the
authentication-outcome vocabulary in general).

**Actor construction**: on success, `actor = ["user" => $authenticatedUser]`
— nothing else, matching the already-decided minimum shape
(`API_V2_AUTHENTICATION_DESIGN.md` §6, `AUTHORIZATION_POLICY_IMPLEMENTATION.md`
§3). No change to `AccessKeyValidator` was needed or made to produce this
— its existing `?string` return is already exactly the value this `actor`
needs.

## 8. Actor Model

```json
{ "user": "<authenticated-user>" }
```

- `actor.user` is **always** the value `AccessKeyValidator::authenticate()`
  returned for the credential presented in this request's `Authorization`
  header. There is no other source.
- **A caller-supplied `user`/`actor` field anywhere in the JSON request
  body is never read for this purpose and must be rejected as an unknown
  field** by request validation (§13) if present in `params` at all —
  not silently ignored, not merged, not treated as an override or a
  hint. This is the exact bug class
  `API_V2_AUTHENTICATION_DESIGN.md` §9 flagged as the one real
  privilege-escalation path in this whole design: "the authentication
  component must be the only place `actor.user` is ever set from
  external input."
- `acting_as` is **not** included — no delegation/impersonation feature
  exists yet (unchanged from `AUTHORIZATION_POLICY_IMPLEMENTATION.md`
  §11's decision).
- `SameUserAuthorizer` remains the final, unmodified authorization
  boundary — `actor.user === target.user`, fail-closed. Nothing in this
  contract weakens, bypasses, or duplicates that check at the HTTP layer;
  the HTTP layer's only authorization-adjacent responsibility is
  ensuring `actor` reaches `CommandAdapter::invoke()` correctly
  constructed (above) and never touched again before that call.

No `AuthorizerInterface`/`SameUserAuthorizer` change is required — this
was already established in `API_V2_ARCHITECTURE_REVIEW.md` §8 and
reconfirmed here.

## 9. Operation Allowlist

**Decision: a separate, small API-operation allowlist — a `const array`
of permitted operation-name strings, checked before `CommandAdapter::invoke()`
is ever called — not a second registry, and not `CommandRegistry` exposed
directly as a public API.**

Three options were weighed:

1. **Expose `CommandRegistry` directly** (treat "is this operation
   registered" as the allowlist). Rejected: `CommandRegistry` is an
   internal adapter concern — its entries carry `argument_order`,
   `fixed_parameters`, `mutation.known_post_mutation_exit_codes`, and
   other fields that are implementation detail, not public contract.
   Coupling "what API v2 exposes" to "what `CommandRegistry` happens to
   contain" would mean a future internal-only adapter operation (added
   purely for, say, an admin CLI tool) automatically becomes
   HTTP-reachable with no separate decision — exactly the coupling
   `API_V2_ARCHITECTURE_REVIEW.md` §9 already warned against
   ("`CommandRegistry` must remain the sole bridge" to `bin/v-*`, but
   that does not mean every registered operation is automatically a
   *public* one).
2. **A full API facade/service layer, one class per operation**
   (`DomainCreateApiHandler`, `DatabaseDeleteApiHandler`, ...). Deferred,
   not rejected outright — this is very plausibly where Sprint 2 will
   land for the *normalization* logic (§11), but a full per-operation
   class hierarchy is more machinery than an allowlist needs to exist;
   building it in Sprint 1 would be implementing the HTTP layer this
   sprint explicitly must not do.
3. **A thin allowlist + mapping, chosen**: a fixed array (illustrative
   shape, not implementation — Sprint 2's job):

```php
const ALLOWED_OPERATIONS = [
    "domain.get", "domain.list", "domain.create", "domain.delete",
    "backup.schedule",
    "database.create", "database.delete",
];
```

   A request whose `operation` is not in this list is rejected before
   any adapter interaction, with `OPERATION_NOT_ALLOWED` (§10) — a
   distinct code from `CommandAdapter`'s own `UNKNOWN_OPERATION` (which
   only fires for an operation absent from `CommandRegistry` entirely,
   a different, internal failure mode this contract does not expose
   verbatim, per §10's mapping strategy).

**This list starts as the full set of seven existing operations** — no
operation is deliberately withheld at this stage, since none has been
identified as unsafe for external exposure specifically; the value of a
separate allowlist is the *structural* guarantee that adding an eighth
internal adapter operation later does not automatically expose it, not
that today's seven need trimming.

**Normalization stays a separate step, after the allowlist check,
described in §11** — the allowlist only answers "is this operation name
permitted at all," never "what does `params.database` mean for this
operation."

## 10. Request Contract

```json
{
  "operation": "domain.create",
  "params": { "user": "admin", "domain": "example.com" }
}
```

- **Required top-level fields**: `operation` (string), `params` (object,
  may be `{}` for zero-parameter operations — none exist today, but the
  schema does not assume there never will be one).
- **Unknown top-level fields**: rejected (`VALIDATION_FAILED`,
  §10-error-vocabulary below) — mirrors `CommandAdapter`'s own existing
  "unexpected parameter" discipline (`UNEXPECTED_PARAMETER`) applied one
  level up, at the envelope itself, not just inside `params`.
- **Unknown fields inside `params`**: not re-validated by the HTTP layer
  at all — `CommandAdapter`'s own validation step already rejects an
  unexpected parameter (`UNEXPECTED_PARAMETER`) for the *adapter's*
  parameter set. The one HTTP-layer-specific exception is `user`/`actor`
  fields inside `params`, which §8 requires rejecting explicitly and
  distinctly (as a request-contract violation, not merely "an adapter
  parameter this operation doesn't happen to declare") specifically
  because their presence signals an attempted actor override, a
  qualitatively different problem from a caller simply mistyping a field
  name.
- **`null` handling**: a `null` value for a declared `params` field is
  treated as "not provided" (absent), not as an empty string or a
  distinct value — matching `SensitiveParameterTest.php`'s own already-
  established convention for `null` (`"sensitive => null must behave
  exactly like sensitive being absent"`) applied here to request
  parameters generally, for consistency rather than inventing a new rule.
- **String types / maximum lengths / empty strings**: **not
  independently re-validated by the HTTP layer** — `CommandAdapter`'s own
  `ParameterValidator` already performs exactly this shape validation
  (`isValidUsername`, `isValidDomain`, `isValidDatabaseName`, etc.), and
  duplicating those rules at the HTTP layer would create two sources of
  truth that could drift (exactly what
  `AUTHORIZATION_POLICY_IMPLEMENTATION.md`'s own `SameUserAuthorizer`
  design deliberately avoided by NOT re-validating usernames a second
  time). The HTTP layer's validation responsibility is the **envelope**
  (top-level JSON shape, `Content-Type`, unknown top-level fields,
  presence of `operation`/`params`) — everything inside `params` is
  `CommandAdapter`'s problem, once. This is the explicit API-level vs.
  adapter-level separation the task requires documenting.
- **Malformed JSON body**: rejected before `operation`/`params` are ever
  inspected — distinct error code (`MALFORMED_JSON`, §10), distinct from
  `VALIDATION_FAILED` (a well-formed-but-invalid request), since a client
  debugging a malformed-JSON failure needs a different fix (its own JSON
  serialization) than a client debugging a validation failure (its field
  values).
- **`Content-Type`**: must be exactly `application/json` (optionally with
  a `charset` parameter) — anything else is `UNSUPPORTED_MEDIA_TYPE`
  (§10), rejected before the body is parsed at all.
- **Duplicate JSON fields**: not a concern this contract needs to define
  — PHP's `json_decode()` (the only JSON parser evidenced anywhere in
  this codebase, e.g. every `web/api/index.php` call site) silently
  keeps the last occurrence of a duplicate key, standard PHP behavior,
  not a decision this document needs to make differently.

## 11. Resource Normalization

**Normalization happens in the future API service/facade layer (§9
option 2/3's mapping step), strictly before `CommandAdapter::invoke()` is
called — never inside `CommandAdapter` or `CommandRegistry`, exactly as
`API_V2_ARCHITECTURE_REVIEW.md` §4 and this sprint's own brief already
require.**

Public API identifiers, decided per resource:

| Resource | Public identifier | Internal adapter value | Normalization |
|---|---|---|---|
| `user` | Hestia username, unchanged | Hestia username, unchanged | none — already 1:1 everywhere |
| `domain` | fully-qualified domain, unchanged | fully-qualified domain, unchanged | none — already 1:1 (`domain.create`/`domain.delete`/`domain.get` all already take the same shape) |
| `database` | **raw suffix**, e.g. `"wordpress_db"` — consistently, for both `database.create` and `database.delete` | `database.create` already expects the raw suffix (`CommandRegistry` entry, unchanged); `database.delete` expects the full prefixed name, e.g. `"admin_wordpress_db"` (`DATABASE_DELETE_IMPLEMENTATION.md`, moved to this directory, §"Source Contract" — this asymmetry is a real, source-verified Hestia CLI quirk, not an adapter defect) | The API layer prefixes `database` with `{user}_` before calling `database.delete`, and passes it through unchanged for `database.create`. This is the exact translation `API_V2_ARCHITECTURE_REVIEW.md` §4 already recommended and explicitly assigned to "a not-yet-built API/service layer, never `CommandAdapter`/`CommandRegistry`" — this document is that assignment's concrete resolution. |
| `backup` | not applicable yet — `backup.schedule` takes only `user`, no backup-specific identifier exists in the registry entry | same | none needed |

**Why `database` is normalized to the raw suffix, not the prefixed
name, as the public shape**: the raw suffix is what a caller actually
chose (`"wordpress_db"`, not Hestia's internal `admin_wordpress_db`
convention) and is already `database.create`'s existing public shape —
picking the *other* option (`database.delete`'s prefixed shape) would
mean `database.create` and `database.delete` disagree on the public
contract even though this document's whole purpose is to make them
agree. This exactly matches `API_V2_ARCHITECTURE_REVIEW.md` §4's own
prior recommendation ("a single `database_id` = raw suffix at the API
layer, with a one-direction transform to the prefixed form").

## 12. Authorization Flow

```
HTTP request
  → parse                    Content-Type check, JSON decode (§10)
  → authenticate              Authorization: Basic header → AccessKeyValidator (§7)
  → construct actor           {user: authenticated_user} (§8)
  → resolve API operation     allowlist check (§9) — UNKNOWN before this point is impossible to reach with a real operation name; OPERATION_NOT_ALLOWED here
  → validate request          envelope shape, unknown top-level fields, actor-override rejection (§10)
  → normalize target          resource-identifier translation (§11)
  → CommandAdapter::invoke($operation, $normalizedParams, $actor)
      → resolve → validate → normalize → authorize (SameUserAuthorizer) → lock (LockManager) → execute
```

**Precise failure-stage attribution** (which stage produces which public
error, §10):

| Stage | Failure | Public error code | Reaches CommandAdapter? |
|---|---|---|---|
| parse | malformed JSON | `MALFORMED_JSON` | No |
| parse | wrong Content-Type | `UNSUPPORTED_MEDIA_TYPE` | No |
| authenticate | missing/malformed/invalid/revoked credential | `AUTHENTICATION_FAILED` | No |
| resolve API operation | not in allowlist | `OPERATION_NOT_ALLOWED` | No |
| validate request | unknown top-level field, actor-override attempt | `VALIDATION_FAILED` | No |
| normalize target | (no failure mode identified — normalization is a pure, total function of already-validated input for every resource in §11) | — | — |
| `CommandAdapter` validate | missing/unexpected/malformed `params` field | (adapter's own `adapterErrorCode`, mapped per §10) | Yes — rejected inside `invoke()`, before authorization |
| `CommandAdapter` authorize | `actor.user !== target.user` | `AUTHORIZATION_DENIED` (passed through, §10) | Yes — rejected inside `invoke()`, before lock |
| `CommandAdapter` lock | timeout/mechanism failure | `LOCK_TIMEOUT`/`LOCK_UNAVAILABLE` (passed through) | Yes |
| `CommandAdapter` execute | Hestia CLI non-zero exit | `hestia_error` (mapped per §16) | Yes |

**Enforced invariants, restated precisely**: an unauthenticated request
never reaches `CommandAdapter::invoke()` at all (authentication is
strictly before operation resolution); authorization never happens before
authentication (it is `CommandAdapter`'s own internal step, reached only
after a real `actor` already exists); lock acquisition never happens
before authorization (unchanged, existing `CommandAdapter` guarantee,
re-affirmed here as untouched).

## 13. Response Contract

**Envelope — every response, success or failure, is this shape:**

```json
{
  "api_version": "v2",
  "success": true,
  "outcome": "succeeded",
  "data": { "...": "..." },
  "error": null,
  "meta": {
    "operation": "domain.create",
    "command_id": "..."
  }
}
```

```json
{
  "api_version": "v2",
  "success": false,
  "outcome": "failed",
  "data": null,
  "error": {
    "code": "AUTHORIZATION_DENIED",
    "message": "You are not authorized to perform this operation for the requested user.",
    "details": null
  },
  "meta": {
    "operation": "domain.create",
    "command_id": "cmd_..."
  }
}
```

- `success` — a plain boolean transport/outcome summary (`true` only for
  `outcome: "succeeded"`/`"succeeded_with_warning"`; `false` for
  everything else including `"pending"` — pending is not yet a completed
  success, §16).
- `outcome` — the normalized public mutation vocabulary (§16), present
  for every response, including read-only operations (`"succeeded"`/
  `"failed"` only — `domain.get`/`domain.list` never produce
  `mutationState` internally either, §2).
- `data` — the operation's own result payload on success (shape is
  operation-specific — e.g. `domain.list`'s parsed JSON collection — and
  intentionally NOT specified further by this document; it is
  Sprint 2/operation-specific work, out of this sprint's scope). Always
  `null` when `success` is `false`.
- `error` — `null` on success; an object with `code`/`message`/`details`
  otherwise. `code` is always one of the stable values in §10's error
  vocabulary — never a raw `hestiaErrorCode`/`adapterErrorCode` string
  passed through unexamined (§16 defines the mapping). `details` is
  reserved for structured, non-sensitive diagnostic data (e.g. which
  field failed validation) — explicitly never stdout/stderr/stack traces/
  filesystem paths (§18).
- `meta.command_id` — `AdapterResult::$commandId`, passed through
  unchanged, for support/correlation purposes; carries no sensitive
  information (it is a random correlation id, not a secret).

**Distinguishing machine-readable code from human-readable message**:
`error.code` is a stable, documented enum string (§10) a client can
branch on; `error.message` is prose, allowed to change wording between
releases without being a breaking change — exactly the distinction the
task requires.

## 14. Error Vocabulary

| `error.code` | Meaning | Source |
|---|---|---|
| `MALFORMED_JSON` | Request body is not valid JSON | HTTP parse stage |
| `UNSUPPORTED_MEDIA_TYPE` | `Content-Type` is not `application/json` | HTTP parse stage |
| `AUTHENTICATION_FAILED` | Missing, malformed, invalid, or revoked credential (collapsed, §7) | authenticate stage |
| `OPERATION_NOT_ALLOWED` | `operation` is not in the API v2 allowlist | resolve stage |
| `VALIDATION_FAILED` | Envelope-level validation failure (unknown top-level field, actor-override attempt), OR a passthrough of `CommandAdapter`'s own `MISSING_PARAMETER`/`UNEXPECTED_PARAMETER`/`UNKNOWN_PARAMETER_TYPE` (all genuinely about caller input shape — collapsed into one public code, since a client's remedy is the same for all three: fix the request body) | request-validate stage or `CommandAdapter` |
| `AUTHORIZATION_DENIED` | `actor.user !== target.user` | `CommandAdapter` (passthrough — this exact code already exists internally and is already stable/documented, §8) |
| `LOCK_TIMEOUT` | The per-user mutation lock could not be acquired in time | `CommandAdapter` (passthrough) |
| `LOCK_UNAVAILABLE` | The locking mechanism itself failed (not ordinary contention) | `CommandAdapter` (passthrough) |
| `UPSTREAM_COMMAND_FAILED` | The underlying `bin/v-*` command exited non-zero (`status === hestia_error`) | `CommandAdapter` — see §16 for the mutation-state nuance this code alone does not capture |
| `UNKNOWN_OUTCOME` | `mutationState === "unknown"` — the command failed and no post-mutation-exit-code declaration exists to say whether it partially mutated | `CommandAdapter` |
| `INTERNAL_ERROR` | Anything not otherwise classified — `REGISTRY_ERROR`, `TEMP_FILE_UNAVAILABLE`, or a genuine unexpected exception at the HTTP layer itself | `CommandAdapter` or the HTTP layer |

**Mapping strategy, stated explicitly**: `CommandAdapter`'s full
`adapterErrorCode`/`hestiaErrorCode` vocabulary (§2) is **not** exposed
verbatim. `AUTHORIZATION_DENIED`, `LOCK_TIMEOUT`, and `LOCK_UNAVAILABLE`
are passed through unchanged because they are already stable, already
meaningful to an external caller, and already part of this project's own
documented vocabulary (`AUTHORIZATION_POLICY_IMPLEMENTATION.md`). Every
other internal code is collapsed into a smaller, stable public set —
`MISSING_PARAMETER`/`UNEXPECTED_PARAMETER`/`UNKNOWN_PARAMETER_TYPE`
collapse into `VALIDATION_FAILED` because they are all implementation
detail of *how* a request was malformed, not distinctions a client needs
to branch on differently. The full `hestiaErrorCode` (`E_NOTEXIST`,
`E_EXISTS`, ...) is **never** placed in `error.code` — it is
implementation-specific to the underlying `bin/v-*` script (per this
sprint's own instruction, "if a Hestia error is implementation-specific,
map it to a stable API error code") and may optionally appear inside
`error.details` for diagnostic purposes only, never as the primary
machine-readable code a client is expected to branch on.

## 15. HTTP Status Mapping

| Situation | HTTP status | `error.code` |
|---|---|---|
| Success (`outcome: succeeded` or `succeeded_with_warning`) | `200` | — |
| Success, operation queued not completed (`outcome: pending`) | `202` | — |
| Malformed JSON | `400` | `MALFORMED_JSON` |
| Unsupported media type | `415` | `UNSUPPORTED_MEDIA_TYPE` |
| Validation failure (envelope or adapter-level) | `422` | `VALIDATION_FAILED` |
| Authentication failure | `401` | `AUTHENTICATION_FAILED` |
| Authorization denial | `403` | `AUTHORIZATION_DENIED` |
| Operation not in allowlist | `404` | `OPERATION_NOT_ALLOWED` |
| Lock timeout | `409` | `LOCK_TIMEOUT` |
| Lock mechanism failure | `503` | `LOCK_UNAVAILABLE` |
| Upstream command failure, mutation state known-failed | `422` | `UPSTREAM_COMMAND_FAILED` |
| Unknown mutation outcome | `207`\* | `UNKNOWN_OUTCOME` |
| Internal/unclassified error | `500` | `INTERNAL_ERROR` |

\* **`207` ("Multi-Status") is a deliberate, reasoned choice, not a
repository-mandated one — flagged as such.** The three real alternatives
and why each was rejected in favor of `207`:

- **A `5xx`** would imply *this API implementation* failed — but
  `mutationState === "unknown"` means the *underlying Hestia command*
  produced an ambiguous, unclassifiable outcome; the HTTP layer,
  `CommandAdapter`, and the request itself all worked correctly. A `5xx`
  here would be the exact "pretend unknown means failed" the task's
  own §16 explicitly forbids, expressed as a status code instead of a
  body field — equally wrong either way.
- **A plain `409`** collides with the already-assigned lock-timeout
  meaning (`409` is also fully appropriate there, per the very
  well-established "409 = conflicting/contended state" REST convention)
  — reusing it for a semantically different situation (ambiguous
  *outcome*, not resource *conflict*) would make `409` ambiguous on the
  wire, undermining the one property a stable status vocabulary needs.
- **A plain `202`** ("accepted, still processing") is close in spirit —
  both mean "the transport succeeded but the operation's true final
  state is not yet knowable" — but `202` carries a strong, near-universal
  REST convention of "this WILL complete, check back" (matching §17's
  `backup.schedule` case exactly), while `mutationState === "unknown"`
  carries no such promise: the command already finished running, exited
  non-zero, and nothing will resolve the ambiguity later by polling.
  Reusing `202` for both would blur a distinction this document's own
  §16 requires keeping sharp.
- **`207`** is the standard HTTP code whose defined meaning — "the
  overall request cannot be described by a single status because
  sub-results differ" — is the closest existing standard semantic to
  "the transport/request succeeded, but this specific operation's true
  effect could not be determined," without collapsing into any of the
  above three, better-claimed meanings. This is flagged explicitly per
  this sprint's own "avoid inventing dozens of status codes... justify
  the choice" instruction, precisely because it is the one status
  mapping in this table that required genuine judgment rather than being
  a near-automatic REST convention.

**Transport-success vs. operation-outcome, made explicit**: HTTP `200`
means "the API v2 request was received, authenticated, authorized, and
executed" — it does **not**, by itself, mean the underlying operation's
intended real-world effect is fully confirmed (`outcome` in the body
carries that distinction, §16). A `200` with `outcome: "succeeded_with_warning"`
is still a `200` (the transport succeeded), never a partial-4xx — this
mirrors `mutationState === "confirmed_degraded"`'s own existing meaning
("the operation ran and Hestia's own CLI reported success in a way this
adapter independently trusts, but a known, source-verified side effect
also occurred," `AdapterResult.php`'s own docblock) precisely.

## 16. Mutation Semantics

| Internal `mutationState` | Public `outcome` | HTTP status | Meaning preserved |
|---|---|---|---|
| `not_attempted` | `failed` | (whatever the rejecting stage's own status is — §15; this state is never reached with a `200`) | Rejected before the underlying process ever ran — validation, authorization, or lock failure. Nothing was mutated; this is knowable with certainty. |
| `confirmed` | `succeeded` | `200` | The underlying `bin/v-*` process exited `0`. `CommandAdapter` trusts the CLI's own success signal — this document does not add a stronger guarantee than the adapter itself already provides. |
| `confirmed_degraded` | `succeeded_with_warning` | `200` | The process exited non-zero, but the registry entry has a source-verified, evidenced declaration that this exact exit code occurs only after the core mutation is durably complete (`known_post_mutation_exit_codes`, e.g. `domain.create`'s `E_RESTART`). The mutation happened; something adjacent (e.g. a service reload) did not. |
| `unknown` | `unknown` | `207` (§15) | The process exited non-zero with no such declaration. **This is never reported as `failed`** — `CommandAdapter` does not know whether zero, some, or all intended writes occurred, and this contract preserves that exact uncertainty rather than resolving it one way or the other for the caller's convenience. |
| *(read-only operation, `mutationState` is `null`)* | `succeeded` / `failed` | `200` / per §15 | Read operations have no mutation concept at all — `outcome` is a plain two-way split on `AdapterResult::$status === "ok"`. |

`pending` (mentioned in the recommended public vocabulary but not yet
produced by any current internal state) is reserved for §17's async case
— no current synchronous operation ever produces it.

**The `backup.schedule` distinction, preserved explicitly, per this
sprint's own example**: a `confirmed` `outcome: "succeeded"` for
`backup.schedule` means *the backup job was queued* (the adapter's own
existing, tested contract — `BACKUP_SCHEDULE_IMPLEMENTATION.md`, moved to
this directory) — it does **not** mean a backup archive now exists. This
document does not invent a stronger claim than the adapter already
makes; §17 addresses whether `outcome` should say so more explicitly via
`pending` instead of `succeeded`.

## 17. Async Semantics

**`backup.schedule` is evaluated specifically, per this sprint's
instruction, against synchronous operations — and is judged to remain
`succeeded` (not `pending`) for now, with the true-completion caveat
carried in `data`, not in `outcome`.**

Reasoning: `backup.schedule`'s own adapter contract
(`BACKUP_SCHEDULE_IMPLEMENTATION.md`) is that the *adapter's own action*
(appending to the backup queue) completes synchronously and is fully
confirmed by the time `CommandAdapter::invoke()` returns — `mutationState:
confirmed` already means exactly "the queue append is durable," not "the
archive exists," and that distinction is real and already correctly
scoped at the adapter level. Reporting `outcome: "pending"` would
actually **overstate** what's async from the API's own point of view: the
API call itself is not "still running" the way a genuinely async HTTP
operation (a long job whose status you must poll for) is — it is a
completed, synchronous "append to queue" call whose *real-world,
off-process* effect (the archive) happens later, asynchronously, outside
any request `CommandAdapter` initiated or can observe.

**Decision: no general async job subsystem, no `202`/`job_id`/`Location`
model, in this sprint** — not implemented, and not designed as a
followed-through contract either, since this sprint's own instruction is
"do not implement a general async job subsystem... if deferred, define
exactly how `backup.schedule` is represented without falsely claiming
completion." That representation is: `outcome: "succeeded"` (the queue
append is genuinely, fully complete — not a false claim), `data` carries
whatever `backup.schedule`'s own operation-specific payload turns out to
be (Sprint 2's job to define in full, out of this document's scope), and
the response's prose/documentation for this specific operation must
state plainly that `succeeded` here means "queued," not "archive
exists" — a documentation/wording obligation on Sprint 2, not a
different `outcome` value or HTTP status.

**If a future operation genuinely needs poll-for-status semantics**
(unlike `backup.schedule`, which does not), `pending`/`202`/`job_id`/
`Location` remain reserved and available in this contract (§15/§16
already define `pending`→`202`) — this document intentionally leaves that
door open without walking through it now, exactly matching
`API_V2_ARCHITECTURE_REVIEW.md` §7's own prior "design but don't build"
conclusion for a general job/operation resource.

## 18. Idempotency

**Deferred — no `Idempotency-Key` support in this sprint, evaluated
against the four operations the task names:**

- **`domain.create`/`database.create`**: a retried request with identical
  parameters would hit `bin/v-add-web-domain`/`bin/v-add-database`'s own
  existing duplicate-detection (`E_EXISTS`, already source-verified and
  already correctly classified as `mutationState: unknown` per
  `DATABASE_CREATE_IMPLEMENTATION.md`/`DOMAIN_CREATE_IMPLEMENTATION.md`)
  — a retry is not silently accepted as a no-op success, but it is also
  not silently destructive; the caller sees a real, distinguishable
  `unknown`/`failed` outcome and can decide what to do next.
- **`database.delete`**: confirmed non-idempotent
  (`DATABASE_DELETE_IMPLEMENTATION.md` §"Idempotency/Nonexistent
  Behavior") — a retried delete against an already-deleted database
  returns `E_NOTEXIST`, a real, informative failure, not a silent no-op.
- **`backup.schedule`**: `E_EXISTS` (already-scheduled) is already
  handled and tested (`BACKUP_SCHEDULE_IMPLEMENTATION.md`).

**Risk accepted by deferring**: a client that retries a request after a
network failure or timeout (never knowing whether the original request
actually reached the server) cannot currently distinguish "my retry was
a true no-op" from "my retry attempted the operation a second time and
hit the underlying script's own duplicate-handling" — for `database.delete`
specifically, a retried request after an already-successful delete will
report a real (not silent) failure (`E_NOTEXIST`/`unknown`), which is
safe (no double-deletion is possible — there is nothing left to delete)
but may confuse a naive retry-until-success client loop into treating a
successful outcome as a failure. This is a real, named, accepted risk —
not invisible — and is exactly the kind of gap `Idempotency-Key` support
exists to close. **Not built now** because no operation in this set has
a retry failure mode that is *silently* destructive or *silently*
duplicative (every retry produces a real, distinguishable, already-
existing-vocabulary outcome) — the bar for building idempotency-key
infrastructure now, per this sprint's own "do not implement... unless
this sprint's evidence requires it," is not met.

## 19. Security Boundaries

Explicit confirmation, each cross-checked directly against
`web/api/index.php`'s own corresponding property:

| Boundary | API v2 (this contract) | `web/api/index.php` (legacy, for contrast) |
|---|---|---|
| Arbitrary shell command execution | Never — no `exec()`/`shell_exec()`/`proc_open()`/backtick anywhere in this contract; `CommandAdapter` (unchanged) is the only process-spawning point, already fully process-argv-based, never shell-string-based | `exec($cmdquery, ...)` with a caller-influenced string (escaped, but still shell-constructed) |
| Arbitrary caller-named script | Never — `operation` is checked against a fixed allowlist (§9) before ever reaching `CommandRegistry`; `CommandRegistry` itself only ever resolves to one of its seven fixed, hardcoded script mappings | `$hst_cmd` accepted if it matches a shape regex AND the named file exists under `$BIN` — no allowlist |
| Raw command-line arguments from the caller | Never — `params` are structured JSON fields mapped through `CommandAdapter`'s own typed parameter validation, never concatenated into argv positions by caller-controlled string content | `arg1`..`arg13`, `quoteshellarg()`-escaped but still directly, positionally caller-controlled |
| Secrets through shell command strings | Never — sensitive `params` (e.g. a future `password`-bearing operation) already use `SENSITIVE_PARAMETER_DESIGN.md`'s temp-file delivery mechanism inside `CommandAdapter`, unchanged by this document | Password material for the legacy password-auth path is delivered via a similar temp-file convention already (`web/api/index.php` lines 131-146) — the one place legacy already does this reasonably |
| Credential secret exposure | Never in a response body, log, or `error.details` (§13/§14); `AccessKeyValidator`'s own existence-non-disclosure is preserved end-to-end by §7's collapsed `AUTHENTICATION_FAILED` | N/A — legacy uses a different auth model entirely |
| Logging authentication secrets | Never — this contract defines no new logging of the `Authorization` header or its decoded contents | N/A |
| Caller-selected `actor.user` | Never — §8 requires rejecting any `user`/`actor` field found in `params` outright, not merely ignoring it | N/A — legacy has its own, different, already-flagged (§3) ad hoc authorization model |
| Caller-selected filesystem paths | Never — no `params` field in any of the seven operations accepts a raw filesystem path; `CommandAdapter`'s own parameter-type validators (`isValidDomain`, `isValidDatabaseName`, etc., unchanged) reject anything path-shaped for the fields that exist | N/A |
| `SameUserAuthorizer` bypass | Never — every mutating (and read) request goes through the unmodified `CommandAdapter::invoke()` pipeline; no alternate code path to `LockManager`/the underlying script exists in this contract | N/A — legacy has its own separate, ad hoc check instead |
| `LockManager` bypass for mutating operations | Never — same reasoning; `CommandAdapter`'s own internal ordering (authorize → lock → execute) is unmodified and is the only path to execution this contract defines | N/A |

## 20. Implementation Boundary for Sprint 2

Sprint 2 may implement, strictly from this document, with no further
architectural decision required:

1. The HTTP router/entry point for `POST /api/v2/execute` (§6).
2. `Authorization: Basic` extraction and `AccessKeyValidator` invocation
   (§7) — `AccessKeyValidator` itself is unchanged.
3. The allowlist check (§9) — a fixed array, sourced from this document's
   §9 table.
4. Envelope-level request validation (§10/§13).
5. The `database` normalization step (§11) — the one concrete
   transformation this document defines precisely enough to implement
   directly (`database.delete`'s `database` = `{user}_{suffix}`).
6. The response envelope (§13) and error-code mapping table (§14/§15/§16)
   — implementable as a single, small translation function from
   `AdapterResult` to the public envelope.
7. Per-operation `data` payload shapes for the seven existing operations
   — **explicitly NOT specified by this document** (§13 states this
   directly) — Sprint 2 must define each operation's own `data` shape
   from its already-existing `parsedOutput`/`resultShape` (§2), which is
   a bounded, operation-by-operation task, not an open architectural
   question this document leaves unresolved by oversight.

## 21. Open Questions / Explicit Deferrals

- **Per-operation `data` payload shape** (§20 item 7) — deferred to
  Sprint 2, deliberately, as bounded implementation work rather than
  architecture.
- **Rate limiting, audit logging of API v2 calls specifically** — not
  evaluated in this sprint (out of the required-decisions list); no
  contract-level blocker identified, but also not designed.
- **API versioning beyond `v2` in the URL path** — this document fixes
  `/api/v2/execute` as the only path; a hypothetical future `v3` is not
  designed, since nothing in this sprint's evidence requires it yet.
- **Whether `error.details` ever carries the raw `hestiaErrorCode`** —
  §14 leaves this "optional," not firmly decided either way; Sprint 2
  may resolve it as a small implementation choice without revisiting
  this document's architecture.
- **The exact set of HTTP methods a future non-`execute`-style endpoint
  might use** — not applicable; §6 already rejected the multi-path REST
  shape for reasons that apply to any future extension of this contract
  too, not just this sprint's seven operations.

## 22. Acceptance Criteria

Sprint 2's implementation satisfies this contract if and only if:

1. No HTTP request reaches `CommandAdapter::invoke()` without a
   successfully authenticated `actor` already constructed (§7/§12).
2. `operation` is checked against the fixed allowlist (§9) before any
   other request processing beyond parse/authenticate.
3. `actor.user` is never derived from anything but
   `AccessKeyValidator::authenticate()`'s return value (§8) — verifiable
   by a test that a `user`/`actor` field inside `params` has zero effect
   on the resulting `actor`.
4. Every response — success or failure — conforms to the envelope in
   §13, with `error.code` drawn only from §14's fixed vocabulary.
5. `mutationState: unknown` never produces `outcome: "failed"` or a `4xx`/
   `5xx` status that collides with a genuinely-failed operation's status
   (§16/§15).
6. `backup.schedule`'s response never implies archive completion (§17).
7. No response body, log entry, or `error.details` value ever contains a
   plaintext credential secret, a shell command string, a raw stack
   trace, or a raw filesystem path (§19).
8. `AccessKeyValidator`, `CommandAdapter`, `CommandRegistry`,
   `LockManager`, `SameUserAuthorizer`, and `AuthorizerInterface` remain
   byte-for-byte unmodified by Sprint 2's implementation, unless Sprint 2
   itself discovers and documents a concrete contract blocker this
   document did not anticipate.
