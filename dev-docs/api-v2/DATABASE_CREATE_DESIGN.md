# `database.create` — Source-First Design Review

Review-only, per the task's explicit instruction. Nothing in this document
has been implemented: no registry entry, no code change, no test. All line
references are to files in this worktree at the time of review.

---

# SOURCE CONTRACT

**Script**: `bin/v-add-database` (114 lines) — confirmed correct; not
guessed from the filename alone. Cross-checked against `bin/v-list-database*`
(read-only siblings, not candidates) and against `func/db.sh`, which holds
every helper `v-add-database` actually calls.

**Argument line** (`bin/v-add-database:20-27`):

```
user=$1
database="$user"_"$2"      # 2nd positional is a SUFFIX, not the final name
dbuser="$user"_"$3"        # 3rd positional is also a SUFFIX
password=$4
HIDE=4                     # marks arg 4 as sensitive for Hestia's own logging
type=${5-mysql}
host=$6
charset=${7-UTF8MB4}
charset=$(echo "$charset" | tr '[:lower:]' '[:upper:]')
```

`check_args '4' "$#" 'USER DATABASE DBUSER DBPASS [TYPE] [HOST] [CHARSET]'`
(`bin/v-add-database:49`) — 7 positional slots total, only the first 4
required.

**Critical naming fact, source-verified, not assumed from any other
`v-add-*` script**: `$2` and `$3` are NOT the final database/user names.
Hestia concatenates `"$user"_"$2"` and `"$user"_"$3"` itself
(`bin/v-add-database:21-22`). A caller who wants database `wordpress_db`
passes `2` as `wordpress_db`, not as `admin_wordpress_db` — the script does
the prefixing. This mirrors nothing in `domain.create`/`domain.delete`
(domain names are not username-prefixed) and must not be assumed away.

**PostgreSQL-specific re-derivation** (`bin/v-add-database:40-43`): if
`type = pgsql`, `$database`/`$dbuser` are recomputed as
`tr '[:upper:]' '[:lower:]'` of the same concatenation — Postgres
identifiers are forced lowercase. This happens BEFORE validation
(`is_format_valid` at line 50 validates the already-lowercased value), so
the type choice changes what string is actually checked and stored.

**Database types supported**: exactly `mysql` and `pgsql`
(`bin/v-add-database:84-87`, `case $type in mysql) add_mysql_database ;;
pgsql) add_pgsql_database ;; esac`). `type` defaults to `mysql`
(`${5-mysql}`) if omitted. `is_type_valid "$DB_SYSTEM" "$type"`
(`func/main.sh:331-335`) restricts `$type` to whatever the installer wrote
into `DB_SYSTEM` (`install/hst-install-ubuntu.sh:1415`) — a
space-separated whitelist chosen once at install time, not caller-editable.
No third database engine exists in this codebase's `func/db.sh`
(confirmed by reading the full file — no `add_sqlite_database`,
no `add_mongo_database`, nothing else).

**Host semantics** (`bin/v-add-database:57-59`, `func/db.sh:218-247`):
`host` is NOT a free-form remote hostname. `get_next_dbhost()` either keeps
a caller-supplied value or, if empty/`'default'`, auto-selects one of the
configured DB hosts by a weighted-least-used algorithm reading
`$HESTIA/conf/$type.conf`. Whatever value results is then checked with
`is_object_valid "../../../conf/$type" 'HOST' "$host"` — a relative-path
trick (see MUTATION FLOW) that resolves to `$HESTIA/conf/$type.conf` and
requires a matching `HOST='...'` line to already exist there. A caller
cannot point this at an arbitrary external hostname; `host` is a symbolic
reference to a database server Hestia's own admin already configured. This
is the same "let Hestia pick, from Hestia's own config, if omitted" shape
`domain.create`'s `ip=""` argument already uses — not a new concept.

**Charset semantics** (`bin/v-add-database:27-28`, `func/db.sh:250-259`):
defaults to `UTF8MB4`, uppercased unconditionally. `is_charset_valid()`
— which actually checks the value against the host's configured `CHARSETS`
list — exists in `func/db.sh:250-259` but is explicitly commented out at
the call site: `#is_charset_valid` (`bin/v-add-database:60`). Source fact:
Hestia's own charset-value validation is dead code on this path. Only
shape validation applies (see SECURITY).

**Password handling**: see PASSWORD HANDLING section.

**Multiple resources created**: yes — one call creates BOTH a database and
a database user/role, atomically from the caller's point of view but as
several independent SQL statements from the server's point of view (see
MUTATION FLOW). This is a first for this operation series; every prior
mutating operation (`domain.create`, `domain.delete`, `backup.schedule`)
manipulated exactly one Hestia-tracked resource.

**Files/configuration modified**:
- `$USER_DATA/db.conf` — one new line appended (`bin/v-add-database:99-103`), the
  Hestia-side bookkeeping record (db name, dbuser, md5 hash pulled back
  from the DB server, host, type, charset, `U_DISK='0'`, `SUSPENDED='no'`,
  timestamp).
- `$HESTIA/conf/$type.conf` (i.e. `mysql.conf` or `pgsql.conf`) — the
  chosen host's `U_DB_BASES` counter incremented and `U_SYS_USERS` list
  updated in place via `sed -i` (`func/db.sh:262-282`,
  `increase_dbhost_values`). This file is **shared across every user on
  that DB host**, not scoped to the calling user. See LOCKING.
- `$USER_DATA/user.conf` — `U_DATABASES` counter incremented
  (`increase_user_value`, `bin/v-add-database:107`).
- Actual database-server state: a new schema/database and a new
  role/user with `GRANT ALL` on it, created via `mysql`/`mariadb` or
  `psql` client invocations (see MUTATION FLOW) — this is state that
  lives entirely outside any file this repository controls.

**System/database-server commands invoked**: `mariadb`/`mysql` CLI (via
`mysql_query()`, `func/db.sh:110-122`) or `psql` CLI (via `psql_query()`,
`func/db.sh:194-199`) — never a raw SQL driver library, always the vendor
CLI client, invoked with a config file (`--defaults-file=$mycnf` for MySQL,
`-h/-U/-p` plus `PGPASSWORD` env for Postgres) that itself contains the
**database ADMIN credentials** Hestia manages, not the new user's
credentials.

