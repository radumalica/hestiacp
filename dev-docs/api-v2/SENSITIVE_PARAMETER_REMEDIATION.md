# SENSITIVE PARAMETER & TEMP-FILE DELIVERY — REMEDIATION REPORT

Follow-up to `SENSITIVE_PARAMETER_REVIEW.md` (verdict: APPROVED WITH
NOTES). This task closes findings **F-1** and **F-2** in code, documents
**F-3** (no code change, by design), fixes **F-4** as a small, adjacent
addition to the same validation loop (the task allowed this explicitly:
"unless fixing them is completely trivial and naturally part of the same
metadata validation change" — a 6-line `is_string()` guard placed
directly next to the F-1 `is_bool()` guard qualifies), and leaves
**F-6** untouched (it concerns `fixed_parameters`, a different array
from the one this task's validation loop iterates, so it is not
"the same metadata validation change" and was left alone). No operation
was added to the registry; `database.create` was not implemented.

---

## 1. F-1 root cause

`CommandRegistry::validateParameterMetadata()` compared a parameter's
`"sensitive"` value with **strict identity** (`$sensitive === true`,
old `CommandRegistry.php:451`) when deciding whether a `"delivery"` mode
was required, while `CommandAdapter::invoke()` compared the same value
with **loose truthiness** (`!($definition["sensitive"] ?? false)`, old
`CommandAdapter.php:308`) when deciding whether to exclude the value
from `$target`. These two checks answered "is this sensitive?"
differently for any value that is truthy but not the literal boolean
`true` — e.g. `1`, `"1"`, `"true"`. For such a value: registry
construction did not throw (no `"delivery"` was required), yet
`CommandAdapter` still excluded the value from `$target` — giving every
external sign of correct protection — while argv construction (which
only branches on `"delivery" === "temp_file"`, never on `"sensitive"`)
placed the plaintext directly into argv, unprotected, reaching
`proc_open()` and `/proc/<pid>/cmdline` in full. The bug's danger was
not that it made anything *worse* than omitting `"sensitive"` entirely —
it made the field **lie about protection it wasn't providing**.

## 2. F-1 exact fix

- **`web/inc/adapter/CommandRegistry.php`**, inside
  `validateParameterMetadata()`: before either existing check runs, a new
  guard now rejects any `"sensitive"` value that is not an actual PHP
  boolean and not `null`/absent:
  ```php
  $rawSensitive = $definition["sensitive"] ?? null;
  if ($rawSensitive !== null && !is_bool($rawSensitive)) {
      throw new \InvalidArgumentException(sprintf(
          "Registry entry '%s' parameter '%s' declares 'sensitive' as %s, but it must be " .
              "an actual boolean (true or false), or omitted/null (treated as false). ...",
          $operationName, $parameterName, var_export($rawSensitive, true)
      ));
  }
  $sensitive = $rawSensitive === true;
  ```
  `null`/absent are preserved as "treated as false" — this matches the
  pre-existing `?? false`/`?? null` convention every other optional
  registry field in this class already uses, so an explicit `null` is
  not a malformed value, only a genuinely wrong type is.
- **`web/inc/adapter/CommandAdapter.php`**, inside the parameter
  validation loop (`invoke()`): the enforcement check was changed from
  loose truthiness to the same strict identity comparison:
  ```php
  if (($definition["sensitive"] ?? false) !== true) {
      $target[$name] = $value;
  }
  ```
  Both sides of the mechanism now use the same comparison, on the same
  vocabulary (`true`, `false`, `null`/absent — nothing else can survive
  registry construction), so they can no longer disagree.

## 3. F-1 tests

Ten new tests added to `test/adapter/SensitiveParameterTest.php`
(numbered 17–25, plus 26 for F-2):

