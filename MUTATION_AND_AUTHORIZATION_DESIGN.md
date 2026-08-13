# Mutation and Authorization Design

Design only. No source code was modified to produce this document. No
new operation was added. No API v2, no authorization, no registry
change was implemented.

**Purpose**: `domain.create` and `domain.delete` are now two independent,
real, source-verified mutating operations. This document turns the
evidence they produced into a final design for the two issues
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` deliberately deferred until a
second mutation existed: a richer mutation-result model, and the
authorization boundary. Both are designed here; neither is built here.

---

# Part 1 — Mutation Result Semantics

## The evidence

| | `domain.create` | `domain.delete` |
|---|---|---|
| Script | `bin/v-add-web-domain` | `bin/v-delete-web-domain` |
| Exit code with a confirmed post-mutation meaning | `E_RESTART` (20) | `E_RESTART` (20) |
| What already happened by the time that code fires | `web.conf` line appended, vhost/proxy config written, directories created | `web.conf` line removed, directories `rm -rf`'d, vhost/proxy config removed |
| Is that prior state change reversible from the adapter's perspective? | Yes in principle (a subsequent `domain.delete` undoes it) | **No** — data is gone (`rm -rf`, no soft-delete, no trash) |

Both scripts share the identical structural shape: **the mutating steps
run to completion with no error-checking between them, and the very
last steps are three independent service-restart calls, each guarded by
`check_result`.** This is not a coincidence specific to domains — it is
how `func/main.sh`'s established pattern of "mutate everything, then
restart services, then log" works across Hestia's CLI in general. That
generality matters: whatever model is designed here should be expected
to recur for every future write operation that follows this same shape,
not treated as a one-off fix for these two scripts.

## Critiquing the prompted five-value model

The candidate values offered for evaluation:
`not_attempted / confirmed / failed_pre_mutation /
confirmed_post_mutation_failure / unknown`.

- **`not_attempted`** — keep, unchanged. Already correctly means "the
  underlying process never spawned." No critique.
- **`confirmed`** — keep, unchanged. Already correctly means "exit 0."
  No critique.
- **`unknown`** — keep as the *default*, not eliminate it. It is the
  necessary fallback for every non-zero exit the registry has not
  explicitly classified. Renaming or removing it would either force
  every exit code to be classified (unrealistic and risky — see below)
  or silently reintroduce the exact "guess from an exit code" problem
  `WRITE_OPERATION_DESIGN.md` already correctly rejected once.
- **`failed_pre_mutation`** — **reject this one specifically**, and the
  reasoning is the central finding of this Part. Adding it requires the
  registry to make a POSITIVE claim: "this exit code is known to occur
  before any mutation." That claim is categorically riskier than its
  mirror image (below), for a concrete reason: `bin/v-add-web-domain`'s
  `E_EXISTS` and `bin/v-delete-web-domain`'s `E_NOTEXIST` both *happen*
  to fire during each script's "Verifications" section today — but nothing
  enforces that a future revision of either script couldn't move a check,
  add a new early side effect, or reorder a step. A registry entry
  claiming `failed_pre_mutation` for a given exit code is a claim about
  *the entire remaining lifetime of a script this adapter does not
  control and does not modify* — exactly the kind of claim
  `WRITE_OPERATION_DESIGN.md` warned against making from exit codes
  alone, now being reintroduced through a different door (registry
  metadata) rather than adapter logic. If that claim is ever wrong, the
  failure mode is a caller being told "definitely nothing happened" when
  something in fact did — a false negative with real operational
  consequences (skipped cleanup, unsafe retry). This is a strictly worse
  failure mode than staying in `unknown`, which never asserts anything
  false, only asserts less than it could.
- **`confirmed_post_mutation_failure`** — **reject the name, keep the
  underlying concept.** The name reads badly: "confirmed" followed
  immediately by "failure" invites misreading as "we confirmed that it
  failed," when the intended meaning is closer to "the mutation is
  confirmed; a subsequent, independent step failed." The concept itself
  — a state for "the core mutation is known-complete, but the operation
  as a whole still exited non-zero" — is exactly right and is the one
  genuinely missing piece. It needs a name that keeps "confirmed" as the
  claim about the *mutation* and clearly marks the *degraded* nature of
  the overall outcome without implying the mutation itself is in doubt.

## The recommended model — four values, not five

```
not_attempted        — process never spawned (unchanged)
confirmed             — exit 0 (unchanged)
confirmed_degraded    — process spawned, exited non-zero, AND the exact
                        exit code is explicitly declared by the registry
                        entry as one that occurs only after this
                        operation's core mutation is durably complete
unknown               — everything else non-zero (unchanged as the
                        default; now used ONLY for exit codes the
                        registry has not classified)
