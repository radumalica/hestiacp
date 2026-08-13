# Domain Delete Implementation

Implementation report for `domain.delete` — the second real mutating
operation registered in `CommandRegistry`, mapping to
`bin/v-delete-web-domain`. Added specifically to stress-test the
architecture that `domain.create` proved with a second, structurally
different, genuinely destructive mutating operation — per
`ADAPTER_ARCHITECTURE_CHECKPOINT.md`'s explicit recommendation to design
any future `mutation_state` refinement "after `domain.delete` exists...
so the schema is built against two real write operations' actual
exit-code behavior instead of guessed from one."

`bin/v-delete-web-domain`, `func/main.sh`, sudoers, and all other
pre-existing Hestia files were NOT modified. `CommandAdapter.php` was
NOT modified. No other operation was added.

---

## 1. Command Contract (discovered from source)

Discovered by reading `bin/v-delete-web-domain` (163 lines) in full,
plus `is_object_valid()`/`check_hestia_demo_mode()`/`check_args()` in
`func/main.sh` — not from the script's `# options:` header comment.

**Argument definition** (lines 17-20):

```
user=$1
domain=$2
domain_idn=$2
restart=$3
```

Only **3 positional slots** — far simpler than `v-add-web-domain`'s 6.
`check_args '2' "$#" 'USER DOMAIN [RESTART]'` (line 43): only `USER` and
`DOMAIN` are required; `restart` may be omitted entirely.

**Validation performed by the script itself** (its own "Verifications"
section, lines 43-50), independent of anything this adapter does:

- `check_args '2' "$#" ...` — `E_ARGS` (1) if fewer than 2 arguments.
- `is_format_valid 'user' 'domain' 'restart'` — shape validation for
  every argument actually supplied.
- `is_system_enabled "$WEB_SYSTEM" 'WEB_SYSTEM'` — `E_DISABLED` (11) if
  the web system is off.
- `is_object_valid 'user' 'USER' "$user"` — `E_NOTEXIST` (3) if the user
  does not exist.
- `is_object_valid 'web' 'DOMAIN' "$domain"` — `E_NOTEXIST` (3) if the
  domain does not exist **for this user** (`func/main.sh`'s
  `is_object_valid()`, non-`USER`/`KEY` branch: `grep "$2='$3'"
  $HESTIA/data/users/$user/$1.conf` — scoped to `$user`'s own
  `web.conf`, confirming this operation IS user-scoped: deleting a
  domain that exists but is owned by a *different* user also produces
  `E_NOTEXIST`, not a distinct "not yours" error).
- `check_hestia_demo_mode` — a direct `exit 1` (not `E_INVALID`; the
  literal exit code is `1`, i.e. `E_ARGS` in `CommandAdapter`'s mapping
  table — same "don't assume the code from the message text" caveat
  already documented for `v-add-web-domain`'s IP-format check in
  `DOMAIN_CREATE_IMPLEMENTATION.md`) if the install is in read-only demo
  mode.

**No quota/package check exists for deletion** — unlike
`v-add-web-domain`'s `is_package_full`, there is nothing to check when
*removing* a resource. This is a genuine, source-confirmed asymmetry
between the two operations' failure surfaces.

**Is deletion user-scoped?** Yes, confirmed above — a caller cannot
delete a domain belonging to a different Hestia user through this
script; that attempt fails with `E_NOTEXIST`, exactly as if the domain
didn't exist at all (from this user's perspective, it doesn't).

**Exit codes observed in the source**: `E_ARGS` (1, too few args, and
the literal `exit 1` from demo mode), `E_INVALID` (2, from
`is_format_valid`'s malformed-argument path), `E_NOTEXIST` (3, missing
user or missing/not-owned domain), `E_DISABLED` (11, web system off),
`E_RESTART` (20, if any of the three post-deletion service restarts
fails — see "Service Reload / Failure Semantics" below). All seven are
already present, unmodified, in `CommandAdapter::HESTIA_EXIT_CODES` — no
new exit code needed mapping.

