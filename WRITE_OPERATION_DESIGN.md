# Write Operation Design — Locking and Result Semantics

Design-only document. No source files were modified, no `flock` was added, no
`domain.create` entry was added to the registry, no API v2 or audit
persistence was built. This resolves the two architectural questions flagged
at the end of `ADAPTER_VERTICAL_SLICE.md`'s "Is the abstraction ready for
`domain.create`?" section, before any write operation is implemented.

Everything below is grounded in: the confirmed race conditions in
`ARCHITECTURE_REVIEW.md`'s Verified Open Questions Area 2; the locking
recommendation in `ARCHITECTURE_ADAPTER_DESIGN.md` section 6; the actual,
currently-implemented `CommandAdapter`/`CommandRegistry`/`AdapterResult` code
in `web/inc/adapter/`; and a full read of `bin/v-add-web-domain` (261 lines)
and the relevant part of `func/domain.sh`'s `add_web_config()`, performed for
this document.

---

# PART 1 — Mutating Operation Classification

## Proposed registry metadata

```php
"domain.create" => [
	"script" => "v-add-web-domain",
	// ...
	"mutation" => [
		"kind" => "create",          // "read" | "create" | "update" | "delete"
		"config_write" => true,      // writes to USER_DATA/*.conf or filesystem under $HOMEDIR
		"service_reload" => true,    // triggers a service restart/reload as part of the operation
		"destructive" => false,      // irreversible data loss if it "succeeds" (deletes are destructive; creates are not)
	],
],
```

`domain.get`/`domain.list`'s entries would carry:

```php
"mutation" => ["kind" => "read", "config_write" => false, "service_reload" => false, "destructive" => false],
```

## Why not `"mutates_state" => true`

The task's example, `"mutates_state" => true`, is a reasonable **first
instinct** but is critiqued and rejected here for being a single boolean
collapsing several genuinely different properties an operation can have,
each of which this design needs to reason about separately:

1. **It cannot express *why* locking matters differently for different
   mutating operations.** A boolean tells the adapter "acquire the lock,"
   but doesn't tell it whether the operation's failure mode is "wrote a
   config line" (recoverable, low blast radius) versus "deleted a mail
   account" (irreversible). Part 4 and Part 6 below both need this
   distinction — a generic "mutation happened, might have failed partway"
   result is very different in consequence for a create versus a delete.
