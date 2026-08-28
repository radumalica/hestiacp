# Sprint 9A: Nginx Security & WordPress Hardening Extension

Extends the composable Nginx feature architecture introduced in Sprint 8
(`dev-docs/nginx/NGINX_SECURITY_EXTENSIBILITY_IMPLEMENTATION.md`) with a
broader, consistent WordPress security profile and an optional auth-endpoint
rate limit. Sprint 8's architecture is unmodified — this sprint only adds
to it, following its own established patterns.

## What was implemented

1. **Sensitive-file blocking extended to backup/dump variants.** The
   sensitive-file `location` block Sprint 8 added to `wordpress.tpl`/
   `.stpl` now also denies `wp-config.php.bak`/`.save`/`.old`/`.orig`/`.swp`/
   `~`, and root-level `*.sql`/`*.sql.gz` dump files. `.env` is not
   repeated — the pre-existing dotfile-deny rule (`location ~
   /\.(?!well-known\/)`) already covers it.
2. **Consistent hardening across all 12 WordPress template variants.**
   Sprint 8 only hardened `wordpress.tpl`/`.stpl`. The other 10 files
   (`wordpress-disable-xmlrpc`, `wordpress-http3`, `wordpress_mu_subdir`,
   and their `-http3` combinations, each `.tpl`+`.stpl`) still carried the
   pre-Sprint-8 `location ~* /(?:uploads|files)/.*.php$` rule. All 10 now
   carry the same sensitive-file block and the same wp-content/uploads +
   wp-content/cache PHP-execution deny, adapted per-variant where a
   variant's own pre-existing behavior required it (see "Template
   coverage" below).
3. **WordPress auth-endpoint rate limiting.** A new zone
   (`install/deb/nginx/hestia-wp-auth-rate-limit.conf`) rate-limits
   `/wp-login.php` and `/xmlrpc.php` only, via a `$request_uri` map —
   every other request maps to an empty key and is never rate-limited.
   One line (`limit_req zone=hestia_wp_auth_rl burst=15 nodelay;`) was
   added to the general PHP-execution location in all 12 templates.
4. **ModSecurity / OWASP CRS extension point: re-verified, unchanged.**
   Sprint 8's `install/deb/templates/web/nginx/snippets/modsecurity-example.conf`
   already documents this extension point correctly and completely; no
   installation mechanism exists in this repository for either dependency
   (re-confirmed by fresh repo-wide search — see below), so per this
   sprint's own instructions, nothing was built. Not modified.
5. **Caching: deferred, not implemented.** See "Caching" below.
6. **Dangerous PHP redirects / quarantine: not implemented, by design.**
   See "PHP execution / quarantine behavior" below.
7. **`snippets/README.md`** updated to reflect that the feature-snippet
   extension point now covers all 12 WordPress templates (Sprint 8 wired
   it into `wordpress.tpl`/`.stpl` only), and documents the new rate-limit
   zone as a baseline mechanism distinct from the opt-in snippets.
8. **Installer / upgrade backfill** for the new zone file and the 10
   newly-hardened templates (`install/hst-install-debian.sh`,
   `install/hst-install-ubuntu.sh`, `install/upgrade/versions/1.10.0.sh`).
9. **Test suite extended**, not replaced —
   `test/nginx/test_wordpress_security_profile.sh` now covers all 12
   templates, the new sensitive-file patterns, the rate limit, and two
   dedicated regression checks (see "Tests").

## What was verified from the repository (not assumed)

- **ModSecurity/OWASP CRS availability**: `grep -rli
  "modsecurity\|mod_security\|owasp\|crs" install/ func/ bin/` returns
  only Sprint 8's own `modsecurity-example.conf`/`security-headers.conf`
  and one unrelated hit in `install/common/dovecot/2.4/conf.d/10-mail.conf`
  (a comment about carriage returns — "CRs" — nothing to do with OWASP
  CRS). No installer step installs `ngx_http_modsecurity_module`, any
  ModSecurity package, or an OWASP CRS ruleset, on either Nginx instance
  Hestia manages. Confirmed unchanged from Sprint 8's own finding.
- **All 12 WordPress template files exist** and, before this sprint, only
  `wordpress.tpl`/`.stpl` matched `grep -l "wp-config\|nginx.features"` —
  the other 10 were confirmed unhardened by direct inspection, not
  assumed.
