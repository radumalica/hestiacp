# Nginx composable feature snippets

These are **reference snippets**, not Hestia templates: they are never
selected as a domain's backend template and never rendered through
Hestia's `%variable%` substitution (`add_web_config()` in
`func/domain.sh`). Each file is plain, self-contained Nginx
configuration, safe to `include` verbatim.

## How to enable one

Copy (or symlink) the snippet into the domain's own configuration
directory, using the extension point all 12 WordPress template variants
provide (`wordpress`, `wordpress-disable-xmlrpc`, `wordpress-http3`,
`wordpress_mu_subdir`, and their `.tpl`/`.stpl` and `-http3` combinations
— extended to the full set in Sprint 9A; Sprint 8 originally added it to
`wordpress.tpl`/`wordpress.stpl` only):

```
cp security-headers.conf \
   /home/<user>/conf/web/<domain>/nginx.features.conf_security-headers
```

For the SSL (`.stpl`-rendered) vhost, use the `.ssl` variant of the
include point:

```
cp security-headers.conf \
   /home/<user>/conf/web/<domain>/nginx.features.ssl.conf_security-headers
```

Nginx picks these up automatically via the wildcard
`include .../nginx.features.conf_*;` / `nginx.features.ssl.conf_*;`
line already present in `wordpress.tpl`/`wordpress.stpl` — no reload of
Hestia itself, no domain rebuild, no template edit. A `systemctl reload
nginx` (or `nginx -s reload`) is enough to pick up the change, exactly
like any other file already matched by that domain's existing
`nginx.conf_*` wildcard include.

Removing the file disables the feature the same way.

See
`dev-docs/nginx/NGINX_SECURITY_EXTENSIBILITY_IMPLEMENTATION.md` for the
full architecture this extension point belongs to.

## Files

- `security-headers.conf` — a small set of low-compatibility-risk
  security headers. Deliberately does **not** enable a Content-Security-Policy
  (see the file's own comment for why).
- `fastcgi-cache-example.conf` — references the `microcache` FastCGI
  cache zone Hestia's own shipped `nginx.conf` already declares
  globally; commented out by default. Not full-page caching — see the
  file's own comment.
- `rate-limit-example.conf` — documents how to apply the global,
  dormant-by-default rate-limit zone shipped in
  `install/deb/nginx/hestia-rate-limit.conf` to a specific `location`.
  Ships with no numeric defaults presented as a recommendation — see
  that file's own comment for why.
- `modsecurity-example.conf` — documents the intended extension point
  for a ModSecurity/OWASP CRS integration. **Not usable as shipped**:
  no ModSecurity Nginx module is installed by this repository's
  installer. See the file's own comment and the implementation doc's
  "Deferred work" section. Re-verified unchanged during Sprint 9A — see
  `dev-docs/nginx/NGINX_WORDPRESS_HARDENING_IMPLEMENTATION.md`.

## WordPress auth-endpoint rate limiting (Sprint 9A)

Unlike everything above, WordPress's rate limit on `/wp-login.php` and
`/xmlrpc.php` is **not** an opt-in snippet — it is a baseline, always-on
single line (`limit_req zone=hestia_wp_auth_rl burst=15 nodelay;`) inside
every WordPress template's own general PHP-execution location, because
the zone it needs (`install/deb/nginx/hestia-wp-auth-rate-limit.conf`) is
scoped by a `$request_uri` map, not by a location block, so there is
nothing to duplicate a `fastcgi_pass` for and nothing to opt into per
domain. See `dev-docs/nginx/NGINX_WORDPRESS_HARDENING_IMPLEMENTATION.md`
for the full rationale and how to adjust its rate/burst.