**Service restarts/reloads**: none. Unlike `domain.create`/`domain.delete`
(which restart nginx/apache/proxy), `v-add-database` never calls
`v-restart-*` anywhere in its source. There is no `E_RESTART`-shaped
post-mutation failure class here at all.

**Exit codes** — every code `v-add-database`/its helpers can produce,
traced to the exact call site:

| Code | Symbolic | Source | Timing |
|---|---|---|---|
| 1 | E_ARGS | `check_args` (line 49) | Pre |
| 2 | E_INVALID | `is_format_valid` → `is_database_format_valid`/`is_dbuser_format_valid`/`is_object_format_valid('charset')` (line 50); `is_type_valid` (line 52); pgsql dash-exclusion checks (lines 64-72) | Pre |
| 3 | E_NOTEXIST | `is_object_valid 'user'` (line 53); `is_object_valid HOST` via the relative-path trick (line 58) | Pre |
| 4 | E_EXISTS | `is_object_new 'db' 'DB'` / `is_object_new 'db' 'DBUSER'` (lines 55-56) | Pre |
| 5 | E_SUSPENDED | `is_object_unsuspended 'user'` (line 54) | Pre |
| 6 | E_UNSUSPENDED | `is_object_unsuspended HOST` (line 59) | Pre |
| 8 | E_LIMIT | `is_package_full 'DATABASES'` (line 61) | Pre |
| 11 | E_DISABLED | `is_system_enabled "$DB_SYSTEM"` (line 51) | Pre |
| 12 | E_PARSING | `mysql_connect`/`psql_connect` config-parse failure (`func/db.sh:43-47`, `174-178`) | Post-connect-attempt, **pre-mutation** (no CREATE statement has run yet) |
| 15 | E_CONNECT | `mysql_connect`/`psql_connect` server-unreachable (`func/db.sh:73-83`, `180-190`) | Post-connect-attempt, **pre-mutation** |
| **raw client exit status** (commonly `1`, but not guaranteed) | none — `check_result $?` with NO third-arg override (`func/db.sh:314`) | `CREATE DATABASE` query failure inside `add_mysql_database` | **Ambiguous, see MUTATION FLOW and MUTATION MODEL** |
| 1 (bare `exit 1`, ambiguous with E_ARGS) | — | `check_hestia_demo_mode` (line 77) | Pre |
| 0 | OK | end of script (bare `exit`, line 113) | — |

`add_pgsql_database` (`func/db.sh:374-397`) has **no `check_result` calls
at all** — every `psql_query` there is unchecked. `add_mysql_database` has
exactly one `check_result` (the `CREATE DATABASE` statement,
line 314); every subsequent `CREATE USER`/`GRANT` query in that function is
piped to `/dev/null` with no result check. See MUTATION FLOW for why this
matters more here than it did for any prior operation.

**stdout/stderr**: no JSON output mode, no `format` argument anywhere in
`v-add-database` or `func/db.sh`'s add functions (confirmed by reading
both files in full) — same as `domain.create`/`domain.delete`. On success,
stdout is empty (or whatever the underlying `mysql`/`psql` client printed,
which is redirected to `/dev/null` for every add-time query). On failure,
`check_result` echoes `"Error: $2"` to stdout and appends to
`$HESTIA/log/error.log` via `log_event`.

---

# MUTATION FLOW

```
Verifications (all pre-mutation, all in "Verifications" section,
lines 49-72)
   ↓
check_hestia_demo_mode (pre-mutation, ambiguous bare exit 1)
   ↓
mysql_connect / psql_connect
   — reads $HESTIA/conf/$type.conf, builds a client config, runs a
     trivial "SELECT VERSION()" probe.
   — E_PARSING / E_CONNECT can fire here. STILL PRE-MUTATION: nothing
     has been created yet, this is a read-only connectivity check.
   ↓
[MySQL] CREATE DATABASE `$database` CHARACTER SET $charset
[Pg]    CREATE ROLE $dbuser WITH LOGIN PASSWORD '...'; then
        CREATE DATABASE $database OWNER $dbuser [...]
   — THIS IS WHERE MUTATION BEGINS.
   — MySQL: checked via check_result (func/db.sh:314) — but with NO
     symbolic error-code override, so failure exits with mysql/mariadb's
     own raw client exit status (typically 1, but this is the CLIENT's
     choice, not Hestia's — not a guarantee).
   — Postgres: NOT checked at all (func/db.sh:378-387) — a failing
     CREATE ROLE or CREATE DATABASE on the Postgres path is silently
     swallowed and the script continues as if it had succeeded.
   ↓
CREATE USER / GRANT (MySQL, func/db.sh:316-338)
GRANT ALL PRIVILEGES / GRANT CONNECT (Pg, func/db.sh:389-393)
   — NONE of these are check_result-checked, on either engine. Every
     one is piped to /dev/null with its exit status discarded.
   — This is the single most important source-verified finding in this
     review: once the CREATE DATABASE statement (MySQL) or the first two
     Postgres statements succeed, THE SCRIPT CANNOT FAIL ANYMORE. There
     is no remaining check_result/exit call between here and the end of
     the script. A GRANT failure (e.g. because $dbuser already exists on
     the SQL server itself with different privileges/password — a
     collision Hestia's is_object_new only rules out against its OWN
     db.conf bookkeeping, never against the actual server's user table)
     is invisible to the caller.
   ↓
md5 = <read back the password hash Hestia itself just set via
       SHOW CREATE USER / SHOW GRANTS / pg_authid> (func/db.sh:340-370,
       395-396)
   — a read-back, not a mutation. If the CREATE USER/GRANT above
     silently failed, this typically returns an empty/stale value,
     which is written into db.conf below as MD5='' — a symptom, not a
     safeguard; nothing acts on an empty md5.
   ↓
Append one line to $USER_DATA/db.conf (bin/v-add-database:99-103)
   — Hestia's own bookkeeping record. From this point on,
     is_object_new('db', ...) will report this database as existing for
     future calls, regardless of whether the SQL-server-side mutation
     above was fully correct.
   ↓
increase_dbhost_values (func/db.sh:262-282)
   — sed -i on $HESTIA/conf/$type.conf, a file SHARED by every user
     assigned to that DB host, not scoped to $user. See LOCKING.
   ↓
increase_user_value (per-user $USER_DATA/user.conf counter)
   ↓
v-log-action + log_event($OK, ...) → exit 0 (line 113, bare exit)
```

