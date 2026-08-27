# Authorization Policy Implementation

## 1. Problem

`API_V2_ARCHITECTURE_REVIEW.md` concluded that the adapter foundation is validated against six real, materially different operations, but is not ready for exposure to any non-trusted caller: there is no real authorization policy behind `AuthorizerInterface`. The seam has always been consulted on every `invoke()` call (see `MUTATION_AND_AUTHORIZATION_DESIGN.md`), but the only implementation that existed, `AllowAllAuthorizer`, allows everything. This document implements the smallest real policy that closes that gap without touching authentication, HTTP, or any per-operation logic.

## 2. Existing Authorization Seam

`AuthorizerInterface::authorize(string $operation, array $target, array $actor): bool` (unchanged by this task) is a single method deliberately carrying no role/scope/tenant vocabulary. `CommandAdapter::invoke()` calls it, strictly after parameter validation/normalization and strictly before lock acquisition, with:

- `$target` — the same fully validated, normalized target `CommandAdapter` already builds for `AdapterResult` (sensitive parameters already excluded, per `SENSITIVE_PARAMETER_DESIGN.md`).
- `$actor` — the same `{user?, acting_as?}` structure `invoke()`'s own `$actor` parameter already normalizes, defaulting to `[]` (i.e. `actor.user === null`) when a caller omits it.

None of this changed. What changed is which class answers the question.

## 3. Policy Implemented

`web/inc/adapter/SameUserAuthorizer.php` (new):

```
actor.user === target.user   -> allowed
actor.user !== target.user   -> denied
actor.user missing/null      -> denied
target.user missing/null     -> denied  (fail closed)
```

It inspects only `$target["user"]` and `$actor["user"]`. It does not know `operation`, does not branch on it, and contains no reference to any specific operation name or script — see §4 of `AUTHORIZATION_POLICY_IMPLEMENTATION.md`'s companion test file, `SameUserAuthorizerTest.php`, test group A, which exercises the class directly against a synthetic operation name.

No administrative roles (`admin`, `superadmin`, `cloud_account_owner`, `tenant_admin`) are implemented. This is deliberate — per the task's explicit scope, those belong to a later authorization model built on top of this seam, not an extension of this class.

## 4. Actor Contract

The existing `{user?: string, acting_as?: string}` shape (already used throughout `AdapterResult::$actor` and `MUTATION_AND_AUTHORIZATION_DESIGN.md`) is sufficient for this policy without any redesign. `SameUserAuthorizer` reads exactly one field, `user`; `acting_as` is present in the contract but unused by this policy (it exists for a future delegation/support-impersonation policy, not this one).

`AuthorizerInterface` itself was not modified — its signature and docblock stand as-is.

## 5. Target Contract

`target.user` is whatever `CommandAdapter` already normalizes into the target array for the operation being invoked. Every one of the seven currently registered operations (`domain.list`, `domain.create`, `domain.delete`, `backup.schedule`, `database.create`, `database.delete`, plus `domain.get`) declares a `user` parameter, so `target.user` is populated for all of them today. The policy's "target missing user -> denied" branch is therefore inert against the current registry — it exists as an explicit fail-closed default, not a currently-reachable path. See §11 for what happens when that stops being true.

## 6. Authorization Decision Rules

Implemented exactly as in §3, as a pure function of `(operation, target, actor)` with no side effects, no I/O, no dependency on `$_SESSION`/`$_GET`/`$_POST`/HTTP of any kind, no command execution, and no interaction with `CommandRegistry` or `LockManager`.

## 7. Execution Ordering

Unchanged: `resolve -> validate -> normalize -> authorize -> lock -> execute`. `CommandAdapter::invoke()` was not restructured; only the default value passed to its `$authorizer` constructor parameter changed (§9). `SameUserAuthorizerTest.php` and the existing `AuthorizationTest.php` both independently prove authorization still strictly precedes lock acquisition and process execution, using the real, temp-directory-backed `LockManager` to prove a denied request never actually holds the lock (not just that `acquire()` was never called).