```

**Why four, not five**: the asymmetry argued above. The model adds
exactly one new state, and only in the direction that is safe to be
wrong about — a registry author who is TOO CONSERVATIVE about declaring
`confirmed_degraded` costs nothing beyond an operation staying in the
already-safe `unknown` bucket a little longer than strictly necessary. A
registry author who is too conservative about a hypothetical
`failed_pre_mutation` costs nothing either — but a registry author who
is WRONG about `failed_pre_mutation` produces a false safety claim,
while a registry author who is wrong about `confirmed_degraded` merely
produces an ambiguous-in-the-wrong-direction claim (telling a caller
"the mutation might have failed to fully apply" when actually it did) —
still not ideal, but not a claim that would cause a caller to skip
recovery/verification steps they should have taken. The model is
deliberately asymmetric because the risk of the two possible mistakes is
asymmetric.

**Why not name it `confirmed_post_mutation_failure`, `mutated_incomplete`,
or similar**: `confirmed_degraded` was chosen over several considered
alternatives, listed here so the choice is auditable, not asserted:

- `confirmed_post_mutation_failure` (the prompted name) — rejected,
  reads as "confirmed [that it] failure[d]," ambiguous on first read.
- `mutated_incomplete` — rejected, actively wrong: the mutation is NOT
  incomplete (per the source evidence, it fully completed in both
  scripts examined); it is the surrounding operation (config write +
  service reload) that is incomplete, not the mutation itself.
- `confirmed_side_effect_failed` — considered, accurate, but longer and
  no clearer than the chosen name for equivalent information.
- **`confirmed_degraded`** (chosen) — "confirmed" carries the same
  meaning it already carries in `confirmed` (the mutation itself
  happened), "degraded" is a single, precise word for "did not reach a
  fully clean end state" without asserting WHAT specifically is
  degraded (that detail belongs in `hestia_error_code`/`error_message`,
  already present on the result — the mutation_state value's job is to
  answer one question honestly, not to duplicate the detailed error).

## The non-negotiable constraint, restated and enforced by design

The adapter must derive `confirmed_degraded` **only** by checking
membership of the actual exit code in a list the REGISTRY ENTRY
declares — never by any hardcoded `if ($exitCode === 20)` anywhere in
`CommandAdapter`. This is not a stylistic preference; it is the same
allowlist-not-inference principle already governing every other part of
this architecture (the operation registry itself, `fixed_parameters`,
locking's mutation-kind check). Concretely, the derivation rule the
adapter would apply is exactly one generic line of logic:

```
if exitCode in ($entry["mutation"]["<declared list>"] ?? [])
    then confirmed_degraded
    else unknown