**Where mutation begins, precisely**: the first `CREATE DATABASE`/
`CREATE ROLE` statement inside `add_mysql_database`/`add_pgsql_database`
(`func/db.sh:312-313` MySQL, `func/db.sh:378` Postgres) — everything
before that, including the connectivity probe, is read-only.

**Can it mutate then fail later?** Yes, in a way none of the three prior
operations exhibited:
- MySQL: if `CREATE DATABASE` succeeds, exit is **guaranteed 0**
  regardless of whether the subsequent `CREATE USER`/`GRANT` statements
  succeeded, because none of them are checked.
- Postgres: `CREATE ROLE`/`CREATE DATABASE`/both `GRANT`s are *all*
  unchecked — even the primary resource's own creation failure is
  invisible.
- In neither case does the script ever reach a non-zero exit once the
  first successful mutating statement has run. There is no exit code —
  known or unknown — that represents "mutated, then something after it
  failed," because nothing after the first successful statement is
  capable of producing a non-zero exit at all. This is different from
  `domain.create`/`domain.delete`'s `E_RESTART` case (a real, distinct,
  checked post-mutation failure) — here the script doesn't check, so it
  can't report.

---

# REGISTRY DESIGN

Proposed only — not implemented. Two variants exist depending on how the
password-handling finding below is resolved; both are shown so the
NEW ABSTRACTIONS section can be evaluated concretely. Field order matches
this series' existing entries.

```php
"database.create" => [
    // bin/v-add-database, positional contract confirmed by direct source
    // read: user=$1; database=$user_$2; dbuser=$user_$3; password=$4;
    // type=${5-mysql}; host=$6; charset=${7-UTF8MB4}
    // (bin/v-add-database:20-27). check_args '4' ... (line 49): first 4
    // slots required, last 3 optional with Hestia-side defaults.
    "script" => "v-add-database",
    "argument_order" => ["user", "database", "dbuser", "password", "type", "host", "charset"],
    "parameters" => [
        "user" => ["type" => "username", "required" => true],
        // NOT the final database name -- this is the un-prefixed suffix
        // the script itself concatenates with "$user"_ (line 21). A new
        // "db_name_suffix"-shaped validator is needed; reusing "domain"
        // or "username" types would be a type-confusion (see SECURITY).
        "database" => ["type" => "db_name_suffix", "required" => true],
        "dbuser" => ["type" => "db_name_suffix", "required" => true],
        // See PASSWORD HANDLING / NEW ABSTRACTIONS for why this entry
        // cannot be a plain caller-supplied parameter under today's
        // CommandAdapter without a new "sensitive" concept.
        "password" => ["type" => "password", "required" => true, "sensitive" => true],
        "type" => ["type" => "db_engine", "required" => false],
        // host intentionally NOT exposed as a caller parameter in the
        // minimal contract -- see rationale below.
    ],
    // host="": lets get_next_dbhost() (func/db.sh:218-247) auto-select,
    // exactly matching domain.create's ip="" "let Hestia pick" pattern.
    // charset="": lets the script's own UTF8MB4 default apply
    // (bin/v-add-database:27) -- Hestia's own is_charset_valid check is
    // dead code on this path regardless (see SOURCE CONTRACT), so
    // exposing charset as caller-controlled would add a parameter
    // whose validity Hestia itself never actually checks beyond shape.
    "fixed_parameters" => [
        "host" => "",
        "charset" => "",
    ],
    // No output_format/result_shape: v-add-database has no JSON mode,
    // confirmed by reading the full script and func/db.sh.
    //
    // No known_post_mutation_exit_codes: there is no service-restart-
    // style exit code here at all (no E_RESTART path exists in this
    // script -- see SOURCE CONTRACT). The one code that occurs after
    // mutation begins (the unchecked-client-exit-status case) is NOT a
    // safe candidate for this list -- see MUTATION MODEL.
    "mutation" => ["kind" => "create"],
],
```

**Minimal public parameter set, and what was deliberately excluded**:
- `type` exposed (caller may reasonably need to choose mysql vs pgsql) —
  needs a new `db_engine` validator (whitelist `mysql`/`pgsql`; do NOT
  reuse `is_type_valid`'s dynamic `$DB_SYSTEM` check as-is, since the
  adapter has no generic mechanism today for a validator that reads
  runtime server config — a real but separate, smaller gap, noted under
  NEW ABSTRACTIONS as optional/deferrable).
- `host` NOT exposed in the minimal contract: it is an internal
  infrastructure-topology detail (which physical DB server), not part of
  "create a database for this user." Matches the review's instruction not
  to blindly mirror every CLI argument. Can be added later as a caller
  parameter without any adapter redesign if a real need appears.
- `charset` NOT exposed for the reason given inline above (Hestia itself
  doesn't validate it against the real whitelist on this path — exposing
  caller control over a value the underlying script doesn't actually
  check is not a safety improvement, just surface area).
- `database`/`dbuser` need a **new type**, `db_name_suffix` (or similar) —
  reusing `domain` or `username` would be a category error (see SECURITY);
  this is a small, mechanical addition to `CommandAdapter::$typeValidators`,
  not an architectural one, so it is not listed as a NEW ABSTRACTION.

---

# SECURITY

**Shell injection**: not exploitable through the existing argv-array
`proc_open()` call path (`ProcOpenProcessRunner.php`, confirmed already
established for prior operations) — this holds unchanged for
`database.create`; no new escape hatch is introduced.

**SQL injection**: `database`/`dbuser` are shape-restricted by
`is_database_format_valid`/`is_dbuser_format_valid` (`func/main.sh:1206-1231`)
to `[[:alnum:]._-]` with no quotes, backticks, or whitespace, before ever
reaching a query string — these are excluded from SQL metacharacters at
the Hestia layer already. `password` is escaped via `mysql_sql_escape`/
`sql_escape` (`func/db.sh:97-108`) immediately before insertion into a
single-quoted SQL literal — both escapers were themselves reviewed and
commented in this codebase (see the inline docblocks at `func/db.sh:94-108`)
specifically for correct backslash/quote handling per engine. No new SQL
injection surface is introduced by adopting this operation as-is.

