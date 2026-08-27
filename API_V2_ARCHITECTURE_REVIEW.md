# API v2 Architecture Review

Design/analysis-only document. No source files were modified, no HTTP endpoint was created, no registry operation was added, `CommandAdapter.php`/`CommandRegistry.php`/`LockManager.php` were not touched, no `v-*` script was touched, no sudoers change was made, and no authentication, Cloud Connect, or Cloud Account work was implemented. This document synthesizes conclusions already established across `ARCHITECTURE_REVIEW.md`, `ARCHITECTURE_ADAPTER_DESIGN.md`, `WRITE_OPERATION_DESIGN.md`, `MUTATION_AND_AUTHORIZATION_DESIGN.md`, `SENSITIVE_PARAMETER_*.md`, and the six operation implementation docs, cross-checked directly against the current source in `web/inc/adapter/` and the legacy `web/api/`, `web/add/`, `web/delete/` callers — not merely restated from the docs, since the docs are not assumed current.

---

## 1. Executive Summary

Six operations (`domain.list`, `domain.create`, `domain.delete`, `backup.schedule`, `database.create`, `database.delete`) have been implemented behind `CommandAdapter`/`CommandRegistry`, exercising every architectural property an API v2 layer would need to depend on: typed parameter validation, per-user locking, four-state mutation classification, an injectable authorization seam, and a generic sensitive-parameter/temp-file delivery mechanism — all data-driven from registry metadata, with zero operation-specific branching inside `CommandAdapter.php` (mechanically enforced by a genericity test in every operation's test suite). 189 tests pass, 3 consecutive clean runs.

**The adapter foundation is architecturally sound and sufficient to begin API v2 design and the first slice of implementation.** It is not, however, sufficient to expose *any* mutating endpoint externally today — the authorization seam has a real interface but only a permissive default implementation (`AllowAllAuthorizer`); there is no authentication layer above it at all; and `CommandAdapter` is not wired into any production PHP code path — `web/inc/adapter/bootstrap.php` is required only by the test suite. Two legacy surfaces (`web/api/index.php`, `web/add/*`/`web/delete/*`) continue to bypass the adapter entirely via direct `exec()`, and — confirmed by direct source read for this review — `web/api/index.php` is *itself* a generic, caller-named-command dispatcher with no registry/allowlist concept at all, which is the exact anti-pattern API v2 must not reproduce.

The smallest safe next step is **not** another adapter operation. Six materially different operations (two reads, two creates, two deletes, one queued/async, one sensitive-parameter) have already validated the abstraction across every dimension this review can identify a concrete need for. The next step is **authorization policy + a minimal API v2 skeleton for the operations that already exist**, gated behind authentication that does not yet exist, built additively alongside the current legacy surfaces.

---

## 2. Current Architecture

### Layers, as implemented today (verified against source, not assumed from docs)

| Layer | File(s) | State |
|---|---|---|
| Legacy UI | `web/add/db/index.php`, `web/delete/db/index.php`, and siblings across `web/add/*`/`web/delete/*`/`web/list/*` | Direct `exec(HESTIA_CMD . "v-* ...")`, unmodified, still the only production path for every operation |
| Legacy REST API ("v1") | `web/api/index.php` (394 lines) | **Confirmed by direct read for this review**: a generic command-dispatch proxy. `$hst_cmd` is caller-supplied, validated only by `preg_match('/^[a-zA-Z0-9_-]+$/', $hst_cmd)` (shape, not membership), then run as `sudo /usr/local/hestia/bin/$hst_cmd $args...` (`web/api/index.php:177-198`, `:315-330`). This is not a resource-oriented API and has no registry/allowlist concept — any of Hestia's ~524 `bin/v-*` scripts is invocable by name, with attacker-influenced (though `quoteshellarg()`-escaped) positional arguments, gated only by whatever access-key/session auth ran earlier in the same request. |
| Adapter | `web/inc/adapter/CommandAdapter.php`, `CommandRegistry.php`, `AdapterResult.php`, `ParameterValidator.php`, `LockManager.php`, `AuthorizerInterface.php`/`AllowAllAuthorizer.php` | Implemented, tested (189/189), **not consumed by any production code path**. `bootstrap.php` (confirmed by direct read) is required only by `test/adapter/run_tests.php` — no `composer.json` PSR-4 entry, no reference from `web/inc/main.php` or `web/api/index.php`. |
| Registry | `CommandRegistry.php` | 6 operations registered, each hand-verified against the underlying script's actual source (not the script's `# options:` header, confirmed unreliable by `ARCHITECTURE_ADAPTER_DESIGN.md` §2 for at least two scripts). |
| Hestia CLI | `bin/v-*` (524 scripts) | Unmodified throughout this entire series of tasks. Remains the sole authoritative business-logic layer. |

### The pipeline actually implemented (verified against `CommandAdapter.php` source)

```
resolve registry entry
  → reject unexpected parameters
  → reject missing/malformed parameters (ParameterValidator, shape-only)
  → normalize target (drop sensitive parameters)
  → authorize (AuthorizerInterface::authorize — AllowAllAuthorizer by default)
  → acquire per-user lock (LockManagerInterface, only if mutation.kind !== "read")
  → build argv (fixed_parameters + validated params, sensitive params → temp file)
  → execute (ProcessRunnerInterface — proc_open in production, FakeProcessRunner in tests)
  → release lock (unconditionally, finally-equivalent)
  → classify mutation_state (registry-driven: not_attempted/confirmed/confirmed_degraded/unknown)
  → build AdapterResult
```

This exact ordering — authorize before lock, lock before execute, release unconditionally — is confirmed present in `CommandAdapter.php` today (grep-verified: `AUTHORIZATION_DENIED`, `LOCK_UNAVAILABLE`, `LOCK_TIMEOUT` all appear in source, in that relative order), not merely documented as a design intention.

### What the six operations collectively proved

| Property | Proven by |
|---|---|
| Read-only, zero-lock-overhead path | `domain.list` |
| Create mutation, checked exit code, post-mutation failure (`E_RESTART`) | `domain.create` |
| Delete mutation, irreversible, same post-mutation pattern | `domain.delete` |
| Queued/async mutation (request accepted ≠ work completed) | `backup.schedule` |
| Sensitive parameter, temp-file secret delivery, hostile-reviewed and remediated | `database.create` |
| Zero-footprint operation — no adapter/validator code at all, registry-only | `database.delete` |
| Inconsistent legacy CLI contracts absorbed without adapter-level special-casing | `database.create` (raw suffix) vs. `database.delete` (prefixed name) — see §5 |
| Unchecked mutating statement producing a possible false `confirmed` | `database.delete`'s `DROP DATABASE` — see §7 |