## 8. Security Properties

Proven by `SameUserAuthorizerTest.php` (new) and the updated `AuthorizationTest.php`:

1. `actor.user === target.user` -> allowed.
2. `actor.user !== target.user` -> denied.
3. `actor.user` missing/null -> denied.
4. `target.user` missing/null -> denied (fail closed).
5. A denial produces `status=adapter_error`, `adapterErrorCode=AUTHORIZATION_DENIED`.
6. A denied mutating request never acquires the lock (proven via real-flock immediate-reacquisition, not just a call count).
7. A denied request never spawns the underlying process.
8. A denied request never creates a sensitive temp file (proven for an operation with a `sensitive`+`delivery=temp_file` parameter).

## 9. Production/Default Wiring

**Finding, established by direct source inspection before any code was written:** no production (non-test) code anywhere in this repository constructs a `CommandAdapter` instance. A repository-wide grep for `new CommandAdapter(` outside `test/adapter/` returned zero matches. `web/inc/adapter/bootstrap.php` is a pure 14 (now 15)-file `require_once` chain with no factory and no default singleton construction — its own docblock already states that wiring the adapter into `web/inc/main.php` or `web/api/index.php` is a separate, future step.

This means the task's literal premise ("determine how bootstrap.php currently constructs the adapter") does not hold as stated: there is no production construction site to redirect. The only concrete "default" that exists is `CommandAdapter`'s own constructor parameter fallback:

```php
$this->authorizer = $authorizer ?? new SameUserAuthorizer();  // was: new AllowAllAuthorizer()
```

This is the one change made to satisfy "change the production/default construction so the real policy is used" — it is, in the strictest sense, the entirety of the available wiring change today. `bootstrap.php` now also requires `SameUserAuthorizer.php` alongside the other adapter classes so any future consumer can use it without an extra require.

**Consequence for the future, real wiring point:** whenever `CommandAdapter` is actually constructed from `web/inc/main.php` or `web/api/index.php`, that call site must inject its authorizer explicitly rather than rely on this default — a constructor default is a safety net for callers that forget to specify a policy, not a substitute for a real wiring decision made by whoever builds the eventual API v2 service layer or legacy-caller migration.

`AllowAllAuthorizer` was **not** removed. Its docblock was rewritten to state plainly that it is a test/development-only policy, that `CommandAdapter` no longer defaults to it, and that it must never be constructed on any path a non-trusted caller can reach. It remains injectable by any test that needs permissive behavior for concerns unrelated to authorization (validation, argv construction, locking, mutation classification, sensitive-parameter handling), via each test file's `buildAdapter()` helper now explicitly passing `new AllowAllAuthorizer()` where it previously relied on omission.

## 10. Test Coverage

- `test/adapter/SameUserAuthorizerTest.php` (new, 8 tests) — the policy in isolation (rules 1-4, calling `SameUserAuthorizer::authorize()` directly against a synthetic operation name, proving zero per-operation knowledge) and wired through the full adapter pipeline (rules 5-8, reusing the same synthetic-registry and real-`LockManager` proof techniques `AuthorizationTest.php` already established).
- `test/adapter/AuthorizationTest.php` (updated) — its three "default authorizer" tests were updated to reflect that the real default is now `SameUserAuthorizer`, not `AllowAllAuthorizer`: two now pass a matching actor to demonstrate the default *allows* a same-user call, and a new test (`testDefaultAuthorizerDeniesWhenActorOmitted`) demonstrates the default now *denies* a call whose actor is omitted entirely — the property the old test's name asserted (permissive-by-default) no longer holds and had to be corrected, not preserved.
- Every other existing test file that constructs a `CommandAdapter` without exercising authorization as its actual subject (`CommandAdapterTest`, `DomainListTest`, `DomainCreateTest`, `DomainDeleteTest`, `MutatingOperationTest`, `MutationClassificationTest`, `BackupScheduleTest`, `DatabaseCreateTest`, `DatabaseDeleteTest`, `SensitiveParameterTest`) had its `buildAdapter()` helper (or, for four one-off `SensitiveParameterTest` F-1/F-2 construction sites, the inline `new CommandAdapter(...)` call) updated to explicitly inject `new AllowAllAuthorizer()` instead of omitting the argument. This was necessary and intentional, not incidental breakage: the whole point of this task was to make the *default* stop being permissive, so every caller that genuinely needs permissive behavior for an unrelated concern must now say so explicitly.

