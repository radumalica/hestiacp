# SENSITIVE PARAMETER & TEMP-FILE DELIVERY — HOSTILE SOURCE-FIRST REVIEW

Review-only pass. No source or test file was modified to produce this
document. All line numbers below refer to the current, uncommitted
working tree at the time of this review.

Files inspected in full for this review:
`web/inc/adapter/CommandAdapter.php`, `web/inc/adapter/CommandRegistry.php`,
`web/inc/adapter/AdapterResult.php`, `web/inc/adapter/AuthorizerInterface.php`,
`web/inc/adapter/AllowAllAuthorizer.php`, `web/inc/adapter/ProcessRunnerInterface.php`,
`web/inc/adapter/ProcOpenProcessRunner.php`, `test/adapter/SensitiveParameterTest.php`,
`func/main.sh` (`is_password_valid`/`is_hash_valid`, lines 625-644),
`web/add/db/index.php` (lines 72-74, the one real production
temp-file-delivery caller). `SENSITIVE_PARAMETER_DESIGN.md` was read but
treated as a claim to verify, not a source of fact — every claim in it
that mattered to this review was re-derived independently from the files
above.

---

# 1. TRACE THE COMPLETE EXECUTION PATH

Full pipeline, with the exact line ranges in `CommandAdapter.php` for
this review's current state:

| Stage | Lines | Can plaintext reach here? | Where stored |
|---|---|---|---|
| `invoke()` entry | 174-181 | No — only `$params` (caller-supplied) exists | `$params` (local var) |
| Parameter validation | 234-311 | Yes — `$value = $params[$name]` (258) | `$value` (local), then conditionally `$target[$name]` (308-310) |
| Target construction | 308-310 | The ONE gate: `if (!($definition["sensitive"] ?? false)) { $target[$name] = $value; }` | Sensitive values never enter `$target` |
| Authorization | 323-337 | `$target` (already missing sensitive values) passed to `authorize()` | Authorizer's own memory, out of adapter's control |
| Argv construction | 365-414 | Yes — `$rawValue = (string) $params[$argName]` (370) still reads the raw value | `$rawValue` (loop-local); for `delivery=temp_file`, written to a new file (393) and the returned path, not `$rawValue`, is pushed to `$argv` (410) |
| Temp-file creation | 622-641 | Yes, briefly, on disk | The temp file itself — this is the intended, minimized exposure surface |
| Lock acquisition | 432-488 | Not touched by this code path at all | n/a |
| Process execution | 490-504 | Only the file PATH is in `$argv`; `$this->runner->run($this->sudoBinary, array_merge([$scriptPath], $argv))` (492) | Child process's own argv, which for a `delivery=temp_file` slot contains a path, not the secret |
| Result construction | 565-584 | `$target` (already scrubbed), `$processResult->stdout`/`stderr` (raw, unfiltered) | `AdapterResult::$target`, `::$stdout`, `::$stderr` |
| `finally` cleanup | 585-594 | n/a — deletes the file, does not touch any in-memory copy | Removes the one on-disk copy |

**Can it escape through the return value?** `$target` — no, verified: the
sensitive value is never copied into `$target` at any point (only site
that populates `$target` is line 309, gated by the `sensitive` check).
`$errorMessage`/`$stdout`/`$stderr` (518-531, 565-584) — **yes, in
principle**, but not because of anything this mechanism does or fails to
do: these fields carry the underlying process's own actual stdout/stderr
verbatim. If a future script the adapter shells out to echoes a secret
value into its own error output, that value reaches `AdapterResult`
regardless of `sensitive`/`delivery` metadata — this mechanism protects
`$target` and argv, nothing else, and does not claim to. This is a
genuine, correctly-scoped boundary, not a defect — but it means
"sensitive" is not a blanket guarantee across the whole `AdapterResult`,
only across `$target` and process argv. Worth stating explicitly because
the design doc's own wording ("why plaintext is excluded from target")
could be over-read as a broader claim than the code delivers.

**Can it escape through an exception?** Two call sites can throw:
`writeSecureTempFile()` (622-641) and `$this->runner->run()` (492).
`writeSecureTempFile()`'s thrown message (627-629, 637) never includes
`$value` — confirmed by reading both `throw` statements; one embeds
`$lastError["message"]` (an OS-level error string), the other embeds only
`$path`. The rejection built from that exception (395-407) interpolates
`$argName` and `$exception->getMessage()` (402) — neither can carry the
secret. `$this->runner->run()` is a caller-supplied `ProcessRunnerInterface`
implementation; whether its own exception message could contain a secret
depends entirely on that implementation, not on this mechanism — the
production `ProcOpenProcessRunner` (below) never touches parameter values
at all, only the resolved `$binary`/`$argv` array (which itself contains
only the path for a `delivery=temp_file` slot).

**Can it escape through an error message?** See above — no, for the two
error paths this mechanism itself introduces (`TEMP_FILE_UNAVAILABLE`).
`hestia_error` messages (line 525-530) are the underlying script's own
stdout/stderr, out of this mechanism's control, as already noted.

**Can it escape through the authorizer?** No — `$target` is built before
`authorize()` is called (309 before 323), and the sensitive value is
never in `$target` to begin with.

**Can it escape through process argv?** No — confirmed at the
`proc_open()` boundary itself (see section 2).

**Can it remain on disk?** Yes, under specific circumstances not covered
by PHP-level exception handling — see sections 3 and 4 for exactly which
ones (OS/process-crash scenarios, not normal-path failures).

---

# 2. PROCESS-LIST EXPOSURE

Traced from `ProcOpenProcessRunner::run()` (`ProcOpenProcessRunner.php`
lines 27-56):

```php
$command = array_merge([$binary], $argv);
...
$process = proc_open($command, $descriptorSpec, $pipes, null, null);
```

