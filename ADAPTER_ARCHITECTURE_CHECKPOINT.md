# Adapter Architecture Checkpoint

Analysis only. No source code was modified to produce this document. No
tests were run (none were needed — this is a design review of code
already implemented and already covered by the existing 54-test suite).

**Context**: three operations exist and are fully tested —
`domain.get` (read/single), `domain.list` (read/collection),
`domain.create` (mutating/create) — plus the locking layer
(`LOCK_IMPLEMENTATION.md`) and the result model
(`WRITE_OPERATION_DESIGN.md`). This document asks: is this the right
foundation for API v2 / Cloud Account / Extensions Marketplace, or are
we quietly accumulating debt that three operations happened not to
expose yet?

**Bottom line, stated up front**: the mechanism is sound and worth
keeping. Three operations is not enough evidence to be certain of that,
but it's enough to see the shape of the actual risk, which is not in
`CommandAdapter.php` — it's in three things adjacent to it: the security
boundary is currently *only* the registry's own discipline (not a
kernel/OS-enforced one), the lock only covers adapter-routed callers
while three legacy PHP call sites remain fully outside it, and the
result model's `unknown` bucket is going to need to grow before API v2
can honestly answer "did it work?" for anything beyond `domain.create`.
None of these are reasons to stop or redesign; they're reasons to solve
them next, deliberately, rather than let API v2 work paper over them.

---

# 1. Genericity

Method: for each mechanism, ask "would a 4th, 5th, 10th operation need
this to change, or would it just add data?" Three real, structurally
different operations (a two-arg read returning one object, a one-arg
read returning N objects, a six-slot write with no output at all) is a
reasonable — not exhaustive — sample to answer this from.

## Genuinely generic (proven, not assumed)

- **Registry structure** (`script`, `argument_order`, `parameters`,
  `fixed_parameters`, `mutation.kind`, optionally `output_format`/
  `result_shape`). All three operations fit this shape with zero new
  fields added for `domain.create`, including a script with an entirely
  different argument *count* (6 vs. 2-3) and no output at all (`no
  output_format` key, and the parsing step already treats that as "skip
  parsing," not as an error).
- **Parameter validation dispatch** (`typeValidators` keyed by declared
  `type`, currently `username`/`domain`). Reused verbatim by
  `domain.create` — no new validator needed because both of its public
  parameters happen to be types that already existed. This is genuinely
  generic *as a dispatch mechanism*; see "accidental" below for what's
  NOT proven yet.
- **Fixed-parameter injection into argv** — `domain.get`'s single fixed
  value (`format=json`) generalized cleanly to `domain.create`'s FOUR
  fixed values (`ip`, `restart`, `aliases`, `proxy_ext`) with no code
  change. This is a stronger proof than it looks: the mechanism doesn't
  care how many fixed slots there are or where they sit in
  `argument_order` relative to caller-supplied ones.
- **argv construction from `argument_order`** — handles a caller-param /
  fixed-param mix in any order, proven now by a 6-slot signature where
  positions 1-2 are caller-supplied and 3-6 are fixed, vs. the read
  operations where the *last* slot was fixed. Genuinely order-agnostic.
- **Rejection-before-execution and the `rejected()` helper** — identical
  code path for all three operations; zero process spawns for any
  rejected request, in all three.
- **Hestia exit-code → `hestia_error_code` mapping**
  (`HESTIA_EXIT_CODES`) — a static table already covering every code any
  of the three scripts can produce (including `E_LIMIT`, `E_EXISTS`,
  `E_RESTART`, which only `domain.create` exercises). Genuinely
  Hestia-wide, not operation-specific, because it's keyed on the
  universal `func/main.sh` `E_*` convention, not on any script's
  identity.
- **Mutation metadata + locking decision** — `mutation.kind !== "read"`
  is the entire gate; adding `domain.create`'s `"create"` kind required
  zero adapter code, only a registry value. Locking, likewise, is keyed
  purely on `$target["user"]`, which every operation with a `user`
  parameter — read or write — already populates identically.
- **`mutation_state` derivation** — `not_attempted` for every
  pre-execution rejection path (uniform across all 7 distinct rejection
  reasons currently in `CommandAdapter`), `confirmed`/`unknown` purely
  from `exitCode === 0`. Nothing operation-specific.
- **`ProcessRunnerInterface`** — completely untouched since it was
  built; `domain.create` runs through the identical
  `ProcOpenProcessRunner`/`FakeProcessRunner` seam as the read
  operations, with no new capability required (no streaming, no stdin,
  no environment injection).

## Currently accidental (true only because of what 3 operations happen to look like)

- **`user` is assumed to be the ONE lock key, always in
  `$target["user"]`.** Every operation so far takes a Hestia system
  `user` as its first, required, owning-account parameter. Nothing in
  the registry format states "the lock key is whichever parameter is
  named `user`" as a declared contract — `CommandAdapter.php:286` reads
  the literal string `"user"` out of `$target`. An operation whose
  natural owner isn't called `user` (e.g. a future DNS-cluster or
  system-wide operation with no per-user owner at all, or one where the
  lock should key on something else, like a domain, for domain-scoped
  rather than user-scoped serialization) would silently either lock on
  nothing (today's `REGISTRY_ERROR` fail-closed, which is at least
  safe) or lock on the wrong identity if a future entry happened to
  reuse the name `user` for something else. **This is a naming
  convention doing load-bearing work without being declared as one.**
- **All three operations have exactly one required user-identity
  parameter and it is always caller-supplied, never fixed.** The
  `is_object_valid`-style existence/ownership checks that `bin/v-*`
  scripts perform are trusted uniformly — reasonable given they're
  Hestia's actual authority on this, but "the adapter never needs to
  know if a user exists" has only been tested for scripts where that
  check happens to live inside the wrapped script itself, which is true
  for these three but is a property of Hestia's CLI design, not a
  property this adapter enforces or falls back on if a future script
  omitted it.