| # | Proves |
|---|---|
| 17 | `sensitive => true` (+ delivery) accepted at construction |
| 18 | `sensitive => false` accepted at construction |
| 19 | `sensitive => 1` (int) rejected at construction |
| 20 | `sensitive => "true"` (string) rejected at construction |
| 21 | `sensitive => "1"` (string) rejected at construction |
| 22 | `sensitive => null` accepted, treated identically to absent (value still reaches `target`) |
| 23 | `sensitive => true` without delivery still rejected (the pre-existing invariant, re-verified against the new code) |
| 24 | `sensitive => true` + `delivery => temp_file`: accepted at construction AND enforced end-to-end (excluded from target, absent from argv) |
| 25 | **The central property**: for the one reachable "considered sensitive" state, target-exclusion and argv-protection are always both true together — no malformed-metadata state can now separate them |
| 26 | (F-2) the generated temp file path begins with the literal `/tmp/` prefix |

All ten are new; none of the 16 pre-existing tests in this file were
modified.

---

## 4. F-2 root cause

`CommandAdapter::writeSecureTempFile()` created its temp file under
`sys_get_temp_dir()` (old `CommandAdapter.php:635`), which resolves
**dynamically** — it honors a `TMPDIR` environment override before
falling back to a compiled-in default that is typically, but not
guaranteedly, `/tmp` on Linux. The one concrete contract this mechanism
was built to satisfy is a **literal string-prefix match**:
`is_password_valid()` (`func/main.sh:626`,
`[[ "$password" =~ ^/tmp/ ]]`) — re-verified directly from source before
making this change, per the task's instruction — and the one existing
real production caller of this exact convention
(`web/add/db/index.php:72`) hardcodes the literal string `"/tmp"`, not
a dynamically-resolved directory. Had `TMPDIR` ever differed from
`/tmp` in the PHP runtime's environment, a future `database.create`
consumer would not have crashed — `is_password_valid()` would have
silently skipped its file-dereference branch and Hestia's script would
have treated the temp-file **path string itself** as the password
value.

No existing adapter abstraction needed to be reused or introduced for
this fix — it is a one-constant, one-call-site correction.

## 5. F-2 exact fix

- **`web/inc/adapter/CommandAdapter.php`**: added a new private constant,
  ```php
  private const TEMP_FILE_DIRECTORY = "/tmp";
  ```
  and changed `writeSecureTempFile()`'s `tempnam()` call from
  `tempnam(sys_get_temp_dir(), self::TEMP_FILE_PREFIX)` to
  `tempnam(self::TEMP_FILE_DIRECTORY, self::TEMP_FILE_PREFIX)`. The
  directory is now a fixed, non-configurable literal — no environment
  variable, no fallback chain, no configuration surface was introduced,
  per the task's explicit "do not introduce a configurable temp
  directory" / "do not introduce environment-dependent behavior"
  instructions.

## 6. F-2 tests

Test 26 (`testTempFilePathBeginsWithLiteralTmpPrefix`,
`SensitiveParameterTest.php`) captures the argv path passed to the
process runner during a real `invoke()` call and asserts
`assertEquals(0, strpos($path, "/tmp/"), ...)` — i.e. the path *begins
with* the literal prefix, not merely "the file exists" or "the file is
somewhere under whatever `sys_get_temp_dir()` returns in this test
environment" (which would not have caught the original bug, since this
test environment's `sys_get_temp_dir()` happens to also return `/tmp`).
`MiniTest::assertEquals()` compares with `!==`/`===` (strict), not `==`,
so this assertion is sound: `strpos()` returning `false` (no match) is
`false !== 0`, which fails the test correctly, rather than the PHP-loose
`0 == false` trap that would have made this assertion pass vacuously —
verified directly against `MiniTest.php`'s implementation before relying
on it.

A second, adjacent gap was closed while verifying this: the existing
leak-detection helper `countAdapterTempFiles()` (used by tests 10 and
elsewhere) globbed `sys_get_temp_dir() . "/hstadapter*"` — the same
dynamic-vs-literal split F-2 just removed from production code, left
behind in the test layer. Under a `TMPDIR` override, that glob would
have silently scanned the wrong directory, so test 10 would have kept
passing without actually observing anything. Changed to glob the literal
`"/tmp/hstadapter*"` instead, matching `CommandAdapter::TEMP_FILE_DIRECTORY`
exactly. This is a one-line hardening of an existing test, not a new
test and not a weakening of one.

---

## 7. F-3 documentation update