---

## 3. Adapter/API Boundary

The proposed separation is architecturally sound and is, in fact, already the separation `ARCHITECTURE_ADAPTER_DESIGN.md` §1 and §9 specified before any operation existed — this review confirms it still holds after six real implementations, rather than asserting it fresh.

```
HTTP/API layer
  ↓ (request/response contract — this document's §6)
API v2 (resource routing, request validation, auth)
  ↓ (operation name + typed params + actor)
CommandAdapter::invoke(operation, params, actor)
  ↓ (registry-resolved script + argv)
bin/v-* scripts
```

### What belongs where, confirmed against actual `CommandAdapter` capabilities (not aspirational)

| Concern | Owner | Evidence |
|---|---|---|
| HTTP routing, request parsing, response shaping | API layer | Nothing in `CommandAdapter`'s public surface (`invoke(operation, params, actor)`) knows HTTP exists |
| Authentication (who is calling) | API layer, above the adapter | `AuthorizerInterface` takes an already-resolved `actor` — it does not authenticate anyone |
| Authorization *policy* (roles, scopes, delegation) | A layer above the adapter, injected via `AuthorizerInterface` | `AllowAllAuthorizer` is the only implementation; the interface carries zero role/scope vocabulary (confirmed by direct read — see §9) |
| Parameter shape validation | Adapter (`ParameterValidator`) | Confirmed: type-keyed validator table, data-driven, not operation-specific |
| Business rule validation (existence, quota, ownership) | `bin/v-*` scripts, via `func/main.sh` | Confirmed unchanged and untouched throughout this entire series — the adapter never duplicates `is_object_valid`/`is_package_full`/etc. |
| Locking | Adapter (`LockManagerInterface`) | Per-user, registry-driven (`mutation.kind !== "read"`) |
| Mutation classification | Adapter (registry-driven `known_post_mutation_exit_codes`) | Confirmed zero hardcoded exit codes in `CommandAdapter.php` — proven mechanically by `MutationClassificationTest::testSameExitCodeDifferentOutcomesPerRegistryEntry` |
| Script selection / argv construction | Registry (`CommandRegistry`) | The **only** bridge from a public operation name to a `bin/v-*` invocation — see §9 |
| Sudoers / OS privilege boundary | Unchanged, outside the adapter entirely | `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` — confirmed still a wildcard, not narrowed by anything built in this series |

