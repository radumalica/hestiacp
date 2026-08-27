# Lock Location Permission Review

Verification-only document. No source files were modified, no `flock`,
`LockManager`, `domain.create`, or mutation metadata was implemented. This
resolves one specific open question from `WRITE_OPERATION_DESIGN.md` Part
2: whether the proposed lock path,
`$HESTIA/data/users/<username>/.adapter.lock`, is actually usable by the
process that will run the adapter, before any of that design is
implemented.

**Headline finding, stated up front**: the proposed location is **not
viable as designed**. The process that will run the adapter does not have
filesystem access to `$HESTIA/data/users/**` at all — every existing PHP
code path in this repository reaches that tree exclusively through `sudo`
into root-run `bin/v-*` scripts, never by direct file access. Full evidence
and a recommended alternative follow.

---

## 1. Ownership and permissions — evidence from the repository

### `$HESTIA/data/users/` (the parent directory)

Created and permissioned in `install/hst-install-ubuntu.sh`:

```
1352	mkdir -p $HESTIA/conf $HESTIA/ssl $HESTIA/data/ips \
1353		$HESTIA/data/queue $HESTIA/data/users $HESTIA/data/firewall \
1354		$HESTIA/data/sessions
...
1359	chmod 750 $HESTIA/conf $HESTIA/data/users $HESTIA/data/ips $HESTIA/log
```

- **Mode**: `750` (owner: read/write/execute; group: read/execute; other:
  none).
- **Owner/group**: **no `chown`/`chgrp` call for `$HESTIA/data/users` was
  found anywhere in `install/hst-install-ubuntu.sh`** (confirmed by a
  targeted search across the whole file for `chown`/`chgrp` near this
  block and for `hestiaweb` combined with `data/users` — zero matches, see
  §4). The installer itself runs as root (an `mkdir -p` with no preceding
  `sudo -u`/`su` context switch), so by default this directory is left
  **owned by `root:root`**.
- **No ACL is applied to this directory**: a repository-wide search for
  `setfacl` in `install/hst-install-ubuntu.sh` found ACL calls only for
  individual users' own home directories (`bin/v-add-user`, see below),
  never for `$HESTIA/data/users` itself or for the `hestiaweb` principal.

### `$HESTIA/data/users/<username>/` (i.e. `$USER_DATA`, per-user metadata directory)

`USER_DATA` is defined once, centrally: `func/main.sh:96`:
`USER_DATA=$HESTIA/data/users/$user`.

It is created by `bin/v-add-user`, not by the installer (each user's
directory comes into existence when that user is added, not at install
time):

```
168	mkdir -p $USER_DATA/ssl $USER_DATA/dns $USER_DATA/mail
...
180	chmod 770 $USER_DATA \
181		$USER_DATA/ssl \
182		$USER_DATA/dns \
183		$USER_DATA/mail
```

