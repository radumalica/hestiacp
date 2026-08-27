# Sensitive Parameter Handling — Design and Implementation

Implements the two generic adapter capabilities identified as required by
`DATABASE_CREATE_DESIGN.md`'s `NEEDS ADAPTER CHANGE` verdict (NEW
ABSTRACTIONS 1 and 2). Nothing operation-specific is added: no
`database.create` registry entry, no `bin/v-add-database` invocation, no
change to any Hestia `v-*` script. This document describes the generic
mechanism only; a separate, later review evaluates it before
`database.create` itself is implemented.

---

# PROBLEM

`CommandAdapter::invoke()` had exactly one path for every caller-supplied
parameter: validate its shape, then unconditionally copy it into `$target`
(`CommandAdapter.php:262`, prior to this change), which is then (a) passed
to `AuthorizerInterface::authorize()` and (b) embedded verbatim in the
returned `AdapterResult` — including on every rejection path, since
`rejected()` takes the same `$target` and returns it too. Argv
construction separately took every parameter's value literally
(`(string) $params[$argName]`) and placed it directly into the child
process's argument list.

Every operation implemented before this task (`domain.get`, `domain.list`,
`domain.create`, `domain.delete`, `backup.schedule`) had no parameter for
which either of these behaviors was a problem — none of their parameters
are secrets. `DATABASE_CREATE_DESIGN.md`'s source-first review found that
`database.create`'s minimal, non-speculative parameter contract genuinely
requires one (the database password), and traced two concrete failure
modes if it were added as an ordinary parameter under the existing
mechanism:

1. The plaintext password would sit in `AdapterResult::$target` and in
   `AuthorizerInterface::authorize()`'s target argument for every call —
   including a call authorization goes on to DENY, since target-population
   happens during parameter validation, strictly before the authorization
   check.
2. The plaintext password would be placed directly into the real child
   process's `argv`, visible via `ps aux` / `/proc/<pid>/cmdline` for the
   process's lifetime — even though Hestia's own `v-add-database` already
   supports, and the one existing production caller
   (`web/add/db/index.php`) already uses, a temp-file-path indirection
   specifically to avoid this.

Both gaps are generic — any future operation with a secret parameter would
hit them identically — so the fix implemented here is generic too: two new
optional registry parameter fields (`"sensitive"`, `"delivery"`) and a
small amount of non-branching logic in `CommandAdapter` that reacts to
those fields the same way for every operation, present or future.

---

# SECURITY REQUIREMENTS

Restated from the task and satisfied as described in EXECUTION LIFECYCLE
below:

- The temp file must have restrictive permissions (0600 — owner
  read/write only).
- The temp file's name must not be predictable.
- The temp file must not be created in a shared, world-readable location
  in a way that exposes its *contents* (see the `/tmp` discussion below
  for why the *directory* itself remains `/tmp`, deliberately).
- The temp file must be deleted after execution, on every success and
  failure path.
- The temp file must not be left behind after an exception.
- The temp file must contain only the secret value the command needs —
  nothing else is ever written to it.