```

— which is exactly as data-driven as today's existing
`$isMutating = $mutationKind !== "read"` check, and requires no new
concept in `CommandAdapter` beyond "read one more optional array out of
the resolved registry entry." See Part 2 for the field itself.

---

# Part 2 — Registry Metadata for Mutation Outcomes

## Should the registry declare this at all?

**Yes.** The alternative — inferring `confirmed_degraded` from
`hestia_error_code` names directly in `CommandAdapter` (e.g. "if the
mapped code is E_RESTART, it's degraded") — would be exactly the kind of
operation-*category* hardcoding this whole exercise is meant to avoid: it
would silently assume every future operation's `E_RESTART` means the
same thing `domain.create`'s and `domain.delete`'s happen to mean today,
without anyone having verified that for the new operation. A future
script that, hypothetically, calls a restart BEFORE writing its own
config (unlikely given the pattern observed, but not something this
adapter should assume without checking) would be silently
misclassified. The registry entry is the only place that already
carries "this specific script's specific, source-verified behavior" —
it is the correct owner of this fact, exactly as it is already the
correct owner of `argument_order` and `fixed_parameters`.

## What should the field be called, and what should it contain?

**Rejecting the prompted example name**: `post_mutation_errors` is close
but ambiguous — "errors" could be misread as "every error this
operation might raise," when the field must contain only the SUBSET the
registry author has positively verified occurs after mutation. Renaming
the concept to make that verification explicit in the name itself
reduces the chance of a future contributor populating it carelessly.

**Recommended name and shape**:

```
"mutation" => [
    "kind" => "delete",
    "known_post_mutation_exit_codes" => ["E_RESTART"],
]
```

Design decisions within this shape, each considered against an
alternative:

- **Symbolic names (`"E_RESTART"`), not raw integers (`20`)** — chosen
  over the prompt's own literal example. `CommandAdapter` already
  maintains a single, authoritative `HESTIA_EXIT_CODES` translation
  table (int → name); every other place in this codebase that talks
  about a Hestia exit code (documentation, `hestia_error_code` on
  `AdapterResult`, this very document) uses the symbolic name, never the
  bare integer. Requiring symbolic names here means a registry author
  writes something self-documenting (`E_RESTART` reads as "restart
  failed" without cross-referencing a table), and means the SAME
  translation table used for `hestia_error_code` is the single source of
  truth resolving both directions — no second, parallel int-based
  vocabulary is introduced.
- **A list, not a single value** — a script could plausibly have more
  than one exit code that occurs only post-mutation (none of the two
  operations examined so far do, but nothing in the architecture should
  assume exactly one).
- **Nested under `mutation`, not a top-level registry field** — this is
  metadata ABOUT the mutation, exactly like `kind` already is; keeping
  it colocated keeps the registry's "everything about whether/how this
  operation mutates state" concern in one place, matching the existing
  precedent of keeping `mutation.kind` minimal-but-together rather than
  scattering related fields.
- **Opt-in, defaulting to an empty list** — an operation that declares
  nothing here behaves EXACTLY as today (every non-zero exit is
  `unknown`). This is a strictly additive, backward-compatible field:
  `domain.get`/`domain.list` (read-only, no `mutation.kind` relevance at
  all) and any future mutating operation whose author simply doesn't
  populate this field all continue to work unchanged.

## Should exit codes, symbolic Hestia errors, or "mutation outcome" be represented?

All three already exist somewhere in the model, at different layers,
and should stay separated exactly as they are:

- **Raw exit code** (`exit_code` on `AdapterResult`) — the ground truth,
  always present, never interpreted.
- **Symbolic Hestia error** (`hestia_error_code`, e.g. `E_RESTART`) — a
  *translation* of the exit code into Hestia's own naming convention,
  already generic and operation-agnostic (the same table serves every
  operation).
- **Mutation outcome** (`mutation_state`, this Part's new
  `confirmed_degraded` value) — an *interpretation* specific to ONE
  registry entry's declared knowledge, correctly the only one of the
  three that needs per-operation metadata, because it is the only one of
  the three that is actually a claim about a specific script's specific
  behavior rather than a universal Hestia convention.

This three-layer separation (raw fact → universal translation →
per-operation interpretation) is already implicitly how the model works
today; this Part's recommendation keeps that separation intact rather
than collapsing any two of the three layers together.

## Security

**The core invariant, stated explicitly because it is the one this
Part is most at risk of getting wrong**: `known_post_mutation_exit_codes`
must be sourced ONLY from the trusted, application-authored
`CommandRegistry` entry — never, under any circumstance, from `$params`
(caller input). This is not a new invariant this Part introduces; it is
the SAME invariant `fixed_parameters` already relies on (Section 4 of
`ADAPTER_ARCHITECTURE_CHECKPOINT.md`: "fixed_parameters must remain
literal, or the registry stops being a trustworthy allowlist"), extended
to a new field. A caller can never supply, override, or influence which
exit codes are treated as `confirmed_degraded` — this is registry-authored
application code, reviewed the same way `argument_order` or
`fixed_parameters` are reviewed, not runtime data. The classification
step (Part 1's one-line rule) reads `$entry[...]`, never `$params[...]`
— the same structural guarantee that already makes `fixed_parameters`
safe applies here without any new mechanism.

**Not implemented in this pass** (design only, per this task's scope):
schema validation of the field's contents against `HESTIA_EXIT_CODES`
(catching a typo'd symbolic name at registry-load time rather than
silently never matching) is a natural, small, additive follow-up
whenever this field is actually built — flagged here, not designed in
detail, since it is a straightforward validation concern rather than an
architectural one.

---

# Part 3 — `domain.create` vs `domain.delete`

| | `domain.create` | `domain.delete` |
|---|---|---|
| **Successful mutation (exit 0)** | Domain fully created: directories, vhost/proxy config, `web.conf` entry, services reloaded. `mutation_state = confirmed`. | Domain fully removed: all of the above deleted, services reloaded. `mutation_state = confirmed`. |
| **Pre-mutation failure** | `E_EXISTS` (duplicate domain), `E_LIMIT` (quota), `E_NOTEXIST` (user), `E_SUSPENDED`, `E_DISABLED`, `E_ARGS`/`E_INVALID` — all fire during "Verifications," before "Action." Today and under this Part's recommended model: `mutation_state = unknown` (no `failed_pre_mutation` claim is made — see Part 1). | `E_NOTEXIST` (user or domain missing/not owned), `E_DISABLED`, `E_ARGS`/`E_INVALID` — same story, all pre-Action. Same `unknown` treatment. **No quota-shaped failure exists at all for delete** — a genuine, confirmed asymmetry (`DOMAIN_DELETE_IMPLEMENTATION.md` Section 11), not an oversight in either operation's design. |
| **Post-mutation failure** | `E_RESTART` only — web or proxy reload fails, AFTER `web.conf` was already appended to and directories already created. Under this Part's model: `mutation_state = confirmed_degraded` (declared via `known_post_mutation_exit_codes: ["E_RESTART"]`). | `E_RESTART` only — web, proxy, OR PHP-backend reload fails, AFTER the domain was already fully removed (directories `rm -rf`'d, `web.conf` line gone). Same declaration, same `confirmed_degraded` outcome. |
| **Irreversible consequences** | The CREATED domain's directories/config are new filesystem state — reversible in principle by a subsequent `domain.delete`, though nothing today automates that. | The DELETED domain's directories are `rm -rf`'d with **no soft-delete, no trash, no undo** — the script's own header comment states this explicitly ("data recovery is possible only with a help of reserve copy"). Strictly more consequential than create's case. |
| **Retry safety** | Retrying a `confirmed_degraded` create is **unsafe as a bare retry** — the domain now exists, so a second `domain.create` call hits `E_EXISTS`. The correct recovery action is "retry only the reload," not "retry the whole operation" — something neither the adapter nor a naive API client can currently express. | Retrying a `confirmed_degraded` delete is **safe in a different way** — a second `domain.delete` call would hit `E_NOTEXIST` (harmless, informative: "already gone"), never re-attempt a destructive action against already-removed state. The failure mode of a careless retry is categorically less dangerous here than for create. |
| **`E_RESTART` semantics** | Config exists, may not be LIVE on the running web/proxy server until a successful reload. | Domain is GONE, but the web/proxy/PHP-backend may still be serving STALE config referencing a domain that no longer exists on disk, until a successful reload. |

## Does the recommended model work for both without `CommandAdapter` branching?

**Yes.** Both operations' registry entries would declare the identical
`"known_post_mutation_exit_codes" => ["E_RESTART"]` — same field, same
value, populated independently for each entry based on each script's own
source (they happen to match here; nothing in the model requires or
assumes they must). `CommandAdapter`'s one-line classification rule
(Part 1) needs no knowledge of which operation it's evaluating — it
reads whichever list the resolved entry happens to declare. This is the
same generality property `ADAPTER_ARCHITECTURE_CHECKPOINT.md` verified
for `mutation.kind`/locking: a registry-data difference, not a code
difference, absorbs the two operations' shared behavior.

The one thing the model does NOT solve, and should not be expected to:
the very different RETRY SAFETY and IRREVERSIBILITY implications of the
same `confirmed_degraded` value for these two operations (row 5/6 above)
are not encoded anywhere in `mutation_state` itself, nor should they be
— `mutation_state` answers "what do we know about whether state
changed," not "what should the caller do next," which is a
service/API-layer concern (Part 10) that would consult BOTH
`mutation_state` and the operation's own semantics (something the
service layer, not the adapter, already has to know about "domain.create
vs domain.delete" as product concepts).

---

# Part 4 — Authorization Boundary

## The current, unenforced flow

```
caller → CommandAdapter::invoke($operation, $params, $actor) → registry → sudo → v-*
```

`$actor` is recorded on the result; `$params["user"]` (i.e.
`$target["user"]`) selects WHOSE resources are touched. Nothing today
compares the two. This was already the single largest finding of
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` (Sections 1, 3, 4, 8); this Part
turns that finding into a design.