- **Mode**: `770` (owner and group: read/write/execute; other: none) — the
  directory itself, note, is more permissive than its parent (`750` for
  `$HESTIA/data/users`, `770` for each user's own subdirectory within it).
- **Owner/group**: again, **no explicit `chown`/`chgrp` for `$USER_DATA`
  was found anywhere in `bin/v-add-user`** (the full script was read for
  this review). `mkdir -p` was invoked with no user-switching wrapper
  around it in this script, and `bin/v-add-user` is itself always invoked
  as `sudo /usr/local/hestia/bin/v-add-user` (the same `HESTIA_CMD`
  convention documented throughout this review sequence) — so it runs as
  **root**, and `$USER_DATA` is left **owned by `root:root`** by default,
  same reasoning as the parent directory.

### Existing files inside the user directory

```
171	touch $USER_DATA/backup.conf \
172		$USER_DATA/history.log \
173		$USER_DATA/stats.log \
174		$USER_DATA/web.conf \
175		$USER_DATA/dns.conf \
176		$USER_DATA/mail.conf \
177		$USER_DATA/db.conf \
178		$USER_DATA/cron.conf
...
185	chmod 660 $USER_DATA/backup.conf \
186		$USER_DATA/history.log \
187		$USER_DATA/stats.log \
188		$USER_DATA/web.conf \
189		$USER_DATA/dns.conf \
190		$USER_DATA/mail.conf \
191		$USER_DATA/db.conf \
192		$USER_DATA/cron.conf
...
270	DATE='$date'" > $USER_DATA/user.conf
271	chmod 660 $USER_DATA/user.conf
```

- **Mode**: `660` for every file (owner and group: read/write; other:
  none) — no execute bit, as expected for plain data files.
- **Owner/group**: same conclusion as the directory — created via `touch`/
  shell redirection with no `chown`, while the script runs as root, so
  every file inside `$USER_DATA` is **root-owned** by default.

### Summary table

| Path | Mode | Owner:group (inferred, no explicit chown found) |
|---|---|---|
| `$HESTIA/data/users/` | `750` | `root:root` |
| `$HESTIA/data/users/<user>/` (`$USER_DATA`) | `770` | `root:root` |
| `$USER_DATA/*.conf`, `*.log` | `660` | `root:root` |

---

## 2. Which Unix user/group the adapter's PHP-FPM process actually runs as

Confirmed directly from the Hestia panel's own PHP-FPM pool definition,
`src/deb/php/php-fpm.conf` (read in full):

```
[www]
listen = /run/hestia-php.sock

user = hestiaweb
group = hestiaweb

listen.owner = hestiaweb
listen.group = hestiaweb
listen.mode = 0660
```

This is the pool that serves the panel's own PHP code (`web/inc/main.php`,
`web/api/index.php`, and — once wired in — the adapter) via a Unix socket
(`/run/hestia-php.sock`). **The adapter, if it runs in-process as part of
this PHP-FPM pool (the natural, lowest-risk placement per
`ARCHITECTURE_ADAPTER_DESIGN.md` section 11's migration plan — a PHP
library called from `web/inc/main.php`), executes as the Unix user
`hestiaweb`, group `hestiaweb`.**

Corroborating evidence, all pointing at the same identity:

- `install/common/sudo/hestiaweb` (already cited in
  `ARCHITECTURE_ADAPTER_DESIGN.md` section 0): `hestiaweb ALL=NOPASSWD:/usr/local/hestia/bin/*`
  — the sudo policy that lets this exact process invoke `bin/v-*` scripts
  as root is written for the `hestiaweb` principal specifically, matching
  the PHP-FPM pool's `user`/`group` directives.
- `install/hst-install-ubuntu.sh:1207-1210`: `useradd "hestiaweb" -c "$email"
  --no-create-home`, followed by locking interactive login (`chpasswd -e`
  with a random password) — confirms `hestiaweb` is a dedicated,
  service-only system account, not a human login, consistent with it being
  the identity a daemon (PHP-FPM) runs as.
- `install/hst-install-ubuntu.sh:2486`: `chown hestiaweb:hestiaweb
  $HESTIA/data/sessions` — PHP's own `session.save_path`
  (`php_admin_value[session.save_path] = /usr/local/hestia/data/sessions`,
  confirmed in the same `php-fpm.conf`) is explicitly given to `hestiaweb`
  ownership so the FPM worker can write session files there — direct,
  first-party confirmation that `hestiaweb` is the identity actually
  executing PHP code for this panel, not merely a nominal owner of static
  files.
- `install/hst-install-ubuntu.sh:2483-2484`: `systemctl start hestia` (the
  service name matching the `pid = /run/hestia-php.pid` in the same
  `php-fpm.conf`) is what starts this pool.

**Not independently re-verified in this pass, flagged explicitly**: whether
the front-end web server (Nginx, proxying to `/run/hestia-php.sock`) itself
runs as a different identity (e.g. `www-data`) was not re-traced in this
review — it doesn't change the finding, since Nginx never executes PHP
code itself; it only proxies to the socket. The identity that matters for
"can the adapter's PHP code open/create/flock a file" is the PHP-FPM worker
identity, which is unambiguously `hestiaweb` per the pool config above.

---

## 3. Can `hestiaweb` create/open/flock `.adapter.lock` at the proposed location?

