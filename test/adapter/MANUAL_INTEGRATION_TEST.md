# Manual Integration Test — Command Adapter `domain.get`

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

**Does not prove**: performance under load, or anything about operations
other than `domain.get`/`domain.list`, since no mutating operation
(`domain.create` or otherwise) is registered yet — see
`ADAPTER_VERTICAL_SLICE.md` "known limitations". The per-user locking
infrastructure now exists (`LockManager`) but is exercised by neither
`domain.get` nor `domain.list`, both of which are read-only and never
acquire a lock; see the section below for what locking-specific manual
verification IS possible today, ahead of the first mutating operation.

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