**Filesystem path traversal**: none of the caller-facing parameters
(`user`, `database`, `dbuser`, `type`) touch a filesystem path directly.
`host`, if ever exposed as a caller parameter, resolves through
`is_object_valid`'s relative-path trick against a FIXED, hardcoded
`"../../../conf/$type"` template (`bin/v-add-database:58`) where only
`$type` (already whitelisted to `mysql`/`pgsql`) is substituted — `host`
itself is never used as a path component, only as a `grep` match value.
No traversal surface here.

**Arbitrary command execution**: none beyond the existing, already-reviewed
argv-array `proc_open()` boundary shared by every operation in this
series.

**Privilege escalation**: `v-add-database` runs as the same sudo-elevated
context every other `v-*` script does; no new sudo surface. Not
re-examined further, consistent with how this was treated for prior
operations (out of scope for adapter-level review — sudoers is a
system-installation concern).

**Unsafe password handling / command-line password leakage**: **yes, a
real, source-verified finding**. See PASSWORD HANDLING below —
significant enough to be broken out into its own section as the task
requires.

**Option injection**: `argument_order`-driven, positional-only argv
construction (unchanged from prior operations) — no caller value is ever
interpreted as a flag/option by the underlying script; nothing here
changes that.

**Database-name/user-name confusion**: **yes, a real, source-verified
finding, specific to this operation**. `database` and `dbuser` are BOTH
un-prefixed suffixes the script itself concatenates with `"$user"_`
(`bin/v-add-database:21-22`) — if the adapter naively reused the existing
`"domain"` or `"username"` parameter types (which validate a *complete*,
final identifier) for these fields, callers would not be able to tell,
from the type name alone, that they must NOT pass the already-prefixed
form. This is a documentation/naming hazard more than an injection
hazard (both fields are still shape-validated before use either way), but
it is a genuinely new confusion this operation introduces that no prior
operation's flat 1:1 parameter-to-final-value mapping had. Addressed in
REGISTRY DESIGN by naming the type `db_name_suffix` rather than reusing an
existing type name.

**What must be validated by the adapter BEFORE authorization/lock/exec**:
exactly what the existing generic parameter-validation step already does
for every operation — shape-validate `user`, `database`, `dbuser`, `type`
(and `host`/`charset` if ever exposed) via `ParameterValidator`-style
functions before authorization is even consulted. This is unchanged
generic machinery; the only genuinely new pre-execution requirement this
operation introduces is the sensitive-parameter handling covered next.

**Hestia validation vs. adapter security-boundary validation** —
distinguished explicitly, per the task's ask:
- **Hestia validation** (already exists, inside `bin/v-add-database`/
  `func/main.sh`, re-run every time regardless of what the adapter does):
  format/shape checks, existence checks, suspension checks, package-limit
  checks, type whitelist checks. The adapter does not need to reimplement
  any of these — it inherits them for free by shelling out, exactly as
  established for every prior operation.
- **Adapter security-boundary validation** (the adapter's own
  responsibility, independent of whether Hestia would eventually catch
  the same problem): (1) the existing generic shape-validators, run before
  authorization, as always; (2) NEW — a parameter must be excluded from
  `AdapterResult`/`$target`/any future logging surface once marked
  `sensitive`, a check that has to happen at the adapter layer because
  Hestia's own script has no visibility into what the adapter does with
  the value after invoking it. This is the one genuinely new
  security-boundary requirement this operation introduces (see PASSWORD
  HANDLING, NEW ABSTRACTIONS).

---

# PASSWORD HANDLING

**How `v-add-database` itself accepts a password**: as positional
argument 4, i.e. a plain CLI argument (`bin/v-add-database:23`). However,
`is_password_valid()` (`func/main.sh:625-633`) gives the script a SECOND,
indirect accepted form: if the argument value starts with `/tmp/`, does
not contain `../`, and the file exists, the script replaces `$password`
with the FIRST LINE read from that file. This is Hestia's own,
already-existing mitigation for exactly the process-listing exposure risk
the task asks about — not something this review is proposing.

**How the one existing production caller actually uses it**
(`web/add/db/index.php:72-98`, read in full): it does NOT pass the
plaintext password as a CLI argument. It writes the password to a
`tempnam("/tmp", "vst")` file, passes that FILE PATH as argument 4, then
`unlink()`s the file immediately after `exec()` returns. This confirms,
from source, that the "safe" way to invoke this specific script — already
established as Hestia's own convention, not an invention of this review —
is via the temp-file indirection, never via literal plaintext argv.