- **Nginx location-precedence claims were tested against a live nginx
  1.18.0 binary**, not inferred from documentation alone (Sprint 8's own
  hard-won lesson, deliberately re-applied rather than trusted this time):
  - The mapped-key `limit_req_zone` design (map on `$request_uri`,
    `limit_req` inside the existing general PHP location) was proven with
    a real content-phase handler (a static file), after an initial test
    using `return` as the mock backend gave a false negative — `return`
    is processed during Nginx's rewrite phase and terminates the request
    before the preaccess phase (where `limit_req` runs) is ever reached.
    This is a genuine pitfall worth flagging for future template testing:
    a synthetic mock backend built from `return` does not exercise the
    same phase sequence as a real content-phase handler (`fastcgi_pass`
    or static file serving via `root`), and will silently produce
    false-negative rate-limit test results.
  - `wordpress-disable-xmlrpc.stpl`'s `location = /xmlrpc.php` sits at
    server level (a sibling of `location /`), while the `.tpl`'s copy is
    nested inside `location /` — a pre-existing inconsistency. Both were
    tested against a live nginx instance with a nested regex PHP location
    present (mirroring the real templates): both return 403 with no
    bypass. Exact-match (`=`) locations are not subject to the nested-
    regex-vs-server-level-regex precedence pitfall Sprint 8 found; that
    pitfall is specific to regex-vs-regex ambiguity. The placement
    inconsistency is therefore cosmetic, not a security bug, and was left
    as-is (documented here rather than "fixed" for consistency's own
    sake, since both are independently verified correct).
  - `wordpress_mu_subdir`'s multisite subdirectory rewrite
    (`rewrite ^/[_0-9a-zA-Z-]+(/.*\.php)$ $1 last;`) only fires when
    `!-e $request_filename` — an attacker-planted PHP file under
    `wp-content/uploads` or `wp-content/cache` always exists on disk, so
    the rewrite never runs for it, and the raw un-rewritten URI reaches
    location matching directly. A root-anchored pattern
    (`^/wp-content/(?:uploads|cache)/...`) was tested against a live
    instance and found to **not** block `/site2/wp-content/uploads/evil.php`
    (a real regression versus the old unanchored `uploads|files` rule).
    Testing further revealed a second, unrelated effect of the same
    rewrite rule: for the root site specifically, when the requested file
    does not yet exist, the rewrite rule misidentifies the literal string
    `wp-content` as if it were a site slug and strips it, turning
    `/wp-content/uploads/evil.php` into `/uploads/evil.php` before any
    `location` block sees it. The final pattern
    (`^/(?:(?:[_0-9a-zA-Z-]+/)?wp-content/)?(?:uploads|cache)/.*\.php$`)
    was tested against both cases live and blocks both; see "Template
    coverage" below for why this pattern is `mu_subdir`-specific.

## Security rationale

- **Sensitive-file additions** (backup/dump variants): these files are
  never legitimately requested by a browser or REST client, are a common
  accidental-exposure pattern (editor swap files, manual `.bak` copies,
  `mysqldump` output left in the web root), and the block list was kept
  narrow and evidence-based — no rule targets `wp-includes/`, plugin
  `readme` files, or anything inside `wp-content/plugins/*`/`themes/*`,
  since those legitimately serve arbitrary filenames.
- **PHP-execution denial in uploads/cache**: unchanged rationale from
  Sprint 8 — these are the two directories WordPress and its plugin
  ecosystem write to at runtime, the classic path for an uploaded or
  planted PHP file to be executed. The rule denies at the Nginx boundary,
  before PHP-FPM ever runs, and never touches, moves, or deletes anything
  on disk.