**stdout**: nothing on success (bare `exit` at line 163, same pattern as
`v-add-web-domain`). On failure via `check_result`, `"Error: $2"` goes
to **stdout** (`func/main.sh`'s plain `echo`, no stderr redirection) —
same behavior already documented and handled generically for
`domain.get`/`domain.create`.

**stderr**: nothing in `bin/v-delete-web-domain`'s own source writes to
stderr directly — same as `v-add-web-domain`. A called subprocess (e.g.
a failed `service $WEB_SYSTEM restart` inside `v-restart-web`) could
still populate it in practice; `AdapterResult` captures both streams
unconditionally regardless.

**JSON output**: **none** — confirmed by reading the full script. No
`format` argument, no `case $format in json)` branch, no structured
output of any kind. Same as `v-add-web-domain`; unlike `v-list-web-domain`/
`v-list-web-domains`.

**Services restarted/reloaded**: THREE, not two —
`$BIN/v-restart-web "$restart"` (line 148), `$BIN/v-restart-proxy
"$restart"` (line 152), AND `$BIN/v-restart-web-backend "$restart"
"$version"` (line 156, PHP-FPM backend restart — `v-add-web-domain` has
no equivalent of this third restart). Each independently checked via
`check_result $? "... failed" > /dev/null`, each capable of producing
`E_RESTART`.

**Files/state modified**, in the order the script performs them (all
gated on the corresponding config values actually being set for this
domain — a domain with no FTP users, no SSL, no proxy, no stats simply
skips those steps, per the script's own `if [ -n "$X" ]`/`if [ "$X" =
'yes' ]` guards):

1. FTP users for the domain deleted (`v-delete-web-domain-ftp`, per FTP
   user) — line 61-65, only if `$FTP_USER` is set.
2. Web (PHP-FPM) backend deleted (`v-delete-web-domain-backend`) — line
   68-70, only if `$WEB_BACKEND` is set.
3. Vhost config file removed (`del_web_config`) — line 73.
4. SSL config/certificates removed, only if `$SSL = 'yes'` — lines 76-81.
5. FastCGI cache cleared, only if `$FASTCGI_CACHE = "yes"` — lines 83-86.
6. **The domain's line removed from `$USER_DATA/web.conf`** (`sed -i
   "/DOMAIN='$domain'/ d"`) — line 89. This is the single line that made
   the domain exist as far as `domain.get`/`domain.list`/`is_object_valid`
   are concerned; once this runs, the domain is gone from Hestia's
   perspective, **before** any of the restart calls below.
7. Proxy config removed, only if `$PROXY_SYSTEM` is set — lines 92-100.
8. Web stats config/files removed, only if `$STATS` is set and not `no`
   — lines 103-107.
9. Log files removed (`/var/log/$WEB_SYSTEM/domains/$domain.*`) — lines
   110-112.
10. **Domain directories removed** (`rm -rf $HOMEDIR/$user/web/$domain`,
    `rm -rf $HOMEDIR/$user/conf/web/$domain`) — lines 115-116. This is
    the actual, irreversible data loss step — matches the script's own
    header comment's warning: "This operation is not fully supported by
    'undo' function, so the data recovery is possible only with a help
    of reserve copy."
11. Per-IP and per-user counters decremented (`decrease_ip_value`,
    `decrease_user_value` ×2-3 depending on SSL/aliases/suspension state)
    — lines 123-138.
12. Web server, proxy server, and PHP backend all restarted/reloaded —
    lines 148-157 (see "Service Reload / Failure Semantics" below).
13. `$BIN/v-log-action` + `log_event "$OK" "$ARGUMENTS"` — Hestia's own
    audit log, same as every other operation, unaffected by this
    adapter's still-unimplemented audit persistence.

**Other `v-*` scripts called internally**: `v-delete-web-domain-ftp`
(conditionally, per FTP user), `v-delete-web-domain-backend`
(conditionally), `v-delete-web-domain-ssl-force` (conditionally, only if
SSL), `v-delete-fastcgi-cache` (conditionally), `v-restart-web`,
`v-restart-proxy`, `v-restart-web-backend`, `v-log-action`.

**Whether it can partially mutate state before returning a non-zero
exit code**: **yes, and this is the single most important finding of
this pass** — see "Mutation Semantics" below. Everything through step 10
above (directories actually removed, `web.conf` line actually removed)
happens **before** step 12's three restart calls, any of which can
return `E_RESTART`. Unlike `v-add-web-domain`'s `E_EXISTS` (which fires
during *Verifications*, genuinely before any mutation),
`v-delete-web-domain`'s only non-`E_RESTART` failure modes (`E_ARGS`,
`E_INVALID`, `E_NOTEXIST`, `E_DISABLED`) ALL fire during *Verifications*
too — meaning **every reachable failure mode of this specific script
falls into one of exactly two buckets: "definitely nothing happened"
(all Verifications-section failures) or "the deletion is definitely,
completely done, only a restart afterward failed" (E_RESTART)**. There
is no exit code in this script's actual, reachable failure surface that
corresponds to "deletion partially completed" in the sense of "some but
not all of steps 1-10 ran" — each of those steps has no error checking
between it and the next (no `check_result` calls anywhere between lines
57 and 147), so if the script reaches step 1 at all, it will run through
step 12 without stopping early, succeeding or failing only based on
whether the FINAL three restart calls succeed.

**Possible failure points**, in the order they can occur: too few
arguments; malformed user/domain/restart; web system disabled; user does
not exist; domain does not exist (or isn't owned by this user); demo
mode; then — deterministically, no further failure points possible
inside the actual deletion logic itself — web server reload failure,
proxy reload failure, or PHP backend reload failure (any and all of
which occur strictly after the deletion is complete).

---

## 2. Registry Mapping

```php
"domain.delete" => [
	"script" => "v-delete-web-domain",
	"argument_order" => ["user", "domain", "restart"],
	"parameters" => [
		"user"   => ["type" => "username", "required" => true],
		"domain" => ["type" => "domain",   "required" => true],
	],
	"fixed_parameters" => [
		"restart" => "yes",
	],
	"mutation" => ["kind" => "delete"],
],
```

Fits the **exact existing registry schema** — no new field, no schema
change. No `output_format`/`result_shape` key (the script has no JSON
mode). This is `web/inc/adapter/CommandRegistry.php`'s only change for
this task.

## 3. Parameters Exposed

**Public (caller-supplied), both required**: `user` (`username` type),
`domain` (`domain` type) — **identical types to every prior operation**,
reusing `ParameterValidator::isValidUsername()`/`isValidDomain()`
unchanged. No new validator was written for this task.

**Why not expose `restart`?** Same reasoning as `domain.create`'s
decision to keep it fixed: no existing production caller of
`v-delete-web-domain` was found to pass a caller-chosen restart value
(there is currently no PHP UI call site for domain deletion at all —
unlike `domain.create`, which had `web/add/web/index.php` as a concrete
precedent to match), and fixing it to `"yes"` matches the only
established precedent in this codebase (`domain.create`'s own fixed
`"yes"`) and ensures a deletion's effects (removed vhost/proxy config)
are actually applied rather than left in a stale-but-half-deleted state.

## 4. Fixed Parameters

Exactly one: `restart => "yes"`. A compile-time string literal in
`CommandRegistry.php`, no caller path to it whatsoever (confirmed:
supplying `"restart"` as a caller parameter is rejected with
`UNEXPECTED_PARAMETER` — test C) — same security property already
established and re-verified for `domain.create`'s four fixed values in
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 4 ("fixed_parameters must
remain literal, or the registry stops being a trustworthy allowlist").

## 5. Validation

Zero new validators. `user`/`domain` reuse
`ParameterValidator::isValidUsername()`/`isValidDomain()` unchanged —
the same two methods every operation registered so far has used. This
is itself evidence toward Section 10.B below.

## 6. Exact Command Construction

For `invoke("domain.delete", ["user" => "admin", "domain" =>
"example.com"])`:

```
argv = ["/usr/local/hestia/bin/v-delete-web-domain", "admin", "example.com", "yes"]
binary = "/usr/bin/sudo"
```

Proven exactly by `DomainDeleteTest::testGeneratedArgv`. Built by the
**same, unmodified** `CommandAdapter::invoke()` argv-construction loop
(`CommandAdapter.php` lines 237-268 as of the `domain.create` pass) that
built `domain.create`'s 7-element and `domain.get`'s 4-element argv —
this operation's 4-element argv (1 fixed slot vs. `domain.create`'s 4,
positioned last instead of scattered) required no change to that loop
whatsoever.

## 7. Locking Behavior

`domain.delete` declares `"mutation" => ["kind" => "delete"]`. The
existing, unmodified locking logic in `CommandAdapter::invoke()` treats
any `kind !== "read"` identically — `"delete"` required no new branch,
no new case, nothing beyond the registry value itself.

**Verified, using the REAL `LockManager` (not a mock) throughout, per
this task's explicit instruction**:

- The lock is acquired for `$target["user"]` before
  `bin/v-delete-web-domain` would be spawned, and released afterward
  (`DomainDeleteTest::testLockReleasedAfterSuccess`/
  `testLockReleasedAfterFailure`/`testLockReleasedAfterException`).
- **The lock is genuinely held — real `flock()`, not a bookkeeping
  flag — for the entire duration of a `domain.delete` call for one
  user.** `testLockGenuinelyHeldDuringExecutionSameUser` proves this
  directly: the fake process runner, while `CommandAdapter`'s own lock
  is held, spins up a SECOND, independent `LockManager` instance
  pointed at the same lock directory and attempts to acquire the SAME
  user's lock — and that attempt fails, exactly as real cross-process
  `flock` contention would fail (the same "two independent open file
  descriptions on one lock file" technique `LockManagerTest.php`
  already establishes as valid). This is a stronger, more direct proof
  than merely asserting `acquireCalls`/`releaseCalls` counts on a spy —
  it demonstrates the actual kernel-level exclusion is in effect for
  the operation's full critical section, matching the requirement to
  "verify that concurrent domain.delete operations for the same user
  are serialized through the existing LockManager."
- **A different user is NOT blocked.** `testDifferentUserNotBlockedDuringExecution`
  uses the identical technique with a different probed username and
  proves the probe succeeds immediately — the lock is per-user, exactly
  as designed, not accidentally global.

No new locking path, no new lock type, no change to `LockManager.php` or
`LockManagerInterface.php` — none was needed.

## 8. Exit-Code Behavior

| Exit code | `hestia_error_code` | Meaning (from source) | Occurs before or after mutation? |
|---|---|---|---|
| 1 | `E_ARGS` | Too few args, or demo mode | Before (Verifications) |
| 2 | `E_INVALID` | Malformed user/domain/restart | Before (Verifications) |
| 3 | `E_NOTEXIST` | User or domain doesn't exist / isn't owned by this user | Before (Verifications) |
| 11 | `E_DISABLED` | Web system not enabled | Before (Verifications) |
| 20 | `E_RESTART` | Web, proxy, or PHP-backend reload failed | **After** — deletion is already fully complete |

All five already exist, unmodified, in `CommandAdapter::HESTIA_EXIT_CODES`.
`errorMessage` derivation (stderr, falling back to stdout, falling back
to a generic message) is the same, unmodified logic already proven for
every prior operation — `testStreamsPreserved` re-confirms it here with
an `E_DISABLED` example.

## 9. Mutation State Behavior

`mutation_state` is `confirmed` for exit `0`, `unknown` for any non-zero
exit — the same, unmodified two-line derivation in `CommandAdapter.php`
(`$mutationState = $processResult->exitCode === 0 ? "confirmed" :
"unknown"`, gated on `$isMutating`). No new value was introduced, per
this task's explicit instruction.

**The finding this task specifically asked to surface, not implement**:
`domain.delete`'s exit-code surface is *cleaner* than `domain.create`'s
in one respect and *more concerning* in another:

- **Cleaner**: as established in Section 1, `domain.delete` has no
  "duplicate"-shaped failure mode analogous to `domain.create`'s
  `E_EXISTS` — every non-`E_RESTART` failure is unambiguously
  pre-mutation.
- **More concerning**: `domain.delete`'s ONE reachable post-mutation
  failure mode, `E_RESTART`, is not merely "possibly happened after
  mutation" (as `WRITE_OPERATION_DESIGN.md`'s general guidance treats
  every non-zero exit) — it is **definitely, unambiguously** after a
  **definitely, unambiguously complete** mutation. There is no
  intermediate case for this specific script: no error check exists
  between the start of the "Action" section and the three restart
  calls, so if `E_RESTART` is the exit code, the domain's directories
  are gone and its `web.conf` entry is gone, full stop, every time. This
  is a **stronger, more certain** version of the same phenomenon
  `DOMAIN_CREATE_IMPLEMENTATION.md`'s "Service Reload / Failure
  Semantics" section identified for `v-add-web-domain`'s own
  `E_RESTART` case (where the created domain's config, similarly, is
  already durably written by the time a restart could fail) — but here
  it applies to a **destructive, irreversible** operation, which raises
  the stakes of the generic model's `unknown` label being the caller's
  only signal.
- **Restated as the specific architecture question this task asked to
  be documented, not resolved**: an API consumer receiving
  `mutation_state: unknown` for a `domain.delete` call currently cannot
  tell "the domain might still exist" (true for every Verifications
  failure) apart from "the domain is 100% gone, only the web server
  didn't reload yet" (true only for `E_RESTART`) — and for a
  **destructive** operation specifically, that distinction plausibly
  matters more to a caller's next action (retry? check state first? did
  my data actually get deleted?) than the equivalent ambiguity does for
  `domain.create`. This is exactly the second, structurally different
  data point `ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 7 said the
  future richer `mutation_state` design should be built against, now
  captured concretely: **`domain.create`'s `E_RESTART` and
  `domain.delete`'s `E_RESTART` are both instances of "exit code X, for
  script Y, is per-source ALWAYS post-mutation" — a per-registry-entry,
  per-exit-code metadata field (e.g. an enumerated list of
  "known-post-mutation" exit codes per operation) is the shape such a
  future enhancement would most likely take, and both operations now
  independently confirm the same specific exit code (`E_RESTART`, 20)
  as the concrete example driving it.** Not implemented here, per this
  task's explicit instruction to only document the finding.

## 10. Tests Added

`test/adapter/DomainDeleteTest.php` — 19 new tests, all using
`FakeProcessRunner` (or the small `LockProbingProcessRunner`-style
anonymous classes for the two concurrency tests) and the REAL
`LockManager` throughout (never a fake locking mechanism, per this
task's explicit instruction). No test requires root or a real Hestia
installation.

| Area | Test(s) |
|---|---|
| Registry | `testRegistered` (operation exists, correct script, correct `mutation.kind`, correct `argument_order`, correct `fixed_parameters`) |
| Command construction | `testGeneratedArgv` (exact argv) |
| Validation | `testUnknownParameterRejected` (`restart` supplied by caller), `testMissingParameterRejected`, `testInvalidUsernameRejected`, `testInvalidDomainRejected`, `testInjectionShapedInputRejected` (5 shell-metacharacter payloads), `testValidationFailureDoesNotAcquireLock` |
| Success | `testSuccessStatusAndMutationState` (`status=ok`, `mutation_state=confirmed`, `exit_code=0`) |
| Hestia errors | `testNotExistFailure` (E_NOTEXIST), `testRestartFailure` (E_RESTART, post-mutation), `testStreamsPreserved` (E_DISABLED, exact stdout/stderr/exit_code/error_message) |
| Locking | `testLockReleasedAfterSuccess`, `testLockReleasedAfterFailure`, `testLockReleasedAfterException`, `testLockTimeoutPreventsExecution` |
| Concurrency (real flock, not fake) | `testLockGenuinelyHeldDuringExecutionSameUser`, `testDifferentUserNotBlockedDuringExecution` |
| Security invariant | `testUnknownOperationStillRejected` |

**Two pre-existing tests required a fix, for the same reason as the
`domain.create` pass**: `CommandAdapterTest::testUnknownOperationRejected`
and `DomainListTest::testUnknownOperationRejected` both used
`"domain.delete"` as their "an operation that doesn't exist" placeholder
(the latter having already been changed once, from `"domain.create"`,
during the `domain.create` pass). Both now use `"domain.rename"` —
explicitly not implemented anywhere in this codebase. This is the only
change made to any pre-existing test file in this pass, besides
`run_tests.php`'s registration lines.

**Full-suite result**: `php test/adapter/run_tests.php` — **73 passed, 0
failed** (54 pre-existing + 19 new for `domain.delete`), run three times
in a row to check for flakiness in the real-flock concurrency probe
tests; all three runs identical.

---

## 11. Anything Discovered That May Require a Future Architecture Change

Restating Section 9's finding as the single concrete architectural
input this task was designed to produce: **`domain.delete`'s `E_RESTART`
gives a second, independent, and stronger example of "an exit code
that, per source, occurs strictly after mutation is complete" — the
same phenomenon `domain.create`'s `E_RESTART` first surfaced.** Two
operations now independently point at the same specific gap
(`ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 7's recommendation to wait
for a second data point before designing a richer `mutation_state`
model). No implementation change is made here — this is the evidence to
design that change against, next.

A second, smaller observation: `domain.delete` has **no quota/limit
failure mode at all** (Section 1), unlike `domain.create`'s `E_LIMIT`.
This is not a gap — it's a genuine, source-confirmed asymmetry — but it
means a future richer result model designed only from `domain.create`
and `domain.delete` would be missing a "operation succeeded but
resource limit reached mid-operation"-shaped case that a *third*
mutating operation might reintroduce; worth keeping in mind rather than
assuming these two operations' exit-code shapes are exhaustive of what
Hestia mutations can produce.

---

## Final Architecture Assessment

### A. Did `domain.delete` fit the existing generic adapter without `CommandAdapter` branching?

**YES.** `CommandAdapter.php` was not modified at all for this task —
confirmed by `git diff --stat` showing zero changes to that file. The
registry entry alone (`argument_order`, `parameters`,
`fixed_parameters`, `mutation.kind`) was sufficient for validation,
argv construction, locking, and mutation-state derivation to all work
correctly, exactly as they did for `domain.create`. This is now the
**second** independent confirmation of this claim (the first being
`domain.create` itself), which is meaningfully stronger evidence than
one data point alone.

### B. Did it require a new validator?

**NO.** Both public parameters (`user`, `domain`) reuse
`ParameterValidator::isValidUsername()`/`isValidDomain()` unchanged —
the same two validators every operation registered so far has used.
Zero new code in `ParameterValidator.php`.

### C. Did it expose a limitation in the current registry schema?

**NO.** The entry fits the exact existing field set
(`script`/`argument_order`/`parameters`/`fixed_parameters`/`mutation`),
with no `output_format`/`result_shape` needed (same as `domain.create`,
for the same reason: no JSON output). No new registry field was added
or found to be missing.

### D. Did it expose a limitation in the current `mutation_state` model?

**Not a limitation that blocks correctness, but YES, a real, now
twice-confirmed gap in USEFULNESS was found and documented** (Section 9,
Section 11): the model cannot distinguish "the domain might still
exist" from "the domain is definitely, irreversibly gone, only the
service reload afterward failed" — both currently report `unknown`.
This does not make the model *wrong* (per `WRITE_OPERATION_DESIGN.md`'s
original, still-correct reasoning, a generic adapter genuinely cannot
know this without script-specific knowledge), but it is now backed by
two independent, source-verified examples of the exact same underlying
pattern (`E_RESTART` firing strictly after a complete, durable
mutation), which is precisely the evidence
`ADAPTER_ARCHITECTURE_CHECKPOINT.md` asked to be collected before
designing a richer model. **No implementation change was made — per
this task's explicit instruction — only documentation of the finding.**

### E. Did it expose a limitation in the per-user locking model?

**NO.** Locking worked identically to `domain.create`, verified this
time with a stronger, more direct proof technique (a live, real-flock
probe from inside the process-runner call, rather than only
acquire/release call counts on a spy) — see Section 7. Same-user
serialization and different-user non-blocking are both confirmed, using
the real `LockManager`, with no changes to `LockManager.php` or
`LockManagerInterface.php`.

### F. Did it reveal any Hestia-specific behavior that should influence API v2 later?

Listed, per this task's instruction — **not implemented**:

- **`domain.delete` is user-scoped at the Hestia CLI level**, meaning a
  future `DELETE /api/v2/domains/{domain}` cannot rely on `{domain}`
  alone to identify the resource — it needs the owning user too (today,
  from `$target["user"]`, which itself currently comes from
  `$actor`/request context with no enforcement — see
  `ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 4's authorization gap,
  restated here as concretely relevant to THIS operation specifically:
  without an authZ layer, nothing stops one user's session from
  supplying a DIFFERENT user's identity as the `user` parameter and
  deleting THAT user's domain).
- **Deletion is irreversible at the Hestia CLI level** ("not fully
  supported by 'undo' function... recovery is possible only with a help
  of reserve copy" — the script's own header comment, and independently
  confirmed true by the source: no soft-delete, no trash/recycle bin,
  `rm -rf` is unconditional once reached). A future API v2 `DELETE`
  endpoint inherits this irreversibility directly; anything like a
  confirmation step, a grace period, or a soft-delete would need to be
  built ENTIRELY above the adapter (e.g. in the API v2 layer, requiring
  a second confirming request before ever calling `domain.delete`) —
  the adapter itself has and should have no concept of this, per
  `ADAPTER_ARCHITECTURE_CHECKPOINT.md` Section 8's product-boundary
  finding.
- **The `E_RESTART`-after-complete-mutation pattern, now seen twice**
  (Section 9/11) — the strongest concrete input for API v2's eventual
  HTTP status-code mapping: an `E_RESTART` result for `domain.delete`
  specifically should probably map to a 2xx (the resource IS gone) with
  a warning, rather than the same 5xx a `LOCK_UNAVAILABLE` might map to
  — a real, evidence-backed API-design decision to make later, not now.
- **No PHP UI call site for domain deletion currently exists to
  reference** (unlike `domain.create`'s `web/add/web/index.php`) — this
  means there is no existing production precedent to validate this
  operation's parameter-exposure decisions against the way
  `domain.create`'s were validated against a real caller. Worth noting
  as a slightly weaker evidentiary basis for the "keep restart fixed"
  decision in Section 3, compared to `domain.create`'s equivalent
  decision.