- **`output_format` is a binary "json or nothing."** `domain.create`
  proved "nothing" works cleanly, but no operation has yet exercised
  `plain`, `csv`, or `shell` — all real Hestia output formats
  (`is_format_valid`'s `format` case: `'plain json shell csv'`). The
  parsing step's `if (($entry["output_format"] ?? null) === "json" ...)`
  would silently leave `parsed_output` null for a `plain`-format
  operation too, which is probably *correct* behavior but has not been
  decided or tested as a decision — it currently falls out of the code
  by accident of only two output modes having been tried.
- **`result_shape`'s two values (`single`/`collection`) are unproven
  against anything with pagination, streaming, or a shape that is
  neither one object nor one flat multi-key object** (e.g. Hestia list
  commands that return a JSON *array*, if any exist elsewhere in
  `bin/v-list-*`, weren't checked here — out of scope for this
  checkpoint, flagged as **Needs further investigation** before this
  field is trusted as a completed 2-value enum rather than "the two
  values seen so far").
- **Every fixed parameter observed so far is a compile-time string
  literal with no caller influence whatsoever.** No operation has yet
  needed a fixed value that is *computed* (e.g. derived from `$actor`,
  from server state, from another parameter) rather than a static
  string in `CommandRegistry.php`. If that need arises, `fixed_parameters`
  as currently typed (`array<string,mixed>` evaluated once at registry
  construction) cannot express it — it would need to become
  `array<string, mixed|callable>` or similar. Not urgent; flagged
  because it's the kind of change that historically becomes "just this
  once" special-casing if not planned for.
- **`actor` is pure metadata today — it is recorded on the result but
  never consulted for a decision.** All three operations run
  irrespective of who `$actor["user"]` claims to be; nothing compares
  `$actor` against `$target["user"]`, checks role/permission, or
  otherwise uses `$actor` to allow or deny anything. This has been
  invisible so far because every test constructs `$actor` and `$target`
  to agree, and because no operation currently NEEDS them to differ
  (e.g. an admin acting on behalf of a client user). This is the single
  largest "genericity" item that is actually an **authorization gap**,
  not a generality gap — see Section 4.

**Overall genericity verdict**: the mechanism (registry shape, argv
building, validation dispatch, locking, mutation-state, exit-code
mapping) is real and proven, not accidental. The accidental items above
are all in the *data model* around the mechanism (what a registry entry
is allowed to declare, what `actor` is allowed to mean), not in the
mechanism's control flow. That is the right kind of debt to have at this
stage — it means the next 2-3 operations should be chosen specifically
to stress these open questions (see Section 10) rather than more of the
same shape.

---

# 2. Registry Design

**Current state**: `CommandRegistry` is plain PHP, hand-written,
`final class` wrapping a `private array $operations` built in the
constructor. Its own docblock already documents this as a known,
deliberate deferral ("a language-neutral file format... has exactly one
consumer today... introducing a file-loading mechanism now would be
building ahead of demonstrated need").

**Should it move to a declarative format now?** No — but the reasoning
matters more than the answer, because the "no" has a specific, testable
trigger for becoming "yes," not an indefinite deferral.

Evaluated against each concern the task asked about:

- **Validation**: PHP arrays get zero structural validation today (a
  typo'd key like `"paramters"` would silently be `?? []`'d away rather
  than erroring). A JSON Schema (or equivalent) representation would
  let a registry entry be validated *as data* independent of
  `CommandAdapter`'s runtime behavior — a real advantage, but one that
  matters more once there are enough entries that a silent typo is a
  real risk (3 entries, all hand-reviewed in PRs, is not yet that
  regime).
- **Security**: no meaningful difference either way. The registry's
  security property (allowlisting, three-layer defense — see
  `ADAPTER_VERTICAL_SLICE.md` "How command injection is prevented") is
  about the registry being closed and enumerable, not about its file
  format. A JSON file checked into the same repo is exactly as trusted
  (or untrusted) as a PHP array in the same repo.
- **Code generation**: this is the strongest argument FOR a declarative
  format eventually — a JSON/YAML registry is what a future Go
  component, a CLI doc generator, or an OpenAPI-spec generator for API
  v2 would want to consume without re-deriving Hestia's argument
  contracts from PHP source. This is real, forward-looking value, not
  present-tense need.
- **Testing**: no difference — tests already exercise `CommandRegistry`
  as a black box (`->get()`/`->has()`), not its internal representation.
  `$additionalOperations` (the test-injection constructor param) would
  work identically against a JSON-array-decoded structure.
- **Future API v2**: a declarative registry is a natural place to also
  declare HTTP-facing metadata (path, method, which parameters are path
  vs. body vs. query) *without* that metadata living inside
  `CommandAdapter` or `CommandRegistry`'s PHP-execution concerns — see
  Section 3's "missing abstraction: an operation-to-HTTP mapping layer."
  This is a genuine motivating case, but it motivates a **separate**
  mapping structure, not necessarily migrating the execution registry's
  format.
- **Documentation generation**: same argument as code generation — real,
  not urgent.
- **Extensions**: this is the one place a declarative format starts to
  matter soon rather than eventually. If the Extensions Marketplace is
  meant to let third parties *declare* new operations without shipping
  PHP code, a JSON/YAML-schema'd registry entry is close to a
  requirement, not a nice-to-have — you cannot safely `eval()` or
  `include()` untrusted PHP as a registry entry, but you can safely
  parse and validate untrusted JSON against a schema before ever letting
  it reach `CommandAdapter`. **This is the concrete trigger**: the day
  a non-first-party author needs to add an operation, PHP-array registry
  entries stop being viable, full stop, on security grounds alone (not
  style/maintainability grounds).
- **Versioning**: a file-based registry (one file per operation, or a
  directory of them) diffs and versions more legibly in a marketplace
  context (approve/reject a single file) than a PHP array literal
  embedded in a larger class. Not a blocker today with 3 entries in one
  file; would matter well before "many entries."

**Recommendation**: keep PHP arrays for now (**KEEP**, see Section 10).
Do not build the JSON/YAML extraction speculatively. But treat "does an
Extensions Marketplace author need to add an operation without shipping
PHP" as the specific, unambiguous trigger to revisit this — not "the
registry got long" or "it would be cleaner." When that trigger arrives,
the migration is mechanical (the existing docblock already says this,
and nothing found in this checkpoint changes that assessment): extract
the array literal, define a JSON Schema matching the existing field set
plus whatever HTTP-mapping fields Section 3 identifies, and have
`CommandRegistry`'s constructor load + schema-validate the file instead
of hand-writing the array. The three real operations now give that
schema three concrete, structurally different examples to be designed
against, which two read-only examples alone would not have.

---

# 3. API v2 Readiness

**Target shape** (from the task): `POST /api/v2/domains`, `GET
/api/v2/domains`, `GET /api/v2/domains/{domain}`, `DELETE
/api/v2/domains/{domain}` — i.e., `domain.create`, `domain.list`,
`domain.get`, and a not-yet-built `domain.delete`, each behind an HTTP
verb+path instead of a PHP method call.

**What already lines up cleanly:**

- `AdapterResult`'s `status`/`exit_code`/`hestia_error_code`/
  `adapter_error_code`/`error_message` are already a reasonable basis
  for an HTTP status code + JSON error body mapping (e.g.
  `adapter_error_code=VALIDATION_FAILED` → 400,
  `hestia_error_code=E_NOTEXIST` → 404, `E_EXISTS` → 409, `LOCK_TIMEOUT`
  → 503/429). No new field is needed on `AdapterResult` for this — an
  HTTP layer would consume the existing fields and apply its own mapping
  table, entirely outside the adapter.
- `parsed_output` for `domain.get`/`domain.list` is already exactly the
  JSON body a `GET` response would want.
- The registry's `parameters` schema (`type`, `required`) is already
  close to what an HTTP request-body validator needs, modulo knowing
  which parameters are path params (`{domain}`) vs. body params (nothing
  in the registry says this today — see missing abstractions, below).

**What is missing — genuine gaps, not implemented here:**

1. **No operation-to-HTTP mapping layer.** Nothing today says
   "`domain.create` is `POST /api/v2/domains`, its `domain` parameter
   comes from the request body, its `user` parameter comes from session/
   auth context, not the body." This mapping is currently undecided even
   as a concept, let alone implemented. It must live in a layer ABOVE
   `CommandAdapter`/`CommandRegistry`, not inside either — see Section 8.
2. **No authentication/authorization layer.** `actor` is recorded but
   never checked (Section 1's "accidental" finding, restated here as an
   API-v2 blocker): an HTTP layer needs to resolve "who is this request
   from" and "is that identity allowed to act as `$target["user"]`"
   *before* calling `invoke()` — today `invoke()` would happily execute
   `domain.create` with `actor.user = "alice"` and `params.user =
   "bob"`, doing nothing to prevent alice from mutating bob's domains.
   This is fine for a single trusted internal PHP caller (today's
   reality) and NOT fine the moment `$actor` is derived from an
   externally-facing API credential. **This is the single largest gap
   for API v2 specifically**, larger than anything about the registry
   format.
3. **No pagination/filtering abstraction.** `GET /api/v2/domains`
   implies list semantics beyond "give me everything for this user"
   (page size, cursor, filter by status) — `domain.list`'s registry
   entry and `result_shape: collection` say nothing about this, because
   `bin/v-list-web-domains` itself has no pagination. This would need to
   be layered on top of (not inside) the adapter, likely as
   application-level slicing of the fully-materialized `parsed_output`
   until/unless it becomes a real performance problem.
4. **No idempotency-key concept for `POST`.** HTTP `POST
   /api/v2/domains` retried by a client (network timeout, etc.) would
   hit `domain.create` twice; today that surfaces as `E_EXISTS` on the
   retry, which is *survivable* but not the same as true idempotency
   (the client can't distinguish "my first request actually succeeded,
   this is a harmless retry" from "I tried to create a domain that was
   already someone else's"). Not built, not urgently needed at 3
   operations, worth deciding explicitly before API v2 ships `POST`.
5. **No versioning concept in the registry itself.** "`/api/v2/...`"
   implies a `v1` existed or a `v3` will — nothing in `CommandRegistry`
   distinguishes "this operation's contract as of registry version N."
   Not needed yet (there is only one registry), but worth naming as a
   gap before multiple HTTP API versions need to map to a registry that
   itself doesn't version.
6. **No rate limiting / quota-awareness at the adapter boundary.**
   `is_package_full` lives inside `bin/v-add-web-domain` and is trusted
   uniformly (Section 1) — fine for an internal caller, but an
   externally-facing API typically wants to reject over-quota requests
   BEFORE spawning a process, for cost/DoS reasons, not just correctness
   reasons. Out of scope for the adapter itself (see Section 9) but a
   real API-v2-layer requirement.

**Recommendation**: the `AdapterResult`/registry contract is a workable
foundation for the DATA layer of API v2 (what a successful/failed
operation looks like), but API v2 additionally needs an entirely
separate, not-yet-designed layer above the adapter for: routing/HTTP
mapping, authN/authZ, idempotency, and quota/rate-limiting. None of
these belong inside `CommandAdapter` (Section 8/9) — they are the actual
scope of "API v2" as a product, and this checkpoint's finding is that
the adapter is a necessary but explicitly not sufficient piece of it.

---

# 4. Security Model

**The chain, restated precisely**: an HTTP/PHP request → PHP process
running as `hestiaweb` → `CommandAdapter::invoke()` → array-form
`proc_open("/usr/bin/sudo", [scriptPath, ...argv])` → sudoers grants
`hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` (verbatim, from
`install/common/sudo/hestiaweb` line 4) → root-executed
`bin/v-add-web-domain` (or any other script matching that glob).

**Is the registry + typed validation currently the effective
command-level security boundary? Yes — unambiguously, and this is worth
being blunt about**: the OS/sudo layer enforces nothing at the
individual-command level. `hestiaweb` can run **any** file under
`/usr/local/hestia/bin/` as root via that sudoers wildcard, including
scripts nowhere in `CommandRegistry` (e.g. `v-delete-user`,
`v-restart-service`, anything). The wildcard was already flagged in
`ARCHITECTURE_REVIEW.md`'s original security findings, before this
adapter existed. What has changed with a real mutating operation now
built: **it is now concretely true, not hypothetical, that the
adapter's only defense against `hestiaweb` (or any code able to call
`CommandAdapter::invoke()`) running an unintended command is
`CommandRegistry::get($operation)` returning `null` for anything not
explicitly enumerated.** That check is real and it works (proven:
`UNKNOWN_OPERATION`, zero process spawns, tested for both read and
write operations) — but it is an **application-level allowlist**, not a
kernel-enforced one. If a bug ever let a caller reach
`$this->runner->run($this->sudoBinary, $arbitraryArgv)` directly
(there is currently no such bug — `invoke()` is the only path to
`$this->runner`, and it is `private`), the sudoers policy underneath
would not stop it. **The registry is the wall; sudo is not.**

**Second layer of the same finding — `domain.create` specifically
sharpened it**: for `domain.get`/`domain.list`, "the registry is the
wall" was already true but low-stakes (read-only, worst case an
information leak). `domain.create` makes the same fact
security-relevant in a new way: the registry entry's `fixed_parameters`
(`ip=""`, `restart="yes"`, `aliases=""`, `proxy_ext=""`) are themselves
now part of the trusted boundary — they are compile-time literals with
no caller path to them (confirmed, Section 1), so today they are safe.
But this means **any future registry entry that computes a "fixed"
value from anything caller-influenced (Section 1's flagged gap) would
silently punch a hole in the exact boundary this section just described
as the adapter's real security model.** This is worth stating as an
explicit constraint on registry authorship, not just an implementation
detail: *fixed_parameters must remain literal, or the registry stops
being a trustworthy allowlist.*

**What must eventually be hardened before exposing API v2 externally**
(not urgent for internal-only use, genuinely blocking for any
external-facing exposure):

1. **AuthZ, restated from Section 3**: nothing today stops an
   authenticated `actor` from mutating a `target.user` that isn't
   theirs. For an internal-only PHP caller this is equivalent to
   today's direct-`exec()` reality (also unchecked) — not a regression.
   For an external API, it is the single most important gap to close,
   and it belongs in a layer above `CommandAdapter`, not inside it (the
   adapter has no concept of "who is allowed to act as whom" and
   shouldn't grow one — see Section 8).
2. **The sudoers wildcard itself.** Narrowing
   `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` to an explicit list
   matching `CommandRegistry`'s enumerated scripts (or at minimum a
   `v-api-*`/similarly-namespaced subset) would convert "the registry is
   the wall" into "the registry AND the OS are both the wall" — real
   defense in depth, currently absent. This is genuinely two layers of
   moat instead of one; not attempted here per explicit instruction not
   to modify sudoers, but this checkpoint's job includes saying
   plainly: **this is a real hardening step to schedule, not a
   theoretical one**, and it gets more urgent, not less, the more
   operations are registered and the more external the caller becomes.
3. **Rate limiting / abuse controls** at whatever layer eventually
   terminates external HTTP requests (Section 3) — the adapter's lock
   provides *correctness* under concurrency, not protection against a
   caller deliberately hammering `domain.create` to exhaust quota or
   disk.
4. **Audit persistence** (already a known, named gap since the first
   vertical slice — `AdapterResult` carries everything an audit record
   needs but nothing writes it anywhere). More urgent for an
   externally-facing surface than an internal one, where Hestia's own
   `log_event`/`v-log-action` (still running, unmodified, inside every
   wrapped script) is the only current audit trail.

**Recommendation**: the current security posture is honest and
consistent for an **internal-only** caller (which is exactly what
exists today — nothing calls the adapter from outside PHP process
space). It is explicitly not sufficient for external exposure without
(1) an authZ layer above the adapter and (2) ideally, narrowing the
sudoers wildcard. Neither is a reason to distrust the mechanism built so
far; both are reasons API v2 cannot be "just add an HTTP router in front
of `CommandAdapter`."

---

# 5. Concurrency

**What the lock actually guarantees today, precisely**: two
`CommandAdapter::invoke()` calls for the same `user`, where at least one
declares a mutating `kind`, are serialized — proven with real
cross-process `flock` (`LockManagerTest`), not just in-process
bookkeeping. This is real and correctly scoped (per-user, not global,
not per-domain — matching `WRITE_OPERATION_DESIGN.md`'s decision).

**The legacy bypass, made concrete now that `domain.create` is real**
(this was already documented abstractly in `LOCK_IMPLEMENTATION.md`;
`domain.create`'s existence lets it be stated as a specific, live race
rather than a hypothetical one):

- `web/add/web/index.php` — the existing, unmodified production UI for
  creating a web domain — calls `v-add-web-domain` via direct `exec()`,
  entirely outside `CommandAdapter`, hence entirely outside
  `LockManager`.
- `web/inc/main.php`/`web/api/index.php` — the general-purpose direct
  `exec()` call sites documented in `ARCHITECTURE_REVIEW.md` from the
  very first pass — same story for any other Hestia mutation, not just
  domains.
- Direct CLI execution (an operator SSH'd in running `v-add-web-domain`
  by hand, or a cron job, or another script) — same story, and
  categorically un-interceptable from PHP at all.

**The concrete race this enables today**: a request through
`CommandAdapter::invoke("domain.create", ...)` and a form submission
through `web/add/web/index.php` for the **same user, same or different
domain**, arriving concurrently, are NOT serialized against each other.
Both can be mid-flight inside `bin/v-add-web-domain` at once — meaning
the exact `is_package_full('WEB_DOMAINS')` check-then-act race
identified back in `ARCHITECTURE_REVIEW.md`'s original "Verified Open
Questions" (before either the adapter or the lock existed) **is still
fully exploitable today, right now, via the legacy path, even though a
domain.create call routed only through the new adapter is safe against
itself.** This is not a regression introduced by this checkpoint's
subject matter — it is the same limitation already named in
`LOCK_IMPLEMENTATION.md`, restated here with the specific pairing
(`web/add/web/index.php` vs. adapter-routed `domain.create`) that makes
it concrete rather than abstract.

**What must happen before the adapter can honestly claim concurrency
safety for domain mutations** (not attempted here, per explicit
instruction not to migrate callers):

1. **Migrate `web/add/web/index.php`** to call
   `CommandAdapter::invoke("domain.create", ...)` instead of its direct
   `exec()`+`quoteshellarg()` call. This is the single highest-value
   migration, because it is the one legacy caller that maps 1:1 onto an
   operation that already exists and is already tested — no new
   registry entry needed, only a call-site change.
2. **Until that migration happens, "concurrency safety" must be scoped
   explicitly to "operations routed through the adapter" in every
   external-facing claim** — this is already how `LOCK_IMPLEMENTATION.md`
   phrases it, and this checkpoint's finding is that this scoping
   remains accurate and load-bearing, not a hedge that's become
   unnecessary now that a real operation exists.
3. Any future write operation added to the registry inherits this same
   caveat automatically (the lock generalizes; the legacy-bypass problem
   generalizes right alongside it, for exactly as long as its
   corresponding legacy PHP caller is left unmigrated).

**Recommendation**: do not treat the lock as "done" in a product sense
until at least the highest-traffic legacy caller per mutating operation
is migrated. Treat "lock exists and is tested" (true today) and
"concurrency is actually safe for this operation in production" (false
today, for `domain.create`, because of `web/add/web/index.php`) as two
different claims, and don't conflate them in future documentation or
in any API v2 marketing/status language.

---

# 6. `domain.create` Contract Critique

**The decision**: expose only `user`/`domain`; fix `ip=""`,
`restart="yes"`, `aliases=""`, `proxy_ext=""`.

**Was this appropriate for what it was — a vertical-slice proof that the
architecture handles a mutating operation?** Yes, unambiguously. It
minimized surface area, required zero new validators, and let the
"does the mechanism generalize" question be answered cleanly without
also having to design an IP-selection API, an alias-list API, or a
proxy-extension API at the same time.

**Is it appropriate for a future API v2?** No, not as-is, and this is
worth being direct about rather than hedging:

- **`ip=""` (auto-select) is a real product limitation, not just a
  scope trim.** A hosting control panel where the caller cannot choose
  which of a server's IPs a new domain binds to is missing something
  real operators need (multi-IP servers, specific IP-per-domain
  requirements, SSL/IP-based routing considerations pre-SNI-only setups,
  etc.) — and notably, the ONE existing production caller
  (`web/add/web/index.php`) treats IP selection as a *required form
  field*, not optional. API v2's `POST /api/v2/domains` will need `ip`
  as a real, caller-supplied, optional-with-sensible-default parameter
  well before it's a credible replacement for that form.
- **`restart="yes"` being unconditional is more defensible** — an API
  caller almost always wants their change to take effect, and "create a
  domain but don't actually apply the config" is a niche enough need
  that keeping this fixed (or, longer-term, allowing an explicit opt-out
  for batch/scripted creation flows) is a reasonable default either way.
- **`aliases=""`/`proxy_ext=""` (script defaults) are the most
  defensible of the four to leave fixed long-term** — they are
  genuinely secondary concerns for a create operation's MVP, and
  Hestia's own defaults for both are sensible for the common case.
  Exposing them later is low-risk, low-urgency.

**Is the current registry mechanism ready to support exposing `ip` (or
any of the other three) later?** Yes, cleanly — Section 1 already
established that `fixed_parameters` values are pure data with no code
dependency on which four happen to be fixed today. Moving `ip` from
`fixed_parameters` to `parameters` requires: (a) a new `ip` type in
`ParameterValidator`/`typeValidators` (not yet written — `func/main.sh`'s
`is_ip_format_valid` is the model to mirror, same pattern as
`isValidUsername`/`isValidDomain`), and (b) the registry entry change
itself. Zero `CommandAdapter.php` changes required — this was already
proven by how cleanly `domain.create` itself was added.

**Recommendation**: keep the current fixed values for now (**KEEP**,
Section 10) — they are not wrong, they are intentionally incomplete for
a still-internal-only, mechanism-proving operation. Flag `ip` exposure
specifically (not the other three) as the concrete, named gap to close
**before** `domain.create` is presented as API v2's `POST
/api/v2/domains`, since that is the one place today's default
meaningfully diverges from what the existing production UI already
requires of users.

---

# 7. Result Semantics

**The model**: `not_attempted` / `confirmed` / `unknown`, derived purely
from (a) whether the process was ever spawned and (b) its exit code.

**Is this sufficient for API consumers, or does it need the richer
6-way split the task poses** (validation failure / rejected before
mutation / definitely succeeded / possibly succeeded / definitely failed
/ infrastructure-adapter failure)?

Walking through each of the task's six proposed distinctions against
what's actually already recoverable from the CURRENT result, without
changing anything:

1. **Validation failure** — already fully distinguishable today via
   `adapter_error_code` (`VALIDATION_FAILED`, `UNEXPECTED_PARAMETER`,
   `MISSING_PARAMETER`, `UNKNOWN_PARAMETER_TYPE`). `mutation_state:
   not_attempted` is the coarse bucket; `adapter_error_code` is already
   the fine-grained answer. **No gap.**
2. **Command rejected before mutation, for a non-validation reason**
   (lock timeout, lock unavailable, registry error, unknown operation) —
   also already fully distinguishable via `adapter_error_code`
   (`LOCK_TIMEOUT`, `LOCK_UNAVAILABLE`, `REGISTRY_ERROR`,
   `UNKNOWN_OPERATION`), all still bucketed under `mutation_state:
   not_attempted` (or `null` for `UNKNOWN_OPERATION`). **No gap** — this
   is really the same distinction as #1, generalized: "why wasn't it
   attempted" is answerable, "was it attempted" already is too.
3. **Mutation definitely succeeded** — `mutation_state: confirmed`,
   `status: ok`, `exit_code: 0`. **No gap.**
4. **Mutation possibly succeeded** (i.e., partial completion) — **this
   is the real, not-yet-answerable question**, and it's the one the task
   is right to press on. `mutation_state: unknown` currently covers BOTH
   "the script failed before touching any state at all" (e.g. `E_LIMIT`,
   which fires during the "Verifications" section, before "Action")
   **and** "the script definitely wrote state and then failed on a
   later step" (`E_RESTART`, which — per `DOMAIN_CREATE_IMPLEMENTATION.md`
   "Service Reload / Failure Semantics" — fires only AFTER `web.conf` was
   already appended to). These are genuinely different situations for an
   API consumer: the first means "nothing to clean up, safe to retry as
   a fresh create"; the second means "a domain now exists in `web.conf`
   with an unclear reload state — retrying `domain.create` will hit
   `E_EXISTS`, not a clean retry." **`unknown` conflates two answers
   that matter differently to a caller deciding what to do next.**
5. **Mutation definitely failed** (i.e., provably zero effect) — this is
   the flip side of #4's gap: today there is no way to say "definitely
   nothing changed" for a non-zero exit, even in cases where — as this
   very implementation pass discovered by reading the source — it is
   knowable FOR A SPECIFIC SCRIPT AND EXIT CODE (e.g. `E_EXISTS` on
   `domain.create`, confirmed to fire during "Verifications," strictly
   before "Action" begins). The adapter's *generic* model correctly
   refuses to claim this (per this task's own prior, explicit
   instruction: "do not claim that a non-zero exit code proves partial
   mutation" — and the inverse, claiming definite non-mutation, has the
   same evidentiary problem in the other direction unless it's backed by
   real, script-specific knowledge). **This is a real gap, but closing
   it generically is not possible from exit code alone — it requires
   either script-specific knowledge the adapter doesn't have, or an
   independent state-verification step (e.g. re-querying
   `bin/v-list-web-domain` after a failure) that no operation performs
   today.**
6. **Infrastructure/adapter failure** (i.e., the adapter itself broke,
   not Hestia) — **partially covered, partially not.** `LOCK_UNAVAILABLE`
   is exactly this (the locking mechanism failed, not the domain logic).
   But a `ProcessRunnerInterface::run()` exception (e.g. `proc_open()`
   itself failing to spawn) is NOT captured as an `AdapterResult` at
   all — it propagates as a raw PHP exception (deliberately, per
   `CommandAdapter`'s documented "every EXPECTED failure is an
   AdapterResult" contract, tested explicitly in
   `MutatingOperationTest::testLockReleasedAfterException`). This is a
   defensible design choice (an adapter that can't spawn processes has a
   deeper problem than any single operation call), but it means
   "infrastructure failure" is currently split across two different
   channels (an `AdapterResult` for lock failures, a thrown exception
   for spawn failures) rather than being one unified concept — worth
   deciding deliberately whether API v2's HTTP layer needs a single
   place to catch both, or whether keeping them separate (result vs.
   exception) is actually the right signal to preserve (an exception
   really is categorically different from "the operation ran and told
   us something").

**Recommendation**: the current 3-value model is not wrong for what it
promises — it is honest, and "honest but coarse" is a legitimate,
deliberate choice this task's own predecessor document
(`WRITE_OPERATION_DESIGN.md`) made explicitly and correctly (rejecting
`partial_failure` as a claim the adapter can't back up generically was
the right call and should not be reversed by adding a
`partial_failure`-shaped state through the back door). What SHOULD
change eventually, once there is a second and third mutating operation
to design this against (not from `domain.create` alone): a **fourth
mutation_state value distinguishing "failed with config as of this
version could not possibly have written any state" (pre-Action-section
failures, script-verifiable per operation) from today's catch-all
`unknown`** — call it something like `unknown_pre_action` vs. leaving
truly ambiguous failures (like `E_RESTART`) as `unknown`. This requires
per-operation, per-exit-code metadata in the registry (e.g. "these exit
codes are known to occur only before Action begins") — a real,
bounded, additive registry-schema change, not a rewrite, and one this
checkpoint recommends **designing after `domain.delete` exists**, so the
schema is built against two real write operations' actual exit-code
behavior instead of guessed from one.

---

# 8. Product Boundary

**The question**: does the adapter stay a thin compatibility/execution
layer beneath future services, or does business logic start moving into
it?

**Answer: stays thin. This is not a close call, and nothing found in
this checkpoint suggests reconsidering it** — but it's worth being
explicit about WHERE the line is, since "thin" is meaningless without
naming what's on each side.

**Belongs in the adapter (present or future), because it is about
*safely executing a known Hestia command*:**

- Registry entries (what scripts exist, their argument contracts).
- Shape-only parameter validation (is this a well-formed username, not
  "does this username exist" — that split is already correct and
  proven).
- argv construction, process execution, stdout/stderr/exit-code capture.
- Per-user locking for the duration of a mutating command.
- Exit-code → Hestia-error-code mapping (this is translation, not
  business logic — it's a lookup table over a convention Hestia itself
  defines in `func/main.sh`).
- Mutation-state derivation (confirmed/unknown/not_attempted) — this is
  a property of "did this specific process run and how did it exit,"
  not a business decision.

**Does NOT belong in the adapter — belongs in a layer above it:**

- **Authorization** (who may act as whom) — Section 4/8's biggest named
  gap. This is a product/business concept (roles, ownership, delegation)
  that has nothing to do with "how do I safely run
  `v-add-web-domain`."
- **HTTP routing/request-shape mapping** (Section 3) — path params vs.
  body params, HTTP status code selection, pagination — all
  presentation-layer concerns about ONE possible consumer (an HTTP API)
  of the adapter, not properties of the adapter itself. A future Cloud
  Account service or CLI tool consuming the same adapter should not have
  to reason about HTTP semantics baked into `CommandAdapter`.
- **Extensions Marketplace concerns** (discovery, installation,
  trust/signing of third-party registry entries, versioning,
  billing/entitlements) — these are about *what operations exist and
  who may install them*, layered entirely on top of "given a trusted,
  already-vetted registry entry, execute it safely," which is all the
  adapter does or should do.
- **Docker support, S3/FTP backups, and other future integrations** —
  these are new CAPABILITIES (new scripts, possibly new registries, quite
  possibly not even `v-*`-shaped at all — e.g. calling a Docker socket
  API directly rather than a Bash script) that should be modeled as
  their OWN, separate execution concerns, potentially reusing the
  `ProcessRunnerInterface`/registry PATTERN but not necessarily living in
  the SAME `CommandRegistry`/`CommandAdapter` instance if their
  execution model differs meaningfully from "shell out to a `v-*`
  script." This checkpoint takes no position on whether they'd literally
  share a class — that's a future design decision, not something 3
  domain operations give enough evidence to decide today.
- **Idempotency-key handling, rate limiting, quota pre-checks for
  cost/DoS reasons** (Section 3) — these are HTTP/API-surface concerns
  about protecting a public endpoint, not about safely running a Hestia
  command once you've already decided to run it.

**The dividing line, stated as a single sentence**: *the adapter's job
ends at "safely and honestly run one already-vetted, already-authorized
Hestia command and report what happened" — everything about deciding
WHETHER a given caller is allowed to ask for that, WHAT HTTP/CLI/
marketplace shape the request arrived in, and WHAT new capability
(Docker, S3, etc.) might need a wrapper it doesn't have yet belongs in
layers that call the adapter, never inside it.*

---

# 9. What NOT to Build (Yet)

Confirmed, with reasoning specific to what 3 real operations have
(and haven't) shown, not just repeated from earlier documents:

- **A generic transaction/rollback engine.** `WRITE_OPERATION_DESIGN.md`
  already rejected this for `domain.create`'s single-script case;
  nothing about having a real mutating operation now changes that
  answer — `bin/v-add-web-domain` has no compensating "undo" script for
  most of its steps (there's no way to "un-append" a `web.conf` line
  transactionally against a mid-write crash), so a rollback engine would
  have nothing correct to invoke even if built.
- **A workflow/orchestration engine.** No operation so far chains
  multiple `v-*` scripts at the ADAPTER level — `domain.create` calling
  `v-restart-web`/`v-restart-proxy` internally is entirely inside the
  wrapped Bash script, invisible to and unmanaged by the adapter. Until
  a real product need requires the ADAPTER (not a Bash script) to
  sequence multiple operations with its own success/failure semantics
  between them, building this is pure speculation.
- **A generic exec/runRaw API.** Still correctly absent; still the
  adapter's core security property (Section 4). Nothing found here
  weakens the case against it — if anything, `domain.create` proves the
  registry-driven approach absorbs even complex, multi-slot, mutating
  scripts without needing an escape hatch, which is the strongest
  argument yet for never adding one.
- **Operation-specific branching in `CommandAdapter`.** Zero exists
  today (verified: `domain.create` required none). Keep it that way as
  a hard rule for the next several operations — the day a genuinely
  operation-specific `if` becomes unavoidable is the day to stop and ask
  whether the registry's DATA model is missing a field, not whether
  `CommandAdapter` needs a branch.
- **Premature abstraction layers for HTTP/marketplace concerns.**
  Section 3/8 named the real, missing abstractions (authZ, HTTP mapping,
  idempotency) — naming them is not the same as building them yet.
  Building an HTTP-router-shaped thing, an auth-token-shaped thing, or a
  marketplace-manifest-shaped thing NOW, before API v2 or the
  Marketplace has an actual first consumer, would be exactly the kind of
  speculative layer `ARCHITECTURE_ADAPTER_DESIGN.md` already correctly
  warned against for the registry file format.
- **API HTTP concerns inside the adapter.** Restated from Section 8 as
  its own explicit "do not build": no status-code mapping table, no
  request-body parsing, no route definitions, anywhere under
  `web/inc/adapter/`.
- **Marketplace concerns inside the adapter.** Same — no
  installation/trust/signing/entitlement logic under
  `web/inc/adapter/`, ever, regardless of how tempting it is to bolt
  "and this operation came from an extension" onto a registry entry
  someday. That's a property of WHERE a registry entry came from, which
  is the marketplace layer's job to track, not the adapter's.
- **A richer `mutation_state` enum, right now.** Section 7 identified a
  real future need (distinguishing pre-Action-section failures from
  genuinely ambiguous ones) but explicitly recommends waiting for
  `domain.delete` to exist before designing that schema change — one
  data point (`domain.create`'s `E_EXISTS`/`E_RESTART`) is not enough to
  design a general schema against without guessing.
- **IP/alias/proxy_ext exposure on `domain.create`, right now.** Section
  6 named `ip` specifically as worth doing before API v2, but "before
  API v2" is not "right now" — no consumer needs it yet, and adding it
  speculatively ahead of a concrete caller repeats the exact pattern
  `ARCHITECTURE_ADAPTER_DESIGN.md` warned against elsewhere.

---

# 10. Final Decision

## KEEP

- **`CommandAdapter`'s single-entry-point, no-`exec()`/`runRaw()`
  design.** Proven, three times over now, to absorb structurally
  different operations (2-arg read/single, 1-arg read/collection, 6-arg
  write/none) without an escape hatch. This is the adapter's core
  security property and its core evidence of genuine generality — do
  not compromise it for convenience on any future operation, however
  tempting a "just this once" raw-argument path might look.
- **The registry as plain PHP arrays, for now**, per Section 2's
  specific trigger (a non-first-party Marketplace author needing to
  declare operations) rather than a vague "it'll get big eventually."
- **Per-user, `flock`-based locking exactly as built**, per Section 5 —
  the mechanism is real and correctly scoped; what's incomplete is
  caller MIGRATION, not the lock itself.
- **The 3-value `mutation_state` model, unchanged, for now** — Section 7
  confirms it is honest rather than wrong, and confirms the
  `WRITE_OPERATION_DESIGN.md` decision to reject `partial_failure` was
  correct and should not be walked back reactively.
- **`domain.create`'s current minimal parameter surface**
  (`user`/`domain` only) — Section 6 confirms this was the right choice
  for a mechanism-proving pass and remains defensible as a live default,
  with one specific, named exception below.
- **The strict separation of "adapter" from "HTTP/authZ/marketplace"**
  (Section 8) as an ongoing rule for every future addition, not a
  one-time decision.

## CHANGE LATER

- **Expose `ip` as a real, caller-supplied, optional parameter on
  `domain.create`**, before presenting it as API v2's `POST
  /api/v2/domains` — Section 6. Requires a new `ParameterValidator`
  method and a registry-entry change; zero `CommandAdapter` change
  expected, based on how cleanly every other parameter addition has
  gone so far.
- **Design a richer `mutation_state` distinction** (pre-Action-section
  failure vs. genuinely ambiguous failure) — Section 7 — but explicitly
  AFTER `domain.delete` exists, so it's designed against two real write
  operations' exit-code behavior, not guessed from one.
- **Migrate `web/add/web/index.php`** to call the adapter's
  `domain.create` — Section 5 — the single highest-value legacy-caller
  migration, because it already maps 1:1 onto a tested, existing
  operation; no new registry work required, only the call-site change
  (still not attempted in this checkpoint, per its own scope).
- **Narrow the `hestiaweb` sudoers wildcard** to match
  `CommandRegistry`'s enumerated scripts — Section 4 — real defense in
  depth, not urgent for internal-only use, increasingly worth doing as
  more operations are registered and especially before any external
  exposure.
- **Extract the registry to a declarative (JSON/YAML) format** — Section
  2 — specifically once a Marketplace author (not a first-party
  developer) needs to declare an operation, not before.
- **Declare an explicit lock-key contract in the registry schema**
  (Section 1's "`user` as lock key is a naming convention, not a
  declared contract") — e.g. a `"lock_key": "user"` field, so a future
  operation whose owning identity isn't literally named `user` doesn't
  have to either invent a same-named parameter or hit `REGISTRY_ERROR`.
  Small, additive, worth doing whenever the first operation that needs
  it arrives.

## BLOCKING ISSUES

*(For any external-facing / API v2 exposure specifically — none of
these block continuing to add internally-consumed operations the way
`domain.create` was added.)*

- **No authorization layer exists anywhere in this system.**
  `$actor` is recorded, never checked. This is the single largest gap
  identified across this entire checkpoint (Sections 1, 3, 4, 8 all
  converge on it independently). It is not a defect in what was built —
  nothing asked for it yet, and today's only caller is fully-trusted
  internal PHP — but it is an absolute precondition for API v2, Cloud
  Account, or any externally-reachable consumer of this adapter. Must be
  designed and built as its own layer before any HTTP surface is
  exposed, full stop.
- **The `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` sudoers policy
  means the registry allowlist is currently the ONLY command-level
  security boundary** (Section 4) — real and functioning today, but a
  single-point-of-failure design for anything externally exposed. Not
  blocking for continued internal development; blocking for treating
  this adapter as "production-ready for an external API" without either
  narrowing the sudoers policy or accepting and documenting this as a
  deliberate, reviewed risk.
- **The legacy-bypass concurrency gap is real and specific, not
  theoretical**, as of `domain.create` existing (Section 5): an
  adapter-routed `domain.create` and a `web/add/web/index.php` form
  submission for the same user are not serialized against each other
  today. Any claim that "the adapter provides concurrency safety for
  domain mutations," made without the "...for adapter-routed callers
  only" qualifier, would be false as stated. Not blocking for continued
  internal adapter development; blocking for any external
  claim/documentation/marketing about concurrency guarantees until at
  least the corresponding legacy caller is migrated.
