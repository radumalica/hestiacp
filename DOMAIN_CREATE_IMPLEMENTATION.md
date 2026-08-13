# Domain Create Implementation

Implementation report for `domain.create` — the first real mutating
operation registered in `CommandRegistry`, mapping to `bin/v-add-web-domain`.
This proves the full architecture end to end: registry → validation →
per-user lock → the existing, unmodified Hestia CLI → structured
`AdapterResult` → `mutation_state`.

`bin/v-add-web-domain` and `func/main.sh` were NOT modified. No other
mutating operation (`domain.delete` included) was added.

# Command Contract

Discovered by reading `bin/v-add-web-domain` (261 lines) and every
helper function it calls, in full — not from the script's `# options:`
header comment (`ARCHITECTURE_ADAPTER_DESIGN.md` section 2 already
established that these headers can be stale) and not inferred from any
other `v-*` script.

**Argument definition** (lines 19-25):

```
user=$1
domain=$2
domain_idn=$2
ip=$3
restart=$4
aliases=$5
proxy_ext=$6
```

**Required vs. optional** (`check_args '2' "$#" 'USER DOMAIN [IP] [RESTART] [ALIASES] [PROXY_EXTENSIONS]'`,
line 52): only `USER` and `DOMAIN` are required; the script tolerates
being called with as few as 2 positional arguments, in which case `$3`
through `$6` are simply empty/unset.

**Defaults for each optional argument, confirmed by reading the branches
that consume them:**

| Argument | If empty/absent | Source |
|---|---|---|
| `ip` | `else get_user_ip` — the script picks the same IP it would use for any new domain for this user | lines 81-85 |
| `restart` | Falls through to the normal (non-scheduled, non-suppressed) restart path in `v-restart-web`/`v-restart-proxy` unless `SCHEDULED_RESTART='yes'` is configured — see "Service Reload / Failure Semantics" below | `v-restart-web` lines 59-69 |
| `aliases` | Script computes its own `www.$domain` alias automatically (unless `$domain` is a 3+ label subdomain and fails a public-suffix check, or `aliases` is the literal string `"none"`) | lines 156-181 |
| `proxy_ext` | A large built-in default extension list (`css,htm,html,js,...`) is used | lines 195-224 |

**Validation performed by the script itself** (its own "Verifications"
section, lines 51-88), independent of anything this adapter does:

- `is_system_enabled "$WEB_SYSTEM"` — fails `E_DISABLED` if the web
  system is off.
- `check_args '2' "$#" ...` — fails `E_ARGS` if fewer than 2 arguments.
- `is_format_valid 'user' 'domain' 'aliases' 'ip' 'proxy_ext' 'restart'`
  — shape validation for every argument that was actually supplied
  (`func/main.sh`'s `is_user_format_valid`/`is_domain_format_valid`/
  `is_alias_format_valid`/`is_ip_format_valid`/
  `is_extention_format_valid`/`is_restart_format_valid`).
- `is_object_valid 'user' 'USER' "$user"` — fails `E_NOTEXIST` if the
  user does not exist.
- `is_object_unsuspended 'user' 'USER' "$user"` — fails `E_SUSPENDED` if
  the user is suspended.
- `is_package_full 'WEB_DOMAINS'` — fails `E_LIMIT` if the user's web
  domain quota is exhausted; also `is_package_full 'WEB_ALIASES'` when
  `aliases != "none"`.
- `is_domain_new 'web' "$domain_utf,$aliases"` / same for `$domain_idn`
  — fails `E_EXISTS` ("Web domain ... exists") if the domain (or one of
  its would-be aliases) is already registered to ANY user's `web.conf`
  on the server, not just this user's. This is the duplicate-domain
  check — see "Idempotency / Duplicate Domain" below.
- `is_ip_format_valid "$domain"` (a defensive check that the *domain*
  argument isn't actually an IP address) — exits `1` directly (not via
  `check_result`) with `"Error: Invalid domain format. IP address
  detected as input."` if it is.