**Why `/tmp` (the literal path, `self::TEMP_FILE_DIRECTORY`), not a new
dedicated directory**:
the task explicitly warns not to assume `/tmp` is safe merely because
Hestia uses it, and separately warns against inventing new mechanisms.
Both are honored by the same decision. `LockManager` already establishes
the alternative pattern this codebase uses when a *dedicated*, non-`/tmp`
location is actually warranted: a directory under `$HESTIA/data/`, created
and `chown`'d at install time specifically for the adapter
(`$HESTIA/data/adapter-locks/`, per `LockManager.php`'s own docblock).
That pattern was deliberately NOT reused here, for a reason specific to
this problem: Hestia's own `is_password_valid()`
(`func/main.sh:625-633`) — the function a future `database.create` mapping
would rely on to turn a file path back into a plaintext password inside
`bin/v-add-database` — only activates its file-dereference behavior when
the supplied value matches `^/tmp/`. A dedicated adapter-owned directory
elsewhere would silently break compatibility with that existing,
unmodified Hestia mechanism the very first time this delivery mode was
actually exercised end-to-end. Using the literal path `/tmp` is
therefore not a security compromise adopted for convenience — it is the
ONLY choice compatible with the existing, unmodified Hestia script this
mechanism exists to serve, per the task's own "use Hestia's existing
temp-file convention" instruction.

**Revision note (SENSITIVE_PARAMETER_REVIEW.md finding F-2):** the
original implementation used `sys_get_temp_dir()` rather than the
literal string `/tmp`, reasoning that `sys_get_temp_dir()` "resolves to
`/tmp` on every supported install target." That reasoning was not
source-verified against the actual contract: `sys_get_temp_dir()`
resolves dynamically and honors a `TMPDIR` environment override, whereas
`is_password_valid()`'s check (`func/main.sh:626`,
`[[ "$password" =~ ^/tmp/ ]]`) and the one real production caller
(`web/add/db/index.php:72`, `tempnam("/tmp", "vst")`) both use the
literal string. If the PHP runtime's environment ever set `TMPDIR` to
something other than `/tmp`, `sys_get_temp_dir()` would silently diverge
from the literal-prefix contract this mechanism was built to satisfy —
not with a crash, but with Hestia's script treating the temp-file path
itself as the password value. `CommandAdapter::writeSecureTempFile()` now
uses `self::TEMP_FILE_DIRECTORY` (`"/tmp"`, a fixed, non-configurable
constant) instead of `sys_get_temp_dir()`, closing that gap
deterministically, matching the one real production caller's own
hardcoded choice exactly. The `/tmp` directory's own permissions (world
read/execute plus the sticky bit) mean other local users can see that a
file exists and enumerate `/tmp`'s contents, but — same posture as the one
existing production caller already has today — cannot READ this
mechanism's files: `tempnam()` creates the file with mode 0600 already
(explicitly re-asserted with `chmod()` here as defense-in-depth, not the
only guarantee), and its name is generated from an unpredictable suffix,
not a caller-controlled or guessable value. This is not a new or weaker
security posture than what `web/add/db/index.php` already ships in
production today — it is the same posture, generalized.

**Trailing newline**: verified against the actual reader. Hestia's own
`is_password_valid()` reads the file with `password="$(head -n1 $password)"`
(`func/main.sh:629`) — `head -n1` does not require a trailing newline to
return a line's content, but the ONE existing production caller writes one
anyway (`web/add/db/index.php:74`,
`fwrite($fp, $_POST["v_password"] . "\n")`). This implementation matches
that existing convention exactly (`writeSecureTempFile()` appends `"\n"`
unconditionally) rather than diverging from it for no reason.

---

# REGISTRY METADATA