`SENSITIVE_PARAMETER_DESIGN.md`'s "CLEANUP GUARANTEES" section now
includes an explicit new subsection stating, precisely and without
solving it in code:

- Normal PHP control-flow cleanup (every case in the existing cleanup
  table) is guaranteed, via `finally`.
- Hard process termination (`SIGKILL`, OOM-killer, PHP-FPM worker
  force-termination, host crash) bypasses `finally` entirely — no
  PHP-level mechanism can run code after the interpreter is killed.
- If that happens after a temp file was created but before cleanup ran,
  a 0600-permissioned file containing the real secret value is left on
  disk under `/tmp`.
- Cleanup at that point depends entirely on the host's own `/tmp`
  lifecycle (`systemd-tmpfiles`, `tmpwatch`, or a reboot), not on
  anything this mechanism does.
- This is stated as an accepted residual risk, not a defect to engineer
  around — no cleanup daemon, database, or persistent temp-file registry
  was added or proposed, per the task's explicit instruction.

No code was changed for this item — `CommandAdapter.php` is unmodified
with respect to F-3.

---

## 8. Full test result

Before this task (baseline, re-confirmed): `140 passed, 0 failed`.

After this task, run three consecutive times as required:

```
150 passed, 0 failed
150 passed, 0 failed
150 passed, 0 failed
```

10 new tests (17–26); 0 existing tests modified, weakened, or removed.

---

## 9. Files changed

- `web/inc/adapter/CommandAdapter.php` — F-1 enforcement (strict
  `!== true` comparison), F-2 fix (`TEMP_FILE_DIRECTORY` constant,
  literal `/tmp` in `writeSecureTempFile()`), updated docblocks.
- `web/inc/adapter/CommandRegistry.php` — F-1 validation (reject
  non-boolean, non-null `"sensitive"` values), F-4 validation (reject
  non-string, non-null `"delivery"` values, adjacent to the F-1 guard),
  updated docblocks.
- `test/adapter/SensitiveParameterTest.php` — 10 new tests (17–26), one
  new helper (`registryWithRawSensitiveValue()`), and one hardening fix
  to the existing `countAdapterTempFiles()` helper (literal `/tmp` glob
  instead of `sys_get_temp_dir()`, discovered while verifying test 26).
- `SENSITIVE_PARAMETER_DESIGN.md` — F-3 documentation, plus revision
  notes on the F-1/F-2 sections reconciling the design doc's original
  reasoning with what the review found and this task corrected.
- `SENSITIVE_PARAMETER_REMEDIATION.md` — this file (new).

No other file was touched by this task. `test/adapter/run_tests.php`
required no change (it already `require_once`s
`SensitiveParameterTest.php` and calls `::register($t)`, from the prior
task).

---

## 10. `database.create` was NOT implemented

Confirmed: no new operation was added to `CommandRegistry`'s
`$operations` array; `grep -c "database.create"` /
`grep -c "v-add-database"` / `grep -c "dbuser"` against
`web/inc/adapter/CommandAdapter.php` and `web/inc/adapter/CommandRegistry.php`
all return `0`. No registry entry, API endpoint, or UI code path for
`database.create` exists anywhere in this working tree.

## 11. Hestia scripts and sudoers were NOT modified

Confirmed via `git status --short` scoped to `bin/`,
`install/common/sudo/`, and `web/api/`: no output — none of these paths
have any pending change. `web/inc/adapter/LockManager.php` is likewise
untouched (not present in `git status --short`'s output at all).

## 12. Final readiness verdict

**READY FOR DATABASE.CREATE**

Both fix-required findings from the source-first review are closed and
covered by new, focused tests; the one document-only finding is now
recorded precisely, without expanding this mechanism's scope. The
generic mechanism (`sensitive`, `delivery => temp_file`) remains free of
any operation-specific logic in `CommandAdapter.php`/`CommandRegistry.php`,
and the 150-test suite is green across three consecutive runs. A future
`database.create` registry entry may declare
`"sensitive" => true, "delivery" => "temp_file"` on its password
parameter and receive the corrected, verified protection this task
established.

Nothing in this task was committed.