- `is_dir_symlink` (×2) — rejects a homedir/domain path that is a
  symlink.
- `is_base_domain_owner` — fails if the domain's base/root domain is
  owned by a different user.
- `is_ip_valid "$ip" "$user"` (only if `$ip` was supplied) — fails if the
  IP isn't valid/assigned to this user.
- `check_hestia_demo_mode` — fails if the install is in read-only demo
  mode.
- `[[ -e "$HOMEDIR/$user/web/$domain" ]] && check_result "$E_EXISTS" ...`
  (line 97, start of the "Action" section) — a second, filesystem-level
  duplicate check, redundant with `is_domain_new` above under normal
  operation but present as defense in depth.

None of the above is duplicated by this adapter (see "Parameter Model"
below) — exactly the same shape-vs-business-validation split already
established for `domain.get`/`domain.list`.

**Exit codes observed in the source** (all pre-existing `func/main.sh`
`E_*` constants, already present in `CommandAdapter::HESTIA_EXIT_CODES`
— no new codes needed): `E_ARGS` (1), `E_INVALID` (2, the direct
`exit 1`... — actually literal `1`, so `E_ARGS`, not `E_INVALID`, for the
"IP address detected as domain" case — confirmed by reading `exit 1`
literally, not assumed from the message text), `E_NOTEXIST` (3),
`E_EXISTS` (4), `E_SUSPENDED` (5), `E_LIMIT` (8), `E_DISABLED` (11),
`E_RESTART` (20, if the web or proxy server reload fails at the very
end).

**stdout**: on success, the script prints nothing (no `echo` in the
success path) and reaches the bare `exit` at line 261 (`exit 0`). On
failure via `check_result`, `"Error: $2"` is printed to **stdout**
(`func/main.sh` line 231: plain `echo`, no `2>&1` or explicit stderr
redirection) — the same stdout-carries-the-error-text behavior already
observed and handled generically for `domain.get`'s `E_NOTEXIST` case
(`CommandAdapterTest::testSeparateStreamCapture`'s sibling test in
`DomainListTest.php`). The one non-`check_result` failure (line 71-74,
invalid-domain-looks-like-IP) also plainly `echo`s to stdout.

**stderr**: nothing in `bin/v-add-web-domain`'s own source writes to
stderr directly. In practice, stderr may still receive output from a
called subprocess (e.g. a failed `service $WEB_SYSTEM restart` inside
`v-restart-web`) — not something this adapter needs to special-case,
since `AdapterResult` already captures stdout and stderr independently
and unconditionally, regardless of which stream a given failure happens
to use.

**JSON output**: **none**. There is no `format` argument, no
`case $format in json) ...` branch, and no `json_list()`-style function
call anywhere in `bin/v-add-web-domain`'s source — confirmed by reading
the full script, not assumed by analogy to `bin/v-list-web-domain`. See
"JSON Output" implications under "Execution Flow" below.

**Services restarted/reloaded**: `$BIN/v-restart-web "$restart"` (line
250) and `$BIN/v-restart-proxy "$restart"` (line 254) — both called
unconditionally near the end, each independently checked via
`check_result $? "... restart failed"`. See "Mutation Semantics" below
for why a restart failure specifically motivates `mutation_state =
unknown` rather than a more specific answer.

**Files/state modified** (in the order the script performs them):

1. `mkdir $HOMEDIR/$user/web/$domain` + subdirectories
   (`public_html`, `document_errors`, `cgi-bin`, `private`, `stats`,
   `logs`) — lines 100-107.
2. Three log files touched under `/var/log/$WEB_SYSTEM/domains/` plus
   symlinks into the new domain's `logs/` directory — lines 110-114.
3. Domain skeleton copied from `$WEBTPL/skel/*` and template-substituted
   — lines 117-120.