**Verification:** `php test/adapter/run_tests.php` run 3 consecutive times: **198 passed, 0 failed** each run (was 189 before this task's new `SameUserAuthorizerTest.php` [8] and `AuthorizationTest.php`'s one added test).

## 11. Future Limitations

- **Server-scoped or userless operations.** Every operation registered today has a `user` parameter, so "target.user missing -> denied" is currently inert. A hypothetical future operation whose target has no concept of a single Hestia user (e.g. a server-wide `sys.*` operation) would be unconditionally denied by this policy and would need a *new* policy decision, not a change to this class — fail-closed is the correct default until that decision is made deliberately.
- **No delegation/support-impersonation.** `actor.acting_as` is carried in the contract but not consulted by this policy. A support agent acting on a customer's behalf (`actor.user = "support-agent"`, `actor.acting_as = "customer"`) is denied today, exactly like any other cross-user request. This is intentional scope discipline, not an oversight — a delegation policy is a distinct authorization model layered on the same seam later.
- **No administrative roles.** `admin`/`superadmin`/`cloud_account_owner`/`tenant_admin` do not exist in this policy. Hestia's own `$_SESSION["userContext"] === "admin"`-driven impersonation pattern (seen in the legacy `web/add/db/index.php`/`web/delete/db/index.php` callers) has no equivalent here yet.
- **No tenant/Cloud Account context.** `AuthorizerInterface`'s narrow `(operation, target, actor)` shape already accommodates a future authorizer implementation that resolves tenancy externally (per `API_V2_ARCHITECTURE_REVIEW.md` §8) without any interface change — but `SameUserAuthorizer` itself does not attempt that; it is deliberately the simplest policy that is still real.
- **Actor is never populated automatically.** `CommandAdapter::invoke()`'s `$actor` parameter still defaults to `[]` for any caller that omits it. Nothing today reads `$_SESSION["user"]` (confirmed present in `web/inc/main.php`, e.g. lines 49, 80, 129) and passes it as `actor.user` — that translation is explicitly out of scope for this task (it is authentication wiring) and belongs to whichever future call site actually constructs `CommandAdapter` from a real request.

## 12. Why Authentication Is Intentionally Not Implemented Yet

Authentication and authorization are separate questions. **Authentication** answers "who is this?" — verifying a credential (password, session cookie, access key) and producing a trusted identity. **Authorization** answers "is this already-trusted principal allowed to operate on this target?" — the question `AuthorizerInterface` exists to answer.

This task implements only the second question. `SameUserAuthorizer` assumes `actor.user` is already a trustworthy, authenticated value by the time it reaches `authorize()` — it does no verification of its own, and it must not: mixing credential-checking into the authorization seam would violate the same separation-of-concerns `AuthorizerInterface`'s docblock was written to preserve (`MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 7), and would make the policy impossible to unit-test in isolation the way §10's Part A tests do.

Building authentication now would mean choosing a mechanism (session cookie reuse from `web/inc/main.php`, the existing access-key flow in `web/api/index.php`, or a new API-token scheme) before the API v2 resource model, HTTP router, or error-vocabulary decisions this policy's own prerequisite review flagged as still open. Per `API_V2_ARCHITECTURE_REVIEW.md` §11, authentication is a separate, still-required-before-API-v2 item — this document's job was only to remove the *other* blocker.