**Evaluation against a future API v2 exposure**:
- **Process listings**: if the adapter's `argv` construction embedded the
  plaintext password directly (the naive, "just do what every other
  parameter does" approach), it WOULD be visible via `ps aux` /
  `/proc/<pid>/cmdline` for the process's lifetime — `ProcOpenProcessRunner`
  passes `argv` straight through to `proc_open()` with no indirection
  (confirmed by reading `ProcOpenProcessRunner.php`). This is a real
  exposure window if the naive path is taken.
- **Logs**: `v-add-database` sets `HIDE=4` (line 24) strictly before
  `source $HESTIA/func/main.sh` (line 34), and `func/main.sh`'s
  `ARGS=("$@")`/`ARGUMENTS` masking loop (lines 158-165) runs at source
  time, referencing `$HIDE` and `$@`. Read in isolation, this is
  consistent with — and evidently intended to produce — masking argument 4
  as `'******'` in every `log_event`/`check_result` call. One link in this
  chain was not independently re-verified in this pass: whether `"$@"`
  inside a `source`d file resolves to the SOURCING script's positional
  parameters (which the mechanism requires) was not confirmed by tracing
  actual `$HESTIA/log/system.log` output, only inferred from the
  variable-scoping intent visible in the source. Treat "Hestia's own logs
  never contain the plaintext password" as highly likely, not as a fully
  traced guarantee, unless independently confirmed against a real log
  line before this is relied upon.
- **stdout/stderr**: no code path in `v-add-database`/`func/db.sh` echoes
  `$password`/`$dbpass`/`$dbpass_esc` to stdout or stderr under either
  success or failure (confirmed by reading both files in full). Safe
  regardless of delivery mechanism.
- **`AdapterResult`**: **unsafe under today's mechanism.**
  `CommandAdapter::invoke()` unconditionally does `$target[$name] = $value`
  for every validated caller parameter (`CommandAdapter.php:262`), and
  `$target` is embedded verbatim in the returned `AdapterResult`
  (`CommandAdapter.php:487`) and passed to the authorizer
  (`CommandAdapter.php:275`). If `password` were declared as an ordinary
  parameter with no special handling, the plaintext value would sit in
  the in-memory `AdapterResult` object and in the authorizer's input for
  every `database.create` call, with no existing redaction mechanism
  (`CommandAdapter`'s own docblock explicitly states "sensitive-argument
  redaction" is NOT implemented in this slice). This is not conditional on
  the underlying command actually running: `$target[$name] = $value`
  (line 262) executes during parameter validation, strictly BEFORE the
  authorization check (line 275) — so a call that is subsequently DENIED
  by the authorizer still carries the plaintext password into the
  `rejected()` path (`CommandAdapter.php:493-530`), which embeds the same
  `$target` into its own returned `AdapterResult` (line 526). The leak
  exists on every code path from parameter validation onward, including
  denial, not only on successful execution. Any future audit-log or
  request-tracing layer built on top of `AdapterResult`/authorizer input
  would then leak the password without ever intending to.
- **Command tracing**: covered by the process-listing point above.

**Is this a blocker or a required change?** A required change — see NEW
ABSTRACTIONS. It is small and precisely scoped, not a redesign of
password handling (the task's instruction not to redesign password
handling is respected: the fix is "use Hestia's own already-existing
temp-file convention, and don't put sensitive values in `$target`," not
"invent a new credential-delivery system").

---

# LOCKING

**Per-user lock (existing, unchanged) is necessary but not fully
sufficient** — a materially different finding than for any prior
operation, explained precisely below.

**What per-user state `database.create` mutates**: `$USER_DATA/db.conf`
(same user, matches the pattern `domain.create`/`domain.delete` already
established) and `$USER_DATA/user.conf`'s `U_DATABASES` counter (same
pattern as `domain.create`'s `U_WEB_DOMAINS`-style counters, already
proven safe under the existing per-user lock). For this slice of state,
the existing per-user lock provides exactly the same guarantee it already
provides for `domain.create`: it closes the TOCTOU race between
`is_object_new('db', ...)`/`is_object_new('db', 'DBUSER', ...)` and the
`db.conf` append, for that one user, the same way it closes the analogous
race for `domain.create`'s `is_object_new('web', ...)`. **No new concept
here.**

**What it ALSO mutates that no prior operation touched**:
`$HESTIA/conf/$type.conf` (`mysql.conf`/`pgsql.conf`) — via
`increase_dbhost_values` (`func/db.sh:262-282`), a **host-scoped, not
user-scoped**, non-atomic `sed -i` read-modify-write of a shared counter
(`U_DB_BASES`) and a shared list (`U_SYS_USERS`). This file can be, and
in a busy installation regularly will be, written by database-create
calls from MULTIPLE DIFFERENT USERS assigned to the same DB host.

**Can two `database.create` calls for the SAME user race?** No — the
existing per-user lock already serializes that, same as every prior
mutating operation.

**Can `database.create` race with `domain.create`/`domain.delete` for the
same user?** No differently than today: different underlying `.conf`
files (`db.conf` vs `web.conf`), same per-user lock already serializes any
concurrent mutating call for that user regardless of which operation, by
design (`LockManager` locks on the user, not on a resource type).

**Can it race with user deletion?** Same answer as it already is for
every mutating operation in this series — a lock on a user being deleted
concurrently is an existing, already-accepted characteristic of the
per-user `LockManager`, not something `database.create` changes.

**Can two `database.create` calls for DIFFERENT users, on the SAME DB
host, race?** **Yes.** The per-user lock is keyed per-user
(`LockManager::acquire($lockUser)`, `CommandAdapter.php:362`, locked on
`$target["user"]`); it structurally cannot serialize two different users'
lock acquisitions against each other. `increase_dbhost_values`'s
`sed -i` on the shared host conf file has no locking of its own inside
Hestia's source (confirmed by reading `func/db.sh:262-282` — no `flock`,
no lockfile, nothing). Two concurrent database creations by different
users on the same host can interleave their read-modify-write of
`U_DB_BASES`/`U_SYS_USERS`, and one increment can be lost (a classic
non-atomic-counter race).

**Is this new, or pre-existing?** Pre-existing and NOT introduced or
worsened by adapter adoption — this race exists identically today via the
direct `exec()` call in `web/add/db/index.php`, with no lock at all on
that path. The adapter, even without closing this gap, is a strict
IMPROVEMENT over today's production behavior (it at least serializes
same-user contention, which today's raw `exec()` path does not do either).
Per this series' established posture (documented in the `domain.create`/
`backup.schedule` reviews): the adapter's job is to faithfully relay
Hestia's own concurrency guarantees, not to invent stronger ones Hestia
itself doesn't have. **Do not modify `LockManager`. Do not propose a new
locking scope** — consistent with the task's explicit instruction, and
because per-user locking remains the correct, sufficient primitive for
everything the adapter itself is responsible for (serializing one user's
own operations). The host-counter race is a Hestia-level fact to document,
not an adapter defect to fix.

---

# AUTHORIZATION TARGET

`target.user` remains necessary, same as every prior operation.

Is it SUFFICIENT? For the minimal registry contract proposed above
(`user`, `database`, `dbuser`, `type` as caller-supplied parameters), the
answer is: **yes, mechanically**, with one deliberate exclusion.
`CommandAdapter::invoke()` already builds `$target` generically from every
validated caller parameter (`CommandAdapter.php:262`) — so `database`,
`dbuser`, and `type` automatically reach the authorizer's `$target`
argument with zero code change, the same generic mechanism that already
serves `domain.create`'s `domain` field. A future authorization policy
that wants to say "this user's plan does not permit pgsql databases" or
"this user may not create a database named X" already has everything it
needs in `$target`, without any adapter change.