2. **It conflates "needs a lock" with "needs a restart afterward."** These
   are related but not identical: a config-only mutation still needs the
   per-user lock (it still touches shared `USER_DATA/*.conf` files, per
   `ARCHITECTURE_REVIEW.md`'s confirmed races), but knowing separately that
   an operation *also* triggers a service reload is useful information for
   a future diagnostics/health-check consumer (e.g., "this operation's
   failure could leave a running service serving a stale config") that a
   single boolean would hide.
3. **It reads as passive/descriptive ("does this operation mutate state")
   rather than prescriptive ("what should the adapter DO because of that").**
   The name chosen instead, `mutation`, is a small object because the
   *consequences* of mutability are multi-dimensional, and naming it as an
   object rather than a scalar signals "there is more here than yes/no" to
   whoever writes the next registry entry.

**Better name and shape**: `"mutation" => [...]` as shown above — a small,
structured object, not a single flag, but still genuinely minimal (four
fields, three of them booleans, the fourth a short enum).

## Do we need `config_write` / `service_reload` / `external_side_effects` / `destructive` as *separate* fields?

Evaluated against the actual evidence in `bin/v-add-web-domain` (Part 5 below
has the full trace):

- **`config_write`**: yes, needed, and always `true` for any operation this
  design currently anticipates registering (create/update/delete all touch
  `USER_DATA/*.conf`) — kept as an explicit field anyway, not hardcoded to
  "true whenever kind != read," because a hypothetical future operation
  (e.g. a pure service-restart operation with no config write) would
  otherwise be misclassified. Minimal cost to keep explicit.
- **`service_reload`**: yes, needed — `v-add-web-domain` calls
  `v-restart-web`/`v-restart-proxy` as its last two steps (lines 250, 254).
  This is a real, separate failure point from the config-write steps (Part
  5 traces exactly this), and a future diagnostics consumer benefits from
  knowing "this operation's failure mode can include a stale running
  service" without re-deriving it from the script source every time.
- **`destructive`**: yes, but **only as a boolean is needed right now** —
  not a numeric "severity" or a list of what's destroyed. `domain.create`
  is `false` (creating something is not destructive to *existing* data);
  a hypothetical future `domain.delete` would be `true`. This single bit
  matters for Part 6 (whether the adapter should ever *attempt* automatic
  retry or any compensating action — never for a destructive operation,
  even hypothetically) and is worth having now precisely because it is the
  one classification question `domain.create` itself can already answer
  cleanly, unlike the harder mutation-state questions in Part 4.
- **`external_side_effects`** (the task's fourth suggested dimension,
  distinct from filesystem/config and service restart): **not added as a
  separate field for this pass.** `v-add-web-domain` has no side effect
  outside the local server (no external API call, no third-party network
  call) — its "external" surface is entirely `$BIN/v-*` subprocess calls to
  other Hestia scripts on the same machine, which is what `config_write`
  and `service_reload` already describe between them. A field for this
  would be speculative today; the honest thing to do is not add it until a
  registered operation actually has an external effect (e.g. a future ACME
  call, a future S3 upload for backups) to classify. Flagged here so the
  gap is visible, not silently absent.

## Recommendation for the minimum metadata needed for the first write operation

```php
"mutation" => [
	"kind" => "create",
	"config_write" => true,
	"service_reload" => true,
	"destructive" => false,
],
```

Four fields. `external_side_effects` deliberately omitted per above. This is
judged the minimum that lets the adapter (a) know to acquire a lock at all
(any `kind` other than `"read"`), (b) know a service reload is part of the
operation's own failure surface (informational, consumed later by
diagnostics, not by locking — locking doesn't change based on this field),
and (c) know whether any future automatic-anything (retry, notification
tone, "are you sure" UI copy) should treat the operation as reversible or
not. Nothing here is used by locking logic beyond `kind !== "read"` — see
Part 2.

---

# PART 2 — Per-User Locking

## The model

**`flock` on a dedicated per-user lock file, one file per Hestia username,
acquired by the adapter for the full duration of any operation whose
registry entry declares `mutation.kind !== "read"`.** This confirms and
details the recommendation already made in `ARCHITECTURE_ADAPTER_DESIGN.md`
section 6 (option B, per-user lock) — this section makes it concrete enough
to implement later, without implementing it now.

### Lock file location

`$HESTIA/data/users/<username>/.adapter.lock` (i.e., inside the same
per-user data directory `func/main.sh`'s `is_object_valid('user', 'USER',
...)` already validates existence of — `$HESTIA/data/users/$3` is the exact
path checked, per `func/main.sh`'s `is_object_valid()`, already cited in
`ARCHITECTURE_ADAPTER_DESIGN.md`).

Rejected alternative: a single flat directory of lock files elsewhere (e.g.
`$HESTIA/data/locks/<username>.lock`). The per-user directory is preferred
because: (a) it inherits that directory's existing ownership/permissions
model rather than requiring a new directory with its own permission story,
(b) it is naturally cleaned up if a user is ever deleted (no orphaned lock
file surviving user removal in a separate location), and (c) it keeps
"things relating to user X" in one place, consistent with how every other
piece of Hestia state for that user already lives there
(`user.conf`, `web.conf`, `mail.conf`, etc., all confirmed in this and the
prior review passes).

### How the user identity is derived

**Exactly the `user` value already validated by `ParameterValidator::isValidUsername()`
before the lock is ever considered** — never a raw, unvalidated caller
string. This is not a new derivation; it reuses the existing shape-validated
parameter from `CommandAdapter::invoke()`'s own validated `params["user"]`
(or, for operations where "user" is inferred from `actor` rather than an
explicit parameter — not applicable to `domain.create`, whose `user` is an
explicit required parameter, but worth naming for future operations — the
same validated-value discipline applies: the lock key must always come from
a value that has already passed `ParameterValidator`, never from `actor`
alone, since `actor` is caller-supplied context, not independently
shape-validated in the current implementation).

### Filename/path safety

The lock file path is constructed as `$HESTIA/data/users/` . **basename**(
validated user ) . `/.adapter.lock` — the `basename()` step is redundant
defense given `ParameterValidator::isValidUsername()` already rejects `/`,
`..`, and any character outside `[a-zA-Z0-9_.-]` (confirmed: the validator's
regex has no `/` in its allowed character class), but is included anyway as
a second, independent guard against path traversal, mirroring the existing
Hestia pattern already found in this codebase: `bin/v-check-access-key`
does exactly this (`access_key_id="$(basename "$1")"`, confirmed in the
architecture review) for the same class of risk on a different identifier.
Defense-in-depth here costs nothing and matches an existing Hestia
convention rather than inventing a new one.

### Lock acquisition timing

Acquired **after** the operation's parameters have passed shape validation
and the argv has been constructed, but **before** the underlying `v-*`
process is spawned. See Part 3 for the exact sequencing and the reasoning
for not acquiring it earlier.

### Lock release

Released **unconditionally** once the underlying process has been waited on
and its `ProcessResult` captured — regardless of the process's exit code.
A failed operation still releases the lock; the lock's job is to serialize
*access*, not to represent "this user's data is known-good." Release must
happen even if the adapter itself throws/errors while processing the
result (i.e., release belongs in a `finally`-equivalent, not only on the
success path) — a lock that leaks on an adapter-internal bug would be worse
than the race it was meant to prevent, since it would then block *all*
future operations for that user, not just let two race.

### Blocking vs. non-blocking acquisition

**Blocking, with a bounded wait**, not indefinite blocking and not a bare
non-blocking "fail immediately if held." Rationale: two legitimate
operations for the same user arriving close together (e.g., a user submits
one form, then immediately submits another before the first's page finishes
loading) are a normal, expected case that should simply queue and both
eventually succeed — failing the second one immediately (non-blocking) would
turn ordinary UI double-submission latency into visible user-facing errors
for no real benefit. Indefinite blocking, conversely, risks pile-ups if one
operation hangs (a hung `v-*` script has no timeout today, per
`ARCHITECTURE_ADAPTER_DESIGN.md` section 4's confirmed gap — the same gap
applies here: nothing currently stops a lock-holding operation from holding
it forever if the underlying process never returns).

### Timeout behavior

A bounded wait for lock acquisition (a `lock_timeout_seconds`, separate from
and likely shorter than the operation's own `timeout_seconds` from
`ARCHITECTURE_ADAPTER_DESIGN.md` section 2) that, if exceeded, returns a
rejection **before** ever attempting to run the underlying script —
i.e. structurally identical to the existing pre-execution rejection paths
(`UNKNOWN_OPERATION`, `VALIDATION_FAILED`, etc.) already implemented in
`CommandAdapter::rejected()`, with a new adapter-native code,
`LOCK_TIMEOUT`. This is a **new rejection reason**, not a new result
`status` value — it fits the existing `status: adapter_error` category
exactly, because from the caller's perspective "the adapter declined to
even attempt this" is true whether the reason is bad input or lock
contention. (The concrete `flock` mechanism this maps to — e.g. `flock`'s
own `-w` wait-seconds flag — is an implementation detail correctly deferred
past this design, per the task's "do not add flock yet.")

### Stale locks / process death

`flock`'s advisory locks are held for the lifetime of the file descriptor
that acquired them and are **automatically released by the kernel** when
the holding process dies (crash, `SIGKILL`, OOM-kill, host reboot after
process loss) — this is `flock`'s standard, well-established behavior, and
is the primary reason `flock` (versus, say, a `mkdir`-based or PID-file-based
lock, both of which require explicit stale-lock detection/cleanup logic) was
already the leading candidate named in the task and in
`ARCHITECTURE_ADAPTER_DESIGN.md`. **No stale-lock detection/cleanup code is
needed as a result** — this is a property of the mechanism, not something
this design has to build. The one caveat worth naming explicitly: this only
covers *process* death. It does not protect against a lock genuinely held
correctly by a still-alive process that is simply taking a very long time
(a hung script) — that case is what the lock *timeout* (above) is for, a
different problem with a different, already-designed answer.

### Permissions

The lock file should be owned by the same principal that already owns
`$HESTIA/data/users/<username>/` and its sibling files (root, per the
existing convention confirmed throughout this codebase — e.g.
`bin/v-add-access-key`'s `chown root:root $HESTIA/data/access-keys/`), with
permissions restrictive enough that only the adapter's own execution
context (today: the PHP-FPM/web process that will eventually host it, per
`ARCHITECTURE_ADAPTER_DESIGN.md` section 11's migration plan) can acquire
it — consistent with, not more permissive than, the existing sudoers
boundary (`hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*`, confirmed in
`ARCHITECTURE_ADAPTER_DESIGN.md` section 5) that already governs what that
process is trusted to do.

### Interaction with sudo

The lock is acquired **by the adapter's own PHP process, before `sudo` is
ever invoked** — it does not need to be, and should not be, a lock acquired
*inside* the sudo'd child process (i.e., not something `v-add-web-domain`
itself would `flock`). This matters for two reasons: (1) the adapter needs
to serialize *its own* concurrent `invoke()` calls, which happens entirely
in the PHP process, before any `sudo`/subprocess exists at all; and (2)
putting the lock inside the Bash script would require modifying
`bin/v-add-web-domain` (explicitly out of scope, per this task and the
architecture design's "wrap, don't rewrite" principle) and would do nothing
to serialize the *other*, non-adapter, still-existing direct `exec()` call
sites in `web/inc/main.php`/`web/api/index.php` any better than an
adapter-side lock does — see the bypass limitation below, which applies
equally to either placement.

### Concurrent operations for the same user

Serialized. Two `domain.create` calls for `admin`, or a `domain.create` and
a hypothetical `mail.create` both for `admin`, contend for the same lock
file (`$HESTIA/data/users/admin/.adapter.lock`) and run one after the other.
This is the entire point of the mechanism and is explicitly what Part 8's
scenario A and C exercise.

### Concurrent operations for different users

Not serialized against each other. `domain.create` for `admin` and
`domain.create` for `bob` acquire different lock files
(`.../admin/.adapter.lock` vs. `.../bob/.adapter.lock`) and proceed fully in
parallel — this is the core benefit of per-user granularity over the
rejected global-lock option, and is Part 8's scenario B.

## Why NOT global

A global lock would serialize `admin`'s `domain.create` behind `bob`'s
unrelated `database.create` (or, once other operations exist, behind a
`backup.create` that runs for minutes) with zero correctness benefit — the
races this lock exists to close (`is_package_full()` vs. later append,
`increase_user_value()`/`decrease_user_value()` lost-update, both confirmed
in `ARCHITECTURE_REVIEW.md` Area 2) are scoped to **one user's own data
files**; two different users' `user.conf`/`web.conf` files are never the
same file, so a lock spanning both buys nothing beyond artificial
throughput loss. This restates and confirms
`ARCHITECTURE_ADAPTER_DESIGN.md` section 6's rejection of option A.

## Why NOT per-domain

Per-domain locking would fail to close the actual race. The confirmed race
in `func/main.sh`'s `is_package_full()` (`func/main.sh:258-280`) reads/
compares against **`user.conf`** (the package's `WEB_DOMAINS` limit and the
row-count of `web.conf`, both scoped to the *user*, not to any single
domain), and `increase_user_value()`/`decrease_user_value()`
(`func/main.sh:727-753`) mutate **`user.conf`** directly. Two `domain.create`
calls for *different* domains but the *same* user would, under per-domain
locking, run concurrently and still race on that shared per-user counter —
per-domain locking would look finer-grained and safer while actually leaving
the confirmed bug open. This restates `ARCHITECTURE_ADAPTER_DESIGN.md`
section 6's rejection of option C, now reinforced by the concrete evidence
in Part 5 below: `v-add-web-domain` itself calls `increase_user_value "$user"
'$U_WEB_DOMAINS'` (line 232) and `increase_user_value "$user"
'$U_WEB_ALIASES'` (line 233) — both keyed by `user`, confirming the shared
resource really is user-scoped, not domain-scoped, for this exact operation.

## Why NOT implemented inside every `v-*` script

Three independent reasons, none of which is "it wouldn't work in principle":

1. **Out of scope by explicit instruction** (this task and every prior task
   in this sequence: "do not modify any existing v-* script"), but that is a
   process constraint, not the architectural reason — the architectural
   reason follows.
2. **It would not close the bypass gap either way** (see next section) —
   even a perfectly-implemented in-script lock only protects callers that go
   through that script; a hypothetical direct-file-write bug elsewhere would
   still bypass it, same as an adapter-side lock does today. Putting the
   lock in Bash doesn't buy additional coverage over putting it in the
   adapter, since coverage in both cases is bounded by "who actually
   acquires the lock before touching the file," not by which layer contains
   the acquisition code.
3. **524 scripts would each need the change**, versus one adapter, for the
   *subset of Hestia operations the fork is actively migrating onto the
   adapter* — modifying every script is a strictly larger, riskier, and
   slower undertaking than adding locking once in the one new component
   this fork controls end-to-end, for no corresponding coverage gain per
   point 2.

## The bypass limitation, stated explicitly

**The adapter's lock only serializes operations that go through the
adapter.** `web/inc/main.php` and `web/api/index.php`'s existing direct
`exec(HESTIA_CMD . "v-add-web-domain ...")` call sites — confirmed still
present and unmodified after every prior pass in this sequence — do **not**
acquire the adapter's lock, because they do not call the adapter at all.
A direct legacy call and an adapter call for the same user, running at the
same moment, are **not serialized against each other** — only two adapter
calls (or two legacy calls, coincidentally serialized only by PHP-FPM's own
worker-process scheduling, which is not a correctness guarantee) are.

## Effect on migration strategy

This is not a flaw discovered now; it is the expected, inherent shape of an
**incremental** migration, and it means the migration plan in
`ARCHITECTURE_ADAPTER_DESIGN.md` section 11 needs one explicit amendment:

- **The safety property this lock provides is only real once a given
  operation's *legacy* call sites are removed**, not merely once the
  adapter's equivalent exists alongside them. Concretely: adding
  `domain.create` to the registry and wiring the adapter's lock around it
  does **not**, by itself, make concurrent `v-add-web-domain` calls safe —
  it only makes concurrent calls *through the adapter* safe, while
  `web/inc/main.php`'s or `web/api/index.php`'s still-existing direct call
  to the same script remains exactly as racy as it is today.
- Section 11's step 4 ("remove that operation's now-dead direct-`exec()`
  code path from both files" — only after the new path is proven) already
  has the right shape for this reason independent of locking, but this
  document makes explicit **why the order matters for correctness, not just
  for cleanliness**: until step 4 completes for a given operation, that
  operation's race conditions are **not actually fixed** for real users of
  the live UI/API, even though the adapter "supports" the operation with a
  lock. The lock is real and correct for adapter callers; the *system-wide*
  guarantee only exists once bypass paths for that specific operation are
  gone.
- Practical implication for `domain.create` specifically: it should not be
  presented (in any status update, changelog, or internal tracking) as
  "the quota/counter race is now fixed" the moment it's added to the
  registry — only once `web/inc/main.php`'s/`web/api/index.php`'s direct
  calls to `v-add-web-domain` are actually removed and replaced with calls
  through the adapter does the race stop being reachable in practice.

---

# PART 3 — Lock Acquisition Location

## The task's example sequence, critiqued

```
validate
→ resolve registry
→ determine mutability
→ acquire lock
→ execute
→ release lock
```

This is close but has the steps in a **suboptimal, though not incorrect,
order**, and elides one important branch. Corrected sequence, matching
`CommandAdapter::invoke()`'s actual current structure (Part 1 of the prior
pass — registry resolution happens first, then unexpected-parameter
rejection, then required/shape validation, then argv construction, then
execution):

```
1. resolve registry entry            (existing step — unchanged)
2. reject unexpected parameters      (existing step — unchanged)
3. reject missing/malformed params   (existing step — unchanged, shape-only)
4. determine mutability from entry.mutation.kind
5. IF mutating: acquire per-user lock (bounded wait)
     IF acquisition fails/times out: return adapter_error(LOCK_TIMEOUT) — do not proceed to 6
6. build argv                        (existing step — unchanged)
7. execute underlying script
8. IF mutating: release lock (always, regardless of step 7's outcome)
9. map exit code → status, build result
```

## Why registry resolution and validation happen BEFORE lock acquisition

This is the one point where the task's own example sequence
("validate → resolve registry → determine mutability → acquire lock") and
this design agree in substance but the task's ordering
(validate *then* resolve registry) is actually backwards relative to what
the current, already-implemented `CommandAdapter` does (resolve registry
*then* validate, since validation needs the registry entry's declared
parameter types to know what to validate against) — worth flagging so the
inherited code's real order is the one carried forward, not the task
prompt's slightly different phrasing of it.

The substantive reason validation (in whichever internal order) happens
**before** lock acquisition either way: **acquiring a lock is not free**
(it can block, per Part 2, for up to `lock_timeout_seconds`), and a request
that is going to be rejected anyway (unknown operation, unexpected
parameter, malformed domain name) should be rejected **immediately**,
without making any other concurrent caller for that same user wait on a
lock that was only ever going to be released unused. This also preserves
the existing, tested property from the prior two passes — "no process is
ever spawned for a rejected request" — and extends it to "no lock is ever
contended for a rejected request" for exactly the same reason: rejections
are caller errors, and caller errors should have zero side effects on any
other concurrent operation, including lock contention.

## Why registry resolution specifically must happen before lock acquisition

Because the lock's *target* — which user's lock file to acquire — is only
knowable once the registry entry's `parameters` schema has told the adapter
which supplied key is the "user" identity to lock on, **and** because
whether to lock *at all* is a property (`mutation.kind`) declared on the
registry entry itself. Acquiring a lock before resolving the registry would
require either guessing which parameter is the lock key (fragile,
operation-specific special-casing — exactly what this design avoids per
Part 1) or always locking on every operation including reads (defeats the
purpose of classifying mutability at all, and would regress `domain.get`/
`domain.list`'s already-proven zero-lock-overhead read path).

## What happens if lock acquisition fails

Two distinct failure modes, both handled the same way for this design
(both are pre-execution rejections, structurally identical to existing
`rejected()` paths):

1. **Timeout** (lock held by another operation for longer than
   `lock_timeout_seconds`): `status: adapter_error`,
   `adapter_error_code: LOCK_TIMEOUT`. The underlying `v-*` script is never
   invoked — `ProcessRunnerInterface::run()` is called zero times, exactly
   as already proven (by existing tests) for every other pre-execution
   rejection reason.
2. **Acquisition mechanism itself errors** (e.g. the lock file's directory
   doesn't exist, or a permissions problem prevents opening it at all —
   distinct from "another process holds it"): also `status: adapter_error`,
   but a different code, `LOCK_UNAVAILABLE`, so the two are distinguishable
   in logs/results — "someone else is using it, try again" is a
   fundamentally different situation from "the locking mechanism itself is
   broken," and collapsing them would make the first (routine, expected
   under real concurrency) indistinguishable from the second (an
   operational problem worth alerting on).

In both cases, this is **still just a new instance of the existing
`adapter_error` status category** — no new top-level `status` value is
needed for lock failures, consistent with how `UNKNOWN_OPERATION`/
`VALIDATION_FAILED`/etc. already share that one status with distinct
`adapter_error_code`s.

---

# PART 4 — Result Semantics for Write Failures

## The core problem, stated precisely

For a read-only operation, a non-zero exit code has one honest meaning:
"the query failed, nothing was ever at risk of changing because the
operation never writes anything." For a mutating operation, a non-zero exit
code has **no single honest meaning** — Part 5 proves concretely, from
`v-add-web-domain`'s actual source, that the identical observable signal
(non-zero exit, or even zero exit) can correspond to at least three
materially different real states: nothing was written, some things were
written and then it stopped, or everything was written and only a
non-essential final step (service reload) failed. The adapter, watching
only stdout/stderr/exit-code from outside the process, **cannot distinguish
these from signal alone** — this is the premise the task explicitly asks to
be honest about, and Part 5 supplies the concrete evidence for why.

## Evaluating the two models the task poses

### Model A — `status = partial_failure`

Rejected. This model implicitly claims the adapter *knows* a partial
mutation occurred and is naming that specific, known condition. Part 5
shows the adapter cannot know this from the outside — a `partial_failure`
status would be **more confident than the evidence supports** in the exact
majority of real failure cases (any failure after line 100 of
`v-add-web-domain` but before line 261 is *plausibly* partial, but "plausibly"
is not "confirmed," and the task explicitly asks to reject any model that
treats a non-zero exit as proof of partial mutation).

### Model B — `status = hestia_error` + `mutation_state = possibly_mutated`

Closer, and the shape this design adopts, but the task's proposed field
name (`possibly_mutated`, implying a single fixed answer for that field) is
under-specified: for a *mutating* operation, "possibly mutated" is true for
almost every non-zero exit code and true by definition for every zero exit
code — a field that reads "possibly" but is nearly always in that state for
one whole operation class doesn't discriminate much on its own. It needs
one more distinction: whether the operation **started performing
irreversible-in-practice work at all**, versus failed at a
validation/pre-flight step that (per Part 5's evidence) provably precedes
any filesystem/config write.

## Proposed model

Keep the existing `status` enum exactly as implemented in the prior two
passes (`ok` | `adapter_error` | `hestia_error` | `timeout` | `cancelled` —
no new values), and add **one new field**, `mutation_state`, populated only
when the operation's registry entry declares `mutation.kind !== "read"`,
with **three** values, not two:

```
mutation_state:
  "not_attempted"   — the operation was rejected before the underlying
                       process was ever spawned (status is always
                       adapter_error in this case: UNKNOWN_OPERATION,
                       VALIDATION_FAILED, LOCK_TIMEOUT, etc.) — the adapter
                       KNOWS no mutation occurred, because nothing ran.

  "confirmed"       — the underlying process exited 0 (status: ok). The
                       adapter treats the operation's own Hestia-defined
                       success exit code as the CLI's own claim that it
                       completed what it set out to do — this is not a
                       new, adapter-invented guarantee; it is trusting the
                       same signal every existing direct exec() call site
                       already trusts today, no more and no less.

  "unknown"         — the underlying process was spawned and exited
                       non-zero (status: hestia_error), OR the adapter's
                       own execution mechanism failed after the process
                       had already started (status: timeout, if the
                       timeout fires after the process was spawned —
                       distinguish from a LOCK_TIMEOUT/pre-flight
                       adapter_error, which is "not_attempted", not
                       "unknown"). This is the honest, explicitly
                       non-committal answer the task asks for: the adapter
                       does NOT claim to know whether zero, some, or all
                       of the operation's intended writes happened —
                       Part 5 demonstrates all three are possible for the
                       same non-zero exit code, for the one concrete
                       script inspected.
```

**Explicitly not a fourth "definitely_mutated_and_failed" value**: the task
asks to reject exactly this kind of overclaiming, and Part 5 confirms the
adapter has no way to earn that level of confidence from outside the
process for `v-add-web-domain` specifically (no structured progress
reporting, no transaction log, no idempotency marker — all confirmed absent
by source reading).

### Read operations

`mutation_state` is simply absent/`null` on results for `mutation.kind ===
"read"` operations (`domain.get`, `domain.list`) — there is nothing to say,
and forcing a value (e.g. always `"not_attempted"`) would suggest a
question ("did this mutate anything") that doesn't apply, muddying the
field's meaning for the operations where it does matter.

## Mapping back to the task's five required distinctions

| # | Task's requirement | Where it lives in this model |
|---|---|---|
| 1 | execution did not start | `status: adapter_error` + `mutation_state: not_attempted` |
| 2 | execution started | any result where `mutation_state` is `confirmed` or `unknown` (both imply the process was spawned — `ProcessRunnerInterface::run()` was called) |
| 3 | execution completed successfully | `status: ok` + `mutation_state: confirmed` |
| 4 | execution failed | `status: hestia_error` (started, non-zero exit) or `status: timeout`/`cancelled` (started, never finished) |
| 5 | mutation known vs. unknown | `mutation_state: confirmed`/`not_attempted` (known) vs. `mutation_state: unknown` (explicitly unknown) — the field's entire purpose |

This is judged the **minimum robust model**: one new field, three values,
reusing every existing `status` value unchanged, with a name
(`mutation_state`) and value set chosen specifically so that `"unknown"` is
a first-class, expected, non-alarming outcome rather than an edge case —
because Part 5 shows it will be the single most common outcome of any
`v-add-web-domain` failure that isn't a pre-flight validation rejection.

---

# PART 5 — `domain.create` as the First Write: What the Adapter Can Actually Know

Full trace of `bin/v-add-web-domain` (261 lines, read in full for this
document), in execution order, annotated with what state changes and where
failure is possible. Line numbers below refer to the script as currently
committed.

## Files/state modified, and service operations, in order

| Lines | Action | Reversible in principle? | Checked for failure? |
|---|---|---|---|
| 51-88 | Verifications only (`is_system_enabled`, `check_args`, `is_format_valid`, `is_object_valid`, `is_object_unsuspended`, `is_package_full` ×2, uniqueness checks via `v-list-web-domain` reads, `is_dir_symlink` ×2, `is_base_domain_owner`, `is_ip_valid`/`get_user_ip`, `check_hestia_demo_mode`) | N/A — nothing written yet | Yes — every one of these is a `check_result`/exit-on-failure gate via `func/main.sh`'s validation primitives, confirmed by pattern already documented in the architecture review |
| 97 | `[[ -e "$HOMEDIR/$user/web/$domain" ]] && check_result E_EXISTS ...` | N/A | Yes |
| 100-101 | `mkdir` + `chown` the domain's web directory | Yes (`rm -rf`) | **No explicit check** — `mkdir`'s own exit code is not checked; a failure here (e.g. disk full, permission issue) would not stop the script, since no `check_result`/`||` follows it |
| 102-107 | Five `$BIN/v-add-fs-directory` calls (public_html, document_errors, cgi-bin, private, stats, logs) — **each a separate subprocess invocation of another `v-*` script** | Yes, in principle (delete the dirs) | **No** — none of these five calls has its exit code checked |
| 110-114 | `touch` three log files, symlink them into the domain's `logs/` dir | Yes | No |
| 117-120 | `cp -r $WEBTPL/skel/*` (the domain's initial file skeleton) into the new directory, then `sed -i` every copied file to replace `%domain%` placeholders | Yes (delete the tree) | No |
| 123-137 | A sequence of `chown`/`chmod`/`find ... xargs chmod` calls setting ownership and permissions on the new tree | Yes (permissions can be reset) | No |
| 140-153 | **Conditionally** (`if [ -n "$WEB_BACKEND" ]`): possibly writes `BACKEND_TEMPLATE` into `$USER_DATA/user.conf` (via `sed -i` or `update_user_value`), then calls `$BIN/v-add-web-domain-backend` — **another `v-*` script, this time WITH its exit code checked** (`check_result $? "Backend error" > /dev/null`, line 152) | The `user.conf` edit: yes, in principle (revert the field). The backend script's own effects: unknown from this script's perspective — it's an opaque subprocess call | **Partially** — this is the ONE external-script call in the whole flow whose failure the script explicitly detects and exits on |
| 155-184 | Pure in-memory alias string computation (`ALIAS=...`), no filesystem/config writes | N/A | N/A |
| 186-189 | Possibly writes `WEB_TEMPLATE` default into `user.conf` via `update_user_value` | Yes, in principle | No |
| 192 | `add_web_config "$WEB_SYSTEM" "$WEB_TEMPLATE.tpl"` — renders and writes the actual Nginx/Apache vhost config file (`func/domain.sh`'s `add_web_config()`, read for this document: builds `$HOMEDIR/$user/conf/web/$domain/$1.conf` via `mkdir -p` + `cat template \| sed ... > $conf`) | Yes, in principle (delete the rendered config file) | **No** — this script has no `check_result`/exit-code check immediately after this call at all |
| 195-224 | **Conditionally** (`if [ -n "$PROXY_SYSTEM" ]`): computes default proxy extension list, possibly writes `PROXY_TEMPLATE` default into `user.conf`, calls `add_web_config` a second time for the proxy config | Yes, in principle | No |
| 231-233 | `increase_ip_value`, `increase_user_value "$user" '$U_WEB_DOMAINS'`, `increase_user_value "$user" '$U_WEB_ALIASES' ...` — the confirmed-racy counter mutation (`ARCHITECTURE_REVIEW.md` Area 2) | Yes, in principle (`decrease_ip_value`/`decrease_user_value` exist as counterparts, per the same function family) | No — these are plain function calls with no return-code check |
| 241-245 | `echo "DOMAIN='$domain' ..." >> $USER_DATA/web.conf` — the actual domain record is appended **here**, i.e. AFTER the counters were already incremented (231-233), not before | Yes, in principle (remove the appended line) | No |
| 247 | `syshealth_repair_web_config` — an additional repair/normalization pass (`func/syshealth.sh`, not traced further in this pass — **flagged as not independently verified**, consistent with this document's own standard of not asserting beyond what was actually read) | Unknown | Unknown from this trace |
| 250-251 | `$BIN/v-restart-web "$restart"` — **another `v-*` subprocess call, with its exit code checked** (`check_result $? "Web restart failed"`) | N/A (a restart is not itself a data mutation to reverse — but a FAILED restart leaves the *previous* running config still active while the *new* config file already exists on disk, an inconsistency, not something "reversible" in the same sense as a file write) | Yes |
| 254-255 | `$BIN/v-restart-proxy "$restart"` — same pattern | Same as above | Yes |
| 258-259 | `$BIN/v-log-action` + `log_event $OK` — **only reached if every prior `check_result` passed** | N/A | N/A |

## Answers to the specific questions posed

- **Files modified**: the domain's entire web directory tree (`mkdir`,
  copied skeleton, permissions), three log files + symlinks, one or two
  rendered vhost config files (`add_web_config`, web + optionally proxy),
  possibly several fields inside `$USER_DATA/user.conf`
  (`BACKEND_TEMPLATE`, `WEB_TEMPLATE`, `PROXY_TEMPLATE` defaults), and
  finally `$USER_DATA/web.conf` (the domain's own record, appended near
  the end, not the beginning).
- **State changes**: two in-memory-then-persisted counters
  (`U_WEB_DOMAINS`, `U_WEB_ALIASES` in `user.conf`) and one IP-usage
  counter (`increase_ip_value`), all confirmed unchecked for
  success/failure at the point they're called.
- **External/service operations**: two — `v-restart-web`, `v-restart-proxy`
  — both are the *only* two steps in the entire script whose failure is
  guaranteed to be visible to the adapter as this specific operation's own
  non-zero exit code close to the very end of a long sequence of
  already-completed writes.
- **Multiple operations**: yes, unambiguously — at minimum six separate
  subprocess invocations of other `v-*` scripts (`v-list-web-domain` ×2 for
  uniqueness checks, `v-add-fs-directory` ×5, `v-add-web-domain-backend`
  conditionally, `v-restart-web`, `v-restart-proxy`, `v-log-action`), plus
  dozens of direct filesystem operations, inside what the adapter's
  registry entry would describe as a single logical `domain.create`
  operation.
- **Calls other `v-*` scripts**: yes, confirmed above — this is not a
  single self-contained script; it's an orchestrator of several others,
  each running as its own subprocess with its own exit code, most of which
  (`v-add-fs-directory` ×5) are not even checked by the orchestrating
  script.
- **Rollback exists**: **no** — zero `trap`, zero cleanup-on-error branch,
  zero compensating call of any kind was found anywhere in the 261 lines.
  A failure at, say, line 152 (`check_result $? "Backend error"`) exits the
  script immediately, leaving the directory tree, skeleton files,
  permissions, log files/symlinks, and any `user.conf` edits already made
  in lines 100-150 permanently in place — the domain simply doesn't have a
  `web.conf` row, a rendered vhost config, or incremented counters, but
  everything before that point stays.
- **Rollback possible**: **in principle, yes, for most individual steps**
  (every filesystem write above has an obvious inverse: `rm -rf`, delete a
  config file, `decrease_user_value`/`decrease_ip_value` exist as the
  counters' actual counterparts per the same function family already
  documented). **In practice, not without new code that does not exist
  today** — building it is explicitly the subject of Part 6, not something
  this document is claiming is free or already available.

## Can the adapter ever reliably classify partial mutation for this operation?

**No, not from outside the process, and not without new instrumentation
this design does not propose adding.** The table above demonstrates the
central problem concretely: a non-zero exit from `v-add-web-domain` could
mean the very first `mkdir` failed (near-zero actual mutation) or it could
mean everything through line 245 succeeded and only `v-restart-web` failed
at line 251 (near-total mutation, domain fully recorded in `web.conf` and
counters already incremented) — **both produce the same observable signal
class from the adapter's vantage point: a non-zero exit code, whatever text
landed in stdout/stderr.** This is the concrete, source-verified
justification for Part 4's `mutation_state: "unknown"` outcome being the
adapter's honest answer for essentially every non-zero exit from this
specific operation, and for rejecting any model (Part 4's Model A) that
would claim more certainty than that.

**Do not assume atomicity — confirmed, not assumed**: the script is a long,
sequential, sub-process-composing procedure with no transactional wrapper
of any kind found. This document does not treat that as a flaw to fix here
(see Part 6); it treats it as an accurately-observed property of the
existing implementation that the result model (Part 4) must be honest
about rather than paper over.

---

# PART 6 — Transaction / Rollback Question

## Should the adapter attempt to become a transaction engine?

**No. The task's assumption is correct, and this document evaluates and
confirms it rather than merely restating it.**

### The case for evaluating it seriously, not just agreeing by default

Given Part 5's finding that most of `v-add-web-domain`'s individual steps
*are* reversible in principle (there is a `decrease_user_value` counterpart
to `increase_user_value`, a directory can be `rm -rf`'d, a config file can
be deleted), it's fair to ask whether the adapter *could* attempt a
best-effort rollback on failure, since the primitives to reverse most
individual steps already exist elsewhere in `func/main.sh`.

### Why this is still the wrong move, evaluated on the actual evidence

1. **The adapter does not know which steps completed.** Part 5's central
   finding is exactly this: from outside the process, a non-zero exit does
   not tell the adapter *which* of the ~15 distinct mutating steps ran.
   A rollback attempt built on "assume everything up to some guessed point
   happened" is not meaningfully safer than doing nothing — it risks
   actively **deleting data that was never created** (e.g., attempting to
   `rm -rf` a domain directory that `mkdir` itself failed to create, or
   worse, one that existed for an unrelated reason if the adapter's
   assumptions about state are wrong) or **failing to clean up data that
   was created** (if the guess undershoots). Either failure mode is worse
   than the status quo of "leave it and surface `mutation_state: unknown`
   honestly."
2. **Several of the "reversible in principle" steps are themselves
   subprocess calls to other, unmodified `v-*` scripts** (`v-add-fs-directory`
   ×5, `v-add-web-domain-backend`) — a rollback attempt would need to
   correctly reverse *those* scripts' own effects too, which the adapter
   has exactly the same opacity problem for, recursively. There is no
   natural stopping point short of reimplementing significant parts of
   Hestia's own internals as reversible operations — which is precisely
   the kind of large, out-of-scope undertaking the task's constraints (no
   rewrite of `bin/v-*`) already rule out.
3. **A destructive operation (Part 1's `destructive` field) makes this
   categorically worse, not just riskier**: for a hypothetical
   `domain.delete`, an automatic "rollback" attempt after a failure could
   mean automatically *recreating* deleted data from a guess about what
   existed — actively dangerous, not merely unhelpful. Even for
   `domain.create` (`destructive: false`), the asymmetry in consequence
   between "leave harmless orphaned files/directories" (current behavior,
   worst case is disk clutter and a confusing but recoverable state an
   admin can inspect) and "actively delete or recreate data based on an
   uncertain guess" (a rollback attempt gone wrong) strongly favors doing
   nothing automatic.
4. **The existing Hestia CLI itself does not attempt this** — Part 5
   confirms zero rollback code in `v-add-web-domain` itself. An adapter
   that tries to add transactional safety the underlying implementation
   was never designed to support is retrofitting a guarantee onto a system
   that has no internal concept of it, from a vantage point (outside the
   process) that has strictly less information than the script itself had
   while running.

### What this means concretely

**The adapter's job, confirmed, is to serialize (Part 2) and execute (Part
3) existing Hestia operations faithfully, and to report honestly what it
does and does not know (Part 4) — not to reverse arbitrary Bash side
effects it cannot fully observe.** If a `domain.create` call fails partway,
the correct behavior is: release the lock, return
`mutation_state: unknown`, and leave whatever state exists on disk exactly
as `v-add-web-domain` left it — the same behavior a direct `exec()` caller
already gets today, with no regression and no false new safety claimed.

**If rollback is ever wanted, it is out of scope for this adapter design
entirely and would need to be a deliberate, separate future capability**
built with the underlying script's own cooperation (e.g., a `v-*` script
that itself supports a dry-run/checkpoint/rollback protocol, or a redesigned
reversible-operation primitive) — not something layered on top of an
unmodified, non-cooperating script from outside. This document does not
propose that capability or estimate it; it only states plainly, per the
task's explicit request, that rollback is **not safely possible with the
current architecture and this adapter should not attempt it.**

---

# PART 7 — Future API v2 Semantics

## Internal adapter contract first (this is what actually needs to be right)

The adapter's own result model (Part 4: `status` + `mutation_state`) is
already transport-agnostic — it says nothing about HTTP. This is
deliberate: API v2 does not exist yet (explicitly out of scope for this and
every prior pass), and locking this design to specific HTTP status codes
before API v2's actual design work happens would be encoding a decision
this document has no basis to make well. What API v2 needs from the
adapter is exactly the `AdapterResult` shape already implemented — nothing
about Part 4's model requires knowing HTTP exists.

## A first-pass sketch of the HTTP mapping (illustrative, not binding)

Offered only because the task asks for examples, and explicitly marked as
**not a commitment** — this is the kind of decision that belongs in an
actual API v2 design pass, informed by whatever HTTP conventions the fork
chooses then (the existing `exit_code_to_http_code()` in
`web/inc/helpers.php`, confirmed in the original architecture review, is
the existing precedent a real API v2 pass would need to reconcile this
against, not something this document should silently diverge from without
that reconciliation happening explicitly):

| Internal result | Illustrative HTTP mapping | Why (tentative) |
|---|---|---|
| `status: ok`, `mutation_state: confirmed` | 200 or 201 | Operation completed as requested |
| `status: adapter_error`, `mutation_state: not_attempted` (validation/unknown-op) | 400 or 422 | Caller error, nothing happened, matches existing `E_INVALID → 422` precedent |
| `status: adapter_error`, `adapter_error_code: LOCK_TIMEOUT` | 409 | "Conflict" — another operation for this resource is in progress, a real REST convention fit, though not yet validated against this fork's broader API v2 conventions |
| `status: hestia_error`, `mutation_state: unknown` | **Not 200, not confidently 4xx or 5xx without more thought** | This is the case worth flagging as genuinely unresolved: the operation may have partially succeeded, which doesn't map cleanly onto REST's implicit assumption that a response describes one clean before/after state transition — **this is exactly the kind of question a real API v2 design pass needs to resolve deliberately, not inherit by accident from whatever felt natural here** |
| `status: timeout`/`cancelled` | 504 or a custom code | Distinct from a Hestia-level error; the operation's fate is unknown for a different reason (the adapter gave up waiting, not that Hestia reported failure) |

The one row flagged above (`hestia_error` + `unknown`) is the honest
takeaway of this section: **API v2 cannot paper over the same uncertainty
Part 4 establishes at the adapter level — it can only choose how to
communicate that uncertainty to an HTTP client**, which is a UX/API-design
decision, not something this locking/result-semantics document should
pre-decide.

---

# PART 8 — Concurrency Scenarios

All scenarios assume `domain.create` exists as a registered, adapter-backed
operation with the locking model from Part 2 (i.e., describing the
*designed* future behavior — none of this is implemented yet).

### A. Same user, two `domain.create` calls

`admin` submits `domain.create` for `alpha.example.com` and, moments later
(or truly simultaneously, e.g. a double-submitted form), for
`beta.example.com`. **Serialized.** Both acquire
`$HESTIA/data/users/admin/.adapter.lock`; the second call blocks (up to
`lock_timeout_seconds`) until the first releases it, then proceeds. This is
exactly the scenario the confirmed `is_package_full()`/`increase_user_value()`
races (`ARCHITECTURE_REVIEW.md` Area 2) require serialization to fix, and is
the primary case this whole design exists for.

### B. Different users, two `domain.create` calls

`admin` creates a domain; `bob` creates a domain, at the same moment. **Not
serialized against each other.** Different lock files
(`.../admin/.adapter.lock` vs. `.../bob/.adapter.lock`); both proceed fully
in parallel. This is the entire benefit of per-user (not global) locking.

### C. `domain.create` + `backup.create` for the same user

`admin` creates a domain while a scheduled `backup.create` (a future,
not-yet-registered operation, but one whose registry entry would also
declare `mutation.kind !== "read"`, since backups mutate `$USER_DATA`-adjacent
state per the original architecture review's Backup Analysis) is also
running for `admin`. **Serialized** — both are mutating operations for the
same user and both would acquire the same per-user lock, regardless of
being conceptually "different kinds" of operation (domain vs. backup).
This is intentional per Part 2's granularity decision: the lock is scoped
to "this user's shared data," not to "this specific resource type," because
the confirmed races are at the user-data level (`user.conf`'s counters),
not the resource-type level.

### D. `domain.get` + `domain.create` for the same user

`admin` runs `domain.create` for a new domain while another request reads
`domain.get` for an existing one. **Not serialized.** `domain.get`'s
registry entry declares `mutation.kind: "read"`; per Part 3's sequencing,
the adapter never attempts lock acquisition for it at all. The read
proceeds immediately, concurrently with the write. (Whether the read might
observe `web.conf` mid-write by the concurrent `domain.create` — e.g., see
a partially-appended line — is a real, narrower question this document
does not resolve: `func/main.sh`'s `sed -i`/`echo >>` operations are not
themselves atomic at the byte level for a concurrent reader, and per Part 2
this design does not attempt read/write mutual exclusion, only write/write.
This is flagged, not silently ignored: full read/write isolation would
require either locking reads too — which would regress `domain.get`'s
already-proven zero-lock-overhead property for no evidence of an actual
observed problem — or a different mechanism entirely, and is judged out of
scope for this pass, consistent with the task's framing of the problem as
specifically "per-user locking for mutating operations.")

### E. Direct legacy PHP call + adapter call for the same user

`web/inc/main.php`'s existing, unmodified `exec("sudo v-add-web-domain
...")` call site runs for `admin` at the same moment as an adapter-routed
`domain.create` call, also for `admin`. **Not serialized — this is exactly
the bypass limitation named explicitly in Part 2.** The legacy call
acquires no lock at all (it doesn't know the adapter's lock exists); the
adapter call acquires and holds its lock as normal, but that lock provides
no protection against the concurrent legacy call, which can freely read/
modify the same `user.conf`/`web.conf` files at the same time. This
scenario is the concrete illustration of Part 2's migration-strategy
amendment: the race is only actually closed for `admin` once
`web/inc/main.php`'s direct call to `v-add-web-domain` is removed in favor
of routing through the adapter (per `ARCHITECTURE_ADAPTER_DESIGN.md`
section 11's migration steps) — until then, scenario E remains possible in
production even after `domain.create` ships behind the adapter.

---

# PART 9 — Recommendation

## Registry metadata

Add a `mutation` object to each registry entry:

```php
"mutation" => [
	"kind" => "create" | "update" | "delete" | "read",
	"config_write" => bool,
	"service_reload" => bool,
	"destructive" => bool,
],
```

Reject a single `mutates_state` boolean as insufficiently expressive; adopt
the structured object above, deliberately still minimal (four fields, no
`external_side_effects` field yet — add only when an operation with a real
external effect is actually being registered).

## Lock model

Per-Hestia-username `flock` on `$HESTIA/data/users/<username>/.adapter.lock`,
username taken only from an already shape-validated parameter (never raw
caller input), with `basename()` applied as redundant defense-in-depth.
Blocking acquisition with a bounded `lock_timeout_seconds` wait, not
indefinite blocking and not bare non-blocking failure. Released
unconditionally after the underlying process's result is captured,
regardless of outcome, and regardless of any adapter-internal error
afterward (must not leak on adapter bugs). Global, per-domain, and
in-Bash-script locking are all rejected, each for a specific evidenced
reason (global: unnecessary cross-user contention; per-domain: does not
cover the confirmed user-scoped counter race; in-script: doesn't close the
bypass gap any better and requires modifying 524 scripts for no
corresponding benefit).

## Lock acquisition point

Inside `CommandAdapter::invoke()`, after registry resolution and all
parameter validation (unexpected-parameter rejection, required/shape
checks) have passed, and after mutability has been read from the resolved
entry's `mutation.kind` — but strictly before argv construction and process
execution. Rejections happen before lock acquisition is even attempted, so
that no concurrent caller for the same user is ever made to wait behind a
request that was always going to fail validation. Lock-acquisition failure
(timeout or mechanism error) is itself a new pre-execution rejection
(`adapter_error` with `LOCK_TIMEOUT`/`LOCK_UNAVAILABLE`), not a new status
category.

## Result model

No new `status` values. One new field, `mutation_state` (`not_attempted` |
`confirmed` | `unknown`), populated only for non-read operations, mapping
directly onto the task's five required distinctions (Part 4's table).
Explicitly reject any model, including a `partial_failure` status, that
would claim the adapter can determine partial mutation occurred merely from
a non-zero exit code — Part 5's full trace of `v-add-web-domain` is the
concrete, source-verified justification for why `"unknown"` — not a more
specific guess — is the honest answer for essentially every failure that
isn't a pre-flight rejection.

## Mutation-state semantics

`not_attempted` when no process was ever spawned (every existing
pre-execution rejection reason, plus the two new lock-related ones).
`confirmed` when the process exited zero — trusting the CLI's own success
signal, the same signal every existing direct caller already trusts, no
more. `unknown` for every non-zero exit and every timeout/cancellation that
occurs after the process was spawned — deliberately the most common
outcome for real-world write-operation failures, per Part 5's evidence, and
deliberately not further subdivided without evidence the adapter can
actually earn that additional confidence.

## What must be implemented before `domain.create`

1. The per-user `flock` mechanism itself (still explicitly not implemented
   by this document, per the task's constraint) — Part 2/3 give an
   implementer everything needed to build it without further design
   decisions.
2. The `mutation_state` field on `AdapterResult` and the logic in
   `CommandAdapter` to populate it per Part 4's rules — mechanical once
   Part 2's lock exists, since it derives entirely from already-available
   signals (was the process spawned, what was its exit code).
3. The `mutation` registry field (Part 1) on `domain.create`'s own entry,
   written the same source-verified way `domain.get`/`domain.list`'s
   entries were (this document's Part 5 already did that verification for
   `v-add-web-domain`, so this specific piece is largely done as a
   byproduct of this design pass, though still not committed to code).
4. A decision — not made by this document — on the exact
   `lock_timeout_seconds` default and whether it should be configurable per
   operation or global to the adapter; Part 2 establishes that a bounded
   wait is correct but does not pick a number.

## What should remain explicitly out of scope

Automatic rollback/compensation of any kind (Part 6 — rejected, not merely
deferred, given the current architecture). API v2's actual HTTP status
mapping (Part 7 — sketched illustratively only; a real decision belongs to
an API v2 design pass with its own reconciliation against
`exit_code_to_http_code()`'s existing precedent). Read/write mutual
exclusion (Part 8 scenario D's flagged, unresolved question) — locking
reads was never in scope and this document does not silently expand into
it. Migrating any existing legacy call site off direct `exec()` — that
remains `ARCHITECTURE_ADAPTER_DESIGN.md` section 11's job, unaffected by
this document except for the explicit warning (Part 2) that the race isn't
actually closed for real users until that migration happens for a given
operation. And, as in every prior pass: no `flock` code, no `domain.create`
registry entry, no API v2, no audit persistence — this document is design
only, exactly as instructed.