**The API layer must not, and per this architecture cannot, execute `v-*` scripts directly, call `exec()`, know sudoers details, know filesystem paths, implement locking, implement mutation classification, or duplicate parameter validation** — all of that already lives exclusively in the adapter/registry, and nothing in the six implemented operations required the API layer (which doesn't exist yet) to reach around that boundary. The one place this boundary is *already violated in production* is `web/api/index.php` itself (see §10) — not a risk API v2 introduces, a pre-existing pattern API v2 must not inherit.

---

## 4. Resource Model

Using the six operations validated across this task series, plus `domain.get` — confirmed registered by direct source read of `CommandRegistry.php` (lines 102-134: `script => v-list-web-domain`, `mutation.kind => read`) but not one of the six this series' tasks exercised — no CRUD symmetry assumed.

| Resource | list | get | create | update | delete | schedule | other |
|---|---|---|---|---|---|---|---|
| `domains` (under a user) | via `domain.list` (adapter operation exists) | via `domain.get` ✓ — confirmed registered (`CommandRegistry.php:102-134`) | `domain.create` ✓ | **no adapter operation exists** | `domain.delete` ✓ | — | — |
| `databases` (under a user) | **no adapter operation exists** | **no adapter operation exists** | `database.create` ✓ | **no adapter operation exists** | `database.delete` ✓ | — | — |
| `backups` (under a user) | **no adapter operation exists** | **no adapter operation exists** | **no `backup.create` exists** — only `backup.schedule` (queues a job; see §8) | — | **no adapter operation exists** | `backup.schedule` ✓ | — |
| `users` | **no adapter operation exists** | **no adapter operation exists** | **no adapter operation exists** | — | **no adapter operation exists** | — | — |

**Example mappings, per the task's own format:**

```
POST /v2/users/{user}/databases           → database.create
DELETE /v2/users/{user}/databases/{database} → database.delete
                                              (database path segment must be
                                              normalized — see §5, NOT fixed
                                              inside CommandAdapter)
POST /v2/users/{user}/domains             → domain.create
DELETE /v2/users/{user}/domains/{domain}  → domain.delete  (no asymmetry here —
                                              domain names are not prefixed by
                                              either operation)
POST /v2/users/{user}/backups             → backup.schedule, but the response
                                              semantics differ materially from
                                              every other create above — see §8
```

**Do not assume CRUD symmetry — restated with evidence, not just accepted as instruction**: `users` has zero adapter operations despite being the resource every other resource is scoped under; `databases` has create/delete but no list/get; `domains` has list/get/create/delete but no update; `backups` has neither create nor delete nor list in the adapter today, only schedule. **A resource-complete API v2 cannot be built from the current registered operations alone** — this is stated plainly here and expanded in §12, not glossed over to make the resource table look more finished than it is.

---

## 5. Normalization Strategy

This is the section the task calls out as critical, and the evidence is unambiguous, source-verified in `DATABASE_DELETE_IMPLEMENTATION.md` §1 and re-confirmed here:

- `database.create`'s `database` parameter is the **raw suffix** (e.g. `"wordpress_db"`) — `bin/v-add-database` prefixes it internally (`database="$user"_"$2"`).
- `database.delete`'s `database` parameter is the **full, prefixed name** (e.g. `"admin_wordpress_db"`) — `bin/v-delete-database` performs no prefixing; its `is_object_valid` check greps `db.conf`'s already-prefixed `DB=` field directly.

**This was deliberately left un-normalized inside the adapter**, per every prior task's explicit instruction, and that choice is evaluated here as correct, not merely obeyed:

### Where the normalization belongs, and why

**Not `CommandAdapter`.** The adapter's genericity is its core proven property (six operations, zero operation-specific branches, mechanically enforced by tests). Adding "if operation is database.delete, strip the `{user}_` prefix from `database`" would be exactly the kind of operation-specific branch every task in this series has explicitly prohibited, and would break the moment a *different* API-level identifier scheme was chosen (see below) — the adapter would then need a second special case to undo the first.

**Not `CommandRegistry`.** The registry's job is describing one operation's mapping to one script's positional contract, hand-verified against source. It has no concept of "this parameter's public API identifier differs from its CLI-facing value" — introducing one would conflate registry-as-CLI-contract-description with registry-as-API-contract-description, two different documents that happen to share a data structure today only because nothing has needed them to diverge yet.

**Yes: an API domain/service layer, above the adapter, below the HTTP router.** This is exactly the "Service Layer" `ARCHITECTURE_ADAPTER_DESIGN.md` §9 and `ARCHITECTURE_REVIEW.md`'s Service Layer Analysis already named as the layer that owns "translate a public API concept into the right adapter call(s)" — normalizing `database.delete`'s prefixed-name requirement is a direct instance of that already-anticipated responsibility, not a new kind of work the architecture wasn't designed for.

### What identifier API v2 should expose

Recommend a single stable `database_id` in the public resource path (`/v2/users/{user}/databases/{database_id}`), where `database_id` is defined at the **API layer** as the raw suffix — the same value `database.create` already accepts, and the more intuitive one for an API client to have supplied at creation time. The service layer then:

- For `database.create`: passes `database_id` straight through as `database` (no transform needed — this is already the raw-suffix contract).
- For `database.delete`: transforms `database_id` into `"{user}_{database_id}"` before calling `database.delete`'s `database` parameter.

This is a **one-line, one-direction transform, owned entirely outside the adapter**, and it is the only way the public API can present one coherent identifier despite the two CLI scripts genuinely disagreeing about what "the database parameter" means. **Do not implement this now** — it is named here as the correct home for a transform this review is confident is needed, not as a spec to build against without a dedicated API v2 design pass deciding the exact resource-path shape.

**A second, larger normalization question this review surfaces but does not resolve**: is `database_id` scoped per-user (two different users can each have a `wordpress_db`, since the true uniqueness key is the prefixed name) or globally unique? The prefixing behavior confirms the *underlying* uniqueness is per-user-then-prefixed, which argues for a per-user-scoped `database_id` in the URL (`/v2/users/{user}/databases/{database_id}`, not a flat `/v2/databases/{id}`) — consistent with every other resource's user-scoping in the table above. Flagged as a real API design decision, not resolved here.

---

## 6. Request/Response Contract

### Mapping `AdapterResult` fields to an HTTP response

| `AdapterResult` field | API-safe? | Treatment |
|---|---|---|
| `operation` | Yes | Echo back — useful for client-side logging/debugging |
| `resolvedCommand` | **No — internal only** | Leaks the underlying `v-*` script name, a Hestia CLI implementation detail the API layer should never expose (per §9's "no Hestia CLI-specific behavior" constraint) |
| `commandId` | Yes | Rename to a public `request_id`/`operation_id` — useful for support/correlation, no internal detail leaked |
| `status` | Partially — normalize, don't pass through raw | `ok`/`adapter_error`/`hestia_error`/`timeout`/`cancelled` is an internal vocabulary; the API needs its own normalized status (see below), informed by but not identical to this enum |
| `exitCode` | **No — internal only** | A raw process exit code is a CLI implementation detail with no meaning to an HTTP client |
| `hestiaErrorCode` | **No, not directly — normalize** | `E_RESTART`/`E_NOTEXIST`/etc. are Hestia's own internal error taxonomy; exposing them raw couples the public API to CLI internals the task explicitly says the API must never leak. Map to a small, stable, API-owned error-code vocabulary instead (e.g. `resource_not_found`, `resource_exists`, `quota_exceeded`) — a translation table, analogous to how `mutation_state`'s `confirmed_degraded` is already a translation of `known_post_mutation_exit_codes`, not a passthrough. |
| `adapterErrorCode` | Partially — normalize | Same treatment: `VALIDATION_FAILED`/`LOCK_TIMEOUT`/`AUTHORIZATION_DENIED` are adapter-internal names; map to API-level codes, though the *concepts* (validation failure, conflict, forbidden) map cleanly to standard HTTP semantics (see below) |
| `errorMessage` | **Caution — may leak internals** | Currently derived from raw stdout/stderr (confirmed in every operation's implementation doc: Hestia's own `check_result` writes to stdout). This text can contain CLI-specific phrasing ("Error: DB=admin_wordpress_db already exists") that leaks the prefixed internal name. API v2 should NOT pass this through verbatim for client-facing error messages — use it only as an internal diagnostic field, with a separate, API-authored human-readable message per normalized error code. |
| `stdout`/`stderr` | **No — internal-only, diagnostic** | Raw CLI output; useful for support/debugging endpoints, never the primary client-facing payload |
| `parsedOutput` | Yes, when present | Already the canonical structured data for read operations (`domain.list`) — this is close to the API's actual response body for `GET`/`LIST` endpoints |
| `startedAt`/`finishedAt`/`durationMs` | Yes | Useful, no internal leakage |
| `lockWaitMs` | Yes, but currently always `null` | Not yet populated (confirmed: `AdapterResult.php`'s own docblock states this) — do not build an API contract that depends on a field that doesn't exist yet |
| `actor`/`target` | Yes, largely | Already scrubbed of sensitive parameters by the adapter (proven by `database.create`'s tests 7-9) — safe to echo, though `target` should be presented using the API's normalized identifiers (§5), not the adapter's raw CLI-facing ones |
| `resultShape` | Internal, informs API shape but isn't itself exposed | Tells the API layer whether to present a single resource or a collection — a routing/serialization hint, not response content |
| `mutationState` | **Do not expose directly — see §7** | The single biggest "do not leak the internal model as-is" case in this whole mapping |

### Error mapping — semantic reasoning, not invented status codes

| Adapter outcome | Semantic meaning | HTTP mapping, with reasoning |
|---|---|---|
| Validation failure (`VALIDATION_FAILED`/`MISSING_PARAMETER`/`UNEXPECTED_PARAMETER`) | Caller error, well-formed request but bad content, nothing happened | `400` or `422` — caller error, retry-after-fixing is meaningful, matches the existing `exit_code_to_http_code()` precedent `ARCHITECTURE_ADAPTER_DESIGN.md` §4 already flags as needing reconciliation, not silent divergence |
| Authorization denial (`AUTHORIZATION_DENIED`) | "You may not ask this," independent of whether the request is well-formed | `403` — this is Part 9 of `MUTATION_AND_AUTHORIZATION_DESIGN.md`'s own flagged gap: today's `AdapterResult.status` enum has no dedicated slot for this concept (it collapses into `adapter_error` alongside validation failures). API v2's response model can and should distinguish it even before the adapter itself gains a dedicated `status` value, since the API layer already has `adapterErrorCode = "AUTHORIZATION_DENIED"` to branch on today. |
| Lock timeout (`LOCK_TIMEOUT`) | Contention, transient, retryable, nothing changed | `409 Conflict` — matches `ARCHITECTURE_ADAPTER_DESIGN.md` §9's own illustrative sketch; a real REST convention fit for "another operation on this resource is in progress" |
| Lock mechanism failure (`LOCK_UNAVAILABLE`) | An operational problem, not caller error, not contention | `503 Service Unavailable` — distinct from `409` precisely because this is not "try again shortly," it's "something is broken," a distinction `WRITE_OPERATION_DESIGN.md` Part 3 already establishes at the adapter level as a different `adapter_error_code` for exactly this reason |
| `hestia_error` + `mutation_state = not_attempted`-equivalent (pre-mutation failure) | Caller error or resource-state conflict, nothing changed | Depends on the specific normalized error code — a duplicate-resource `E_EXISTS` maps to `409 Conflict`, a not-found `E_NOTEXIST` maps to `404`, a quota `E_LIMIT` maps to `403` or a dedicated `429`-adjacent code. **This requires per-operation, product-level knowledge of which `hestia_error_code`s are pre-mutation for THAT operation** — exactly the knowledge `MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 9 scenario 3 already identifies as a service-layer concern the adapter itself deliberately does not assert generically (see §7). |
| `ok` + `mutation_state = confirmed` | Full success | `200`/`201`/`204` per normal REST verb conventions — the one unambiguous case |
| `hestia_error` + `mutation_state = confirmed_degraded` | Success with a caveat — see §7 | **Not a clean 2xx or 4xx** — the honest answer, expanded in §7, is that this needs a `2xx` status (the resource does exist / was in fact deleted) with an explicit warning field in the body, not a status code alone |
| `hestia_error` + `mutation_state = unknown` | Genuinely uncertain outcome | The single hardest row, expanded in §7 — no clean status code exists for "we don't know if this worked," and this document does not invent one without a real API design pass reconciling it against product requirements, per `WRITE_OPERATION_DESIGN.md` Part 7's own explicit refusal to pre-decide this |
| `timeout`/`cancelled` | Adapter gave up waiting; Hestia-level outcome unknown for a different reason | `504 Gateway Timeout` or a custom code — distinct from a Hestia-reported failure |

### Async operations (`backup.schedule`)

See §8 for the full treatment — the short version: this cannot be represented as a normal synchronous create response at all; it needs the operation/job resource pattern.

---

## 7. Mutation Semantics

### Is the four-state model suitable as an internal concept? Yes, unchanged.

`not_attempted`/`confirmed`/`confirmed_degraded`/`unknown` correctly and deliberately encodes exactly what the adapter can honestly know from outside a process it does not instrument (`WRITE_OPERATION_DESIGN.md` Part 5's full trace of `v-add-web-domain` is the original, still-valid evidence; `database.delete`'s unchecked `DROP DATABASE` — see below — is new evidence for the *same* conclusion, not a reason to revisit it). **Do not modify the mutation model** — this review's job is to decide the API's relationship to it, not re-derive it.

### Should API v2 expose `mutation_state` directly? No.

`mutation_state`'s four values answer one narrow, internal question — "what does the adapter know about whether the underlying process's core mutation happened" — a question phrased in terms of adapter internals (was a process spawned, did it exit zero, does the registry declare this exit code post-mutation). An API client does not want to know "was a process spawned" — it wants to know "did my request succeed," a related but not identical question, and `MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 9 already reached exactly this conclusion for `confirmed_degraded` specifically (scenarios 2 and 5: same `mutation_state` value, materially different client-facing implications for create vs. delete).

### Should it be hidden entirely? No — that loses real information a sophisticated client needs.

### Recommendation: expose both a normalized API status and a lower-level diagnostic field, not one or the other

```
{
  "status": "succeeded" | "succeeded_with_warning" | "failed" | "unknown" | "pending",
  "warning": null | { "code": "...", "message": "..." },   // populated only for succeeded_with_warning
  "diagnostic": {                                            // internal-facing, present for support/debugging
    "mutation_state": "confirmed_degraded",
    "hestia_error_code": "E_RESTART"
  }
}
```

- `succeeded` — `mutation_state = confirmed`.
- `succeeded_with_warning` — `mutation_state = confirmed_degraded`. This is the API-level translation of "the adapter knows the process exited 0 or knows this specific non-zero exit is post-mutation" into "the requested resource change DID happen, but something adjacent (a service reload) did not complete cleanly" — the exact distinction the task asks to draw explicitly. The `warning` object is populated with product-authored text (owned by the service layer, per §5's precedent), not the raw `errorMessage`.
- `failed` — `mutation_state = not_attempted`, OR `mutation_state = unknown` where the service layer has product-level knowledge (per operation, per `hestia_error_code`) that this specific failure is known-pre-mutation for THIS operation (e.g. `database.create`'s `E_EXISTS`, `database.delete`'s `E_NOTEXIST` — both confirmed pre-mutation by source in their respective implementation docs). This is exactly the "service layer may encode presentation knowledge the adapter itself does not assert generically" pattern `MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 9 scenario 3 already names.
- `unknown` — `mutation_state = unknown` for a failure the service layer does NOT have specific product knowledge about. **This must remain a real, distinct, honestly-labeled API status, not silently folded into `failed`.** Collapsing it into `failed` would tell a client "nothing happened, retry freely" when the adapter's own evidence (`WRITE_OPERATION_DESIGN.md` Part 5, and now `database.delete`'s unchecked-`DROP DATABASE` finding) says the truth might be "something happened, retrying could be unsafe or redundant." This is the single most important "clearly distinguish" instruction in the task, and the API contract above is designed specifically so this distinction survives all the way to the HTTP response, not just the adapter's internal model.
- `pending` — reserved for async operations (§8), not produced by any of the six operations reviewed here except `backup.schedule`'s own accepted-not-completed semantics.

### The `database.delete` finding, applied here explicitly

`DATABASE_DELETE_IMPLEMENTATION.md` §7's finding — an unchecked `DROP DATABASE` can let a real deletion failure exit 0, reported as `mutation_state = confirmed` — means the API's `succeeded` status for `DELETE /v2/users/{user}/databases/{database_id}` is, for this specific operation, a **slightly weaker guarantee than for `domain.delete`** (whose only post-mutation risk is at least a *checked* `E_RESTART`). This review does not propose fixing this at the adapter level (per `DATABASE_DELETE_IMPLEMENTATION.md`'s own conclusion, doing so would require either modifying `bin/v-delete-database`, prohibited, or inventing a new mutation state, prohibited by this task too) — it is named here so a future API v2 design pass makes an informed choice about whether `succeeded` needs a caveat specifically for this endpoint (e.g. documented, or a lighter-weight `succeeded_unverified` distinction) rather than silently inheriting an unequal confidence level across otherwise-identically-shaped delete endpoints.

---

## 8. Async Operations

`backup.schedule` maps to "request accepted / job queued," confirmed by source (`BACKUP_SCHEDULE_IMPLEMENTATION.md`: the operation's entire mutation is appending one line to `$HESTIA/data/queue/backup.pipe`; the actual backup archive is produced up to five minutes later by a cron-driven, fully detached run of `bin/v-backup-user` that this operation never invokes and the adapter does not represent or observe).

### What API v2 must not do

Must not represent `POST /v2/users/{user}/backups` as a synchronous create returning `201` with a "backup" resource — the adapter's own result for `backup.schedule` says nothing about whether a backup was ever produced, only that a queue entry was accepted. Returning a resource that implies completion would be a direct, avoidable case of exactly the "exit 0 ≠ guaranteed mutation" overclaim §7 spends its whole section warning against — worse here, since the *adapter's own result* (`mutation_state = confirmed`) is honestly reporting "the queue write succeeded," and it is the API layer's job not to overclaim beyond that, not the adapter's fault if it does.

### Recommended: a generic operation/job resource, but scoped to what's actually needed now

```
POST /v2/users/{user}/backups            → 202 Accepted
  { "operation_id": "...", "status": "pending", "resource": "backup",
    "action": "schedule", "created_at": "..." }

GET /v2/operations/{operation_id}        → poll for eventual completion
```

**Is this premature at this stage? Partially, and the honest answer has two parts:**

1. **The shape is not premature to *design*** — `backup.schedule` is real, implemented, and already exhibits exactly this semantics; any API v2 that exposes it at all needs *some* async representation, so naming the shape now (rather than discovering it ad hoc when `backup.schedule` is the first operation wired up) is legitimate forward design, consistent with this review's "design/analysis only" scope.
2. **A full generic job-resource *subsystem* (polling infra, job-state persistence, webhook delivery, cross-operation job history) IS premature to *build*** — `backup.schedule`'s own "queued" state is currently only observable by re-querying Hestia state elsewhere (there is no `backup.status`/`backup.get` adapter operation, confirmed absent from the six operations reviewed here); building a generic async-job store today would be infrastructure ahead of a second concrete consumer, exactly the over-engineering pattern `ARCHITECTURE_REVIEW.md`'s Service Layer Analysis already warned against for premature multi-backend abstractions. **Recommendation: design the `operation_id`/`status` shape now (as above), but implement only the minimal version needed for `backup.schedule` specifically when API v2's first release actually includes it — do not build a general job engine speculatively.**

---

## 9. Authentication/Authorization Boundary

### Should authorization remain an adapter concern? Yes, exactly as currently scoped — confirmed, not re-decided.

`AuthorizerInterface` (read directly for this review) is a single method, `authorize(string $operation, array $target, array $actor): bool`, carrying zero role/scope/tenancy vocabulary — confirmed by source, matching `MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 4's "option D" design exactly. The adapter's ownership is **the structural guarantee that a check happens** (mirroring the `ProcessRunnerInterface`/`LockManagerInterface` injection pattern), never the policy behind it. This remains correct: an API layer with its own authorization logic, bypassing the adapter's seam, would mean the seam's "structurally guaranteed" property becomes false the moment any caller forgets to consult it — the same argument that already justified locking living in the adapter rather than a service layer.

### Does API authentication belong above it? Yes, unambiguously — and it does not exist yet.

Nothing in the adapter, the registry, or `AuthorizerInterface` establishes *who* an HTTP caller is. That is entirely an API-layer (and, beneath it, an authentication-provider) responsibility, consistent with `ARCHITECTURE_ADAPTER_DESIGN.md` §1's explicit "not decide auth/session policy" non-goal for the adapter.

### How does an authenticated principal become the adapter's `actor`?

The API layer resolves an authenticated principal (however that's implemented — session, API key, future Cloud Account token) into `{user, acting_as}` before calling `CommandAdapter::invoke()`. This is already the exact shape `AdapterResult::$actor` has carried since the very first vertical slice (confirmed: `acting_as` exists today, unused by any enforcement, exactly as `MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 5 describes) — API v2 does not need a new actor shape, it needs to finally *populate* `acting_as` meaningfully for delegated-administration scenarios (an admin managing a customer's resources) rather than leaving it permanently null.

### Should tenant/account context exist independently of the Hestia username? Yes — this is the one place this review recommends going slightly beyond what's already designed.

Today's `actor`/`target` model is entirely in terms of Hestia usernames — there is no tenant/account concept anywhere in the adapter, registry, or authorization seam, and none is needed for the adapter itself to keep working. But a **future** Cloud Account principal is not guaranteed to map 1:1 to a Hestia username (a Cloud Account could plausibly span multiple panels/servers, or a single panel could eventually host resources for multiple distinct billing accounts) — introducing that mapping later should not require changing `AuthorizerInterface`'s signature, since it already takes an opaque `actor` array the authorization implementation is free to interpret using whatever richer context it has access to (a Cloud Account ID looked up server-side, not passed as a new adapter parameter). **The architectural requirement this review identifies**: whatever authorization implementation eventually replaces `AllowAllAuthorizer` must resolve tenant/account context *before* calling `authorize()`, using its own data source — the adapter's `actor` parameter should stay exactly as narrow as it is today (`user`, `acting_as`), not grow a `tenant_id`/`account_id` field, because that would push a Cloud Account-specific concept into a class (`AuthorizerInterface`) explicitly designed to know nothing about Cloud Account.

### How Cloud Account could eventually map to Hestia users, architecturally, without redesigning `CommandAdapter`

1. A Cloud Account-aware authorization implementation (injected via the existing `AuthorizerInterface` seam, zero change to `CommandAdapter`) resolves "this Cloud Account principal is entitled to act on Hestia user X" using its own, external mapping table — not built here, not designed in detail here, per the task's explicit "do not implement Cloud Account" instruction.
2. That implementation's `authorize()` call still receives exactly `{operation, target, actor}` — the same three arguments every other authorizer (including today's `AllowAllAuthorizer` and test's `SpyAuthorizer`) already receives. **No interface change is required for Cloud Account to plug in later** — this is the concrete, verifiable answer to "can Cloud Account be added later without redesigning `CommandAdapter`": yes, because the seam it needs already exists, unmodified, today.
3. `actor.acting_as` remains the one mechanism a Cloud Account-aware authorizer would need to formalize (e.g. a support engineer acting through Cloud Account tooling as a specific hosting user) — already present, already unused, ready to be given real semantics without new adapter code.

---

## 10. Security Model

### Should API v2 ever allow raw command execution, arbitrary script selection, arbitrary binary paths, arbitrary fixed parameters, or arbitrary environment variables? No, on every count, and the evidence is stronger now than when this was first designed.

- **Raw command execution / arbitrary script selection**: `web/api/index.php` — confirmed by direct read for this review — is precisely this anti-pattern already in production (`$hst_cmd` is caller-named, shape-checked only, not allowlisted). API v2 replicating this pattern would not be a new risk; it would be re-introducing a risk this entire adapter series exists to retire. `CommandRegistry` is the answer: only registered operation names ever resolve to a script at all — there is no "pass the script name through" path anywhere in `CommandAdapter`'s public interface, confirmed unchanged across all six operations added in this series.
- **Arbitrary Hestia binary paths**: `CommandAdapter`'s constructor takes a fixed `binDir`/`sudoPath` at construction time, never per-call — confirmed by every operation's test suite using the same hardcoded `/usr/local/hestia/bin/`/`/usr/bin/sudo` values. No caller-supplied path ever reaches process construction.
- **Arbitrary fixed parameters**: `fixed_parameters` values are registry-authored PHP literals (`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 4's invariant, re-confirmed unbroken by every operation added since: `database.create`'s `type`/`host`/`charset`, `domain.create`'s `restart`, all compile-time constants in `CommandRegistry.php`, never sourced from `$params`).
- **Arbitrary environment variables**: `ProcOpenProcessRunner` does not pass caller-controlled environment variables to the spawned process (confirmed unchanged, `ARCHITECTURE_ADAPTER_DESIGN.md` §5's environment-sanitization design; no operation added in this series introduced an environment-variable parameter of any kind).

### Should `CommandRegistry` remain the only bridge between public operations and Hestia scripts? Yes, and this is the load-bearing security property the entire six-operation series exists to prove.

Every operation's genericity test (culminating in `database.delete`'s zero-footprint result — no `CommandAdapter.php`/`ParameterValidator.php` changes at all) mechanically confirms that adding a new operation never requires, and structurally cannot introduce, a new execution path outside the registry. API v2's own security posture inherits this directly: **as long as API v2 only ever calls `CommandAdapter::invoke(operation, params, actor)` with an `operation` string it looked up from its own fixed route table (never one derived from arbitrary request content), the registry-allowlist guarantee holds all the way to the HTTP boundary.** The one thing that would break this is an API v2 design that lets a client specify the operation name directly in the request body (mirroring `web/api/index.php`'s `cmd` field) — this review recommends explicitly against that shape: **routes, not a `cmd` parameter, should select the operation.**

---

## 11. Legacy Migration Strategy

### Recommendation: **A — coexist temporarily, migrating incrementally, per the already-established plan — not a new strategy this review invents.**

`ARCHITECTURE_ADAPTER_DESIGN.md` §11 and `WRITE_OPERATION_DESIGN.md` Part 2's migration amendment already specify exactly this, and nothing in the six operations built since has surfaced evidence to revise it:

1. Build additively — API v2 alongside the legacy UI and `web/api/index.php` v1, neither modified nor removed yet.
2. Migrate one legacy call site at a time onto the adapter/API v2, starting with the operations that already exist (`domain.create`/`domain.delete`/`database.create`/`database.delete`/`backup.schedule`), verifying behavioral equivalence.
3. Remove a given legacy `exec()` call site only after its adapter-routed replacement is proven in production for that specific operation — never before, never speculatively.

**Why not B (migrate UI first) or C (migrate only after API v2 stabilizes)**: B risks the exact same category of premature-infrastructure mistake `ARCHITECTURE_REVIEW.md`'s Service Layer Analysis already warned against — migrating the UI onto the adapter with no external consumer yet doesn't buy anything additional over what the existing 189-test suite already proves, and delays the thing that actually needs the adapter to exist (API v2). C is overly conservative in the other direction: it treats "API v2 stabilizes" as a precondition for UI migration when the two are actually independent — UI migration only needs the adapter (already proven); it does not need API v2 to exist at all, and delaying it needlessly extends the window in which the bypass (below) remains live.

### Security/concurrency implications of leaving the legacy bypass in place, restated with the two new operations' evidence added

Three properties — adapter security (registry allowlist), concurrency safety (per-user lock), authorization — are each **only as strong as the path actually used** (`MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 8's finding, unchanged). Concretely, for the two operations added this session: `web/add/db/index.php` and `web/delete/db/index.php` (both confirmed still present, both still direct `exec()` calls, by source read performed for `database.create`/`database.delete`'s own implementation passes) remain **completely outside** the adapter's lock, authorization seam, and registry allowlist. A direct legacy `database.create`-equivalent call and an adapter-routed one, for the same user, at the same moment, are not serialized against each other — this is not a new risk API v2 introduces, it is the same, already-documented, unresolved gap, now demonstrated against two more real operations than when it was first identified.

---

## 12. Minimum Requirements Before API v2

### A. REQUIRED BEFORE API v2

1. **An authorization *policy* implementation** (something other than `AllowAllAuthorizer`) — even a minimal one (e.g. "actor.user must equal target.user, full stop, no roles yet") is required before ANY externally-reachable endpoint, mutating or not, per `MUTATION_AND_AUTHORIZATION_DESIGN.md`'s own BLOCKERS section, unchanged and still accurate.
2. **An authentication mechanism above the adapter** — does not exist anywhere in the reviewed architecture today. Without it, "authorization" has no `actor` to evaluate that can be trusted.
3. **A decided (not necessarily fully implemented) API-level status/error-code vocabulary** — §6/§7 of this document sketch one; a real design pass must finalize it before the first endpoint ships, so early endpoints don't calcify an ad hoc mapping.
4. **The normalization strategy (§5) decided at the service-layer design level** — at minimum, which identifier scheme (`database_id` vs. exposing the raw/prefixed distinction to clients) is chosen, before any database endpoint is built, since retrofitting a resource identifier scheme after clients exist is a breaking change.

### B. REQUIRED FOR FIRST API v2 RELEASE (not before design/skeleton work, but before it's usable)

5. A minimal HTTP router mapping a small, fixed set of routes to the six existing operations (no `cmd`-parameter passthrough, per §10).
6. The async/job representation for `backup.schedule` (§8) — the minimal version, not a general job engine.
7. At minimum the highest-traffic legacy caller per exposed operation migrated (restated, unchanged finding from `ADAPTER_ARCHITECTURE_CHECKPOINT.md`/`MUTATION_AND_AUTHORIZATION_DESIGN.md`) — so "authorized"/"concurrency-safe" claims are true for the operation as a product feature, not merely the adapter-routed subset of its call sites.
8. `web/inc/composer.json` PSR-4 entry for `web/inc/adapter/` (currently absent — confirmed by reading `bootstrap.php`) — a small, mechanical prerequisite for any real API v2 code to consume the adapter without the manual-require pattern.

### C. CAN BE DEFERRED

9. Sudoers narrowing (`ARCHITECTURE_ADAPTER_DESIGN.md` §5/§12, `MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 7B) — genuinely valuable defense-in-depth once API v2 exists, but not a hard blocker for API v2's *first* release given the registry already provides the primary allowlist and the caller population (internal, then a small set of authenticated API clients) is not yet the untrusted-third-party case Part 7C (Marketplace) specifically flags as an actual blocker.
10. `lockWaitMs` population (currently always `null`).
11. Read operations beyond `domain.list`/`domain.get` (a `database.list`/`database.get`/`backup.list` set) — needed for resource-model completeness (§4) but not architecturally novel; each would follow the exact same zero-adapter-change pattern `database.delete` just proved is achievable when reusing existing types.
12. A general async-job-resource subsystem beyond `backup.schedule`'s minimal needs (§8).
13. State-diffing/precise "what changed" audit persistence (`ARCHITECTURE_ADAPTER_DESIGN.md` §8, explicitly deferred there and still not built).

### D. NOT NEEDED

14. **More adapter operations purely to "validate the abstraction" further.** This is the direct answer to the task's explicit challenge: **the current six operations are sufficient.** They already cover read/collection, create, delete, queued/async, sensitive-parameter/secret-delivery, inconsistent-CLI-contract absorption, and a zero-footprint (pure-registry) operation. No architectural property this review can identify remains unexercised. If a *product* need for `database.list`/`database.get`/etc. exists, build them for that reason (resource-model completeness, §4/§12-B), not to further stress-test an abstraction that has already been stress-tested six independent ways with a 100% success rate.
15. A Go daemon / adapter-as-standalone-process (`ARCHITECTURE_ADAPTER_DESIGN.md` §10/§12 already deferred this; nothing in this review's evidence changes that call — the adapter's current in-process PHP form has not shown any limitation API v2's first release would hit).
16. A generic/dynamic command-execution escape hatch of any kind, at any layer, for any convenience reason (§10 restates why, explicitly, in case the pressure to add one arises during actual API v2 implementation).
17. Cloud Account / Cloud Connect implementation (explicitly out of scope per this task; §9 establishes the architectural readiness without building any of it).
18. Marketplace/third-party-extension support (`MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 7C's own finding: a genuine architectural blocker, categorically distinct from and harder than API v2 itself — not in scope for "is the adapter ready for API v2").

---

## 13. Deferred Work

(Restated from §12-C/D for a single consolidated view, per the required section list — not a new list.)

Sudoers narrowing; `lockWaitMs` population; additional read operations beyond current product need; general async-job subsystem; state-diffing audit; Go daemon extraction; Cloud Account/Cloud Connect; Marketplace support. None of these block starting API v2 design or its first implementation slice against the six existing operations.

---

## 14. Architectural Risks

Ranked by severity, using the task's own risk list as the checklist, cross-referenced against concrete evidence from this series rather than asserted generically.

1. **CRITICAL — No authorization policy exists.** `AllowAllAuthorizer` is the only implementation. This is not a risk API v2 introduces; it is the single fact that makes exposing ANY of the six operations externally unsafe today, restated with full force because it is the one item every prior design doc already flagged as a blocker and nothing since has changed it.
2. **CRITICAL — `web/api/index.php`'s existing command-passthrough pattern is a live template for how API v2 could go wrong.** Confirmed by direct read for this review (§2, §10) — not a hypothetical anti-pattern, an actual pattern already running in production today. The single highest-leverage design mistake API v2 could make is unconsciously reproducing it (a `cmd`/`operation` field in the request body instead of route-selected operations).
3. **HIGH — Treating exit 0 as guaranteed mutation.** Directly evidenced twice now: `WRITE_OPERATION_DESIGN.md` Part 5 (`v-add-web-domain`) and this session's `database.delete` finding (unchecked `DROP DATABASE`). §7's `succeeded`/`succeeded_with_warning`/`unknown` API status model exists specifically to prevent this from leaking into client-facing contracts as a bare "success."
4. **HIGH — Legacy `exec()` bypass, unresolved.** Every safety property (registry allowlist, locking, future authorization) is only as strong as the path actually used, confirmed for two more real operations this session. Growing API v2 alongside an unmigrated legacy surface means the SYSTEM-level claim "these operations are safe" remains false until migration happens, even while the API-level claim becomes true.
5. **MEDIUM-HIGH — Inconsistent resource identifiers, evidenced concretely, not hypothetically.** `database.create`/`database.delete`'s raw-suffix-vs-prefixed-name asymmetry (§5) is a real, source-verified case, not a speculative "CLI contracts might diverge" risk — the same resource (`database`) is addressed two different ways by two operations of the underlying CLI itself. Having surfaced once, on the first pair of mutating operations examined closely enough to notice, it should be assumed possible anywhere the API layer maps one identifier onto more than one underlying CLI contract, not dismissed as an isolated edge case.
6. **MEDIUM — Exposing `E_*`/adapter-internal codes verbatim.** Straightforward to avoid (§6's normalization table), but easy to get wrong under implementation-speed pressure, especially since `hestiaErrorCode` is a convenient, already-present string that a rushed first API v2 pass might be tempted to pass straight through.
7. **MEDIUM — Async operation semantics under-designed relative to product need.** `backup.schedule` is the only async operation that exists; a real API v2 backup endpoint needs at minimum a way to observe completion, which no adapter operation currently provides (§8) — this is a genuine gap, not merely a design nicety.
8. **MEDIUM — Tenant/account model absent, though the seam for it exists.** Not a blocker for a first API v2 release scoped to "the existing Hestia RBAC model, exposed over HTTP" (§9), but becomes a real risk the moment Cloud Account work begins if the `AuthorizerInterface` seam is bypassed or duplicated rather than extended, per §9's explicit guidance not to widen the adapter's `actor` shape.
9. **LOW-MEDIUM — API versioning and backwards compatibility**, genuinely low risk *right now* only because nothing has shipped yet — this flips to a real risk the moment the first API v2 endpoint is public, and this review recommends deciding a versioning stance (§6's error-code vocabulary, §5's identifier scheme) before that point specifically because retrofitting either is a breaking change once real clients exist.
10. **LOW — Idempotency.** `database.create`/`database.delete`'s "duplicate/nonexistent rejected pre-mutation" behavior (confirmed by both implementation docs) already gives API v2 a reasonably safe default (a naive retry of a failed create/delete either succeeds harmlessly or fails informatively, never silently double-mutates) — lower urgency than the items above, but worth an explicit idempotency-key design before any client-facing release, consistent with `ADAPTER_ARCHITECTURE_CHECKPOINT.md`'s already-standing "idempotency keys" gap.
11. **LOW — Error normalization mechanics.** Real work (§6), but mechanical, low-ambiguity work once the vocabulary is decided — the risk is in *not deciding* the vocabulary early (folded into item 9), not in the normalization logic itself being hard.

---

## 15. Recommended Implementation Sequence

1. **Decide and document the API-level status/error-code vocabulary (§6/§7) and the resource-identifier normalization strategy (§5)** — pure design work, no code, resolves items A.3/A.4 from §12.
2. **Build a minimal authorization policy implementation** replacing `AllowAllAuthorizer` for at least the simplest case (`actor.user === target.user`, full self-service, no roles yet) — resolves A.1. Still entirely internal; no HTTP surface needed to build or test this, since `AuthorizerInterface` is already injectable and testable in isolation (per the existing `SpyAuthorizer`/`AuthorizationTest.php` pattern).
3. **Build the authentication layer above the adapter** that resolves a request principal into an `actor` — resolves A.2. This is the first genuinely new infrastructure this sequence requires; everything before it reuses existing seams.
4. **Build the minimal HTTP router**, routes-only (no `cmd` parameter, per §10), for the six existing operations, each route explicitly calling one fixed `CommandAdapter::invoke()` operation name.
5. **Migrate the highest-traffic legacy caller for at least one operation** (recommend `database.create`/`database.delete`, since `web/add/db/index.php`/`web/delete/db/index.php` are already fully characterized from this session's work) onto the new API v2 path — resolves B.7, and is the first point at which any of these six operations' safety properties become true for the system, not merely the adapter.
6. **Add `backup.schedule`'s minimal async representation** (§8) once at least one synchronous operation is live end-to-end, so the async pattern can be validated against a working baseline rather than designed in isolation.
7. Only after 1-6: consider whether resource-completeness gaps (`database.list`, `database.get`, `backup.list`, etc. — §12-C item 11) are actually needed by a real product requirement, and build them then, each following the same registry-first pattern already proven six times.

---

## 16. Final Verdict

**"Is the current adapter foundation ready for us to start implementing API v2?"**

**Yes, for design work and the first internal implementation slice — no, for external exposure of any mutating (or, arguably, any) endpoint until §12-A's four items are addressed.** The adapter/registry/mutation-classification/authorization-seam/sensitive-parameter machinery is proven across six materially different, source-verified operations, with zero operation-specific branching and a 189/189 test result. That is a sufficient, not merely adequate, foundation to begin building the HTTP layer, the authentication layer, and the authorization policy on top of it — none of that work requires a seventh adapter operation first. What is missing is not more adapter maturity; it is the layers *above* the adapter that were always understood to be built later (`ARCHITECTURE_ADAPTER_DESIGN.md` §9 named this gap before any operation existed) and a firm decision to stop treating "add another operation" as the next default step now that the abstraction's generality is no longer in serious doubt.

**"What is the smallest next implementation step?"**

**Not a seventh adapter operation.** The smallest next step is §15 item 2: **implement a minimal, real authorization policy** (even the crudest "self-service only" rule) behind the existing `AuthorizerInterface` seam. This is the single highest-leverage, smallest-scoped piece of work that is (a) a hard blocker for everything downstream per §12-A, (b) buildable and testable entirely with tools already proven in this codebase (`SpyAuthorizer`, `AuthorizationTest.php`'s existing pattern), and (c) requires zero new infrastructure, zero HTTP code, and zero changes to `CommandAdapter.php`/`CommandRegistry.php` — it plugs into a seam that has been sitting ready, unused, since `MUTATION_AND_AUTHORIZATION_DESIGN.md` was implemented.