The deliberate exclusion is `password`: it must NOT flow into `$target`
through this same generic mechanism (see PASSWORD HANDLING). This is the
one respect in which "just declare it as a caller parameter like
everything else" is NOT sufficient for `database.create` — not because
the target MODEL is missing a concept, but because the generic
target-population mechanism needs a way to skip one specific field. See
NEW ABSTRACTIONS.

No RBAC, no policy rules proposed here, per the task's instruction —
only what information the future authorization layer needs to receive,
which the above already answers: everything except the password.

---

# MUTATION MODEL

The existing 4-value model (`not_attempted` / `confirmed` /
`confirmed_degraded` / `unknown`) is **sufficient as-is** — no new state
is required — but `confirmed`'s meaning for this operation is narrower
than it is for `domain.create`, and must be documented with the same
precision `backup.schedule`'s "queued, not exists" caveat already
established as this series' pattern.

**Pre-mutation failures**: every code in the exit-code table above marked
"Pre" (E_ARGS, E_INVALID, E_NOTEXIST, E_EXISTS, E_SUSPENDED, E_UNSUSPENDED,
E_LIMIT, E_DISABLED, demo-mode's bare exit 1, E_PARSING, E_CONNECT) —
`mutation_state` correctly resolves to `not_attempted` for all of these
under the existing `CommandAdapter` rejection path, or to `unknown` if
they somehow reach `CommandAdapter`'s post-execution classification (they
won't reach process execution at all for the adapter's own pre-checks,
but if the underlying script itself exits with one of these, the existing
generic "not a known-post-mutation code → unknown" fallback is the
correct, conservative answer — same as it already is for every prior
operation's pre-mutation codes).

**Successful mutation**: exit 0 → `confirmed`. Source-verified meaning,
precisely: *the `CREATE DATABASE`/`CREATE ROLE` statement(s) succeeded and
Hestia's own `db.conf` bookkeeping was written.* It does **not** guarantee
the `CREATE USER`/`GRANT` statements that were supposed to accompany it
actually succeeded — see MUTATION FLOW's central finding. This is a
strictly weaker guarantee than `domain.create`'s `confirmed` (which does
mean the domain is fully provisioned, since every step of that script IS
checked). Must be documented with the same explicitness as
`backup.schedule`'s "confirmed means queued, not archived" caveat.

**Post-mutation failures — should any exit code be declared as
`known_post_mutation_exit_codes`?** **No.** There is no
`E_RESTART`-equivalent here at all (no service restart exists in this
script). The one exit code that occurs chronologically after mutation
begins — the unchecked, non-symbolic MySQL client exit status from the
`CREATE DATABASE` `check_result` call (`func/db.sh:314`) — is
**disqualified** from this list by the task's own standard ("Do not
classify based on exit-code names alone" / "Do not invent mappings"):
its numeric value is the MySQL/MariaDB client's own choice, not a Hestia
symbolic code, commonly but NOT reliably `1`; declaring it as, say,
`E_ARGS` in `known_post_mutation_exit_codes` would be actively WRONG (a
`CREATE DATABASE` failure is not "not enough arguments," and — the more
dangerous direction — this specific failure mode occurs **before** the
database is durably created, so misclassifying it as
`confirmed_degraded` would be a false claim that mutation succeeded when
it may not have). Leaving it unclassified means it correctly falls to
`unknown` under the existing model — the safe, conservative default this
architecture was explicitly designed to prefer (see
`MUTATION_AND_AUTHORIZATION_DESIGN.md` Part 1's asymmetric-risk
reasoning). **No adapter change and no registry entry addresses this —
this is intentional, not a gap.**

**Genuinely ambiguous failures**: the silent `CREATE USER`/`GRANT`
failures identified in MUTATION FLOW are the most important ambiguity in
this whole review, but they never surface as a distinguishable exit code
at all — the script exits 0 either way. This is not a case the mutation
STATE model can address (there is no failing exit code to classify); it
is a case the DOCUMENTATION must address, by stating plainly that
`database.create`'s `confirmed` does not, and structurally cannot,
guarantee the created database user's grants are correct. No new
mutation state is invented to paper over this — consistent with this
series' established rule against inventing finer-grained states.

---

# RESULT MODEL

`AdapterResult` remains sufficient, unchanged, for the same reason it was
sufficient for `domain.create`/`domain.delete`/`backup.schedule`: no JSON
output mode exists on this script (confirmed above), so `parsed_output`
stays `null`, correctly, exactly as it already does for the two prior
mutating operations. No structured output, no generated identifier beyond
what the caller already supplied (the database/user names are
caller-chosen, not server-generated — unlike, say, an auto-incrementing
ID), no warnings channel, and — despite creating two resources internally
— nothing in the script's output distinguishes "both succeeded" from "only
the database succeeded" (see MUTATION MODEL), so there is no richer
result to surface even if `AdapterResult` were extended; the underlying
script itself doesn't produce the information a richer result model would
need. **No missing concept here** — the gap this operation exposes is
entirely in MUTATION FLOW/MUTATION MODEL, not in the result shape.

---

# RETRY SAFETY

**Executed twice with identical parameters, in the ordinary case**:
rejected cleanly. `is_object_new('db', 'DB', "$database")`
(`bin/v-add-database:55`) fires as soon as the FIRST call's `db.conf`
entry exists, producing `E_EXISTS` (exit 4) — a **pre-mutation** rejection
for the second call. Same shape as `domain.create`'s idempotency story,
already established as safe.