## Evaluating the four options

**A — the adapter trusts the service layer completely (no enforcement
inside the adapter at all).**
Simplest, keeps the adapter maximally thin, matches
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 8's "authorization does not
belong in the adapter" finding taken literally. **Rejected as the sole
mechanism**: it means the ONE component in the entire system capable of
running root-privileged Hestia operations has zero ability to refuse a
request even if every layer above it has a bug, is misconfigured, or is
simply new code that forgot the check. Given the adapter already
provides defense-in-depth for injection (three independent layers,
per `ADAPTER_VERTICAL_SLICE.md`) and for concurrency (the lock, which
also could theoretically "belong" purely in a service layer but was
correctly placed in the adapter for exactly this reason — see
`WRITE_OPERATION_DESIGN.md`'s original locking-placement reasoning),
authorization has the identical shape of argument in its favor.

**B — the adapter performs a minimal ownership/scope assertion itself
(e.g., hardcoding "actor.user must equal target.user unless actor has
role X").**
**Rejected.** This requires the adapter to understand ROLES and SCOPES —
genuine business/product concepts (Part 6) — which is precisely the line
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 8 drew and which this
document does not find new evidence to redraw. An adapter that knows
what a "reseller" is has stopped being a thin execution layer.

**C — the adapter accepts an authorization CONTEXT that a layer above it
has already resolved, and merely records/propagates it (no enforcement,
only bookkeeping).**
Correct separation of concerns (the adapter still knows nothing about
policy), but provides **zero actual enforcement** — identical to option
A in terms of what happens if the caller forgets to check. The only
difference from A is that the (unchecked) context is now visible on the
result for audit purposes after the fact, which is useful but does not
answer this Part's actual question.

**D — the adapter calls an injectable authorization SEAM (an interface,
not a policy) before proceeding, structurally mirroring the existing
`ProcessRunnerInterface`/`LockManagerInterface` dependency-injection
pattern already used throughout this codebase.**
**Recommended.** This is genuinely C's separation of concerns PLUS A's
concern about enforcement resolved correctly: the adapter still contains
**zero policy** (no role names, no scope logic — that all lives in
whatever concrete implementation is injected, i.e. the service layer, per
Part 10), but the adapter STRUCTURALLY GUARANTEES the check happens,
the same way it structurally guarantees a lock is acquired for every
mutating operation today — not by convention, not by hoping every future
caller remembers, but by the interface being consulted inside `invoke()`
itself. A safe, permissive default implementation (conceptually: "allow
everything," exactly matching today's actual behavior) means this can be
designed now with **zero behavior change** for the current, fully-trusted
internal caller — nothing about existing tests, existing behavior, or
existing callers would need to change the day this seam is introduced;
only API v2's eventual service layer would ever supply a non-default
implementation.

## Where in `invoke()` would this be consulted?

Conceptually, BEFORE lock acquisition, and reasonably early — likely
immediately after the registry entry resolves and parameters validate
(so `$target` is known and vetted), but strictly before any lock
contention or process spawn is attempted. This mirrors the existing
principle already established for locking ("don't acquire a lock for a
request that was going to be rejected anyway") — don't contend for a
lock, or spawn a process, for a request that authorization was always
going to deny. **Not designed in implementation detail here** (no
interface signature, no exception types, no `AdapterResult` field names)
— per this task's explicit "do not implement" instruction, this Part
identifies the SEAM and its position, not its code.

---

# Part 5 — Actor vs. Target

## The three scenarios, analyzed

**`actor = admin`, `target.user = customer123`** — a legitimate
delegated-administration action (a system administrator managing a
customer's domain on their behalf, e.g. for support). This should be
ALLOWED, but only for an actor whose role grants it, and ideally only
when explicitly flagged as acting-on-behalf-of, not silently permitted
just because the actor happens to have broad access.

**`actor = customer123`, `target.user = customer123`** — ordinary
self-service. This is the ONLY scenario that requires no delegation
concept at all — an actor acting on their own resources.

**`actor = customer123`, `target.user = customer456`** — the attack
case. Must be DENIED unconditionally for a `hosting_user`-class actor
(Part 6); there is no legitimate scenario in a hosting control plane
where one customer directly mutates another's resources without going
through a role that explicitly grants cross-account access.

## The existing, already-scaffolded hook

`AdapterResult`'s actor already carries an `acting_as` field
(`$normalizedActor = ["user" => ..., "acting_as" => ...]`,
`CommandAdapter.php`), present since the very first vertical slice,
currently populated by callers but never checked or enforced. **This is
worth stating plainly: the architecture already anticipated exactly this
delegation scenario, months before authorization was designed.** This
Part recommends FORMALIZING `acting_as` as the delegation mechanism,
rather than inventing a new one: `actor.user` identifies who is actually
authenticated; `actor.acting_as` (optional) identifies whose identity
they claim to be operating as; `target.user` identifies whose resources
the operation touches.

## The design: what must be true before `invoke()` executes anything

The authorization decision (evaluated by whatever implements the seam
from Part 4, NOT by the adapter itself) must resolve, before `invoke()`
proceeds past that seam, exactly one question: **"is `actor` permitted to
act as `target.user` for this `operation`?"** — decomposed as:

1. If `actor.acting_as` is not set: the effective identity is
   `actor.user` itself, and the check collapses to "does `actor.user`
   equal `target.user`, or does `actor`'s role grant blanket access
   regardless of ownership (Part 6)?"
2. If `actor.acting_as` is set: the effective identity is
   `actor.acting_as`, and the check additionally requires "does
   `actor.user`'s role grant delegation rights over `actor.acting_as`
   specifically (e.g. a reseller acting as one of their own downstream
   accounts, an administrator acting as anyone)?" — a genuinely separate,
   stronger check than plain self-service, which is exactly why keeping
   `acting_as` a distinct, explicit field (rather than simply letting
   `target.user` silently diverge from `actor.user`) matters: it makes
   delegation an auditable, intentional act rather than an
   indistinguishable side effect of "the target happened to be someone
   else."
3. In both cases, the FINAL check is that the resolved effective
   identity actually equals `target.user` — an authorization verdict of
   "yes, you may act as X" that is then handed a request targeting a
   DIFFERENT user Y is not a valid basis for proceeding, and this final
   equality check is what actually prevents the third scenario above.

This resolution happens entirely OUTSIDE the adapter (Part 4's option D
— the adapter only consults the seam's yes/no verdict for THIS specific
`(actor, target, operation)` triple, already fully resolved by the time
it's asked). **Not implemented here** — this is the shape of the
decision, not its code.

---

# Part 6 — Authorization Policy

## Minimum viable roles

- **System administrator** — unrestricted scope, every operation, every
  user's resources. Maps to today's implicit reality (the one existing
  Hestia "admin" role already has this level of access at the CLI
  layer; this role formalizes rather than expands that).
- **Reseller** — scope limited to their own downstream/created accounts
  plus themselves; full operation set within that scope. Not a role that
  exists in today's adapter at all (no operation has been built with
  reseller semantics in mind), but a standard hosting-control-plane
  concept worth naming now so the policy model has a place for it later.
- **Hosting user** — scope limited to `self` only, ordinary operation
  set (their own domains, their own backups, not other users' anything,
  not user-management operations at all).
- **Service account / API token** — NOT a peer of the human roles above;
  a narrower, purpose-built scope+operation combination (e.g. a
  backup-automation token that can only call `backup.*` operations for
  one specific user, nothing else) — the "principle of least privilege"
  case, and the one most directly relevant to any future Cloud
  Account/automation integration.

## Operation-based, resource-based, or both?

**Both — as a `(operation, scope)` pair, not two independent
dimensions.** Reasoning:

- **Operation-based alone is insufficient**: knowing an actor may call
  `domain.create` says nothing about FOR WHOM — the exact gap this whole
  Part exists to close.
- **Resource-based alone (a per-domain ACL) is the wrong grain for this
  system**: it cannot express "may create NEW domains for user X" (the
  resource doesn't exist yet to attach an ACL to), and it does not match
  how Hestia's own data model already works — ownership is inherently
  per-USER (`$USER_DATA/web.conf`), not per-domain-with-an-independent-
  ACL. Building a parallel per-resource ACL system would duplicate and
  potentially conflict with the ownership model Hestia's CLI already
  enforces (`is_object_valid`'s user-scoped checks, `DOMAIN_DELETE_IMPLEMENTATION.md`
  Section 1).
- **`(operation, scope)` fits both the evidence and Hestia's actual data
  model**: `scope` is expressed as an ownership/hierarchy relation
  (`self` / `downstream` / `all`), not an enumerated resource list. This
  is standard RBAC-with-ownership-scoping, deliberately NOT a full
  ABAC/attribute-based policy engine and NOT a per-resource IAM system —
  matching this Part's explicit "minimum viable," not "complete
  enterprise IAM," instruction.

## Example permission set (illustrative, not exhaustive)

| Permission | `self` | `downstream` | `all` |
|---|---|---|---|
| `domain.read` | hosting user, reseller, admin | reseller, admin | admin |
| `domain.create` | hosting user, reseller, admin | reseller, admin | admin |
| `domain.delete` | hosting user, reseller, admin | reseller, admin | admin |
| `backup.create` | hosting user, reseller, admin | reseller, admin | admin |
| `user.manage` | — (not a self-permission; managing YOUR OWN account is a distinct, narrower concept than this permission implies) | reseller, admin | admin |

A service account/API token is not a row in this table — it is a
CUSTOM, narrower `(operation, scope)` set assigned per-token, typically
a strict subset of what its associated human owner could do (e.g. one
operation, one scope), which is why it is modeled as its own actor
category (Part 5/6) rather than squeezed into one of the four role rows
above.

---

# Part 7 — Critical Security Question

The sudoers policy: `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*`.

**A. Internal adapter usage — acceptable today, unchanged finding from
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 4.** The registry is the
only enforced allowlist, but the only caller today is fully-trusted
internal PHP, and no untrusted input reaches operation selection. Risk
is low and matches the risk already accepted by every existing direct
`exec()` call site in this codebase, which has run under this exact
sudoers policy for far longer than the adapter has existed.

**B. Future API v2 — NOT acceptable alone.** Once ANY externally
reachable, authenticated-but-not-necessarily-perfectly-implemented
service layer sits above the adapter, the registry allowlist becomes the
ONLY thing standing between a bug/compromise in that layer and root
execution of ANY script under `bin/*` — not merely the four operations
currently registered, because the sudoers grant itself does not know the
registry exists. Two independent hardenings both matter here, not as
alternatives but as layers: (1) the authorization design in Parts 4-6,
and (2) narrowing sudoers itself (already flagged as a "CHANGE LATER"
item in the prior checkpoint) so that even a hypothetical bug bypassing
the registry check entirely still cannot reach an unregistered command.
Neither alone is sufficient for B; both together provide real defense in
depth.

**C. Third-party extensions (Marketplace) — NOT acceptable as-is, and
this is a harder problem than B, not merely a stricter version of it.**
Even a fully schema-validated, reviewed, declarative registry entry
(the format `ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 2 already
flagged as the eventual trigger for moving off PHP arrays) still, today,
resolves to `sudo <anything under bin/*>` — meaning the wildcard grant
is not narrowed AT ALL by how carefully a marketplace entry's DATA is
vetted, because the OS-level privilege boundary doesn't consult the
registry at all. A marketplace author's operation, however well-reviewed
its registry entry is, still runs with the SAME blast radius as every
other operation, which is inconsistent with any meaningful
extension-trust model (a well-reviewed "read my own domain list"
extension and a compromised "delete arbitrary files" extension currently
have identical OS-level privilege if either could somehow reach an
unintended script). **This is a genuine architectural blocker for
Marketplace specifically**, not a hardening nice-to-have — it requires
either a per-operation, mechanically-derived-from-the-registry sudoers
policy (replacing the wildcard with an explicit, generated list matching
exactly the registered scripts), or an entirely different execution
privilege model for third-party-originated operations (e.g. a
more-restricted service account, a broker process, or disallowing
marketplace-originated operations from ever reaching `sudo` directly at
all). Not designed further here — flagged as the specific blocker it is
(see BLOCKERS at the end of this document).

---

# Part 8 — Legacy Callers

Three genuinely distinct properties, easy to conflate, each with a
different scope of protection:

- **Adapter security** (the registry allowlist + typed validation + no
  raw exec) — protects ONLY calls that go through
  `CommandAdapter::invoke()`. Zero effect on anything else.
- **System-wide security** (what `hestiaweb` can actually execute as
  root) — governed entirely by sudoers, completely unaffected by the
  adapter's existence. The wildcard grant is exactly as broad today,
  for a direct `exec()` call site or a hand-run CLI command, as it was
  before the adapter existed. The adapter adds a NEW, narrower path with
  better guarantees; it does not narrow or replace the existing, wider
  path.
- **Concurrency safety** (the per-user lock) — protects ONLY mutations
  that acquire it, i.e. only adapter-routed mutating calls
  (`LOCK_IMPLEMENTATION.md`/`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section
  5's already-established finding). `web/add/web/index.php`'s direct
  `exec()` call for domain creation remains entirely outside this
  protection today.

**Authorization (this document's new design) inherits the identical
scoping problem, and this is worth stating explicitly rather than
assuming it away**: an authorization seam implemented per Part 4 protects
ONLY calls that reach `CommandAdapter::invoke()` through a service layer
that actually consults it. `web/add/web/index.php` and any other direct
`exec()` call site would have NO authorization check whatsoever, even
after this design is fully implemented — not because the design is
incomplete, but because those call sites are structurally outside the
adapter entirely, exactly as they are already outside the lock. Nothing
about authorization is special here; it is the third instance of the
same pattern (adapter security, concurrency safety, and now
authorization all share the identical "only as strong as the path
actually used" limitation).

**Migration implication**: this makes eventual legacy-caller migration
not merely a cleanup task but a genuine PREREQUISITE for any of these
THREE properties — adapter security, concurrency safety, authorization —
to be truthfully described as protecting "the system" rather than "one
path into the system." As long as `web/add/web/index.php` (and any
future comparable legacy call site) remains un-migrated, every one of
these three claims must be stated with the same qualifier:
"for adapter-routed operations only." **Not implemented here** — migration
itself remains explicitly out of scope, per this and every prior task in
this series.

---

# Part 9 — API v2 Implications (semantic model, not HTTP codes)

For each of the seven scenarios, the SEMANTIC outcome the API would need
to represent, independent of any specific HTTP status code choice:

1. **A successful create** — outcome: success. The created resource
   (domain) is returned/echoed. Maps directly from today's `status=ok`,
   `mutation_state=confirmed`.
2. **A create that succeeded but restart failed** — outcome: **success,
   with an explicit degraded/warning signal, distinct from both plain
   success and plain failure.** The resource DOES exist and should be
   returned as such (a client checking "does my domain exist now?" gets
   yes), but the response must carry enough information that a client
   knows NOT to retry the create (would hit a duplicate-resource
   condition) and instead understands a service-reload step may need to
   be retried or waited on separately. Maps from this document's new
   `mutation_state=confirmed_degraded`.
3. **A failed create before mutation** — outcome: failure, with a
   client-actionable reason (validation error, quota exceeded, resource
   already exists, etc. — Hestia's own `hestia_error_code` already
   carries this detail). Nothing was created; retry is safe once the
   underlying issue (e.g. quota) is resolved. Maps from
   `status=hestia_error` or `status=adapter_error`,
   `mutation_state=not_attempted` or `unknown` (see Part 1 — most
   pre-mutation Hestia-side failures remain `unknown` under the
   recommended model, not a newly-added `failed_pre_mutation`; the API
   layer can still present these as "failed, nothing changed" using
   PRODUCT knowledge that a particular `hestia_error_code` like
   `E_EXISTS` is pre-mutation for THIS operation — a mapping the service
   layer is free to encode for presentation purposes even though the
   adapter itself does not assert it generically, per Part 1's
   asymmetry argument).
4. **A successful delete** — outcome: success, resource confirmed gone.
   Maps directly from `status=ok`, `mutation_state=confirmed`.
5. **A delete where deletion succeeded but restart failed** — outcome:
   success, with the SAME degraded/warning signal as scenario 2, but a
   materially different implication for the client: the resource is
   **irreversibly gone** (Part 3), so this is not "retry-safe" the same
   way; the client should understand cleanup is complete and any warning
   concerns only a dependent service's live configuration, not the
   resource's existence. Maps from `mutation_state=confirmed_degraded`
   as well — the SAME mutation_state value serving two operations whose
   PRODUCT implications differ, which is exactly the separation Part 3
   concluded is correct (mutation_state answers one narrow question;
   product-level guidance is a service-layer concern, not something a
   single adapter-level enum value should try to also encode).
6. **An authorization failure** — outcome: denied, and this is a
   semantically DIFFERENT category from every other failure listed here
   — it happens (per Part 4/5's design) before validation, before the
   registry is even consulted in some framings, and it says nothing
   about whether the REQUEST was well-formed or whether Hestia would
   have accepted it; it says only "you may not ask this." This does not
   exist as a distinct concept anywhere in today's `AdapterResult` model
   (today's `status` enum — `ok` / `adapter_error` / `hestia_error` /
   `timeout` / `cancelled` — has no slot for it) and, per Part 4's
   design, would not be produced by the adapter itself at all under
   option D (the seam is consulted by the adapter, but the actual DENIAL
   is a verdict from the injected authorizer, conceptually similar in
   shape to how `LOCK_TIMEOUT` is a verdict from the injected lock
   manager today) — worth flagging explicitly that representing this
   cleanly may eventually motivate a genuinely new `status` value
   (something like `unauthorized`, name not finalized here) alongside
   the existing five, since collapsing it into `adapter_error` would put
   it in the same bucket as things like `VALIDATION_FAILED` and
   `REGISTRY_ERROR`, which are answerable-by-the-caller-fixing-their-request
   failures — a fundamentally different class from "you are not allowed
   to make this request no matter how you phrase it."
7. **A lock timeout** — outcome: failure, but RETRYABLE (the resource's
   state is unaffected, per `not_attempted`, and contention is often
   transient). This one is already fully represented today
   (`adapter_error_code=LOCK_TIMEOUT`, `mutation_state=not_attempted`) —
   no new concept needed; flagged here only to confirm it fits cleanly
   alongside the six scenarios that DO need new or refined
   representation, and as the natural (not finalized here, per
   instruction) candidate for retry-after-shaped HTTP semantics later.

---

# Part 10 — Product Boundary

**A. Adapter** — owns: the operation registry (including the new
`known_post_mutation_exit_codes` metadata from Part 2), typed shape
validation, argv construction, process execution, per-user locking, exit
code → Hestia-error-code translation, mutation-state classification
(strictly registry-data-driven, per Part 1's constraint), and — per Part
4's recommended design — the STRUCTURAL POINT where an authorization
seam is consulted (not the policy behind it). Remains, unchanged from
`ADAPTER_ARCHITECTURE_CHECKPOINT.md`: Hestia-specific, execution-focused,
registry-driven, independent of HTTP, independent of Marketplace,
independent of Cloud Account. This document adds exactly one new
adapter-owned concept (the classification rule) and one new adapter-owned
STRUCTURAL HOOK (the authorization seam's call site) — neither is a
policy, both are mechanisms that read externally-supplied,
externally-defined data/decisions.

**B. Service/Application layer** — owns: orchestrating one or more
adapter calls into a product-meaningful action (today, one adapter call
per product action; a FUTURE product action spanning multiple Hestia
operations — e.g. "provision a new hosting account" touching domains,
DNS, mail — would be orchestrated HERE, never inside `CommandAdapter`,
consistent with the explicit "no workflow engine inside the adapter"
finding already established); translating `AdapterResult` (including
its `mutation_state`) into product-level outcomes (Part 9's seven
scenarios); and — this document's addition — EVALUATING the
authorization POLICY itself (Parts 5-6: resolving actor/target/role/scope
into an allow/deny verdict), which the adapter's seam then merely
consults.

**C. Authorization layer** — owns: role, permission, and scope
definitions (Part 6); the actor/target/delegation resolution logic (Part
5). Recommended as its own logical component (a shared
library/service), reusable across every future operation category
(domain.*, backup.*, user.*, ...) rather than duplicated per
service-layer action, even though it may be deployed alongside the
service layer rather than as a physically separate system. Owns nothing
about Hestia CLI argv, exit codes, or scripts — it answers "may X act as
Y for operation Z," full stop, and knows nothing about HOW operation Z
is actually executed.

**D. API layer** — owns: HTTP routing and request/response shaping,
authentication (verifying WHO is calling — a distinct concern from
authorization's WHAT they may do), rate limiting, idempotency keys,
pagination — all already named as missing in
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 3 and unchanged by this
document. Consumes the service layer; never talks to the adapter
directly.

**The adapter must not become a business workflow engine.** Restated
because it is the single easiest boundary to erode under pressure: even
though this document adds mutation-outcome metadata and an
authorization hook to the adapter's responsibilities, NEITHER of those
additions gives the adapter any new knowledge of PRODUCT concepts
(what a "hosting account" is, what a "reseller" is, what a multi-step
"provisioning" workflow looks like). Both additions are mechanisms that
consult externally-owned data/decisions; the adapter's job after this
document is implemented is still exactly "safely and honestly run one
already-vetted, already-authorized Hestia command and report what
happened," per `ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 8's original
dividing line, unchanged.

---

# MUTATION MODEL

Four values: `not_attempted`, `confirmed`, `confirmed_degraded`,
`unknown`. `confirmed_degraded` is produced only when the exact exit
code appears in the resolved registry entry's `known_post_mutation_exit_codes`
list (symbolic Hestia error names, e.g. `["E_RESTART"]"`); every other
non-zero exit remains `unknown`, the safe default. `partial_failure` is
rejected, again, on the same grounds `WRITE_OPERATION_DESIGN.md`
originally rejected it. A speculative `failed_pre_mutation` state is
also rejected — deliberately, on asymmetric-risk grounds (a false
"definitely mutated" claim degrades gracefully into an ambiguous
warning; a false "definitely did not mutate" claim could cause a caller
to skip real recovery steps). The classification rule itself is one
generic, registry-data-driven line inside `CommandAdapter` — no
per-operation branching, no hardcoded exit codes.

# AUTHORIZATION MODEL

An injectable authorization seam, consulted by `CommandAdapter::invoke()`
itself (structurally guaranteeing the check happens, mirroring the
existing `ProcessRunnerInterface`/`LockManagerInterface` pattern),
positioned after parameter validation and before lock acquisition,
defaulting to a fully permissive implementation that preserves 100% of
today's behavior for the current, fully-trusted internal caller. The
POLICY itself — roles (system administrator / reseller / hosting user /
service account), `(operation, scope)` permissions where scope is
`self`/`downstream`/`all`, and the actor/target/`acting_as`-delegation
resolution — lives entirely in the service/authorization layer above
the adapter, never inside it. `actor.acting_as` (already present,
already unused, in today's `AdapterResult`) is formalized as the
delegation mechanism rather than replaced.

# ADAPTER RESPONSIBILITIES

**Owns**: registry (including mutation-outcome metadata), typed
validation, argv construction, process execution, per-user locking,
exit-code translation, data-driven mutation-state classification, and
the call site (not the content) of an authorization check.

**Must never own**: HTTP concerns of any kind; authorization POLICY
(roles, scopes, permission definitions); Marketplace concerns (trust,
installation, versioning of third-party operations); Cloud Account
concerns; multi-operation business workflow orchestration; any concept
of what a "hosting account," "reseller," or "provisioning flow" is.

# API V2 PREREQUISITES

1. The authorization layer (Parts 4-6) actually implemented — currently
   the largest concrete gap, now with a specific design, still entirely
   unbuilt.
2. The adapter's authorization seam actually wired into
   `CommandAdapter::invoke()` (Part 4) — a small, additive,
   backward-compatible change once the authorization layer exists to
   inject.
3. The `known_post_mutation_exit_codes` registry field and the
   `confirmed_degraded` mutation state (Parts 1-2) actually implemented,
   so the API can honestly represent scenarios 2 and 5 from Part 9
   rather than collapsing them into the same ambiguous `unknown` every
   other failure gets today.
4. A `status` representation for authorization denial distinct from
   validation/Hestia-level failure (Part 9, scenario 6) — not
   necessarily a full implementation, but a decided model before the
   first API v2 endpoint ships.
5. At minimum the highest-traffic legacy caller per exposed operation
   migrated onto the adapter (unchanged finding from
   `ADAPTER_ARCHITECTURE_CHECKPOINT.md`, restated here because
   authorization inherits the identical scoping gap per Part 8) — so
   that "authorized" and "concurrency-safe" claims are true for the
   operation as a product feature, not merely for the adapter-routed
   subset of its call sites.
6. Sudoers narrowing or an equivalent mechanism, at minimum before any
   Marketplace/third-party-originated operation, and seriously
   considered as defense-in-depth before ANY external exposure (Part 7).
7. The remaining items already named in
   `ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 3 (idempotency keys,
   rate limiting, pagination, HTTP-mapping layer) — unchanged by this
   document, restated only for completeness, not re-analyzed here.

# BLOCKERS

Genuine blockers only — for any EXTERNALLY-exposed, mutating API
surface specifically. None of these block continuing internal adapter
development (adding more operations, more tests) the way this series of
tasks has proceeded so far.

- **No authorization enforcement exists anywhere in this system, at any
  layer.** This document designs where and how it should be enforced;
  none of it is built. This is an absolute precondition for exposing
  `domain.create`/`domain.delete` (or any future mutating operation)
  externally — restated from the prior checkpoint, now with a concrete
  design attached, still fully unbuilt.
- **The `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` sudoers grant
  remains the only OS-level boundary, and Part 7 established this is
  categorically insufficient for third-party extensions specifically**
  — not merely "worth narrowing eventually," but a structural blocker
  for Marketplace: no amount of registry-side validation changes what
  the OS will let a compromised or buggy extension-originated call
  actually execute.
- **Legacy, unmigrated callers mean every safety property this document
  and its predecessors describe — adapter security, concurrency safety,
  and now authorization — is only true for adapter-routed calls, never
  for the system as a whole**, per Part 8. Any external
  documentation/claim describing these properties without that
  qualifier would be false. Not a blocker for continuing to design or
  build the adapter itself; a hard blocker for describing the SYSTEM
  (not just the adapter) as protected by any of these properties.