4. Ownership/permission changes across all of the above — lines 122-137.
5. Possibly `$USER_DATA/user.conf` updated (`BACKEND_TEMPLATE`,
   `WEB_TEMPLATE`, `PROXY_TEMPLATE` defaults persisted the first time
   they're needed) — lines 140-149, 186-189, 218-221.
6. Web server vhost config file(s) written via `add_web_config` — line
   192, and proxy config via the same function — line 223, only if
   `$PROXY_SYSTEM` is configured.
7. Per-IP and per-user domain/alias counters incremented
   (`increase_ip_value`, `increase_user_value`) — lines 231-233.
8. One new line appended to `$USER_DATA/web.conf` — lines 241-245. This
   is the line `is_object_valid('web', 'DOMAIN', ...)` (used by
   `domain.get`/`domain.list`) and `is_web_domain_new()` (used by future
   `domain.create` calls, including duplicate detection) both read.
9. Web server and proxy server reloaded (see above).
10. `$BIN/v-log-action` + `log_event "$OK" "$ARGUMENTS"` — Hestia's own
    audit log, entirely separate from and unaffected by this adapter's
    (still unimplemented) audit persistence.

**Other `v-*` scripts called internally**: `v-list-web-domain` (twice,
during the duplicate-check verification step, lines 63/66),
`v-add-fs-directory` (×5, lines 102-107), `v-add-web-domain-backend`
(line 151, only if `$WEB_BACKEND` is set), `v-restart-web` (line 250),
`v-restart-proxy` (line 254), `v-log-action` (line 258).

**Possible failure points**, roughly in the order they can occur: web
system disabled; too few arguments; malformed user/domain/ip/aliases/
proxy_ext; user does not exist or is suspended; web-domain or web-alias
quota exhausted; domain (or its computed alias) already registered to
any user; domain string that is actually an IP address; homedir/domain
path is a symlink; base domain owned by another user; supplied IP not
valid/not assigned to this user; demo mode; domain folder already exists
on disk (race with the `is_domain_new` check above — see "Idempotency /
Duplicate Domain"); web or proxy server reload failure after
configuration was already written.

# Public Operation Contract

```
domain.create(user: string, domain: string) -> AdapterResult
```

Called exactly like `domain.get`/`domain.list`, through the same, only,
public entry point:

```php
$adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);
```

No new public method, no new adapter class, no `if ($operation ===
"domain.create")` branch anywhere — `CommandAdapter.php` is **completely
unmodified** by this pass (see "Execution Flow" below for why the
existing, already-generic mechanism was sufficient).

# Parameter Model

**Public (caller-supplied), both required:**

| Parameter | Type | Validator |
|---|---|---|
| `user` | `username` | `ParameterValidator::isValidUsername()` (unchanged, already used by `domain.get`/`domain.list`) |
| `domain` | `domain` | `ParameterValidator::isValidDomain()` (unchanged, already used by `domain.get`/`domain.list`) |

**No new validator was written.** Both types already existed; this is
direct evidence the two-operation validator set built for the read-only
operations generalizes to the first write operation without change.

**Internal/fixed (never caller-controlled)**, all four of
`bin/v-add-web-domain`'s remaining positional slots:

| Registry-fixed value | Value | Why |
|---|---|---|
| `ip` | `""` | Triggers the script's own `get_user_ip` fallback (line 84) — the same IP Hestia would pick for any other domain this user owns. |
| `restart` | `"yes"` | Matches the one existing production caller's own hardcoded choice (`web/add/web/index.php:81`, `... . " 'yes'"`) — the new vhost is applied immediately rather than left queued. |
| `aliases` | `""` | The script's own default `www.$domain` alias behavior applies (lines 156-181) — matches the existing UI caller, which never supplies a 5th positional argument at all. |
| `proxy_ext` | `""` | The script's own built-in extension list applies (lines 195-224) — matches the existing UI caller, which never supplies a 6th positional argument at all. |

**Why this parameter set and not a 1:1 mirror of all six CLI slots**:
the task explicitly asked for the *minimum* public model, not "expose
everything the script accepts." `user`/`domain` are the only two values
an operation meaning "create this domain for this user" cannot function
without. The other four already have well-defined, source-verified
default behaviors that match Hestia's own existing UI's real-world usage
pattern (`web/add/web/index.php`) almost exactly — the only difference
is `ip`, where the existing UI always supplies an explicit value (its
form requires one) while this adapter's first pass lets the script
auto-select via `get_user_ip()` instead. Exposing `ip` (or any of the
other three) as a real caller-facing parameter is a natural, low-risk,
strictly additive follow-up whenever needed — it requires only a new
`parameters` entry (`ip` moves from `fixed_parameters` to `parameters`,
with `ParameterValidator::isValidIp()` — not yet written — as its
validator) and does not touch `CommandAdapter.php` at all, for the same
structural reason `domain.create` itself didn't need to.

**Rejected inputs** (all proven by tests, see "Tests" below):

- Unknown parameters — including, notably, `ip`/`restart`/`aliases`/
  `proxy_ext` themselves, since none of the four is part of the public
  `parameters` schema. Supplying `"ip" => "203.0.113.5"` as a caller
  parameter is rejected with `UNEXPECTED_PARAMETER`, exactly like any
  other unrecognized key — it is never silently accepted, silently
  ignored, or allowed to override the registry-fixed value.
- Missing `user`/`domain`.
- Malformed `user`/`domain`, including shell-metacharacter payloads
  (`$(...)`, backticks, `&&`, embedded newlines) — rejected by the same
  `ParameterValidator` methods already proven against these exact
  payload shapes for `domain.get`.

All rejection happens strictly before lock acquisition — see "Locking".

# Registry Mapping

```php
"domain.create" => [
	"script" => "v-add-web-domain",
	"argument_order" => ["user", "domain", "ip", "restart", "aliases", "proxy_ext"],
	"parameters" => [
		"user"   => ["type" => "username", "required" => true],
		"domain" => ["type" => "domain",   "required" => true],
	],
	"fixed_parameters" => [
		"ip" => "", "restart" => "yes", "aliases" => "", "proxy_ext" => "",
	],
	"mutation" => ["kind" => "create"],
],
```

No `output_format` key (the script has no JSON mode — see "JSON Output"
under "Execution Flow"). No `result_shape` key (only meaningful for a
JSON-producing operation). The public operation name (`domain.create`)
never leaks the underlying script name to a caller except via
`resolvedCommand` on the result, exactly like `domain.get`/`domain.list`
already do.

# Execution Flow

```
CommandAdapter::invoke("domain.create", {user, domain}, actor)
  │
  ├─ 1. CommandRegistry::get("domain.create")        → entry found
  ├─ 2. reject any param key not in {user, domain}    → else UNEXPECTED_PARAMETER
  ├─ 3. reject missing user/domain                    → else MISSING_PARAMETER
  ├─ 4. shape-validate user/domain                    → else VALIDATION_FAILED
  ├─ 5. build argv: [user, domain, "", "yes", "", ""]
  │      (user/domain from validated params; the other four from fixed_parameters)
  ├─ 6. mutation.kind = "create" ≠ "read"  →  ACQUIRE LOCK for $target["user"]
  │      ├─ LockUnavailableException  → LOCK_UNAVAILABLE, process never spawned
  │      └─ acquire() returns false   → LOCK_TIMEOUT,      process never spawned
  ├─ 7. ProcessRunnerInterface::run("/usr/bin/sudo", [scriptPath, ...argv])
  ├─ 8. release lock (finally)
  ├─ 9. map exit code → status (ok / hestia_error) using the existing E_* table
  ├─ 10. output_format not "json" → parsed_output stays null (no parser invented)
  ▼
AdapterResult (status, mutation_state, exit_code, stdout, stderr,
               hestia_error_code / adapter_error_code, ...)
```

**This is line-for-line the same flow already implemented for
`domain.get`/`domain.list` plus the locking pass** — nothing in
`CommandAdapter.php` changed for this task. The only new code anywhere
in `web/inc/adapter/` is the `domain.create` registry entry itself. This
is the strongest possible evidence that the architecture generalizes: a
six-slot, partially-optional, side-effecting script was absorbed by the
exact same generic mechanism that already handled two simpler,
side-effect-free scripts.

**JSON Output**: `bin/v-add-web-domain` was confirmed (by full source
read) to have no JSON output mode at all. No parser was written or
invented for it. `AdapterResult::$parsedOutput` is `null` for every
`domain.create` result — not a bug, not a gap, simply "this script never
produces machine-parseable stdout, so there is nothing to parse." The
result is still fully structured via `status`, `mutation_state`,
`exit_code`, `stdout`, `stderr`, and the error-code fields — exactly the
list the task specified must remain present regardless.

# Locking

`domain.create`'s registry entry declares `"mutation" => ["kind" =>
"create"]`. `CommandAdapter::invoke()`'s existing (locking-pass) logic —
unmodified by this task — already does exactly what the task's required
flow specifies:

```
validate → resolve registry → build argv → acquire per-user lock → execute → release lock
```

The lock is keyed on `$target["user"]`, which for `domain.create` is the
same already-validated `user` parameter used to build `argv[0]`. No new
locking mechanism was introduced; `LockManager` was not modified — no
concrete defect was found that required it. `LockManagerTest.php`'s
existing real-flock, real-subprocess tests already cover the mechanism
itself; `DomainCreateTest.php` adds tests H (validation failures never
attempt the lock) and I (a timed-out lock never spawns
`v-add-web-domain`), specific to this operation's own registry entry.

# Mutation Semantics

Exactly the three-value model from `WRITE_OPERATION_DESIGN.md`, applied
here without any operation-specific exception:

| Condition | `mutation_state` |
|---|---|
| Unknown operation | `null` (mutation status of an unknown operation is unknowable — same as every other operation) |
| Unexpected parameter | `not_attempted` |
| Missing required parameter | `not_attempted` |
| Malformed `user`/`domain` | `not_attempted` |
| Lock timeout (`LOCK_TIMEOUT`) | `not_attempted` |
| Lock mechanism failure (`LOCK_UNAVAILABLE`) | `not_attempted` |
| `v-add-web-domain` exits `0` | `confirmed` |
| `v-add-web-domain` exits non-zero, for ANY reason, including `E_EXISTS` (duplicate domain) or `E_RESTART` (reload failure after config was already written) | `unknown` |

**No `partial_failure` value exists anywhere in this codebase.** The
`E_EXISTS` (duplicate domain) and `E_RESTART` (post-mutation reload
failure) cases are exactly the two scenarios `WRITE_OPERATION_DESIGN.md`
predicted would tempt a more specific label — and both still resolve to
the same generic `unknown`, per that document's Part 4/5 and this task's
explicit instruction not to introduce a new state. See "Idempotency /
Duplicate Domain" and "Error Handling" below for the source evidence
behind each.

# Error Handling

`status` is derived exactly as it already is for `domain.get`/
`domain.list` — `ok` for exit `0`, `hestia_error` for any other exit
code, mapped through the existing, unmodified
`CommandAdapter::HESTIA_EXIT_CODES` table (already covers every code
`bin/v-add-web-domain` can produce: `E_ARGS`, `E_NOTEXIST`, `E_EXISTS`,
`E_SUSPENDED`, `E_LIMIT`, `E_DISABLED`, `E_RESTART` — no new codes were
needed).

`errorMessage` is derived the same way as every other operation:
stderr if non-empty, else stdout, else a generic "Command exited with
code N" fallback. Since `bin/v-add-web-domain`'s own error text goes to
stdout (via `check_result`'s plain `echo`, confirmed by source read —
see "Command Contract"), `errorMessage` in practice surfaces that text
via the stdout fallback, exactly like `domain.get`'s `E_NOTEXIST` case
already does.

# Security

- **No new injection surface.** `argv` is still built by the same
  array-form-`proc_open()` path already proven immune to shell
  metacharacter interpretation (`ProcOpenProcessRunnerTest`). The four
  fixed values (`ip=""`, `restart="yes"`, `aliases=""`, `proxy_ext=""`)
  are compile-time string literals in `CommandRegistry.php`, never
  derived from caller input, so there is no path from a caller-supplied
  value to any of those four argv slots at all — the only way to affect
  them would be to change the registry source file itself.
- **No path traversal.** `user`/`domain` are validated by the same
  `ParameterValidator` methods already exercised against traversal-shaped
  input for the read-only operations; nothing in this operation builds a
  filesystem path from caller input directly (that happens entirely
  inside `bin/v-add-web-domain`, unchanged, using its own existing
  `is_object_valid`/`is_dir_symlink` checks).
- **No privilege escalation.** Still invoked via the existing, unchanged
  `sudo /usr/local/hestia/bin/v-add-web-domain` mechanism, under the same
  `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*` sudoers policy
  documented in `ARCHITECTURE_ADAPTER_DESIGN.md` section 5 — this task
  does not change that policy, narrow it, or widen it.
- **No unsafe environment inheritance.** `ProcOpenProcessRunner` (unchanged
  by this task) does not pass any caller-controlled environment variables
  to the spawned process.
- **No lock path manipulation.** The lock is keyed on `$target["user"]`,
  which by the time locking runs has already passed
  `ParameterValidator::isValidUsername()` (parameter validation, step 4)
  AND `LockManager`'s own independent `lockFilePath()` re-validation
  (defense in depth, unchanged from the locking pass) — the same
  double-check already proven against path-traversal-shaped usernames in
  `LockManagerTest::testInvalidUsernameRejected`.
- **No parameter confusion.** `ip`/`restart`/`aliases`/`proxy_ext` cannot
  be supplied by a caller at all (they are not in the `parameters`
  schema) — there is no code path where a caller-supplied value could
  end up in the wrong argv slot or silently override a fixed value; an
  attempt to supply any of them is rejected outright (`UNEXPECTED_PARAMETER`,
  test C).
- **No generic exec/runRaw mechanism was introduced.**
  `CommandAdapter`'s public surface is unchanged: `invoke(operation,
  params, actor)` remains the only entry point; there is still no way for
  a caller to name an arbitrary command or supply a raw argument list.

# Tests

`test/adapter/DomainCreateTest.php` — 14 new tests, all using
`FakeProcessRunner` (no real subprocess) and either a real
temp-directory-backed `LockManager` or `SpyLockManager` (only where a
specific lock outcome must be forced). No test requires root or a real
Hestia installation.

| Req. | Test | What it proves |
|---|---|---|
| A | `testRegistered` | `domain.create` resolves to a registry entry mapping to `v-add-web-domain` with `mutation.kind = "create"` |
| B | `testGeneratedArgv` | argv is exactly `[script, user, domain, "", "yes", "", ""]` |
| C | `testUnknownParameterRejected` | supplying `ip` as a caller parameter is rejected (it is fixed, not public) |
| D | `testMissingParameterRejected` | missing `domain` is rejected |
| E | `testInvalidUsernameRejected` | malformed `user` is rejected |
| F | `testInvalidDomainRejected` | malformed `domain` is rejected |
| G | `testInjectionShapedInputRejected` | 5 shell-metacharacter payloads all rejected, zero process calls |
| H | `testValidationFailureDoesNotAcquireLock` | a validation failure never calls `LockManager::acquire()` |
| I | `testLockTimeoutPreventsExecution` | a timed-out lock returns `LOCK_TIMEOUT`/`not_attempted` and `v-add-web-domain` is never spawned |
| J | `testSuccessStatusAndMutationState` | exit `0` → `status=ok`, `mutation_state=confirmed` |
| K | `testFailureStatusAndMutationState` | exit `4` (E_EXISTS) → `status=hestia_error`, `mutation_state=unknown` |
| L | `testStreamsPreservedOnFailure` | exit code/stdout/stderr are captured exactly as returned |
| M | `testLockReleasedAfterSuccess` | lock released after a successful call |
| N | `testLockReleasedAfterFailure` | lock released after a failed call |
| O | (whole suite) | `domain.get`/`domain.list` tests remain green |

**Requirement O required one pre-existing test fix, not a new
limitation**: `DomainListTest::testUnknownOperationRejected` used
`"domain.create"` as its "an operation that doesn't exist" placeholder,
written before this task. Now that `domain.create` is real (and
mutating), that test was updated to use `"domain.delete"` instead —
still explicitly unimplemented anywhere in this codebase — to keep
testing the same thing ("an operation not in the registry") it always
intended to test. This is the only change made to any pre-existing test
file in this pass.

**Full-suite result**: `php test/adapter/run_tests.php` — **54 passed, 0
failed** (24 pre-existing + 16 from the locking pass + 14 new for
`domain.create`), run twice in a row to check for flakiness in the
real-subprocess/real-flock tests; both runs identical.

# Known Limitations

- **`ip`/`restart`/`aliases`/`proxy_ext` are not caller-configurable.**
  All four are fixed to values matching the existing production UI
  caller's real-world usage (see "Parameter Model"). A caller cannot
  request a specific IP, suppress the automatic restart, set a custom
  alias list, or set custom proxy extensions through `domain.create` in
  this pass. Exposing any of them is additive and does not require
  touching `CommandAdapter.php` (see "Parameter Model" for exactly what
  it would take).
- **No idempotency added.** A second `domain.create` call for the same
  domain fails with the underlying script's own `E_EXISTS`
  (`mutation_state = unknown`, not a special "already exists, no-op"
  success) — the adapter deliberately preserves Hestia's existing
  semantics rather than inventing new ones (see "Idempotency / Duplicate
  Domain" below).
- **Legacy bypass, unresolved by design** (unchanged from
  `LOCK_IMPLEMENTATION.md`): `web/inc/main.php`,
  `web/api/index.php`, and `web/add/web/index.php` (the existing UI
  caller for domain creation) all remain **unmodified** and continue to
  call `v-add-web-domain` directly via `exec()`, entirely outside this
  adapter's locking boundary. A `domain.create` call through the adapter
  and a form submission through `web/add/web/*` for the same user are
  NOT serialized against each other — only two adapter-routed
  `domain.create` calls for the same user are. This is the same,
  already-documented limitation, now demonstrated against a real
  mutating operation rather than only described abstractly.
- **No API v2, no migration.** Nothing in `web/inc/main.php`,
  `web/api/index.php`, or `web/add/web/index.php` was touched, and no
  HTTP-facing API v2 layer exists — `domain.create` is reachable only by
  directly instantiating `CommandAdapter` in PHP code, exactly as
  instructed.
- **`domain.delete` does not exist.** There is no code path to remove a
  domain created through this adapter other than the existing, unrelated
  `bin/v-delete-web-domain` script, run directly (see the manual test's
  "Cleanup" section).
- **Reload-failure ambiguity is inherent, not adapter-specific.** If
  `v-restart-web`/`v-restart-proxy` fails after `web.conf` has already
  been appended to, `bin/v-add-web-domain` itself returns non-zero
  (`E_RESTART`) even though the domain's configuration was, in fact,
  written. This is exactly the scenario `mutation_state = unknown` (not
  a false "confirmed" or an invented "partial_failure") exists to
  represent honestly — see "Service Reload / Failure Semantics" below.
- **`$lockWaitMs` still not populated** (unchanged from
  `LOCK_IMPLEMENTATION.md`).

## Idempotency / Duplicate Domain

Investigated directly from source, not assumed: `bin/v-add-web-domain`
rejects a duplicate domain cleanly, via `is_domain_new()` →
`is_web_domain_new()` (`func/domain.sh` lines 45-56), which runs during
the script's "Verifications" section (lines 63-70) — **before** any
directory is created, before `web.conf` is touched, before any service
is restarted. `check_result "$E_EXISTS" "Web domain $1 exists"` exits
the script immediately at that point.

There is also a second, redundant filesystem-level check at the start of
the "Action" section (`[[ -e "$HOMEDIR/$user/web/$domain" ]] &&
check_result "$E_EXISTS" ...`, line 97) — belt-and-suspenders for the
same condition, not a second distinct failure mode.

**No adapter-level idempotency behavior was added.** The task explicitly
asked not to add any unless existing Hestia semantics justify it — they
don't: Hestia's own semantics are "reject cleanly with E_EXISTS", which
the adapter already surfaces faithfully as `status = hestia_error`,
`hestia_error_code = "E_EXISTS"`, `mutation_state = "unknown"` (not a
special "duplicate" mutation_state — the adapter's generic model doesn't
know E_EXISTS specifically means "definitely no mutation happened"; it
only knows "the process exited non-zero"). A future refinement could, in
principle, special-case `E_EXISTS` into a would-be
`not_attempted`-equivalent, but that would require the adapter to trust
per-error-code semantic knowledge about a specific script — exactly the
kind of operation-specific special-casing this task explicitly
prohibited introducing generically.

## Service Reload / Failure Semantics

Investigated directly from source: `$BIN/v-restart-web "$restart"`
(line 250) and `$BIN/v-restart-proxy "$restart"` (line 254) both run
**after** `web.conf` has already been appended to (line 245) and after
the vhost/proxy config files have already been written (lines 192, 223).
Each is checked independently via `check_result $? "... restart
failed"` (`E_RESTART` = 20).

This means a reload failure produces a non-zero exit from
`bin/v-add-web-domain` **even though the domain's configuration was, in
fact, durably written** — a real instance of the exact
partially-completed-then-failed scenario `WRITE_OPERATION_DESIGN.md`
Part 5 predicted for this specific script, using this specific
`bin/v-add-web-domain` trace as its evidence. This is precisely why
`mutation_state = unknown` (never `confirmed`, never a more specific
`partial_failure` guess) is the correct, honest answer for any non-zero
exit here — the adapter cannot distinguish "nothing happened" from
"config was written, but the reload afterward failed" from the exit code
alone, and does not claim to.

# Next Step

With `domain.create` implemented and its full mutation-state/locking
behavior tested, the natural next steps — none pursued in this task,
per its explicit scope — are:

1. **`domain.delete` → `bin/v-delete-web-domain`**, the natural pairing
   operation, needed before the manual integration test's "Cleanup" step
   can itself be routed through the adapter instead of a direct CLI call.
2. **Parameterizing `ip`** (see "Parameter Model") — the one place this
   pass's fixed-default choice most visibly diverges from the existing
   production UI's real behavior (which always lets the operator choose
   an IP).
3. **Migrating `web/add/web/index.php`** to call
   `CommandAdapter::invoke("domain.create", ...)` instead of its current
   direct `exec()` + `quoteshellarg()` call — bringing the one real
   production caller of this exact operation inside the new locking
   boundary. Not attempted here, per this task's explicit "do not migrate
   existing PHP callers" instruction.
4. **A registry/JSON-file extraction** (already deferred twice, in
   `CommandRegistry.php`'s own docblock) — now backed by three real,
   meaningfully different examples (two read shapes, one write shape),
   which is a stronger basis for designing that file format than the two
   read-only examples alone would have been.