**"Same dbuser, new database" is not a retry case — it is a case
`v-add-database` cannot express at all, a source-verified inconsistency
between create and delete.** `is_object_new 'db' 'DBUSER' "$dbuser"`
(`bin/v-add-database:56`) rejects with `E_EXISTS` the moment `$dbuser`
already appears anywhere in `db.conf`, i.e. it forbids one dbuser from
ever owning a second database. But `delete_mysql_database`
(`func/db.sh:541`, `if [ "$(grep "DBUSER='$DBUSER'" $USER_DATA/db.conf | wc -l)" -lt 2 ]`)
and its Postgres counterpart (`func/db.sh:560`) explicitly anticipate a
dbuser owning MULTIPLE databases — they only drop the underlying SQL user
once the last database referencing it is gone. The delete path's own logic
proves shared dbusers are a state Hestia expects to exist, yet the create
path has no way to produce that state. This is not a retry-safety gap in
the sense of "same call twice"; it means a caller intentionally trying to
grant one existing dbuser access to a second database cannot do so through
`v-add-database` at all — worth noting because a future API v2 caller
might reasonably expect "reuse an existing dbuser" to be supported, and
source confirms it structurally is not, on the create side.

**Retry after the process is killed/times out AFTER mutation but BEFORE
the `db.conf` append is written** (`bin/v-add-database:99-103`, which runs
strictly after `add_mysql_database`/`add_pgsql_database` return): the
database and user WOULD already exist on the SQL server, but
`is_object_new` — which only checks Hestia's own `db.conf`, never the
actual SQL server — would NOT detect this. A retry would attempt
`CREATE DATABASE`/`CREATE ROLE` again, which the SQL server itself
rejects (duplicate name), surfacing as the same ambiguous, non-symbolic
exit code identified in MUTATION MODEL. This class of gap — "mutate the
real resource, then crash before writing Hestia's own bookkeeping line" —
is **not unique to `database.create`**; it is structurally identical to
the equivalent window in `domain.create` (which also mutates a real
resource — the vhost directory — before appending to `web.conf`). Noted
for completeness, not flagged as a new finding.

**Retry after E_RESTART**: does not apply — no restart step exists in
this script (see SOURCE CONTRACT).

**Retry after an "unknown" failure**: unsafe to blindly retry, for the
same reason `unknown` exists at all in this model — the caller cannot
distinguish "nothing happened" from "the database now exists but the
grants are broken" from a bare exit code. This is the SAME caution that
already applies to every `unknown` mutation_state in this architecture,
not a new retry hazard specific to this operation — but the earlier
MUTATION FLOW finding (silent GRANT failures inside an exit-0 run) means
even a "successful," `confirmed` `database.create` call may leave a state
a human would want to inspect before assuming a subsequent password
change or grant repair is unnecessary. Worth calling out precisely
because, unlike `domain.create`, `confirmed` here does not close the
book on "did this fully work."

**Does behavior differ by database type?** Yes, in exactly one respect
material to this section: the MySQL path has one checked mutating
statement (`CREATE DATABASE`) before its unchecked tail; the Postgres path
has ZERO checked mutating statements — even the analogous "at least the
primary object's creation is checked" property MySQL has does not hold
for Postgres. Both paths share the identical retry-safety and
ambiguous-failure characteristics described above; Postgres's failure
window is simply wider (nothing at all is checked, vs. MySQL checking
only the first statement).

---

# CROSS-OPERATION COMPARISON

| Dimension | domain.create | domain.delete | backup.schedule | database.create |
|---|---|---|---|---|
| Arity | 6 slots, 2 required | 3 slots, 2 required | 1 slot, 1 required | 7 slots, 4 required |
| Optional params w/ Hestia-chosen defaults | yes (ip, aliases, proxy_ext) | yes (restart) | no | yes (type, host, charset) |
| Sensitive parameter | no | no | no | **yes — password (new)** |
| Locking | per-user, sufficient | per-user, sufficient | per-user, sufficient | per-user, sufficient for user-scoped state; **does not close a pre-existing host-level counter race across different users (documented, not blocking)** |
| Mutation classification | known_post_mutation_exit_codes: E_RESTART | known_post_mutation_exit_codes: E_RESTART | none needed (all exits pre-mutation) | **none declared — the one post-mutation-begins exit code is non-symbolic and must stay `unknown` (new reasoning, not a new state)** |
| Authorization target | user, domain | user, domain | user | user, database, dbuser, type (password deliberately excluded — new) |
| Result shape | none (no JSON mode) | none (no JSON mode) | none (no JSON mode) | none (no JSON mode) |
| Error mapping reliability | clean symbolic mapping throughout | clean symbolic mapping throughout | clean symbolic mapping throughout | **degrades once mutation begins — one exit code is the SQL client's raw status, not a Hestia symbolic code (new)** |
| Resources created per call | 1 (web domain) | 1 (removed) | 0 (a queue entry, not a resource) | **2 (database + database user/role) — new** |

The existing adapter model **absorbs three of these four new dimensions
without any code change** (sensitive-target-exclusion aside): arity,
optional params, result shape, and even the weaker mutation guarantee are
all expressible as registry data plus careful documentation, the same
technique already used for `backup.schedule`'s "confirmed means queued."
The one dimension that does NOT fit today's `CommandAdapter` mechanics
without a change is the sensitive parameter — see NEW ABSTRACTIONS.

---

# API V2 IMPLICATIONS

Not designing the HTTP endpoint. What a future `database.create`
operation conceptually needs to represent, based purely on the above:

- **Target user** — same as every other operation; already modeled.
- **Database name / database user name** — both un-prefixed suffixes; an
  API-level representation needs to make this explicit to callers (e.g.
  field names like `database_name_suffix`, not `database_name`), or a
  future layer above the adapter could do the "final name = user + this"
  presentation translation — that translation itself should live OUTSIDE
  the adapter (the adapter's job, per its own docblock, is to relay
  Hestia's contract faithfully, not to add a naming-convenience layer).
- **Database type** — mysql/pgsql, a small closed enum; needs a new
  adapter-level type validator (mechanical, not architectural).
- **Database credentials** — the password must be representable as a
  write-only, never-echoed field at every layer above the adapter too,
  not just inside it; this is a property the API v2 design will need to
  carry forward, not something the adapter alone can guarantee end-to-end.
- **Result** — success/failure plus the narrower-than-`domain.create`
  `confirmed` semantics; an API consumer needs this caveat surfaced in
  documentation, not just internally.
- **Errors** — the existing `hestia_error`/`adapter_error` split remains
  sufficient; the ambiguous raw-exit-code case correctly surfaces as
  `unknown` mutation_state with a real, human-readable `errorMessage`
  (since `check_result`'s own `echo "Error: $2"` text is still captured
  in stdout/stderr regardless of the exit code's symbolic ambiguity) —
  API consumers lose the symbolic code in this one case but not the
  message.
- **What should remain outside the adapter**: host selection (already
  excluded from the minimal contract), charset (already excluded), and
  any user-facing "is this database name available" pre-check UX (Hestia
  already reports this cleanly via E_EXISTS; no separate check-endpoint
  is implied by anything found in this review).

---

# FUTURE PRODUCT IMPLICATIONS

Strictly separated from source fact, per the task's instruction.

**SOURCE FACT**: `v-add-database` supports exactly two database engines
(`mysql`, `pgsql`), selected from a server-wide `DB_SYSTEM` install-time
whitelist; `host` is always a symbolic reference into Hestia's own,
locally-configured `mysql.conf`/`pgsql.conf` host list, never an arbitrary
external address; there is no concept anywhere in `func/db.sh` of a
containerized, per-tenant, or externally-hosted database.

**FUTURE PRODUCT IMPLICATION** (unverified against this repo beyond the
above; a forward-looking inference, not a source claim): a
"managed database" / "external database server" / "Docker-based database"
direction for Cloud Account or the Extensions Marketplace would need an
entirely new registry-level concept for "which host/topology backs this
database," since today's `host` field is scoped to symbolic references
into a config file Hestia's own installer wrote — it has no notion of
dynamically provisioning a NEW host at request time, only of picking among
already-configured ones. This is the same category of limitation the
`backup.create` review already surfaced for local-vs-S3-vs-cloud storage
(a fixed, install-time-configured set of destinations, no dynamic
provisioning) — a recurring pattern across this codebase's subsystems,
not something specific to databases. Nothing here should be designed now;
noted only because the task asked whether this operation exposes anything
relevant to that later direction, and it does, in the same shape as the
backup review already found elsewhere.

---

# NEW ABSTRACTIONS

Two genuine, small, registry-driven gaps — not present for
`domain.create`/`domain.delete`/`backup.schedule` — both scoped precisely
to the finding that exposed them. Neither requires a new execution path, a
new locking implementation, a new authorization mechanism, a workflow
engine, or a generic exec/raw-command escape hatch. Neither is proposed
for implementation here, per the task's explicit "do not implement" scope.

**1. A `sensitive` marking on a registry-declared parameter, honored
generically by `CommandAdapter`.** Required because: (a) the value must
still reach `argv` (the script needs it to function) but (b) it must NOT
be copied into `$target` (and therefore not into `AdapterResult` or the
authorizer's input) the way every other parameter is today
(`CommandAdapter.php:262`). This is a small, generic, data-driven flag —
`CommandAdapter` would need exactly one new conditional ("if this
parameter definition says sensitive, skip the `$target[$name] = $value`
line"), not an operation-specific branch (`database.create` is never named
in that logic; any future operation with a secret parameter reuses the
same flag). Smallest missing abstraction that resolves the PASSWORD
HANDLING / AUTHORIZATION TARGET findings above.

**2. A delivery-mode concept for a parameter (temp-file indirection vs.
literal argv value), honored generically by argv construction.** Required
because Hestia's OWN script (`is_password_valid`, `func/main.sh:625-633`)
already expects and supports a password delivered as a `/tmp/...` file
path rather than as literal text — and the one existing production caller
already uses exactly this indirection (`web/add/db/index.php:72-98`).
Without this, the adapter would either (a) put the plaintext password
directly into `argv`, reintroducing the process-listing exposure risk the
existing production code already avoids, or (b) require every future
caller of the adapter to manually pre-write a temp file themselves before
calling `invoke()`, pushing a security-relevant detail out of the adapter
and onto every caller — fragile and easy to get wrong once. A generic
"this parameter's value should be written to a securely-created temp file
and the file's path substituted into argv instead" mechanism, gated by a
registry flag, keeps this inside the adapter, matches Hestia's own
established convention, and — like (1) — requires no `database.create`-
specific code in `CommandAdapter`, only a second small generic flag any
future secret-accepting operation can reuse.

Both gaps are additive (new optional registry fields + a few lines of
generic, non-branching logic in `CommandAdapter`), not restructuring —
they do not touch locking, authorization, mutation classification, or
process execution's fundamental shape. They are exactly the kind of
"mechanical follow-up" this codebase's own design docs already describe
for other deferred fields (e.g. `lock_scope`, `timeout_seconds` in
`CommandRegistry`'s docblock) — small, targeted, and only needed because
`database.create` is genuinely the first operation in this series whose
minimal required contract includes a secret.

Not proposed: a general-purpose redaction/masking framework, a secrets
manager integration, or any change to how Hestia itself handles passwords
downstream of the script boundary — all out of scope per the task, and
none are needed to close the two gaps identified.

---

# VERDICT

**NEEDS ADAPTER CHANGE**

The registry-driven architecture absorbs every dimension of
`database.create` that the three prior operations already tested —
different arity, optional Hestia-chosen defaults, a weaker-than-usual but
still cleanly documentable mutation guarantee, and a locking gap that is
pre-existing and out of adapter scope to fix — without any change to
`CommandAdapter`'s control flow, without a new execution path, without a
new locking implementation, and without a new authorization mechanism.

It does NOT yet absorb `database.create`'s ONE genuinely new dimension:
this is the first operation in the series whose minimal, non-speculative
required parameter set includes a secret (`password`), and today's
`CommandAdapter` has no way to accept that value into `argv` without
either (a) leaking it into `AdapterResult`/the authorizer's `$target`
(no redaction concept exists) or (b) leaking it into the real process's
argv/`ps aux` output (no temp-file-delivery concept exists), even though
Hestia's own script already supports the safe alternative for exactly
this argument.

Both required changes (NEW ABSTRACTIONS 1 and 2) are small, additive,
registry-driven, and require zero `database.create`-specific branching in
`CommandAdapter` — consistent with every architectural constraint this
series has upheld so far. Once they exist as generic mechanisms,
`database.create` itself becomes a pure registry-data addition, exactly
like `domain.create`, `domain.delete`, and `backup.schedule` were.