Two new, optional fields on a parameter definition
(`CommandRegistry`'s existing `"parameters"` array), alongside the
existing `"type"`/`"required"`:

```php
"secret" => [
    "type" => "username",      // shape validation is unchanged and unaffected
    "required" => true,
    "sensitive" => true,       // excluded from $target / authorizer target
    "delivery" => "temp_file", // value delivered via a secure temp file, not literal argv
],
```

- **`"sensitive" => true`** (default: `false`/absent — current behavior
  unchanged): the parameter is still validated normally and is still
  available for argv construction, but is never copied into `$target`.
- **`"delivery" => "temp_file"`** (default: `null`/absent — current
  behavior unchanged): the parameter's value is written to a secure temp
  file at argv-construction time, and the file's PATH — never the
  plaintext — becomes the actual argv entry.
  `CommandAdapter::SUPPORTED_DELIVERY_MODES` is the single, authoritative
  list of valid values (currently `["temp_file"]` — mirrors the existing
  `HESTIA_EXIT_CODES` pattern of "one table, validated against at registry
  construction time, never duplicated").

**The two fields are independent.** `"sensitive"` controls
target/authorizer exposure; `"delivery"` controls how the value reaches
argv. Nothing about routing a value through a temp file requires also
hiding it from `$target`, so `"delivery" => "temp_file"` without
`"sensitive" => true` is allowed (test 14,
`testDeliveryWithoutSensitiveIsAllowed`) — no known caller needs this
combination today, so it is left unforbidden rather than speculatively
restricted, per the task's own "do not invent future mechanisms"
instruction.

## The enforced invariants

`CommandRegistry::validateParameterMetadata()` (called from the
constructor, alongside the existing `validateMutationMetadata()`) rejects,
at construction time, three invalid shapes:

1. **A `"sensitive"` value that is not an actual PHP boolean, and not
   absent/null.** Added after SENSITIVE_PARAMETER_REVIEW.md finding F-1:
   the original implementation compared `"sensitive"` with strict
   `=== true` here while `CommandAdapter::invoke()` compared it with
   loose truthiness (`!($definition["sensitive"] ?? false)`). A value
   like `"sensitive" => 1` therefore passed this method's check (since
   `1 === true` is false, the "requires delivery" rule below never
   fired) while `CommandAdapter` still treated it as sensitive enough to
   exclude from `$target` (since `1` is truthy) — giving every outward
   sign of correct protection while the plaintext still reached argv
   unprotected, because no `delivery` mode had been required. Both sides
   now compare with strict `=== true` — `CommandAdapter`'s gate is
   `(($definition["sensitive"] ?? false) !== true)` — so a malformed
   value can no longer produce disagreement between "excluded from
   target" and "protected in argv." `null` and an absent key remain
   accepted and are both treated identically to `false` (matching the
   pre-existing `?? false`/`?? null` convention already used for every
   other optional field in this registry), since neither is a malformed
   value — only a genuinely wrong type (`1`, `"true"`, `"1"`, an array,
   etc.) is rejected.
2. **A `"delivery"` value not in `CommandAdapter::SUPPORTED_DELIVERY_MODES`.**
   Same "fail loudly on a typo, at construction time" posture
   `validateMutationMetadata()` already established for
   `known_post_mutation_exit_codes`.
3. **`"sensitive" === true` with no `"delivery"` declared at all.** This is
   the invariant the task specifically asked to be reasoned about.
   Decision: **forbidden.** A sensitive value without a safe delivery mode
   would still fall through to the plain `$argv[] = $rawValue;` branch —
   i.e. the plaintext would still be placed directly into argv, which is
   exactly the exposure `"sensitive"` exists to prevent in the FIRST
   place (marking a parameter sensitive only ever protects `$target`; it
   says nothing to the argv-construction step on its own). Since
   `"temp_file"` is currently the only supported delivery mode, this
   invariant is, in effect, "sensitive parameters must currently use
   temp-file delivery" — but the check itself does not hardcode that
   mode's name; it asks only "is a value present in the supported-modes
   table," so a second safe delivery mode, if one is ever added to
   `SUPPORTED_DELIVERY_MODES`, would be accepted without this method
   changing. No such second mode is proposed or implemented here — per
   the task's explicit "do not invent future mechanisms," only the one
   mechanism a real, source-verified need (the database password) exists
   for today is built.

**Scope boundary: `parameters` only, not `fixed_parameters`.** `delivery`
is read from `$parameterSchema[$argName]`, i.e. from an operation's
`parameters` entry — never from `fixed_parameters`. A value in
`fixed_parameters` therefore cannot use temp-file delivery; it is always
placed into argv literally, exactly as before this task. This is
unstated-but-intentional rather than an oversight: `fixed_parameters`
values are registry-authored constants (e.g. a fixed `--format json`),
never actor-supplied secrets, so there is no case in the current design
that needs this combination.

---

# EXECUTION LIFECYCLE

```
resolve → validate → normalize → authorize → lock → execute
                                       ↑
                    (unchanged position — see below)
```

1. **Resolve / validate / normalize** (unchanged sequence): every
   parameter is shape-validated exactly as before. The ONE change here:
   a validated value is copied into `$target` only `if (!$definition["sensitive"] ?? false)`.
   A sensitive value remains available afterward only through the local
   `$params` array (never through `$target`).
2. **Authorize** (unchanged position, unchanged inputs): `$target` —
   already missing any sensitive value — is what
   `AuthorizerInterface::authorize()` receives, exactly as before this
   change. **No temp file has been created yet at this point.**
3. **Argv construction** (existing step, extended): for each argument in
   `argument_order`, if that parameter declares
   `"delivery" => "temp_file"`, its value is written to a new secure temp
   file (`CommandAdapter::writeSecureTempFile()`) and the file's path
   becomes the argv entry; otherwise, behavior is byte-for-byte unchanged
   from before this task. Every path created this way is collected into a
   local `$temporaryFiles` array.
4. **Lock → execute** (unchanged): identical to before this task.
5. **Cleanup**: `CommandAdapter::removeSecureTempFile()` runs, for every
   path in `$temporaryFiles`, inside a `finally` block that wraps the
   ENTIRE region from step 3 through the method's return — see CLEANUP
   GUARANTEES.

**Ordering achieved**: temp files are created strictly AFTER
authorization succeeds, because argv construction (step 3) already ran
strictly after the authorization check in the pre-existing code, and this
task did not move that check. This is a structural argument from code
ordering — the `authorize()` call returns (or throws/rejects) before the
line that opens the `try` around temp-file creation is ever reached, so
there is no code path between them that could create a file first. Test
10 (`testAuthorizationDenialCreatesNoTempFile`) corroborates this at
runtime — it asserts zero new adapter-prefixed temp files exist after a
denied call — but the assertion itself (`before === after` on a glob
count) is equally satisfied by "never created" or by "created and then
cleaned up"; it is evidence of no *leak*, not by itself a proof of no
*creation*. The stronger claim rests on the code-ordering argument above,
not on the test in isolation. No pipeline restructuring was needed to
achieve this — the task's preferred outcome ("prefer designing the flow
so the temp file is not created until after authorization... if
compatible with the existing architecture") held without any special
effort.

---

# AUTHORIZATION INTERACTION

Unchanged mechanism, improved input: `AuthorizerInterface::authorize()`'s
signature, its unconditional-per-call-site invocation, and its position in
the pipeline are all untouched. The only difference is that `$target` —
which was already the sole channel through which caller data reaches the
authorizer — now excludes any parameter marked `"sensitive"`. A future
authorization policy inspecting `$target["database"]` or
`$target["type"]` (say) works exactly as it would for any other
parameter; it simply never receives `$target["password"]`, by
construction, not by any authorizer-side responsibility to redact it
itself.

---

# CLEANUP GUARANTEES

A single `try { ... } finally { foreach ($temporaryFiles as $path) { removeSecureTempFile($path); } }`
wraps the entire region from the start of argv construction through the
method's final `return new AdapterResult(...)`. Every one of the task's
required cleanup cases is covered by this ONE structural guarantee, not
by case-specific cleanup code:

| Case | Why cleanup fires |
|---|---|
| Successful execution | Falls through to the normal `return`, inside the `try` — `finally` still runs. |
| Command failure (non-zero exit) | Same return path as success; only `$status`/`$mutationState` differ. |
| A registry-authoring error discovered mid-argv-build (`REGISTRY_ERROR`) | `return $this->rejected(...)` is itself INSIDE the `try` block — `finally` still runs on a `return` from inside `try`. |
| Lock unavailable / lock timeout | Same as above — both early returns happen inside the same `try`. |
| Process runner throws | The exception propagates out of the INNER `try/finally` (which still releases the lock, unchanged from before this task) and then out of the OUTER `try`, running the OUTER `finally` (temp-file cleanup) before continuing to propagate to the caller — matching `CommandAdapter`'s existing, documented "exceptions propagate unchanged" contract, now with cleanup added ahead of that propagation. |
| Temp-file creation itself fails (e.g. disk full) | Caught locally (`writeSecureTempFile()` throws `\RuntimeException`), converted to a `TEMP_FILE_UNAVAILABLE` rejection; any files ALREADY created for earlier parameters in the same call (only possible with more than one temp-file-delivered parameter) are still in `$temporaryFiles` and are still cleaned up by the same outer `finally`. |
| Authorization denial | Structurally cannot leave a temp file behind — see EXECUTION LIFECYCLE; no file is ever created on this path in the first place. |

`removeSecureTempFile()` itself is deliberately best-effort and
exception-free (`@unlink` after an `is_file()` guard) — cleanup must never
become a NEW failure mode on top of whatever the invocation was already
reporting.

**Scope of this guarantee, stated precisely (added after
SENSITIVE_PARAMETER_REVIEW.md finding F-3):** the table above covers
every normal PHP control-flow exit from `invoke()` — a return, an early
rejection, or a propagating catchable exception. It is a PHP-language
guarantee, not an operating-system one, and it does not, and cannot,
extend to the process itself being terminated before that `finally`
block has a chance to run:

- Normal PHP control-flow cleanup (every case in the table above) is
  guaranteed, via `finally`.
- Hard process termination — `SIGKILL`, an out-of-memory kill, a
  PHP-FPM worker being force-terminated, or a host crash — bypasses
  `finally` entirely; no PHP-level mechanism can run code after the
  interpreter itself has been killed.
- If that happens after a temp file was created but before cleanup ran,
  a 0600-permissioned file containing the real secret value is left on
  disk under `/tmp`.
- Cleanup at that point depends entirely on the host's own `/tmp`
  lifecycle (e.g. `systemd-tmpfiles`, `tmpwatch`, or a reboot) — not on
  anything this mechanism does.
- This is an accepted residual risk for this mechanism, not a defect to
  be engineered around here: no application-level crash-recovery
  mechanism (a cleanup daemon, a persistent temp-file registry, or
  similar) is currently provided, and adding one is out of proportion to
  this mechanism's scope.

---

# FAILURE BEHAVIOR

- **`writeSecureTempFile()` failure** (e.g. `tempnam()` returns `false`,
  or the subsequent `file_put_contents()` fails): surfaced as a new
  adapter error code, `TEMP_FILE_UNAVAILABLE`, via the existing
  `rejected()` path — same shape as the existing `LOCK_UNAVAILABLE`
  code, not a new response shape. This path is not exercised by
  `SensitiveParameterTest.php` (forcing `tempnam()`/`file_put_contents()`
  to fail deterministically was not attempted, and the task's required
  test list does not call for it) — a known gap, not a silent one. It was
  checked by inspection instead: the rejection message is built from
  `$argName` and `$exception->getMessage()` only, and neither call site
  that constructs the `\RuntimeException` inside `writeSecureTempFile()`
  includes the secret `$value` in its message, so this path does not leak
  the value into `errorMessage` even though it is untested.
- **A sensitive value in an error message**: out of scope for this
  mechanism specifically, and no new risk is introduced by it — this task
  changes how parameter values populate `$target`/argv, not what a
  failed command's own stdout/stderr contains. (For the one concrete case
  this mechanism was built to eventually serve, `bin/v-add-database`'s own
  `HIDE=4`/`$ARGUMENTS` masking already keeps Hestia's own log files free
  of the value regardless — see `DATABASE_CREATE_DESIGN.md` PASSWORD
  HANDLING. That finding was not independently re-verified in this pass;
  see that document's caveat.)

---

# WHY PLAINTEXT IS EXCLUDED FROM TARGET

`$target` is not a private, internal-only value — it is handed to
`AuthorizerInterface::authorize()` (an injectable, potentially
third-party-implemented interface) and embedded in every `AdapterResult`
this method returns, including denied/rejected ones. Any future
audit-log, request-tracing, or API-response layer built on top of
`AdapterResult` inherits whatever is in `$target` automatically, with no
additional code of its own. Keeping a secret out of `$target` at the one
generic point it is populated is therefore the smallest change that
protects every current AND future consumer of `AdapterResult`/the
authorizer, without requiring any of those consumers to know to redact it
themselves.

# WHY TEMP-FILE DELIVERY IS USED

Because Hestia's own script already expects and supports it
(`is_password_valid()`), and the one existing production caller
(`web/add/db/index.php`) already uses exactly this mechanism today. Using
the same convention means a future `database.create` registry entry
requires zero changes to `bin/v-add-database` and produces behavior
identical to what the existing PHP UI already does — this generic
mechanism generalizes an existing, proven pattern rather than inventing a
new one.

# WHY THIS IS GENERIC

`CommandAdapter.php` contains no reference to `database.create`,
`v-add-database`, `password`, or `dbuser` anywhere — confirmed by direct
search (see FINAL VERIFICATION). Every behavior described above is
conditioned only on the generic `"sensitive"`/`"delivery"` registry
fields, which any current or future operation may declare. The tests in
`SensitiveParameterTest.php` exercise the mechanism exclusively through
synthetic, test-only registry entries (`CommandRegistry`'s
`$additionalOperations` constructor parameter, the same test-only
extension point `AuthorizationTest.php`/`CommandRegistryValidationTest.php`
already use) — no `database.create` entry exists anywhere in this
codebase after this task.

---

# WHAT IS DELIBERATELY NOT IMPLEMENTED

Per the task's explicit "do not over-engineer" list — none of the
following exist anywhere in this change:

- A secrets manager or Vault integration.
- An encryption-at-rest framework for the temp file (its confinement to
  0600 permissions, an unpredictable name, and prompt deletion is the
  entire security model — the same model the existing production caller
  already relies on).
- A generic credential-provider abstraction.
- A general-purpose logging/redaction framework — only the one, specific,
  already-existing choke point (`$target` population) is touched;
  nothing about how any OTHER surface (stdout/stderr, exception messages
  from unrelated code, PHP's own error log) handles secret-shaped strings
  is changed or newly guarded.
- A new `ProcessRunnerInterface` implementation or method — the mechanism
  lives entirely inside `CommandAdapter`, upstream of the existing,
  unmodified `ProcessRunnerInterface::run($binary, $argv)` contract; the
  runner still just receives a plain `string[]` argv, exactly as before,
  and has no idea any entry in it is a temp file path rather than a
  literal value.
- Any `database.create`-specific code, registry entry, or test.
- Any API v2 endpoint.
- Any change to `bin/v-add-database`, any other Hestia `v-*` script,
  sudoers, or filesystem permissions outside the temp file this mechanism
  itself creates and deletes.
- A second supported delivery mode (e.g. stdin, an environment variable)
  — not proposed because no source-verified need for one exists yet; see
  REGISTRY METADATA's invariant discussion for why the validation logic
  is written to accept one later without changing its own code, should a
  real need appear.