`$binary` is `/usr/bin/sudo` (the adapter's `$sudoBinary`); `$argv` is
exactly the array `CommandAdapter::invoke()` built at lines 365-414. For
a `delivery=temp_file` parameter, the entry pushed into `$argv` at line
410 is `$tempPath` — the return value of `writeSecureTempFile()`, which
returns the value of PHP's own `tempnam()` call (624), never `$rawValue`.
The only way the plaintext secret could reach `$argv` is if the `else`
branch at line 412 (`$argv[] = $rawValue;`) executed for a sensitive
parameter — which requires `$delivery !== self::DELIVERY_TEMP_FILE`,
i.e. the parameter's registry entry declaring `sensitive => true` without
a matching `delivery => "temp_file"`. `CommandRegistry::validateParameterMetadata()`
(432-463) is meant to forbid exactly this combination at construction
time — see section 7 for a real gap in that enforcement.

Since PHP 7.4, `proc_open()`'s array-form `$command` executes via
`execve()`-family calls without a shell interpretation step (confirmed by
this codebase's own `ProcOpenProcessRunner.php` docblock, lines 9-21,
which is accurate to PHP's documented array-command behavior). This means
whatever ends up in `$argv` is exactly what appears in
`/proc/<pid>/cmdline` for both the `sudo` process and (once `sudo` execs
it) the target script — for a `delivery=temp_file` slot, that is the file
path, never the plaintext. **Verified from source, not merely trusted
from the test suite**: the code path from argv-construction (410) to
`proc_open()` (37) contains no re-substitution, expansion, or file
dereference of any kind — the string that becomes `$argv[N]` at line 410
is the exact string handed to `proc_open()`.

`ps aux` / any other process listing tool reads the same kernel-exposed
`cmdline`, so the same conclusion holds for those too.

---

# 3. TEMP FILE SECURITY

`writeSecureTempFile()`, lines 622-641:

```php
$path = @tempnam(sys_get_temp_dir(), self::TEMP_FILE_PREFIX);
...
@chmod($path, 0600);
$written = @file_put_contents($path, $value . "\n");
```

- **Filename unpredictability**: `tempnam()` appends a random,
  filesystem-unique suffix (PHP delegates to `mkstemp()`-equivalent
  logic internally); the prefix `hstadapter` is fixed and known, but the
  suffix is not attacker-predictable. This matches the unpredictability
  property of the pattern being mirrored (`web/add/db/index.php:72`,
  `tempnam("/tmp", "vst")`).
- **Permissions**: PHP's `tempnam()` creates the file already restricted
  to the owning user (mode 0600, independent of the process umask — this
  is `tempnam()`'s own documented, glibc-backed guarantee, not something
  this code has to enforce). The explicit `@chmod($path, 0600)` at line
  632 is redundant defense-in-depth over an already-0600 file, not a fix
  for a real gap. **Not perfect, but correct**: there is no window where
  the file exists at a permission looser than 0600 that this code is
  responsible for.
- **Umask interaction**: none — as above, `tempnam()`'s mode is fixed at
  creation time regardless of the calling process's umask.
- **Symlink concerns**: `tempnam()` generates its own unique filename
  under the target directory; it does not open or follow a
  caller-supplied or predictable path, so there is no TOCTOU window in
  which an attacker could pre-place a symlink at the exact path this code
  will use, before it exists.
- **Race conditions**: the create-then-chmod-then-write sequence
  (624 → 632 → 634) is not atomic as a whole, but every individual step is
  already safe at its own permission level: the file is 0600 from the
  moment `tempnam()` returns (624), so the `chmod()` (632) and
  `file_put_contents()` (634) calls that follow never operate on a
  world- or group-readable file. There is no window in this sequence
  where the file is briefly more permissive than 0600.
- **Can another local process replace the file?** Only by first
  deleting/unlinking it, which requires either owning it, being root, or
  (on a `/tmp` without the sticky bit) belonging to a directory with lax
  permissions. Every standard Linux `/tmp` ships with the sticky bit set,
  which prevents non-owners from deleting or renaming another user's
  files in that directory even though the directory itself is
  world-writable. This is an OS-level property this code does not itself
  verify (no `stat()` check on `sys_get_temp_dir()`'s permission bits
  before use) — it inherits whatever protection the deployment's `/tmp`
  actually has, exactly as the pre-existing `web/add/db/index.php`
  convention already does. Not a regression; also not independently
  hardened.
- **Does 0600 mean "only the owning process can read it"?** Only against
  *other unprivileged local users* on the same box. It does not, and
  cannot, protect against `root` — but every consumer of this file is
  Hestia's own `v-*` script chain, invoked via `sudo` (line 492), which
  runs as root anyway. This is not a new gap: root already has
  unconditional access to everything this mechanism produces, exactly as
  it already has to the file `web/add/db/index.php` creates.
- **Can the file survive a PHP crash / SIGKILL / PHP-FPM restart?**
  **Yes.** The `finally` block (585-594) is PHP-language-level exception
  safety; it guarantees cleanup runs for every PHP-level control-flow
  exit (normal return, early return, propagating exception) but provides
  **no guarantee whatsoever** against the PHP process itself being killed
  before that `finally` block executes — `SIGKILL`, an out-of-memory
  kill, a hard crash in a PHP extension, or a `PHP-FPM` worker being
  force-terminated mid-request would all leave the temp file on disk with
  no PHP-level cleanup ever running. This is a real, currently
  undocumented (in the design doc's "cleanup guarantees" table, which
  only lists PHP-level control-flow cases) exposure. See section 4 for
  the severity assessment.

---

# 4. CLEANUP ANALYSIS

Confirmed from source for every listed case:

| Case | Lines | Cleanup fires? | Mechanism |
|---|---|---|---|
| Successful command | 518-584 (return inside `try`) | Yes | `finally` (591-593) |
| Non-zero command exit | 522-531, same return | Yes | Same `finally` |
| Process-runner exception | `run()` call at 492, inside the `try` | Yes | Exception propagates past the inner `finally` (493-504, releases the lock) and then past the outer `finally` (591-593), before continuing to propagate to the caller |
| Timeout | N/A | N/A | No timeout mechanism exists anywhere in this codebase yet (`ProcOpenProcessRunner.php` docblock, lines 23-25, states this explicitly) — there is no separate "timeout" code path to analyze; a hang in the child process is not distinguished from a slow-but-successful one at the PHP level today. Out of scope for this mechanism; not a new gap it introduces. |
| Lock failure (`LockUnavailableException`) | 455-471, `return` inside `try` | Yes | `finally` (591-593) |
| Lock timeout (`!$lockAcquired`) | 473-487, `return` inside `try` | Yes | `finally` (591-593) |
| `AdapterResult` construction failure | 565-584 | N/A | `AdapterResult`'s constructor (`AdapterResult.php` 139-178) does nothing but assign scalar/array fields to properties — there is no plausible failure mode here (no I/O, no validation, no exceptions thrown) to analyze. |
| Unexpected PHP exception (anywhere else in the `try` block) | 365-584 | Yes, for any *catchable* exception | Any exception thrown from inside the `try` (365) up to the `finally` (585) — including one from `$this->authorizer->authorize()` if a real implementation throws, or from `$this->lockManager->acquire()` if it throws something other than `LockUnavailableException` — passes through the `finally` (591-593) before propagating. This is a genuinely strong guarantee: the `finally` is scoped around the entire post-authorization body, not just the process-execution step. |
| Fatal error / process crash | n/a | **No** | PHP fatal errors that are *not* catchable exceptions (a small, specific set — recoverable-in-older-PHP errors, or the process being killed outright) do not run `finally` blocks. See section 3's SIGKILL discussion. |

**Distinction the task asked for, stated explicitly:** the `finally`
block guarantees cleanup for every *PHP-level* exit from the
try — every `return`, every propagating catchable exception. It provides
**zero guarantee** against the PHP process itself being terminated
(SIGKILL, OOM-killer, host crash, `kill -9` on the FPM worker). This is a
real, if narrow, residual risk: a temp file containing a real secret
value can outlive the request that created it if the PHP process dies
hard mid-request, and will then sit on disk at 0600 until whatever
external mechanism cleans `/tmp` (systemd-tmpfiles, `tmpwatch`, or a
reboot) eventually removes it — commonly on the order of days, not
minutes, on a default Linux distribution. **This is the single most
significant caveat this review found on the "cleanup guarantees" claim
in `SENSITIVE_PARAMETER_DESIGN.md`**, which documents PHP-level exception
safety thoroughly but does not call out the crash-survival gap at all.
See FINDING F-3 below.

---

# 5. AUTHORIZATION ORDER

Confirmed directly from the method body's line order, not from comments:

- Parameter validation and `$target` construction: lines 234-311.
- Authorization call: line 323, `if (!$this->authorizer->authorize($operation, $target, $normalizedActor))`.
- The `try` block that contains the ONLY code in this class capable of
  creating a temp file (the loop at 366-414, calling `writeSecureTempFile()`
  at 393) begins at line 365 — **after** line 323/337 (the authorization
  check and its early-return path) in the method's linear control flow.

There is no branch, loop, or jump anywhere between line 337 and line 365
that could reach the temp-file-creation code without first passing
through (and not being denied by) the authorization check at line 323.
This is a structural, source-verifiable fact, not an inference from
tests: **a denied request's control flow returns at line 336
(`return $this->rejected(...)`) and never reaches line 365 at all.**

Test 10 (`testAuthorizationDenialCreatesNoTempFile`,
`SensitiveParameterTest.php` 302-316) corroborates this at runtime by
asserting the adapter-prefixed temp-file count is unchanged after a
denied call. Worth being precise about what that assertion actually
proves: `assertEquals($before, $after, ...)` on a glob count is satisfied
equally by "no file was ever created" and by "a file was created and then
deleted" — it is evidence against a *leak*, not standalone proof against
*creation*. The stronger claim — no creation at all — rests on the code-
ordering argument above, which this review independently confirmed by
reading the method top to bottom. Both together (structural argument +
corroborating test) support the property; the test alone would not.

**Conclusion: the property holds, confirmed from source.**

---

# 6. SECRET IN TARGET / RESULT

Every site that touches `$target` in `CommandAdapter.php`:

- **Populated**: line 309, `$target[$name] = $value;` — the only
  assignment site in the entire file, gated by the `sensitive` check at
  line 308.
- **Copied**: never — `$target` is never `array_merge()`'d, cloned into
  another variable, or reconstructed from `$params` a second time
  anywhere else in the method.
- **Returned**: via every `AdapterResult` constructed in this method —
  both the success path (581) and every `rejected()` call (which all pass
  `$target` through, e.g. 227, 250, 275, 291, 333, 384, 406, 449, 467,
  483). This includes rejections that happen *after* some, but not all,
  parameters have been validated (e.g. `MISSING_PARAMETER` partway
  through the loop) — in that case `$target` legitimately contains only
  whatever non-sensitive parameters were already validated before the
  rejection fired, which is correct: a sensitive parameter that failed
  its own shape validation earlier in the loop would already have
  short-circuited before reaching line 308 for that parameter, so it was
  never a candidate for inclusion regardless.
- **Passed to authorizer**: line 323, the same `$target` object, already
  scrubbed.
- **Included in rejection results**: yes, confirmed above — importantly
  including `AUTHORIZATION_DENIED` itself (line 333) and
  `TEMP_FILE_UNAVAILABLE` (line 404), both of which fire *after* target
  construction, so both correctly still exclude the sensitive value.
- **Included in success results**: line 581, same object.
- **Serialized**: `AdapterResult::toArray()` (`AdapterResult.php` 185-207)
  includes `$this->target` (203) verbatim — anything in `$target`
  reaches any JSON-encoding or logging layer built on `toArray()`
  automatically. Since the sensitive value is never in `$target`, it is
  never in `toArray()`'s output either — test 2
  (`testSensitiveParameterExcludedFromTarget`, lines 149-157) directly
  asserts `strpos(json_encode($result->toArray()), "s3cr3t-val") === false`,
  which is a meaningful, non-tautological check (it inspects the whole
  serialized structure, not just the `target` key in isolation, so it
  would also catch a secret leaking into `stdout`/`stderr` in this
  specific fake-runner scenario — though see section 1's caveat about
  what happens with a real runner and a script that echoes the secret).
- **Logged**: no logging framework exists anywhere in this codebase (see
  section 11) — there is no code path that writes `$target` to any log
  sink today.

**Can a sensitive parameter accidentally re-enter `$target` through
another code path?** No other code path writes to `$target` at all — it
is populated exactly once, at line 309, under exactly one condition.
There is no second registry-driven or fallback population mechanism to
audit.

**Can the sensitive value appear in `AdapterResult`, adapter errors,
Hestia errors, validation errors, or exception messages?** `AdapterResult::$target`
— no (confirmed above). `AdapterResult::$stdout`/`$stderr`/`$errorMessage`
for a `hestia_error` — potentially yes, but only as a function of what the
underlying script itself writes to its own stdout/stderr, entirely
outside this mechanism's control (see section 1). Adapter-native error
messages (`UNEXPECTED_PARAMETER`, `MISSING_PARAMETER`, `VALIDATION_FAILED`,
`UNKNOWN_PARAMETER_TYPE`, `AUTHORIZATION_DENIED`, `REGISTRY_ERROR`,
`LOCK_UNAVAILABLE`, `LOCK_TIMEOUT`, `TEMP_FILE_UNAVAILABLE`) — traced
each `sprintf`/string-concatenation call individually (lines 192, 225,
248, 273, 289, 331, 382, 402, 447, 465, 481) — none interpolates a raw
parameter *value*, only parameter *names* (`$name`, `$argName`),
*operation names*, and *type names*. **No adapter-native error message
can carry a sensitive value.**

---

# 7. REGISTRY VALIDATION

`CommandRegistry::validateParameterMetadata()`, lines 432-463:

```php
$delivery = $definition["delivery"] ?? null;
$sensitive = $definition["sensitive"] ?? false;

if ($delivery !== null && !isset($supportedDeliveryModes[$delivery])) {
    throw new \InvalidArgumentException(...);
}

if ($sensitive === true && $delivery === null) {
    throw new \InvalidArgumentException(...);
}
```

Walking every combination the task asks for:

- **`sensitive=true, delivery=temp_file`**: both checks pass; accepted.
  Correct.
- **`sensitive=true` without delivery**: `$delivery === null`, so the
  second `if` fires; construction throws. Correct — and directly tested
  (test 12).
- **`delivery=temp_file` without `sensitive`**: `$sensitive` defaults to
  `false`, so the second `if`'s left operand is false; no exception.
  Accepted, as documented and intended. Correct — directly tested (test
  14).
- **Unknown delivery mode**: `$delivery !== null` and not in the
  flipped-array lookup; first `if` fires; construction throws. Correct —
  directly tested (test 13).
- **Malformed `sensitive` value — THE ACTUAL FINDING.** The check at
  line 451 is `$sensitive === true` — a **strict** type/value comparison.
  `CommandAdapter`'s own gate at line 308, by contrast, is
  `!($definition["sensitive"] ?? false)` — a **loose, truthy**
  check. These two checks do not agree on the same input space. Consider
  a registry entry declaring `"sensitive" => 1` (an integer, e.g. from a
  config-authoring mistake, a value inherited from a language/format
  where booleans are represented as 0/1, or a decoded JSON value handled
  carelessly upstream of the PHP array literal) with no `"delivery"` key
  at all:
  - `CommandRegistry::validateParameterMetadata()`: `1 === true` is
    `false` in PHP (strict comparison across types never coerces), so
    the "sensitive requires delivery" check at line 451 **does not
    fire** — construction succeeds silently, no exception, no warning.
  - `CommandAdapter::invoke()`: `!(1 ?? false)` evaluates `!1`, which
    **is** `false` (PHP treats non-zero int as truthy), so the parameter
    **is** excluded from `$target` at line 308-310 — giving every
    appearance of working correctly.
  - But argv construction (390-413) checks `$delivery === self::DELIVERY_TEMP_FILE`
    — since no `delivery` was declared, `$delivery` is `null`, the
    condition is false, and the **plaintext value is placed directly
    into `argv` at line 412**, unprotected, reaching `proc_open()` and
    therefore `/proc/<pid>/cmdline` in full.

  **This is a real, demonstrable defect, not a hypothetical.** It is the
  worst shape of bug for a security control to have: it fails *open*
  (the value still leaks into argv) while presenting every *external*
  signal of success (`$target` is correctly scrubbed, registry
  construction does not throw, and none of the 16 existing tests use a
  non-strict-boolean `sensitive` value, so the suite is green and gives
  false confidence). Note the honest calibration here: with `sensitive`
  omitted entirely, the plaintext already lands in argv identically —
  this bug does not make exposure any *worse* than the pre-existing
  no-protection baseline. What makes it a real finding is that it
  produces **false confidence that protection was applied when it was
  not** — the parameter definition explicitly declares `sensitive`,
  `$target` is correctly scrubbed, and nothing signals that argv
  protection silently failed to activate. The only thing standing
  between this and an actual exposure is a future registry author
  writing `"sensitive" => true` exactly, in the literal boolean form,
  every time — a convention, not an enforced invariant. See FINDING F-1.

- **Malformed `delivery` value (non-string, e.g. an array)**: `isset($supportedDeliveryModes[$delivery])`
  at line 441 uses `$delivery` as an array key. If `$delivery` is itself
  an array (or another illegal offset type), PHP raises a `TypeError`
  ("Illegal offset type") rather than reaching either of this method's
  own `throw new \InvalidArgumentException(...)` statements. The net
  effect is still fail-closed (construction still aborts, no registry
  entry with bad metadata is ever usable), but with a different,
  less-informative exception type than the one this method is designed
  to produce, and the `TypeError` occurs before the more helpful,
  parameter-naming `InvalidArgumentException` message can be built. Since
  registry entries are authored in code, not accepted from any runtime
  input, this is a minor developer-experience gap (a confusing crash
  instead of a clear validation message), not a security issue. See
  FINDING F-4 (low).

**A validation-scope gap, in the same silent-failure family as the
`sensitive` finding below.** `validateParameterMetadata()` (432-463)
iterates `$entry["parameters"] ?? []` (436) only — it never looks at
`$entry["fixed_parameters"]`. A `sensitive`/`delivery` pair authored on a
`fixed_parameters` entry is therefore silently ignored by both
validation (no exception, even for an invalid combination) and
enforcement (`CommandAdapter`'s argv loop reads `delivery` from
`$parameterSchema[$argName]`, i.e. `parameters` only — a
`fixed_parameters` value can never receive temp-file delivery no matter
what metadata is attached to it). Low practical risk today, since
`fixed_parameters` values are registry-authored constants, not secrets —
but it is exactly the kind of "invalid combination slipping through
unnoticed" this section was asked to hunt for.

**Is the "delivery-without-sensitive is allowed" invariant correct?**
Yes, on the reasoning given in `SENSITIVE_PARAMETER_DESIGN.md` and
re-verified here: `sensitive` and `delivery` genuinely answer different
questions (target/authorizer exposure vs. argv delivery mechanism), and
nothing about routing a value through a temp file requires also hiding it
from `$target` — a non-secret value that happens to be long, or that a
future script prefers to receive via file for an unrelated reason (e.g.
avoiding an argv length limit), would have no reason to also be excluded
from `$target`. This asymmetry is correctly reasoned and correctly
implemented (subject to finding B-1 above, which is about the reverse
direction — `sensitive` without effective `delivery` enforcement, not
about this direction).

---

# 8. GENERICITY

Grep of `CommandAdapter.php` and `CommandRegistry.php` for the four
terms the task specifies:

```
grep -ci "database\.create\|v-add-database\|password\|dbuser" CommandAdapter.php
→ 1 match: line 123, `9 => "E_PASSWORD",`
```

That one match is a pre-existing entry in the `HESTIA_EXIT_CODES` table
(the generic, project-wide symbolic-exit-code vocabulary this class
already exposed before this task) — confirmed via `git diff` that line
123 is unchanged by this work. It is a generic Hestia exit-code *name*
("password was wrong," a symbolic constant reused across many `v-*`
scripts, not specific to any database operation), not
operation-specific logic. `CommandRegistry.php` never references any of
the four terms at all (this file's own docblocks describe only
`domain.*`/`backup.schedule`, which predate this task).

**Neither class contains any conditional, branch, string literal, or
comment referencing `database.create`, `v-add-database`, or `dbuser`
anywhere.** The two mechanisms (`sensitive`, `delivery`) are read purely
by field name (`$definition["sensitive"]`, `$parameterSchema[$argName]["delivery"]`)
— nothing in `CommandAdapter` inspects a parameter's *name* or a
registry entry's *operation name* to decide whether to apply either
mechanism.

**Could `mail.create`, `ftp.create`, `api-key.create`, `webhook.create`
use `sensitive=true`/`delivery=temp_file` without modifying
`CommandAdapter`?** Yes, mechanically — any future registry entry that
declares a parameter with `"sensitive" => true, "delivery" => "temp_file"`
(as a literal boolean and string, given finding B-1) gets the exact same
treatment `test.sensitive-op` gets in the test suite: excluded from
`$target`/authorizer input, routed through a fresh, independently-created
temp file, cleaned up by the same generic `finally` block. Nothing in
`CommandAdapter` needs to know the operation exists in advance. This is
the review's central positive finding — the mechanism is genuinely
generic at the code level, subject to the strict/loose comparison gap in
section 7 being a registry-authoring pitfall rather than an architectural
coupling.

---

# 9. HESTIA COMPATIBILITY

Re-verified independently (not merely trusted from
`DATABASE_CREATE_DESIGN.md`), against the actual current source:

`func/main.sh` lines 625-633:
```bash
is_password_valid() {
	if [[ "$password" =~ ^/tmp/ ]]; then
		if ! [[ "$password" == *../* ]]; then
			if [ -f "$password" ]; then
				password="$(head -n1 $password)"
			fi
		fi
	fi
}
```

`web/add/db/index.php` lines 72, 74:
```php
$v_password = tempnam("/tmp", "vst");
...
fwrite($fp, $_POST["v_password"] . "\n");
```

Two facts confirmed directly from source, matching the design doc's
claims:

1. **The `^/tmp/` prefix match is a literal string-prefix regex, not "any
   OS temp directory."** It matches only paths that literally begin with
   `/tmp/`. The one real production caller hardcodes the literal string
   `"/tmp"` (not `sys_get_temp_dir()`) when calling `tempnam()`.
2. **The trailing newline is not required by `head -n1`'s own semantics**
   — `head -n1` reads up to the first newline *or* end-of-file, whichever
   comes first, so a file with no trailing newline at all would still be
   read correctly, character-for-character up to EOF. The trailing
   newline this mechanism adds (`writeSecureTempFile()` line 634,
   `$value . "\n"`) is not load-bearing for correctness against
   `head -n1` specifically — it is there purely to **match the existing
   production convention exactly** (`web/add/db/index.php:74` also
   appends `"\n"`), which is a reasonable and low-risk choice (no
   plausible scenario where an extra trailing newline breaks `head -n1`),
   just worth stating precisely rather than implying it as a hard
   requirement.

**A real compatibility gap, not previously flagged: `sys_get_temp_dir()`
vs. the literal `"/tmp"`.** `writeSecureTempFile()` (line 624) calls
`tempnam(sys_get_temp_dir(), self::TEMP_FILE_PREFIX)`. `sys_get_temp_dir()`
resolves dynamically — it honors the `TMPDIR` environment variable (and,
on Windows, `TMP`/`TEMP`/`USERPROFILE`) before falling back to a
compiled-in default that is typically, but not guaranteedly, `/tmp` on
Linux. If the PHP-FPM (or CLI) process this adapter runs under has
`TMPDIR` set to anything other than `/tmp` — e.g. `/var/tmp`, or a
per-pool temp directory some hardened PHP-FPM configurations set
deliberately — `writeSecureTempFile()` would create the file under that
directory instead, and the resulting path would **not** match
`is_password_valid()`'s `^/tmp/` regex. The consequence for a future
`database.create` consumer of this mechanism would not be a crash or a
loud failure: `is_password_valid()` would simply skip its file-dereference
branch entirely (626-631 never execute their body) and Hestia's own
script would treat the **literal temp-file path string itself** as the
password value — a silent authentication/behavior corruption, not a
loud error. This is a forward-looking risk (no operation consumes this
path today, so nothing is broken *right now*), but it is a real,
source-verified divergence between this generic mechanism's chosen
directory source (`sys_get_temp_dir()`, dynamic) and the one concrete
contract it was built to eventually satisfy (`^/tmp/`, a literal-string
match, matching the one existing caller's hardcoded `"/tmp"`). See
FINDING F-2.

One more observation worth recording here: `is_password_valid()` calls
`head -n1 $password` **unquoted** (`func/main.sh:629`), even though the
adjacent existence check on the line above it, `[ -f "$password" ]`, is
quoted. This is safe today only because `tempnam()` never produces a
path containing whitespace or shell metacharacters — an implicit,
undocumented dependency Hestia's own script has on how any caller names
its temp files. Not a defect in this mechanism (it does not control what
Hestia's script does with the path once handed to it), but worth
flagging: any future delivery mode that ever let a caller influence the
temp file's name would need to preserve this property explicitly.

---

# 10. FAILURE MODES — `TEMP_FILE_UNAVAILABLE` MANUAL REVIEW

No test forces `tempnam()` or `file_put_contents()` to fail (confirmed —
`SensitiveParameterTest.php` has no test targeting lines 622-641's error
branches), so this path was reviewed by direct code inspection only, as
the task requires.

```php
private function writeSecureTempFile(string $value): string {
    error_clear_last();
    $path = @tempnam(sys_get_temp_dir(), self::TEMP_FILE_PREFIX);
    if ($path === false) {
        $lastError = error_get_last();
        throw new \RuntimeException(
            "Unable to create a secure temporary file: " . ($lastError["message"] ?? "unknown error")
        );
    }

    @chmod($path, 0600);

    $written = @file_put_contents($path, $value . "\n");
    if ($written === false) {
        @unlink($path);
        throw new \RuntimeException("Unable to write to secure temporary file: " . $path);
    }

    return $path;
}
```

- **Does it fail safely?** Yes. Both failure branches throw
  `\RuntimeException`, which is caught by the call site (394) and
  converted into an ordinary `rejected()` `AdapterResult` — no exception
  escapes to the caller for *this specific* failure (unlike a
  `ProcessRunnerInterface` exception, which does propagate).
- **Can the secret enter an error message?** No — traced both `throw`
  statements: the `tempnam()` failure message embeds only
  `$lastError["message"]` (an OS-level string like "No such file or
  directory," never derived from `$value`); the `file_put_contents()`
  failure message embeds only `$path`. `$value` (the secret) is never
  interpolated into either message. The `rejected()` call built from this
  exception (395-407) further only adds `$argName` and
  `$exception->getMessage()` (402) — still no path for `$value` to reach
  it.
- **Can a partially-created file remain?** For the `tempnam()` failure
  branch: no, because `tempnam()` returning `false` means no file was
  created at all — there is nothing to clean up. For the
  `file_put_contents()` failure branch: the code already handles this —
  `@unlink($path)` runs at line 636, immediately after the write failure
  is detected, before the exception is thrown. This is a second,
  narrower cleanup mechanism, distinct from the outer `finally` (which
  only cleans up paths already pushed into `$temporaryFiles`, and this
  path is never pushed there since the `catch` block at 394 returns
  before line 409 executes). **Correctly handled** — verified from
  source, not merely asserted.
- **Can the secret be returned in `AdapterResult`?** No — the resulting
  `rejected()` call passes `$target` (already scrubbed, per section 6),
  never `$value` or `$rawValue`.
- **Can authorization/locking state remain inconsistent?** No lock has
  been acquired yet at this point in the method (lock acquisition is at
  432-488, strictly after the argv-construction loop at 365-414 where
  `writeSecureTempFile()` is called) — so there is no lock to release or
  leak here. Authorization has already succeeded (this code is
  unreachable otherwise, per section 5) and is a one-time yes/no gate
  with no state to become inconsistent.

**Conclusion: this path, while untested, is correct by inspection.** The
test-list gap is real and should be named plainly (the task itself
anticipated this and did not require a test for it), but it is not
evidence of a latent bug — the code was traceable and sound on manual
review.

---

# 11. LOGGING / OBSERVABILITY

Searched `web/inc/adapter/` for any logging call (`error_log`, `syslog`,
a custom logger class, `fwrite(STDERR, ...)` outside of the process
runner, or any file-append pattern used for audit purposes) — none
exists anywhere in this directory. `CommandAdapter.php`'s own docblock
(34-38) explicitly lists "audit persistence" as NOT implemented in this
slice. There is, today, no logging framework of any kind for this
mechanism to leak a sensitive value into, because there is no logging
framework at all. This is consistent with the task's "do not invent a
logging/redaction framework" exclusion — there being nothing to review
here is the expected, correct state, not a gap this task should have
filled.

The one adjacent observability surface that does exist is
`AdapterResult::toArray()` (see section 6) — already confirmed clean.

---

# 12. CONCURRENCY

- **Same user, same secret (two concurrent identical requests)**: each
  invocation of `writeSecureTempFile()` calls `tempnam()` independently
  (line 624), which is guaranteed unique per call by its own contract —
  two concurrent calls produce two distinct files with two distinct
  random suffixes, never the same path. No shared mutable state between
  them at the temp-file layer.
- **Same user, different secrets**: same reasoning — independent
  `tempnam()` calls, independent files, no interaction.
- **Different users**: same reasoning, plus per-user locking (unrelated
  to temp-file creation — see next point) further serializes execution,
  but even without that, temp-file creation itself has no cross-user
  shared state to race on.
- **Simultaneous `tempnam()` calls**: `tempnam()`'s uniqueness guarantee
  is provided by the underlying OS temp-file-creation primitive (an
  atomic create-with-unique-name operation), which is designed
  specifically to be safe under concurrent callers — this is not
  something this code has to additionally guard.
- **A subtler point the task didn't explicitly ask but is relevant here:
  temp-file creation happens BEFORE lock acquisition, not after.**
  Argv construction (365-414, where `writeSecureTempFile()` is called)
  runs strictly before the lock-acquisition block (432-488) in this
  method's control flow. This means: for a mutating operation, if two
  concurrent requests for the *same user* both pass authorization, both
  will each independently create their own temp file *before* either one
  contends for the per-user lock. Whichever loses the lock race (timeout
  or `LockUnavailableException`) still had a temp file materialized to
  disk for the duration of its wait — correctly cleaned up by the outer
  `finally` either way (already confirmed in section 4), so this is not
  a cleanup gap. It is, however, a slightly larger exposure *window* than
  the minimum possible (the secret sits on disk for the lock-wait
  duration too, not just the execution duration) — worth noting as a
  minor, low-severity observation rather than a defect, since the task's
  actual required property ("no temp file before *authorization*") is
  satisfied, and nothing in the task asked for "no temp file before
  *lock acquisition*" specifically.

**No shared mutable state was found anywhere in this mechanism.**

---

# 13. ARCHITECTURAL REVIEW

Compared against each existing seam:

- **Registry-driven operations**: unchanged mechanism — `sensitive`/`delivery`
  are just two more optional keys on a parameter definition, read the
  same way `type`/`required` already are. No second registry-parsing
  path was introduced.
- **Generic `CommandAdapter`**: confirmed in section 8 — no second
  execution path exists. There is exactly one `invoke()` method, one
  argv-construction loop, one process-execution call site (line 492).
  The `delivery=temp_file` branch (391-410) is an `if`/`else` *inside*
  the existing loop, not a parallel method or a separate code path
  invoked under different conditions.
- **`AuthorizerInterface`**: unchanged interface, unchanged single call
  site (323), unchanged position in the pipeline. No second
  authorization path.
- **`LockManager`**: entirely untouched by this work — not referenced
  anywhere in `writeSecureTempFile()`/`removeSecureTempFile()`, and the
  lock-acquisition block (432-488) is byte-for-byte the same code that
  existed before this task (confirmed by the earlier `git diff` showing
  no changes to that region). No second locking path.
- **`AdapterResult`**: the class itself (`AdapterResult.php`) was not
  modified at all by this task — confirmed via `git status --short`,
  which shows no change to that file. Only *what* `CommandAdapter`
  chooses to put into the existing `$target` field changed, not the
  result model itself.
- **`ProcessRunnerInterface`**: unchanged interface, unchanged single
  call site (492), unchanged contract (`run(string $binary, array $argv): ProcessResult`).
  No new method, no new implementation required for production use.

**No second execution path, no operation-specific branching, no second
authorization path, no second locking path, and no hidden coupling were
found.** The one piece of genuinely new *state* this mechanism introduces
is `$temporaryFiles` (line 363) — but it is function-call-local (a plain
local array, not a class property), lives only for the duration of one
`invoke()` call, and is not shared, cached, or persisted across
invocations. This is the correct amount of statefulness for what the
mechanism does — not "stateful behavior that doesn't belong in the
adapter," just an ordinary local variable scoping a cleanup list for one
request.

---

# 14. SECURITY SEVERITY — FINDINGS

Conservative classification; no finding manufactured to pad the list.
Several sections above ("does it fail safely," "no shared mutable
state," "no second execution path") concluded the code is correct as
inspected — those are not repeated here as findings, since the task asks
to state explicitly when something is correct rather than force a
finding out of it.

### FINDING F-1 — `sensitive` field uses inconsistent comparison strictness between validation and enforcement
**Severity: HIGH**
**Location**: `CommandRegistry.php:451` (`$sensitive === true`) vs.
`CommandAdapter.php:308` (`!($definition["sensitive"] ?? false)`).
**Scenario**: a registry entry declares `"sensitive" => 1` (or any other
PHP-truthy, non-`true` value) with no `"delivery"` key. Registry
construction does not throw (the strict `=== true` check does not match).
`CommandAdapter` still excludes the value from `$target` (loose truthy
check does match) — giving every appearance of correct behavior — but
argv construction places the plaintext value directly into `argv`
unprotected, since no `delivery` mode routes it through a temp file. The
secret reaches `proc_open()`, `/proc/<pid>/cmdline`, and any process
listing, in full. **Why HIGH despite requiring a registry-authoring
mistake to trigger**: this is exactly the failure mode a security control
must not have — it fails *open*, not closed, and does so *silently*,
with no exception, no test failure (none of the 16 existing tests probe
a non-strict-boolean `sensitive` value), and a `$target` that looks
correctly scrubbed, actively masking the fact that argv is not. The
smallest generic correction (not implemented here, per this task's
scope): make both checks use the same comparison — either both strict
(`=== true`) or both loose (truthy) — so a malformed `sensitive` value
either uniformly fails registry construction or uniformly fails to
protect anything, rather than partially protecting in a way that hides
the gap.

### FINDING F-2 — `sys_get_temp_dir()` may not equal the literal `/tmp` Hestia's password-file reader requires
**Severity: MEDIUM**
**Location**: `CommandAdapter.php:624`.
**Scenario**: `sys_get_temp_dir()` resolves dynamically (honors `TMPDIR`);
the one concrete consumer this mechanism was built for
(`is_password_valid()`, `func/main.sh:626`) matches only a literal
`^/tmp/` prefix, and the one existing production caller
(`web/add/db/index.php:72`) hardcodes the literal string `"/tmp"`. If the
PHP runtime's temp directory ever resolves to something else, a future
consumer of `delivery=temp_file` for a Hestia password-style field would
silently pass the temp file's own path string as the literal password
value, rather than triggering Hestia's file-dereference behavior — a
silent correctness/security divergence, not a crash. **Not exploitable
today** (no operation currently uses this delivery mode against a real
Hestia password field), which is why this is MEDIUM rather than HIGH —
it is a landmine for whenever `database.create` is actually wired up, not
a live issue in the code as it stands. Smallest generic correction (not
implemented here): either hardcode `"/tmp"` for `delivery=temp_file`
specifically (accepting the coupling to Hestia's own convention, which
this generic mechanism was explicitly built to match) or document the
`TMPDIR` assumption as a hard deployment requirement.

### FINDING F-3 — Cleanup guarantee does not, and cannot, cover PHP process crash / SIGKILL
**Severity: MEDIUM**
**Location**: `CommandAdapter.php:585-594` (the `finally` block).
**Scenario**: if the PHP process executing `invoke()` is killed outright
(SIGKILL, OOM-killer, FPM worker force-termination, host crash) after a
temp file is created (393) but before the `finally` block runs (591-593),
the temp file is never deleted by this code — it persists on disk,
0600-permissioned, containing the real secret value, until an external
mechanism (systemd-tmpfiles, tmpwatch, reboot) eventually clears it,
typically on the order of days by default. This is not a defect in the
`finally`-based cleanup design (a `finally` block categorically cannot
protect against the interpreter being killed — no PHP-level mechanism
can), but it is a real, currently under-documented residual risk that the
design doc's "cleanup guarantees" section does not call out. Smallest
generic correction (not implemented here, and likely out of proportion
for this task's scope per its own "do not over-engineer" instruction):
none is proposed — this is the kind of gap addressed by an external,
periodic sweep (e.g. an OS-level tmp-cleaner with a short retention
window for this prefix), not by anything inside `CommandAdapter` itself.
Documenting the boundary is the correct-sized response, not code.

### FINDING F-4 — Non-string `delivery` values throw an uninformative `TypeError` instead of the intended `InvalidArgumentException`
**Severity: LOW**
**Location**: `CommandRegistry.php:441`.
**Scenario**: a registry entry declaring `"delivery" => ["temp_file"]`
(an array, e.g. from a copy-paste or JSON-authoring mistake) causes
`isset($supportedDeliveryModes[$delivery])` to attempt an illegal
array-offset type, raising a `TypeError` rather than reaching either of
this method's own, more informative `InvalidArgumentException` messages.
Still fail-closed (construction still aborts); only the failure mode's
clarity is degraded. Since registry entries are authored in code, not
accepted from runtime input, this affects developer experience, not
security. No correction proposed — informational.

### FINDING F-5 — `$target`/argv protection does not extend to a script's own stdout/stderr
**Severity: INFORMATIONAL**
**Location**: `CommandAdapter.php:525-530`, `AdapterResult.php:41-44`.
Already covered in full in section 1/6. Restated here as a scope
boundary worth keeping in mind for any future operation's registry
entry, not a defect in this mechanism — `sensitive`/`delivery` were
never intended to, and do not, sanitize a script's own output.

### FINDING F-6 — `validateParameterMetadata()` never inspects `fixed_parameters`, so a `sensitive`/`delivery` pair authored there is silently ignored
**Severity: LOW**
**Location**: `CommandRegistry.php:436` (`$entry["parameters"] ?? []`);
`CommandAdapter.php:390` (`$parameterSchema[$argName]["delivery"]`, where
`$parameterSchema = $entry["parameters"] ?? []`, line 209).
**Scenario**: a `fixed_parameters` entry carrying `"sensitive"`/`"delivery"`
keys passes validation with no exception (the loop at 436 never reaches
it) and receives no enforcement (argv construction reads `delivery` from
`parameters` only, never `fixed_parameters`). Low practical risk today —
`fixed_parameters` values are registry-authored constants, not secrets,
so no realistic registry entry would need this combination — but it is
the same "invalid combination slips through with no signal" shape as
F-1, on a smaller, currently-unused surface. No correction proposed;
worth a one-line note in `SENSITIVE_PARAMETER_DESIGN.md` if that document
is revisited.

### Explicitly confirmed correct (not findings)
- Authorization-before-temp-file ordering (section 5): **correct**,
  proven from code structure, not just tests.
- Process-argv exposure (section 2): **correct** — the file path, never
  plaintext, reaches `proc_open()`.
- `TEMP_FILE_UNAVAILABLE` failure path (section 10): **correct** by
  manual inspection, despite being untested.
- Concurrency / shared mutable state (section 12): **none found**.
- Architectural isolation (section 13): **no second execution/auth/lock
  path, no operation-specific branching** — confirmed by direct reading
  of every relevant seam.
- `delivery`-without-`sensitive` invariant (section 7): **correctly
  reasoned and correctly implemented**.
- `TEMP_FILE_UNAVAILABLE` error-message secret-leak question (sections 1,
  10): **confirmed no leak path exists**.

---

# 15. VERDICT DISCUSSION

The core security property this mechanism exists to deliver — a secret
parameter value never appears in `AdapterResult::$target`, never reaches
`AuthorizerInterface::authorize()`, never appears in process argv (and
therefore never in `/proc/<pid>/cmdline` or `ps`), and is deleted from
disk on every PHP-level exit path — is **correctly implemented and
verified from source**, not merely from the test suite. The architecture
is genuinely generic: no operation-specific branching exists anywhere in
`CommandAdapter.php` (the sole `E_PASSWORD` match is a pre-existing,
unrelated generic exit-code entry), and a future operation could adopt
`sensitive`/`delivery` metadata with zero changes to `CommandAdapter`.

It is not, however, unconditionally airtight, and one finding (**F-1**)
is serious enough to flag as a real, if narrow, security gap rather than
a stylistic nit: the `sensitive` flag's protection can be silently
defeated by a registry-authoring mistake (a non-strict-boolean truthy
value) that produces no error and passes every existing test, because
`CommandRegistry`'s validation and `CommandAdapter`'s enforcement
disagree on comparison strictness. Calibrated honestly: this does not
make exposure worse than having no `sensitive` flag at all — it makes the
`sensitive` flag lie about protection it isn't actually providing. That
distinction is why it does not block proceeding with `database.create`
(it requires only that whoever writes that registry entry use the
literal boolean `true`, which every design document and test in this
codebase already does consistently) but should be closed before this
mechanism is trusted as a long-term API v2 foundation rather than a
single, carefully-authored registry entry.

**Recommended (not implemented) before treating this as a durable
platform primitive, in priority order:**
1. **F-1** (HIGH): unify the comparison strictness between
   `CommandRegistry::validateParameterMetadata()`'s `sensitive` check and
   `CommandAdapter::invoke()`'s `sensitive` check, so a malformed value
   fails the same way in both places instead of passing validation while
   silently losing argv protection.
2. **F-2** (MEDIUM): resolve the `sys_get_temp_dir()` vs. literal `/tmp`
   question explicitly before `database.create`'s registry entry is
   written, since that entry is the mechanism's first real consumer of
   the Hestia-compatibility property this was built for.
3. **F-3** (MEDIUM): document (design-doc only, no code) the PHP-crash
   residual risk explicitly, so it is a known, accepted tradeoff rather
   than an implicit gap in the "cleanup guarantees" claim.
4. **F-4** (LOW) / **F-6** (LOW): fix opportunistically if the validation
   method is touched again for another reason.
5. **F-5** (INFORMATIONAL): no action — document as a permanent scope
   boundary.

---

# FINAL VERDICT

- **Verdict**: **APPROVED WITH NOTES**
- **Findings by severity**: HIGH: 1 (F-1) · MEDIUM: 2 (F-2, F-3) · LOW: 2
  (F-4, F-6) · INFORMATIONAL: 1 (F-5). No BLOCKER findings.
- **Whether `database.create` can proceed**: Yes — the mechanism is safe
  enough for that specific, planned next step, provided its registry
  entry declares `"sensitive" => true` (the literal boolean) and
  `"delivery" => "temp_file"` exactly as every existing test and design
  document already assumes.
- **Required changes, if any**: None required to unblock `database.create`.
  Recommended before this mechanism is treated as a durable platform
  primitive, in priority order: unify F-1's strictness mismatch; resolve
  F-2's `sys_get_temp_dir()`-vs-`/tmp` question; document F-3's
  crash-survival boundary; opportunistically fix F-4/F-6. None of these
  were implemented as part of this review, per its explicit scope.
- **Explicit confirmation that no source/test files were modified**:
  Confirmed via `git status --short`, run both before and after writing
  this document — the only change to the working tree from this review
  is the addition of this file, `SENSITIVE_PARAMETER_REVIEW.md`, itself.
  `web/inc/adapter/CommandAdapter.php`, `web/inc/adapter/CommandRegistry.php`,
  and `test/adapter/SensitiveParameterTest.php` are exactly as they were
  before this review began.

## Reported per task requirements

- **Files inspected**: listed in full at the top of this document.
- **Relevant source locations**: cited inline throughout, by exact line
  number, against the current working-tree state.
- **Test suite status**: `php test/adapter/run_tests.php` → `140 passed, 0 failed`,
  re-run as part of this review (read-only; no test was added, removed,
  or modified).
- **Architectural conclusions**: genuinely generic, single execution
  path, no operation-specific coupling in `CommandAdapter.php`; one real
  strictness-mismatch gap (F-1) and one forward-compatibility gap (F-2)
  identified, neither requiring architectural rework to fix — both are
  narrow, local corrections to existing validation/constant logic.
- **Nothing was committed.**