- **Rate limiting**: scoped narrowly to the two endpoints that are
  actually abuse targets (`wp-login.php` for credential stuffing/brute
  force, `xmlrpc.php` for the same plus pingback-based amplification). The
  `$request_uri`-map design means every other WordPress request —
  including the general PHP location's own `if ($request_uri ~*
  ".../wp-json/...")` cache-bypass logic and everything else already in
  that location — is completely unaffected; there is no duplicated
  `fastcgi_pass` wiring to drift out of sync with the general location
  over time. Defaults (`rate=2r/s`, `burst=15`) are a deliberately
  generous operational starting point, not a claimed best practice — see
  `install/deb/nginx/hestia-wp-auth-rate-limit.conf`'s own comment for the
  reasoning and how to adjust it (it is a plain `/etc/nginx/conf.d/` file,
  not template-rendered, so it can be hand-edited directly).
- **xmlrpc.php in the `-disable-xmlrpc` variants**: already fully denied
  (403) by Sprint 8's original design; this sprint added the sensitive-
  file/uploads hardening and the rate-limit line (harmless there, since
  xmlrpc.php never reaches the PHP location in this variant, but kept for
  template consistency and in case an admin later re-enables xmlrpc via a
  custom override).

## Template coverage

All 12 files (6 families × `.tpl`/`.stpl`) now carry: the sensitive-file
block, the uploads/cache PHP-deny block, the `limit_req` line, and the
`nginx.features.conf_*`/`.ssl.conf_*` composable-feature include point
(previously only on `wordpress.tpl`/`.stpl`).

One family required a different uploads/cache pattern:
`wordpress_mu_subdir`(`-http3`) uses
`^/(?:(?:[_0-9a-zA-Z-]+/)?wp-content/)?(?:uploads|cache)/.*\.php$` instead
of the other 10 files' `^/wp-content/(?:uploads|cache)/.*\.php$`, to cover
both a live multisite subsite path and the root-site rewrite-mangled path
described above (both empirically verified — see "What was verified").
This pattern is intentionally broader than the other 10 files': because
the `wp-content/`-and-optional-site-slug prefix is entirely optional, it
also denies PHP execution under a bare `/uploads/` or `/cache/` at the
path root (e.g. a hypothetical `/uploads/evil.php` with no `wp-content`
segment at all), which the other 10 files' anchored pattern would not
match. This is deliberate — it is strictly a wider *denial* surface, never
an allowed one — and is still narrower than the old, unanchored
`uploads|files` rule it replaces, which matched those paths anywhere in
the URL rather than only at the root.

The `wordpress-disable-xmlrpc`(`-http3`) family's `xmlrpc.php` placement
inconsistency between `.tpl` (nested) and `.stpl` (server-level sibling)
was left as-is and documented (see "What was verified") rather than
normalized, since both are independently confirmed correct and changing
working, tested config for cosmetic consistency alone was judged higher
risk than value.

The three `-http3.sh` post-render QUIC `reuseport` hook scripts
(`wordpress-http3.sh`, `wordpress-disable-xmlrpc-http3.sh`,
`wordpress_mu_subdir-http3.sh`) are unrelated to security hardening and
were not touched.

## ModSecurity / OWASP CRS availability

Unchanged from Sprint 8: neither is installed anywhere in this repository,
on either the panel's own Nginx (port 8083) or the system Nginx used for
hosted domains. `install/deb/templates/web/nginx/snippets/modsecurity-example.conf`
already documents exactly what installing the dependency would require
and how to wire it in once available, via the same `nginx.features.conf_*`
extension point every other opt-in feature snippet uses. Per this sprint's
own explicit instruction, no installation architecture was invented for
either dependency this sprint.

## Rate-limit behavior

- Zone: `hestia_wp_auth_rl:10m`, `rate=2r/s`, per-domain
  `limit_req zone=hestia_wp_auth_rl burst=15 nodelay;`.
- Key: `$binary_remote_addr`, but only for requests whose `$request_uri`
  matches `/wp-login.php` or `/xmlrpc.php` (case-insensitive); every other
  request gets an empty key and is not rate-limited at all — verified live
  (25 rapid sequential requests to `/index.php` never returned 503; the
  same volume to `/wp-login.php` did).
- Installed unconditionally (not opt-in) by the installer/upgrade scripts,
  because every WordPress template references the zone unconditionally —
  making it optional at the file level would break `nginx -t` for every
  WordPress domain whenever the file was missing.
- Does not duplicate or interact with API v2's own, separate rate
  limiting.

## Caching

**Deferred — not implemented this sprint.** Sprint 8 already shipped
everything actually available for this: the global `microcache`
`fastcgi_cache_path` zone in `install/deb/nginx/nginx.conf`, the `$no_cache`
bypass logic already present in every WordPress template's general PHP
location (wp-admin, wp-json, cart/checkout/my-account, and a broad set of
WordPress/WooCommerce session cookies), and the opt-in
`fastcgi-cache-example.conf` reference snippet. What is missing, and not
something this repository can safely supply on its own, is **cache
invalidation**: there is no purge hook, no WordPress plugin integration,
and no mechanism to invalidate a cached page the moment its content
changes (a published post, an edited page, a changed price). Enabling
full caching without invalidation means a site can serve stale content
indefinitely after any change until the `inactive` TTL expires. Per this
sprint's own explicit instruction ("if the repository does not provide
enough information to implement this safely, document the limitation
instead of inventing behavior"), this is documented as a limitation
rather than worked around with new template logic.

## PHP execution / quarantine behavior

**Not implemented — deny/404 is the shipped behavior, by design.**
Sprint 8 already established the resolution here for every sensitive-file
and uploads/cache location: `deny all; return 404;`, rejected at the
Nginx boundary before PHP-FPM is ever invoked. A "quarantine" or "move to
trash" redirect for a blocked PHP request was considered and rejected:
- It would confirm to an attacker that the requested path exists (a
  deny/404 response is deliberately indistinguishable from "nothing here"
  in this profile).
- A redirect target is itself cacheable/probeable and adds a second URL
  an attacker could target.
- Any "move" implies a filesystem write from within the request path,
  which is exactly the kind of new attack surface this sprint's own scope
  explicitly warns against introducing.
No such requirement or precedent exists anywhere in this repository's
history; deny/404 is both the simpler and the more secure choice, and no
code was written for a redirect/quarantine path.

## Tests

`test/nginx/test_wordpress_security_profile.sh`, extended (not replaced)
from Sprint 8. New in Sprint 9A:
- Template-coverage loop: all 12 template variants render (no unresolved
  `%var%` placeholders) and pass `nginx -t`, each in its own isolated
  `http{}` block.
- New sensitive-file paths: `wp-config.php.bak`/`.save`/`.old`/`~`,
  `/backup.sql`, `/dump.sql.gz`, over both HTTP and HTTPS.
- Rate-limit behavioral check: 25 rapid sequential requests to
  `/wp-login.php` must include a 503; the same volume to `/index.php`
  must never include one.
- `wordpress-disable-xmlrpc.tpl`/`.stpl` precedence check: `/xmlrpc.php`
  returns 403 in both the nested and server-level-sibling placements.
- `wordpress_mu_subdir.tpl` regression check: a planted PHP file under
  both the root site's and a subsite's `wp-content/uploads/` is blocked
  (404), including the rewrite-mangled root-site path.

Run three consecutive times, all clean:

```
=== Summary: 53 passed, 0 failed ===   (×3 consecutive runs)
```

Other verification run:
- `bash -n` on all three modified shell scripts (`install/hst-install-debian.sh`,
  `install/hst-install-ubuntu.sh`, `install/upgrade/versions/1.10.0.sh`) —
  clean.
- `php -l` — not applicable; confirmed via
  `git diff --cached --name-only | grep -E '\.(php|phtml)$'` returning
  empty — no PHP files were modified this sprint.
- `nginx -t` — exercised extensively as part of the test suite above
  (every template variant, individually and combined).
- `git diff --check` — clean (see final report).

Relevant-existing-suite check: `grep -rl "wordpress\|nginx" test/
--include='*.sh' --include='*.php'` returns 5 files besides this sprint's
own suite. Each was inspected directly, not assumed irrelevant:
`test/make-test-containers.php` only uses "nginx" as a boolean container-
provisioning flag (`--nginx yes/no` for LXC test containers, unrelated to
template rendering); `test/adapter/DatabaseCreateTest.php`,
`DatabaseDeleteTest.php`, `test/api/ExecuteRequestHandlerTest.php`, and
`ParameterNormalizerTest.php` only use `wordpress_db`/`admin_wordpress_db`
as sample database-name strings in database-adapter parameter-
normalization tests, with no relation to Nginx templates or WordPress
hardening. None touches WordPress templates, the installer's Nginx
section, or the upgrade script's Nginx section, so none was "relevant"
per this sprint's own definition; none were run.

## Limitations / deferred work

- Full-page WordPress caching remains deferred — see "Caching" above.
  Unchanged from Sprint 8's own "Deferred work" section.
- ModSecurity/OWASP CRS remain unavailable — see "ModSecurity / OWASP CRS
  availability" above. Unchanged from Sprint 8.
- The `wordpress-disable-xmlrpc`(`-http3`) `.tpl`/`.stpl` placement
  inconsistency for `location = /xmlrpc.php` (nested vs. server-level
  sibling) was left as-is; both are independently verified safe. A future
  cleanup sprint could normalize this purely for readability, with no
  security motivation.
- The rate-limit defaults (`rate=2r/s`, `burst=15`) are a single global
  operational default shared by every WordPress domain on a server; there
  is no per-domain override mechanism for this specific value (unlike the
  opt-in feature snippets, which are per-domain by construction). An
  admin who needs a different value edits
  `install/deb/nginx/hestia-wp-auth-rate-limit.conf` directly.

## STOP conditions

None were hit. The two conditions the task explicitly anticipated STOPing
on were both resolved without inventing behavior:
- ModSecurity/OWASP CRS unavailable → documented, extension point only
  (already existed from Sprint 8; re-verified, not modified).
- Caching safety → deferred and documented, not implemented.

## Final verdict

Sprint 9A extends Sprint 8's WordPress security profile from 2 of 12
template variants to all 12, adds a scoped and empirically-verified
auth-endpoint rate limit with zero duplicated backend wiring, closes a
real regression that a naive port of the hardening would have introduced
in the multisite variant, and adds no new runtime dependency, no shell
execution, and no changes to API v2, the adapter/authorizer stack, or any
`bin/v-*` script. All behavioral claims about Nginx location precedence
were verified against a live nginx instance rather than assumed from
documentation, consistent with Sprint 8's own methodology.