**No — none of the three.** Given §1's finding (`$HESTIA/data/users/` is
`root:root`, mode `750`; `$USER_DATA` is `root:root`, mode `770`) and §2's
finding (the adapter runs as `hestiaweb`, which is not `root` and was not
found added to any group with access to this tree — no `usermod -a -G ...
hestiaweb` referencing a group associated with `data/users`, and no ACL
grant, were found in a targeted search of `install/hst-install-ubuntu.sh`
and `bin/v-add-user`):

- **Traverse into `$HESTIA/data/users/`**: mode `750` gives execute (traverse)
  permission to owner (`root`) and group (`root`, by inference) only —
  `hestiaweb` is neither, so standard Unix permission semantics mean
  `hestiaweb` cannot even `stat`/enter this directory, let alone reach a
  subdirectory inside it.
- **Create `.adapter.lock` if it doesn't exist**: requires write+execute on
  the containing directory (`$USER_DATA`, mode `770`, `root:root`) — even
  if `hestiaweb` could somehow reach it (it can't, per the point above, since
  the parent `$HESTIA/data/users/` already blocks traversal), `hestiaweb`
  still has no write bit on `$USER_DATA` itself.
- **Open and `flock` an existing `.adapter.lock`**: same blocker — no read
  access to the containing directory to even resolve the path, before
  `flock` semantics are relevant at all.
- **Keep the lock file with safe ownership/permissions**: moot — the file
  can never be created by this process at this location under the
  permission model found in the repository.

**This is not a marginal or edge-case problem — it is a complete access
block**, consistent with (and explained by) a broader pattern already
established throughout this review sequence:
`ARCHITECTURE_REVIEW.md`/`ARCHITECTURE_ADAPTER_DESIGN.md` both document
that `web/inc/main.php` and `web/api/index.php` **never read `USER_DATA`
files directly** — every single access to that tree, everywhere in this
codebase's PHP layer, goes through `exec(HESTIA_CMD . "v-* ...")`, i.e.
through `sudo` into a root-run script. This was previously understood as
an architectural choice (keep business logic in the CLI); this review
confirms it is **also a permission necessity** — `hestiaweb` has no other
way to reach that data. The proposed lock design implicitly assumed the
adapter's PHP process could do something (direct file access under
`$HESTIA/data/users/`) that no existing code in this repository does or
could do.

---

## 4. Would this require changing existing Hestia ownership/permissions?

**Yes — unavoidably, and no such change was made in this review (per
instruction).** To make the proposed path work as originally designed,
at least one of the following would be required, none of which exists
today and none of which this document implements:

- Grant `hestiaweb` a POSIX ACL entry (`setfacl -m u:hestiaweb:rx`) on
  `$HESTIA/data/users/` and on each `$USER_DATA` directory — the same
  mechanism `bin/v-add-user` already uses for a *different* purpose (owner
  ACLs on each Linux user's own home directory, `setfacl -m
  "u:$user:r-x" "$HOMEDIR/$user"`, confirmed at `bin/v-add-user`'s
  directory-tree-building section) but never applied to `$HESTIA/data/users`
  or to `hestiaweb` anywhere in the codebase today; **or**
- Add `hestiaweb` to a group with access to that tree (none currently
  exists for this purpose — `root`'s own group is not one `hestiaweb`
  should be added to, since that would grant far broader access than just
  lock-file management); **or**
- Change `$HESTIA/data/users`'s and/or each `$USER_DATA`'s mode/ownership
  outright — the most invasive option, and the one most likely to have
  unintended consequences given how many other scripts already depend on
  this tree being `root`-exclusive (every `is_object_valid()`,
  `is_package_full()`, `update_object_value()` call across all 524 `bin/v-*`
  scripts implicitly relies on only root-run processes touching these
  files).

Every one of these is a permission/ownership change to **existing,
currently-root-exclusive** Hestia state, explicitly out of scope for this
review ("Do NOT make those changes") and judged, even prospectively, as
**not the right fix** — see §6 for the recommended alternative that avoids
touching this tree's permissions at all.

---

## 5. Existing Hestia convention for runtime/lock-adjacent files

One clear, directly-applicable precedent was found:

**`$HESTIA/data/sessions/`** — created at install time alongside
`data/users` (same `mkdir -p` line, `install/hst-install-ubuntu.sh:1352-1354`),
but **explicitly re-owned to `hestiaweb:hestiaweb`** immediately after the
`hestia` (PHP-FPM) service starts:

```
1362	chmod 770 $HESTIA/data/sessions
...
2486	chown hestiaweb:hestiaweb $HESTIA/data/sessions
```

This is the **one directory in the entire installer that is deliberately
handed to `hestiaweb` for the PHP-FPM worker to write into directly** —
matching exactly what PHP's own `session.save_path` needs
(`php_admin_value[session.save_path] = /usr/local/hestia/data/sessions`,
`src/deb/php/php-fpm.conf`). This is strong, first-party evidence of
Hestia's own convention for "a directory the PHP process itself is trusted
to write runtime files into, separate from the root-exclusive
`$HESTIA/data/users` tree" — and is the natural template for where an
adapter-owned runtime artifact (a lock file) should live instead.

No other candidate location scoped specifically to "runtime/ephemeral,
hestiaweb-writable" state was found: `$HESTIA/data/queue` is `chmod -R 750`
with no `chown` to `hestiaweb` found (same root-exclusive pattern as
`data/users`); `/run/hestia-php.pid`/`/run/hestia-php.sock` are PHP-FPM's
own master-process-owned runtime files (created by PHP-FPM itself, running
as whatever starts the `hestia` service — not written to by request-time
PHP code, and not an appropriate place for per-user application data
regardless).

---

## 6. Lifecycle scenarios

### After user creation

No effect either way on the *lock's* viability: `bin/v-add-user` creates
`$USER_DATA` fresh, root-owned, mode `770` (per §1) — a lock file placed
inside it would need to be created at the moment of first use by whichever
process holds the lock, and per §3 that process (`hestiaweb`) cannot create
anything there regardless of when the surrounding directory was created.
This is a permission problem, not a timing/existence problem — user
creation does not change the underlying access blocker.

### After user deletion

`bin/v-delete-user:110`: `rm -rf $USER_DATA` — run as root (same
sudo'd-script convention), which would delete a lock file placed inside
`$USER_DATA` along with everything else, with no special handling needed —
**this specific lifecycle question is a non-issue for the proposed
location** (deletion cleanup would have worked correctly); it's included
here for completeness since it was explicitly asked, not because it
contributes to the location being rejected.

### After reboot

Not directly evidenced in the repository (this is host/OS behavior, not
Hestia-specific code) — **flagged as not verifiable from the repository
alone**: whatever underlying filesystem holds `$HESTIA/data` is expected
to persist across a reboot (it is not a `tmpfs`-backed path by any
convention found in this codebase), so a lock file's on-disk presence
would survive a reboot regardless of location chosen. What matters more
for correctness is that `flock`'s *held* locks do not survive process
death (see next point) — a reboot implies every holding process died, so
any theoretically-held lock is implicitly released by the kernel already,
consistent with `WRITE_OPERATION_DESIGN.md` Part 2's stale-lock analysis.

### After PHP-FPM restart

All PHP-FPM worker processes for the `www` pool are terminated and new
ones started (standard PHP-FPM behavior, not independently re-verified
against a specific Hestia config beyond confirming the pool exists and its
`pm = ondemand` setting, `src/deb/php/php-fpm.conf:20`, which spawns/kills
workers on demand — meaning worker turnover is not even restart-exclusive;
it's a routine, frequent event under this pool manager). Any `flock` held
by a terminated worker is released by the kernel when that worker's file
descriptors close — no stale-lock cleanup code is needed, consistent with
`WRITE_OPERATION_DESIGN.md` Part 2. This reasoning holds **regardless of
which viable location is ultimately chosen** — it is a property of `flock`
itself, not of the path.

### Adapter process crash/SIGKILL

Same conclusion as PHP-FPM restart: `flock`'s advisory lock is tied to the
file descriptor/process, and a `SIGKILL`'d worker's held lock is released
by the kernel automatically. No new evidence changes this from what
`WRITE_OPERATION_DESIGN.md` Part 2 already established; this section
exists to confirm the reasoning is unaffected by the location finding
above.

---

## 7. Security concerns

- **The permission block found is not a security bug — it is the security
  model working as designed**, and this review's main risk is not "the
  proposed path is insecure" but "the proposed path silently would not have
  worked at all," which is a correctness/viability problem, not a new
  vulnerability. Worth stating plainly since the review's tone could
  otherwise be misread as flagging a weakness in Hestia's existing
  permission model — it is not; `root`-exclusive access to `USER_DATA` is
  exactly the boundary the rest of this review sequence has consistently
  found intact and load-bearing.
- **If a future fix grants `hestiaweb` broad ACL access to
  `$HESTIA/data/users/**` just to solve the lock problem**, that would be a
  materially larger and riskier change than the lock feature needs —
  it would hand the web-facing process direct read/write access to every
  user's `web.conf`/`mail.conf`/`user.conf`/password-hash-adjacent files,
  collapsing the exact privilege separation (`hestiaweb` can only act via
  narrow, validated `sudo`'d CLI calls) that the rest of this architecture
  review has repeatedly identified as Hestia's real security boundary. This
  is the strongest argument against "just add an ACL for `hestiaweb` on
  `data/users`" as the fix, even though it would technically work — see §8
  for the recommended alternative that avoids this entirely.
- **A lock file's content is not sensitive** (it holds no secrets — at most
  a lock byte/PID for diagnostic purposes) — so a `hestiaweb`-owned,
  `hestiaweb`-writable lock directory carries no confidentiality risk
  itself, unlike the `data/users` tree it should not be co-located with.

---

## 8. Recommended lock location (if the proposed one is not viable)

**`$HESTIA/data/adapter-locks/<username>.lock`** — a new, flat directory,
sibling to `data/users`/`data/sessions`/`data/queue`, created and owned the
same way `data/sessions` already is: `mkdir -p` at install time, then
`chown hestiaweb:hestiaweb`, mode `770` — i.e., a direct application of the
one existing convention found in §5, extended to a second purpose (locks)
rather than inventing a new pattern.

Why this satisfies every constraint this review was scoped to check,
without requiring any change to `$HESTIA/data/users`'s existing permissions:

- **`hestiaweb` can create/open/`flock` files here directly**, by
  construction — same reasoning as `data/sessions` (§2's corroborating
  evidence), no `sudo` round-trip needed to acquire or release the lock,
  which is essential since the lock must be held for the *entire* adapter
  operation (`WRITE_OPERATION_DESIGN.md` Part 2/3) — something only
  possible if the PHP process holding the file descriptor is the same
  process for the whole critical section, not a short-lived `sudo`'d
  subprocess that exits and closes its descriptors the moment one `v-*`
  script finishes.
- **Filename derived from the same validated username**
  (`WRITE_OPERATION_DESIGN.md` Part 2's `basename()`-guarded, already-shape-
  validated value) — the only change from the original design is the
  containing directory, not the filename-safety reasoning, which is
  unaffected by this finding and carries over unchanged.
- **Flat, not nested per-user** (`<username>.lock`, not
  `<username>/adapter.lock`) — deliberately simpler than mirroring
  `data/users`'s per-user-subdirectory structure, since there is no
  existing per-user substructure under this new directory to mirror, and
  no other adapter-owned per-user files are being proposed that would
  justify a subdirectory per user. One flat file per user is sufficient
  and avoids needing to `mkdir` a new per-user directory (with its own
  ownership question) on first use.
- **Deletion on user removal is not automatic** at this location, unlike
  the original proposal (where `rm -rf $USER_DATA` in `bin/v-delete-user`
  would have cleaned it up for free, per §6). This is a real, acknowledged
  trade-off, not something this document glosses over: a stray
  `<username>.lock` file could persist after a user is deleted, though it
  is harmless (an unused lock file for a nonexistent user has no observable
  effect beyond trivial disk usage — a future `domain.create`-equivalent
  request for a deleted user would already be rejected by `is_object_valid`
  long before lock acquisition would matter again). If this is judged worth
  closing, the correct fix is adding one `rm -f
  $HESTIA/data/adapter-locks/$user.lock` line to `bin/v-delete-user`'s
  existing cleanup block (alongside its existing `rm -rf $USER_DATA`,
  `rm -f /var/spool/mail/$user`, etc.) — **not implemented here**, since
  this document does not modify any script, but noted as a small, precisely
  scoped, low-risk follow-up rather than a blocker.
- **Requires one new install-time step** (create the directory, `chown` it
  to `hestiaweb`) that does not exist in the repository today — this is a
  real, necessary follow-up before this location can be used in practice
  (the directory does not create itself), but it is strictly **additive**
  (a new directory, new ownership scoped to that new directory only) rather
  than a change to any existing directory's permissions — satisfying this
  review's constraint against modifying existing ownership/permissions,
  since nothing existing is touched.

---

## What could not be verified from the repository alone

Per the task's instruction to state this explicitly rather than guess:

1. **The exact primary group `root`'s `mkdir`/`touch` operations default
   to inside the installer's execution context** — this review inferred
   `root:root` from the absence of any `chown`/`chgrp`/`newgrp` around the
   relevant `mkdir -p`/`touch` calls and standard Unix default-ownership
   behavior (new files/directories are owned by the creating process's
   euid/egid), but the *actual* egid in effect during a real install run
   (which could differ if the installer script itself were ever invoked
   under an unusual umask/group context not visible in the script) was not
   independently confirmed by running the installer. **Would need
   verification on a real, freshly-provisioned Hestia installation**:
   `stat $HESTIA/data/users` and `stat $HESTIA/data/users/<any-user>` to
   confirm actual owner:group and mode bits match what this document
   infers from source.
2. **The front-end Nginx process's run-as identity** — not re-verified in
   this pass (noted in §2); does not affect this document's conclusion,
   since Nginx does not execute PHP code, but flagged for completeness.
3. **Whether any post-install/upgrade script (outside
   `install/hst-install-ubuntu.sh`, e.g. something under
   `install/upgrade/versions/*.sh`) has ever applied an ACL or ownership
   change to `$HESTIA/data/users` on an existing installation** that this
   targeted search did not surface — a full-repository search for
   `setfacl`/`chown`/`chgrp` combined with `data/users` was performed
   against the main installer and `bin/v-add-user` specifically (the most
   likely locations), but not exhaustively against all ~30
   `install/upgrade/versions/*.sh` migration scripts individually.
   **Would need verification**: `getfacl $HESTIA/data/users` on a real,
   long-lived (upgraded-through-many-versions) Hestia installation to rule
   out an ACL grant introduced by some historical upgrade script not
   covered by this review's search scope.
4. **Actual runtime behavior of `pm = ondemand` under real load** — the
   claim that PHP-FPM workers are spawned/killed on demand (§6) is read
   directly from `src/deb/php/php-fpm.conf`'s documented directive
   semantics, not observed on a running system in this review.

---

## Final verdict

# NEEDS CHANGE

The proposed lock location,
`$HESTIA/data/users/<username>/.adapter.lock`, is **not viable as
designed** — the `hestiaweb` identity that will run the adapter has no
create/open/`flock` access to `$HESTIA/data/users/` or anything beneath it,
confirmed by direct evidence (mode `750`/`770`, no `chown`/ACL grant to
`hestiaweb` found anywhere in the installer or `bin/v-add-user`) rather than
inferred from the design alone.

**This is not a `BLOCKED` verdict**: the underlying goal (a per-user lock
file `hestiaweb` can manage directly, per `WRITE_OPERATION_DESIGN.md` Part
2) remains achievable — it just needs a different, already-precedented
location. **Recommended change**: relocate to
`$HESTIA/data/adapter-locks/<username>.lock`, a new directory owned by
`hestiaweb:hestiaweb` following the exact existing convention already
established for `$HESTIA/data/sessions`, with a small optional follow-up
(a one-line cleanup addition to `bin/v-delete-user`, not implemented here)
to avoid orphaned lock files after user deletion. `WRITE_OPERATION_DESIGN.md`
Part 2's remaining content (identity derivation, filename safety, blocking/
timeout semantics, release discipline, why not global/per-domain/in-script)
is **unaffected by this change** — only the directory path changes; every
other property of the lock design carries over unchanged.
