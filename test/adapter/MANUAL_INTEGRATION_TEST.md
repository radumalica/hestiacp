# Manual Integration Test — Command Adapter `domain.get`, `domain.list`, `domain.create`

This is a documented manual procedure, to be run by a human on a real Hestia
installation. It is intentionally not automated in CI because it requires
root/sudo, a provisioned Hestia user with at least one web domain, and the
real `bin/v-list-web-domain` script — none of which the unit test suite
(`test/adapter/run_tests.php`) depends on or should depend on.

Its purpose is narrow: prove that `CommandAdapter::invoke("domain.get", ...)`,
wired to the **real** `ProcOpenProcessRunner` (not `FakeProcessRunner`),
produces a result equivalent to what `web/inc/main.php`'s existing
`exec(HESTIA_CMD . "v-list-web-domain ...")` pattern already produces today —
i.e., that the adapter is a faithful, behavior-preserving wrapper around the
existing CLI, not a reimplementation.

## Prerequisites

- A server with Hestia Control Panel installed (`$HESTIA` = `/usr/local/hestia`).
- Root or a user permitted to run `sudo /usr/local/hestia/bin/v-list-web-domain`.
- At least one Hestia user with at least one web domain (e.g. `admin` with
  `example.com`). Substitute your own values below.
- PHP CLI available on that server.
- This repository's `web/inc/adapter/` directory copied onto the server
  (e.g. alongside the existing Hestia web root, or anywhere readable — the
  script below only needs a filesystem path to it).

## Step 1 — Establish the baseline (existing behavior, unchanged)

Run the exact command the adapter will construct, directly, to capture
today's real output:

```bash
sudo /usr/local/hestia/bin/v-list-web-domain admin example.com json
echo "exit code: $?"
```

Record the JSON output and exit code shown. This is the ground truth the
adapter must match.

## Step 2 — Run the adapter against the same target

Save the following as `/tmp/adapter-manual-test.php` on the Hestia server
(adjust the `require_once` path to wherever you placed `web/inc/adapter/`):

```php
<?php

require_once "/path/to/hestiacp/web/inc/adapter/bootstrap.php";

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcOpenProcessRunner;

$adapter = new CommandAdapter(
	new CommandRegistry(),
	new ProcOpenProcessRunner()
	// binDir/sudoBinary left at their defaults:
	// "/usr/local/hestia/bin/" and "/usr/bin/sudo", matching
	// HESTIA_DIR_BIN/HESTIA_CMD in web/inc/main.php.
);

$result = $adapter->invoke(
	"domain.get",
	["user" => "admin", "domain" => "example.com"],   // <-- substitute your real user/domain
	["user" => "admin"]
);

echo json_encode($result->toArray(), JSON_PRETTY_PRINT) . "\n";
```

Run it:

```bash
php /tmp/adapter-manual-test.php
```

## Step 3 — Compare

Confirm all of the following against Step 1's baseline:

1. `exit_code` in the adapter's result equals the exit code from Step 1.
2. `status` is `"ok"` (Step 1's exit code was `0`).
3. `parsed_output` is a decoded JSON object with the domain (e.g.
   `example.com`) as its top-level key, matching the structure of Step 1's
   raw JSON output (this comes directly from `bin/v-list-web-domain`'s
   `json_list()` function).
4. `stdout` (raw, before parsing) is byte-for-byte equal to Step 1's stdout.
5. `resolved_command` is `"v-list-web-domain"`.

## Step 4 — Negative case: domain that does not exist

```bash
sudo /usr/local/hestia/bin/v-list-web-domain admin does-not-exist.example json
echo "exit code: $?"
```

Then adjust the manual test script's `domain` value to `"does-not-exist.example"`
and re-run. Confirm:

1. Baseline exit code is non-zero (expected: `3`, Hestia's `E_NOTEXIST`, per
   `func/main.sh`'s `is_object_valid()` — confirmed in
   `ARCHITECTURE_ADAPTER_DESIGN.md` section 3 and this slice's source
   citations).
2. Adapter result: `status` is `"hestia_error"`, `hestia_error_code` is
   `"E_NOTEXIST"`, `exit_code` matches the baseline.

## Step 5 — Negative case: caller-side rejection (no process spawned)

Adjust the script to pass an invalid domain value, e.g.
`"domain" => "bad;domain"`, and re-run. Confirm:

1. `status` is `"adapter_error"`, `adapter_error_code` is `"VALIDATION_FAILED"`.
2. No `bin/v-list-web-domain` invocation occurred at all — this can be
   confirmed by checking that `$HESTIA/log/system.log`/`error.log` gained no
   new entry for this attempt (unlike Steps 2 and 4, which do produce a
   Hestia-side log entry via that script's own `log_event` calls), since the
   adapter never reached the point of invoking `sudo`.

## What this manual test does and does not prove

**Proves**: the adapter, using the real `ProcOpenProcessRunner` against a
real Hestia installation, produces output equivalent to today's direct
`exec()` pattern for both success and failure cases, and that caller-side
validation failures never reach the underlying script.

**Does not prove**: performance under load, behavior under load-bearing
production traffic, or anything about `domain.delete` or any other
operation not implemented in this codebase (see
`ADAPTER_VERTICAL_SLICE.md` "known limitations"). Steps 1-6 cover the two
read-only operations (which never acquire a lock); Step 7 covers
`domain.create`, the first — and, as of this document, only — mutating
operation, including its real per-user lock acquisition against the real
`$HESTIA/data/adapter-locks/` directory.

## Step 6 — Manual verification: lock directory (locking infrastructure only)

This step verifies the installer-provisioned lock directory itself —
`LOCK_IMPLEMENTATION.md`'s "Lock Location" — since no mutating operation
exists yet to exercise `LockManager` through `CommandAdapter::invoke()`
end-to-end on a real install. Run on a server where
`install/hst-install-ubuntu.sh` or `install/hst-install-debian.sh` has
been run with this branch's installer changes.

1. **Directory exists, correct ownership and mode**:

   ```bash
   ls -ld /usr/local/hestia/data/adapter-locks
   ```

   Expected: `drwxrwx---` (mode `770`), owner and group both `hestiaweb`,
   matching the existing `/usr/local/hestia/data/sessions` directory's
   ownership (confirm with `ls -ld /usr/local/hestia/data/sessions` for
   comparison — both should match).

2. **`data/users` was NOT touched**:

   ```bash
   ls -ld /usr/local/hestia/data/users
   ```

   Expected: ownership/mode unchanged from before this branch was
   installed (this branch's installer diff never touches this path —
   confirm by inspecting `install/hst-install-ubuntu.sh`/
   `hst-install-debian.sh`'s diff directly if in doubt).

3. **`hestiaweb` can create and lock a file in the new directory** (the
   actual permission claim this whole design rests on):

   ```bash
   sudo -u hestiaweb php -r '
     $h = fopen("/usr/local/hestia/data/adapter-locks/manual-test.lock", "c");
     if ($h === false) { fwrite(STDERR, "OPEN FAILED\n"); exit(1); }
     if (!flock($h, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "FLOCK FAILED\n"); exit(1); }
     echo "OK: opened and locked as hestiaweb\n";
     flock($h, LOCK_UN);
     fclose($h);
   '
   rm -f /usr/local/hestia/data/adapter-locks/manual-test.lock
   ```

   Expected: `OK: opened and locked as hestiaweb`, no `OPEN FAILED` or
   `FLOCK FAILED`. This is the exact operation `LockManager::acquire()`
   performs, run as the same identity PHP-FPM actually runs as
   (`src/deb/php/php-fpm.conf`'s `user = hestiaweb; group = hestiaweb`).

4. **root-run `v-*` commands still function normally** (proving the new
   directory and installer changes introduced no regression to the
   existing, unrelated CLI path):

   ```bash
   sudo /usr/local/hestia/bin/v-list-web-domain admin example.com json
   echo "exit code: $?"
   ```

   Expected: identical to Step 1's baseline above — this command never
   touches `data/adapter-locks` at all, so it is unaffected by any of
   this change; it is included here only as a smoke test that nothing
   else on the server was disturbed.

## Step 7 — Manual verification: `domain.create` (MUTATING — creates real state)

**This step is destructive.** It creates an actual web domain (directories,
vhost config, log files, a `web.conf` entry, and a running web server
reload) on the target server. Do NOT run this in CI or against a
production Hestia instance. Use a disposable test user and a domain name
that does not need to resolve anywhere (Hestia does not verify DNS before
creating the vhost). Follow "Cleanup" below immediately after.

### 7a — Baseline: confirm the domain does not already exist

```bash
sudo /usr/local/hestia/bin/v-list-web-domain testuser adapter-manual-test.example json
echo "exit code: $?"
```

Expected: non-zero exit (E_NOTEXIST), confirming a clean starting state.

### 7b — Run `domain.create` through the adapter

Extend `/tmp/adapter-manual-test.php` from Step 2 (or create a fresh copy):

```php
<?php

require_once "/path/to/hestiacp/web/inc/adapter/bootstrap.php";

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcOpenProcessRunner;

$adapter = new CommandAdapter(
	new CommandRegistry(),
	new ProcOpenProcessRunner()
	// LockManager also left at its default — the real
	// $HESTIA/data/adapter-locks/ directory, exercised for real here.
);

$result = $adapter->invoke(
	"domain.create",
	["user" => "testuser", "domain" => "adapter-manual-test.example"],
	["user" => "testuser"]
);

echo json_encode($result->toArray(), JSON_PRETTY_PRINT) . "\n";
```

```bash
php /tmp/adapter-manual-test.php
```

### 7c — Verify

1. `status` is `"ok"`, `mutation_state` is `"confirmed"`, `exit_code` is `0`.
2. `$HOMEDIR/testuser/web/adapter-manual-test.example/` now exists, owned
   `testuser:testuser`, containing `public_html/`, `private/`,
   `document_errors/`, `cgi-bin/`, `stats/`, `logs/` (bin/v-add-web-domain
   lines 100-107).
3. `$USER_DATA/web.conf` (i.e.
   `/usr/local/hestia/data/users/testuser/web.conf`) gained one new line
   with `DOMAIN='adapter-manual-test.example'` (line 241 of
   bin/v-add-web-domain).
4. Re-running Step 7a now returns exit code `0` with a JSON body
   describing the domain.
5. The web server (nginx/apache2, per `$WEB_SYSTEM`) was reloaded without
   error — confirm with `systemctl status $WEB_SYSTEM` or equivalent, and
   check the adapter result's `stdout`/`stderr` for any
   `"... restart failed"` text (bin/v-add-web-domain lines 250-251).
6. **Lock proof**: while step 7b is running (e.g. by adding a `sleep 2`
   right before `v-add-web-domain` in a scratch copy of the script, or by
   running two `domain.create` calls for the SAME `testuser` back to
   back from two terminals), confirm the second call visibly waits — this
   is the same per-user serialization already proven automatically in
   `test/adapter/LockManagerTest.php`'s cross-process tests, here observed
   against the real script instead of a fixture.

### 7d — Negative case: duplicate domain

Re-run 7b with the same parameters a second time. Confirm:

1. `status` is `"hestia_error"`, `hestia_error_code` is `"E_EXISTS"`
   (bin/v-add-web-domain's `is_web_domain_new()`, func/domain.sh line 49:
   `check_result "$E_EXISTS" "Web domain $1 exists"` — detected during
   this script's own "Verifications" section, before any directory is
   created).
2. `mutation_state` is `"unknown"` — not `"not_attempted"` — since the
   adapter has no operation-specific knowledge that E_EXISTS in
   particular always fires pre-mutation; that fact is documented in
   `DOMAIN_CREATE_IMPLEMENTATION.md` "Idempotency / Duplicate Domain" as
   something confirmed by reading the source for this report, not
   something the adapter's generic result model claims to know.
3. No second copy of the domain directory or `web.conf` entry was
   created (confirm the directory/file from 7c are unchanged).

### Cleanup

```bash
sudo /usr/local/hestia/bin/v-delete-web-domain testuser adapter-manual-test.example
```

Confirm the domain no longer appears in
`sudo /usr/local/hestia/bin/v-list-web-domains testuser json`, and that
`$HOMEDIR/testuser/web/adapter-manual-test.example/` no longer exists.
If a disposable `testuser` was created solely for this test, also remove
it with `sudo /usr/local/hestia/bin/v-delete-user testuser` — note
`LOCK_IMPLEMENTATION.md` "Known Limitations": this leaves an orphaned
`testuser.lock` file under `$HESTIA/data/adapter-locks/`, which is
expected and harmless (see that document for why).
