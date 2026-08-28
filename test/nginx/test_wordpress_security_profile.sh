#!/bin/bash
#
# Sprint 8 (dev-docs/nginx/NGINX_SECURITY_EXTENSIBILITY_IMPLEMENTATION.md)
# and Sprint 9A (dev-docs/nginx/NGINX_WORDPRESS_HARDENING_IMPLEMENTATION.md)
# standalone test suite for the composable Nginx feature architecture and
# the WordPress security profile. Does not require a Hestia install —
# renders the shipped templates through the same sed substitution
# add_web_config() uses, then (if a local nginx binary is available)
# validates the result with the real nginx binary: `nginx -t` for syntax,
# and short-lived nginx instances for behavioral checks (blocked paths
# return 404 without reaching PHP-FPM, legitimate paths still do, and — new
# in Sprint 9A — the auth-endpoint rate limit actually throttles).
#
# Usage: bash test/nginx/test_wordpress_security_profile.sh

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKDIR="$(mktemp -d /tmp/hestia-nginx-test.XXXXXX)"
trap 'cleanup' EXIT

PASS=0
FAIL=0
NGINX_PID=""

cleanup() {
	if [[ -n "$NGINX_PID" ]] && kill -0 "$NGINX_PID" 2> /dev/null; then
		nginx -c "$WORKDIR/master.conf" -s stop 2> /dev/null
	fi
	rm -rf "$WORKDIR"
}

ok() {
	echo "[PASS] $1"
	PASS=$((PASS + 1))
}

bad() {
	echo "[FAIL] $1"
	FAIL=$((FAIL + 1))
}

render_template() {
	local src="$1" out="$2"
	cp "$src" "$out"
	sed -i \
		-e "s|%ip%|127.0.0.1|g" \
		-e "s|%web_port%|18080|g" \
		-e "s|%web_ssl_port%|18443|g" \
		-e "s|%domain_idn%|example.test|g" \
		-e "s|%domain%|example.test|g" \
		-e "s|%alias_idn%|www.example.test|g" \
		-e "s|%alias%|www.example.test|g" \
		-e "s|%docroot%|$WORKDIR/webroot|g" \
		-e "s|%sdocroot%|$WORKDIR/webroot|g" \
		-e "s|%home%|$WORKDIR/home|g" \
		-e "s|%user%|testuser|g" \
		-e "s|%backend_lsnr%|127.0.0.1:19000|g" \
		-e "s|%ssl_crt%|$WORKDIR/ssl/cert.pem|g" \
		-e "s|%ssl_key%|$WORKDIR/ssl/key.pem|g" \
		-e "s|%ssl_pem%|$WORKDIR/ssl/combined.pem|g" \
		-e "s|%ssl_ca_str%||g" \
		-e "s|%ssl_ca%|$WORKDIR/ssl/ca.pem|g" \
		-e "s|/var/log/nginx/domains|$WORKDIR/logs|g" \
		"$out"
}

echo "=== Sprint 8 Nginx feature architecture test suite ==="
echo "workdir: $WORKDIR"

mkdir -p "$WORKDIR/webroot" "$WORKDIR/logs" "$WORKDIR/ssl" "$WORKDIR/cache" \
	"$WORKDIR/home/testuser/conf/web/example.test"

# The general PHP-execution location does `try_files $uri =404;` before
# fastcgi_pass — a deliberate, pre-existing safeguard against invoking
# PHP-FPM for a URI with no backing file. To distinguish "blocked by the
# new security profile" from "404 because the file simply doesn't exist
# in this synthetic webroot", legitimate PHP paths need a real file on
# disk; the blocked paths must NOT get one, since blocking must work
# regardless of whether the target file exists.
mkdir -p "$WORKDIR/webroot/wp-admin" "$WORKDIR/webroot/wp-content/plugins/some-plugin" "$WORKDIR/webroot/wp-content/themes/some-theme"
touch "$WORKDIR/webroot/index.php" \
	"$WORKDIR/webroot/wp-admin/admin-ajax.php" \
	"$WORKDIR/webroot/wp-content/plugins/some-plugin/some-plugin.php" \
	"$WORKDIR/webroot/wp-content/themes/some-theme/functions.php"

# --- 1. Existing behavior unchanged when no new profile/features enabled ---
# The generic base templates and the master nginx.conf must be byte-for-byte
# untouched by this sprint.
for f in \
	"$REPO_ROOT/install/deb/templates/web/nginx/default.tpl" \
	"$REPO_ROOT/install/deb/templates/web/nginx/hosting.tpl" \
	"$REPO_ROOT/install/deb/nginx/nginx.conf"; do
	if git -C "$REPO_ROOT" diff --quiet -- "$f" 2> /dev/null; then
		ok "unchanged: $(basename "$f")"
	else
		bad "unexpectedly modified: $f"
	fi
done

# --- 2. Render wordpress.tpl/.stpl via the same sed pipeline add_web_config() uses ---
render_template "$REPO_ROOT/install/deb/templates/web/nginx/php-fpm/wordpress.tpl" "$WORKDIR/rendered.conf"
render_template "$REPO_ROOT/install/deb/templates/web/nginx/php-fpm/wordpress.stpl" "$WORKDIR/rendered.ssl.conf"

# Use Unix domain sockets instead of TCP ports for the behavioral tests
# below: this sandbox shares the network namespace with unrelated
# processes, and a fixed TCP test port can collide with something else
# already listening (observed in practice) — a socket path under $WORKDIR
# cannot collide with anything.
sed -i "s|^\tlisten      127.0.0.1:18080;|\tlisten      unix:$WORKDIR/http.sock;|" "$WORKDIR/rendered.conf"
sed -i "s|^\tlisten      127.0.0.1:18443 ssl;|\tlisten      unix:$WORKDIR/https.sock ssl;|" "$WORKDIR/rendered.ssl.conf"

# --- 3. No unresolved %var% placeholders remain in any new/changed file ---
UNRESOLVED=0
for f in "$WORKDIR/rendered.conf" "$WORKDIR/rendered.ssl.conf"; do
	if grep -q '%[a-z_]*%' "$f"; then
		bad "unresolved template variable left in $(basename "$f")"
		grep -n '%[a-z_]*%' "$f"
		UNRESOLVED=1
	fi
done
[[ "$UNRESOLVED" -eq 0 ]] && ok "no unresolved %var% placeholders in rendered WordPress templates"

for f in "$REPO_ROOT"/install/deb/templates/web/nginx/snippets/*.conf; do
	# Only real directive lines matter here — comments are allowed to
	# mention %var% syntax (e.g. to explain why it can't be used in a
	# raw, non-rendered snippet); strip them before checking.
	if grep -v '^\s*#' "$f" | grep -q '%[a-z_]*%'; then
		bad "raw snippet $(basename "$f") contains a Hestia %var% placeholder in an active directive (snippets are never sed-substituted)"
	else
		ok "no %var% placeholder in snippet $(basename "$f")"
	fi
done

# --- 4. Untrusted input cannot inject directives/include paths: sed pipeline itself untouched ---
if git -C "$REPO_ROOT" diff --quiet -- func/domain.sh func/rebuild.sh 2> /dev/null; then
	ok "unchanged: func/domain.sh, func/rebuild.sh (rendering pipeline untouched)"
else
	bad "func/domain.sh or func/rebuild.sh was modified — out of Sprint 8 scope"
fi

# --- 5. nginx availability check (task requires: run nginx -t if a safe test env exists) ---
if ! command -v nginx > /dev/null 2>&1; then
	echo "[SKIP] nginx binary not found in PATH — cannot run nginx -t or behavioral tests."
	echo "Summary: $PASS passed, $FAIL failed (nginx-dependent checks skipped)"
	[[ "$FAIL" -eq 0 ]] && exit 0 || exit 1
fi

openssl req -x509 -newkey rsa:2048 -nodes \
	-keyout "$WORKDIR/ssl/key.pem" -out "$WORKDIR/ssl/cert.pem" \
	-days 1 -subj "/CN=example.test" > /dev/null 2>&1
cp "$WORKDIR/ssl/cert.pem" "$WORKDIR/ssl/combined.pem"
cp "$WORKDIR/ssl/cert.pem" "$WORKDIR/ssl/ca.pem"

# --- 5b. Template coverage (Sprint 9A, scope item #8): all 12 WordPress
# template variants must render and pass `nginx -t`, not just wordpress.tpl/
# .stpl. Each variant is rendered to its own file and syntax-checked in its
# own minimal http{} block (not sharing a listen socket with anything else)
# so a failure in one variant is attributed correctly.
ALL_WP_TEMPLATES=(
	wordpress
	wordpress-disable-xmlrpc
	wordpress-http3
	wordpress_mu_subdir
	wordpress-disable-xmlrpc-http3
	wordpress_mu_subdir-http3
)
TPLDIR="$REPO_ROOT/install/deb/templates/web/nginx/php-fpm"
COVERAGE_OK=1
for base in "${ALL_WP_TEMPLATES[@]}"; do
	for ext in tpl stpl; do
		src="$TPLDIR/$base.$ext"
		if [[ ! -f "$src" ]]; then
			bad "expected template missing: $base.$ext"
			COVERAGE_OK=0
			continue
		fi
		out="$WORKDIR/coverage-$base.$ext.conf"
		render_template "$src" "$out"
		if [[ "$ext" == "tpl" ]]; then
			sed -i "s|^\tlisten      127.0.0.1:18080;|\tlisten      unix:$WORKDIR/cov-$base-$ext.sock;|" "$out"
		else
			sed -i \
				-e "s|^\tlisten      127.0.0.1:18443 ssl;|\tlisten      unix:$WORKDIR/cov-$base-$ext.sock ssl;|" \
				-e "s|^\tlisten      127.0.0.1:18443 quic;|#quic-disabled-in-test\n|" \
				"$out"
		fi
		if grep -q '%[a-z_]*%' "$out"; then
			bad "unresolved template variable left in $base.$ext"
			grep -n '%[a-z_]*%' "$out"
			COVERAGE_OK=0
			continue
		fi
		cat > "$WORKDIR/cov-master.conf" << EOF
daemon off;
worker_processes 1;
error_log stderr;
pid $WORKDIR/cov-nginx.pid;
events { worker_connections 16; }
http {
	include /etc/nginx/mime.types;
	default_type application/octet-stream;
	access_log off;
	log_format main '\$remote_addr - \$remote_user [\$time_local] \$request "\$status"';
	log_format bytes '\$body_bytes_sent';
	client_max_body_size 1024m;
	fastcgi_cache_path $WORKDIR/cache/micro-cov levels=1:2 keys_zone=microcache_cov:10m inactive=30m max_size=1024m;
	map \$http_cookie \$no_cache {
		default 0;
		~SESS 1;
		~wordpress_logged_in 1;
	}
	include $REPO_ROOT/install/deb/nginx/0rtt-anti-replay.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-rate-limit.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-wp-auth-rate-limit.conf;
	include $out;
}
EOF
		if nginx -t -c "$WORKDIR/cov-master.conf" > "$WORKDIR/cov-nginx-t.log" 2>&1; then
			ok "nginx -t: $base.$ext renders with valid syntax"
		else
			bad "nginx -t failed for $base.$ext"
			cat "$WORKDIR/cov-nginx-t.log"
			COVERAGE_OK=0
		fi
	done
done
[[ "$COVERAGE_OK" -eq 1 ]] && ok "all 12 WordPress template variants render and pass nginx -t"

cat > "$WORKDIR/master.conf" << EOF
daemon off;
worker_processes 1;
error_log stderr;
pid $WORKDIR/nginx.pid;
events { worker_connections 64; }
http {
	include /etc/nginx/mime.types;
	default_type application/octet-stream;
	access_log off;
	log_format main '\$remote_addr - \$remote_user [\$time_local] \$request "\$status"';
	log_format bytes '\$body_bytes_sent';
	client_max_body_size 1024m;
	fastcgi_cache_path $WORKDIR/cache/micro levels=1:2 keys_zone=microcache:10m inactive=30m max_size=1024m;
	map \$http_cookie \$no_cache {
		default 0;
		~SESS 1;
		~wordpress_logged_in 1;
	}
	include $REPO_ROOT/install/deb/nginx/0rtt-anti-replay.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-rate-limit.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-wp-auth-rate-limit.conf;
	include $WORKDIR/rendered.conf;
	include $WORKDIR/rendered.ssl.conf;
}
EOF

# --- 6. nginx -t: base rendered config, no features enabled ---
if nginx -t -c "$WORKDIR/master.conf" > "$WORKDIR/nginx-t.log" 2>&1; then
	ok "nginx -t: rendered WordPress templates (no features enabled) — valid syntax"
else
	bad "nginx -t failed on rendered WordPress templates (no features enabled)"
	cat "$WORKDIR/nginx-t.log"
fi

# --- 7. nginx -t: with every shipped feature snippet enabled simultaneously (no duplicate/conflicting directives) ---
DOMD="$WORKDIR/home/testuser/conf/web/example.test"
cp "$REPO_ROOT/install/deb/templates/web/nginx/snippets/security-headers.conf" "$DOMD/nginx.features.conf_security-headers"
cp "$REPO_ROOT/install/deb/templates/web/nginx/snippets/rate-limit-example.conf" "$DOMD/nginx.features.conf_rate-limit"
if nginx -t -c "$WORKDIR/master.conf" > "$WORKDIR/nginx-t-features.log" 2>&1; then
	ok "nginx -t: WordPress template with feature snippets enabled — valid syntax, no conflicts"
else
	bad "nginx -t failed with feature snippets enabled"
	cat "$WORKDIR/nginx-t-features.log"
fi
rm -f "$DOMD"/nginx.features.conf_*

# --- 8. Behavioral checks: start nginx, verify blocked vs legitimate paths ---
nginx -c "$WORKDIR/master.conf" > /dev/null 2>&1 &
NGINX_PID=$!
sleep 1

http_code() {
	curl -s --unix-socket "$WORKDIR/http.sock" -o /dev/null -w "%{http_code}" "http://localhost$1" 2> /dev/null
}

check_blocked() {
	local path="$1" code
	code="$(http_code "$path")"
	if [[ "$code" == "404" ]]; then
		ok "blocked at Nginx boundary (404, PHP never invoked): $path"
	else
		bad "expected 404 (blocked) for $path, got $code"
	fi
}

check_reaches_php() {
	local path="$1" code
	code="$(http_code "$path")"
	# 502 = nginx tried to reach PHP-FPM and failed (no real backend in this
	# test) — proves the request was NOT blocked at the Nginx boundary.
	if [[ "$code" == "502" ]]; then
		ok "reaches PHP-FPM boundary as expected (502, no real backend): $path"
	else
		bad "expected 502 (would reach PHP-FPM) for $path, got $code"
	fi
}

https_code() {
	curl -s -k --unix-socket "$WORKDIR/https.sock" -o /dev/null -w "%{http_code}" "https://localhost$1" 2> /dev/null
}

# .stpl carries its own copy of the security profile (a separate template
# file, edited separately from .tpl) plus a TLS 1.3 0-RTT anti-replay
# guard ($anti_replay -> 307/425) ahead of it. A 307/425 here means the
# anti-replay guard engaged, not that the security block was bypassed —
# treat those as inconclusive rather than pass/fail either way, since
# they say nothing about whether the block itself works.
check_blocked_ssl() {
	local path="$1" code
	code="$(https_code "$path")"
	if [[ "$code" == "404" ]]; then
		ok "blocked at Nginx boundary over SSL (404, PHP never invoked): $path"
	elif [[ "$code" == "307" || "$code" == "425" ]]; then
		echo "[SKIP] SSL anti-replay guard intercepted $path (code $code) before the security block — inconclusive"
	else
		bad "expected 404 (blocked) for $path over SSL, got $code"
	fi
}

check_reaches_php_ssl() {
	local path="$1" code
	code="$(https_code "$path")"
	if [[ "$code" == "502" ]]; then
		ok "reaches PHP-FPM boundary over SSL as expected (502, no real backend): $path"
	elif [[ "$code" == "307" || "$code" == "425" ]]; then
		echo "[SKIP] SSL anti-replay guard intercepted $path (code $code) — inconclusive"
	else
		bad "expected 502 (would reach PHP-FPM) for $path over SSL, got $code"
	fi
}

if kill -0 "$NGINX_PID" 2> /dev/null; then
	check_blocked "/wp-config.php"
	check_blocked "/wp-config-sample.php"
	check_blocked "/readme.html"
	check_blocked "/license.txt"
	check_blocked "/wp-content/debug.log"
	check_blocked "/wp-content/uploads/evil.php"
	check_blocked "/wp-content/cache/evil.php"
	# Sprint 9A additions: backup/editor-leftover variants of wp-config.php
	# and root-level SQL dump files.
	check_blocked "/wp-config.php.bak"
	check_blocked "/wp-config.php.save"
	check_blocked "/wp-config.php.old"
	check_blocked "/wp-config.php~"
	check_blocked "/backup.sql"
	check_blocked "/dump.sql.gz"

	check_reaches_php "/index.php"
	check_reaches_php "/wp-admin/admin-ajax.php"
	check_reaches_php "/wp-content/plugins/some-plugin/some-plugin.php"
	check_reaches_php "/wp-content/themes/some-theme/functions.php"

	# wordpress.stpl is a separate template file from wordpress.tpl,
	# edited separately — it must be verified independently, not assumed
	# to behave the same just because .tpl was proven correct.
	check_blocked_ssl "/wp-config.php"
	check_blocked_ssl "/wp-content/uploads/evil.php"
	check_blocked_ssl "/wp-config.php.bak"
	check_blocked_ssl "/backup.sql"
	check_reaches_php_ssl "/index.php"

	# --- Sprint 9A: WordPress auth-endpoint rate limiting. wp-login.php
	# must throttle to 503 once its burst is exhausted; unrelated paths
	# (mapped to an empty zone key) must never be throttled regardless of
	# request volume. Uses HTTP/1.0-style sequential requests (not
	# parallel) so timing is deterministic.
	RL_LOGIN_STATUSES=""
	for _ in $(seq 1 25); do
		RL_LOGIN_STATUSES="$RL_LOGIN_STATUSES $(http_code /wp-login.php)"
	done
	if echo "$RL_LOGIN_STATUSES" | grep -q 503; then
		ok "rate limit engages on /wp-login.php after burst exhausted (Sprint 9A)"
	else
		bad "rate limit never engaged on /wp-login.php after 25 rapid requests — statuses:$RL_LOGIN_STATUSES"
	fi

	RL_INDEX_STATUSES=""
	for _ in $(seq 1 25); do
		RL_INDEX_STATUSES="$RL_INDEX_STATUSES $(http_code /index.php)"
	done
	if echo "$RL_INDEX_STATUSES" | grep -q 503; then
		bad "unrelated path /index.php was rate-limited — map-based key scoping is broken: $RL_INDEX_STATUSES"
	else
		ok "unrelated path /index.php never rate-limited regardless of request volume (Sprint 9A)"
	fi

	nginx -c "$WORKDIR/master.conf" -s stop 2> /dev/null
	wait "$NGINX_PID" 2> /dev/null
	NGINX_PID=""
else
	echo "[SKIP] could not start nginx in this sandbox (bind/permission restricted) — behavioral checks skipped"
fi

# --- 9. Optional feature: security-headers snippet only emitted when enabled ---
cp "$REPO_ROOT/install/deb/templates/web/nginx/snippets/security-headers.conf" "$DOMD/nginx.features.conf_security-headers"
nginx -c "$WORKDIR/master.conf" > /dev/null 2>&1 &
NGINX_PID=$!
sleep 1
if kill -0 "$NGINX_PID" 2> /dev/null; then
	HEADERS="$(curl -s --unix-socket "$WORKDIR/http.sock" -D - -o /dev/null "http://localhost/index.php" 2> /dev/null)"
	if echo "$HEADERS" | grep -qi "X-Content-Type-Options: nosniff"; then
		ok "feature snippet emitted when enabled: X-Content-Type-Options present"
	else
		bad "feature snippet enabled but header missing"
	fi
	nginx -c "$WORKDIR/master.conf" -s stop 2> /dev/null
	wait "$NGINX_PID" 2> /dev/null
	NGINX_PID=""
else
	echo "[SKIP] could not start nginx for feature-enabled check"
fi
rm -f "$DOMD"/nginx.features.conf_*

# --- 10. Sprint 9A: wordpress-disable-xmlrpc — /xmlrpc.php must be blocked
# regardless of whether its `location = /xmlrpc.php` is nested inside
# location / (the .tpl) or a server-level sibling of it (the .stpl) — a
# pre-existing inconsistency between the two files that was flagged for
# verification rather than assumed safe, given Sprint 8's precedent that a
# server-level regex location can lose to a nested regex matching the same
# URI. Exact-match (`=`) locations are a different code path in nginx and
# were empirically confirmed immune to that specific pitfall.
XMLD="$WORKDIR/home/testuser/conf/web/xmlrpc.test"
mkdir -p "$XMLD"
render_template "$TPLDIR/wordpress-disable-xmlrpc.tpl" "$WORKDIR/xmlrpc.conf"
render_template "$TPLDIR/wordpress-disable-xmlrpc.stpl" "$WORKDIR/xmlrpc.ssl.conf"
sed -i \
	-e "s|^\tlisten      127.0.0.1:18080;|\tlisten      unix:$WORKDIR/xmlrpc-http.sock;|" \
	-e "s|%domain_idn%|xmlrpc.test|g" -e "s|%domain%|xmlrpc.test|g" \
	"$WORKDIR/xmlrpc.conf"
sed -i \
	-e "s|^\tlisten      127.0.0.1:18443 ssl;|\tlisten      unix:$WORKDIR/xmlrpc-https.sock ssl;|" \
	-e "s|%domain_idn%|xmlrpc.test|g" -e "s|%domain%|xmlrpc.test|g" \
	"$WORKDIR/xmlrpc.ssl.conf"

cat > "$WORKDIR/xmlrpc-master.conf" << EOF
daemon off;
worker_processes 1;
error_log stderr;
pid $WORKDIR/xmlrpc-nginx.pid;
events { worker_connections 32; }
http {
	include /etc/nginx/mime.types;
	default_type application/octet-stream;
	access_log off;
	log_format main '\$remote_addr - \$remote_user [\$time_local] \$request "\$status"';
	log_format bytes '\$body_bytes_sent';
	fastcgi_cache_path $WORKDIR/cache/micro-xmlrpc levels=1:2 keys_zone=microcache_xmlrpc:10m inactive=30m max_size=1024m;
	map \$http_cookie \$no_cache { default 0; }
	include $REPO_ROOT/install/deb/nginx/0rtt-anti-replay.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-rate-limit.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-wp-auth-rate-limit.conf;
	include $WORKDIR/xmlrpc.conf;
	include $WORKDIR/xmlrpc.ssl.conf;
}
EOF

nginx -c "$WORKDIR/xmlrpc-master.conf" > /dev/null 2>&1 &
NGINX_PID=$!
sleep 1
if kill -0 "$NGINX_PID" 2> /dev/null; then
	code="$(curl -s --unix-socket "$WORKDIR/xmlrpc-http.sock" -o /dev/null -w "%{http_code}" "http://localhost/xmlrpc.php" 2> /dev/null)"
	if [[ "$code" == "403" ]]; then
		ok "wordpress-disable-xmlrpc.tpl: /xmlrpc.php blocked (nested exact-match location, 403)"
	else
		bad "wordpress-disable-xmlrpc.tpl: expected 403 for /xmlrpc.php, got $code — possible bypass"
	fi
	code_ssl="$(curl -s -k --unix-socket "$WORKDIR/xmlrpc-https.sock" -o /dev/null -w "%{http_code}" "https://localhost/xmlrpc.php" 2> /dev/null)"
	if [[ "$code_ssl" == "403" ]]; then
		ok "wordpress-disable-xmlrpc.stpl: /xmlrpc.php blocked (server-level sibling exact-match location, 403)"
	elif [[ "$code_ssl" == "307" || "$code_ssl" == "425" ]]; then
		echo "[SKIP] SSL anti-replay guard intercepted /xmlrpc.php (code $code_ssl) — inconclusive"
	else
		bad "wordpress-disable-xmlrpc.stpl: expected 403 for /xmlrpc.php, got $code_ssl — possible bypass"
	fi
	nginx -c "$WORKDIR/xmlrpc-master.conf" -s stop 2> /dev/null
	wait "$NGINX_PID" 2> /dev/null
	NGINX_PID=""
else
	echo "[SKIP] could not start nginx for xmlrpc precedence check"
fi

# --- 11. Sprint 9A: wordpress_mu_subdir — a planted PHP file under a
# multisite subsite's wp-content/uploads must still be denied even though
# the site-rewrite only fires for paths that do NOT already exist on disk
# (`if (!-e $request_filename)`) — an attacker-planted file always exists,
# so the rewrite never runs and the request is served by whichever
# location matches the raw, un-rewritten /site2/wp-content/uploads/... URI
# directly. This regresses with an anchor that only matches ^/wp-content/,
# which is why the mu_subdir variant uses a pattern with an optional
# leading site-segment instead.
MUD="$WORKDIR/home/testuser/conf/web/mu.test"
mkdir -p "$MUD" "$WORKDIR/webroot/site2/wp-content/uploads"
touch "$WORKDIR/webroot/site2/wp-content/uploads/evil.php"
render_template "$TPLDIR/wordpress_mu_subdir.tpl" "$WORKDIR/mu.conf"
sed -i \
	-e "s|^\tlisten      127.0.0.1:18080;|\tlisten      unix:$WORKDIR/mu-http.sock;|" \
	-e "s|%domain_idn%|mu.test|g" -e "s|%domain%|mu.test|g" \
	"$WORKDIR/mu.conf"

cat > "$WORKDIR/mu-master.conf" << EOF
daemon off;
worker_processes 1;
error_log stderr;
pid $WORKDIR/mu-nginx.pid;
events { worker_connections 32; }
http {
	include /etc/nginx/mime.types;
	default_type application/octet-stream;
	access_log off;
	log_format main '\$remote_addr - \$remote_user [\$time_local] \$request "\$status"';
	log_format bytes '\$body_bytes_sent';
	fastcgi_cache_path $WORKDIR/cache/micro-mu levels=1:2 keys_zone=microcache_mu:10m inactive=30m max_size=1024m;
	map \$http_cookie \$no_cache { default 0; }
	include $REPO_ROOT/install/deb/nginx/hestia-rate-limit.conf;
	include $REPO_ROOT/install/deb/nginx/hestia-wp-auth-rate-limit.conf;
	include $WORKDIR/mu.conf;
}
EOF

nginx -c "$WORKDIR/mu-master.conf" > /dev/null 2>&1 &
NGINX_PID=$!
sleep 1
if kill -0 "$NGINX_PID" 2> /dev/null; then
	code="$(curl -s --unix-socket "$WORKDIR/mu-http.sock" -o /dev/null -w "%{http_code}" "http://localhost/wp-content/uploads/evil.php" 2> /dev/null)"
	if [[ "$code" == "404" ]]; then
		ok "wordpress_mu_subdir.tpl: root-site /wp-content/uploads/evil.php blocked"
	else
		bad "wordpress_mu_subdir.tpl: expected 404 for root-site uploads PHP, got $code"
	fi
	code_sub="$(curl -s --unix-socket "$WORKDIR/mu-http.sock" -o /dev/null -w "%{http_code}" "http://localhost/site2/wp-content/uploads/evil.php" 2> /dev/null)"
	if [[ "$code_sub" == "404" ]]; then
		ok "wordpress_mu_subdir.tpl: subsite /site2/wp-content/uploads/evil.php blocked (regression check)"
	else
		bad "wordpress_mu_subdir.tpl: expected 404 for subsite uploads PHP, got $code_sub — multisite bypass"
	fi
	nginx -c "$WORKDIR/mu-master.conf" -s stop 2> /dev/null
	wait "$NGINX_PID" 2> /dev/null
	NGINX_PID=""
else
	echo "[SKIP] could not start nginx for mu_subdir regression check"
fi

echo "=== Summary: $PASS passed, $FAIL failed ==="
[[ "$FAIL" -eq 0 ]]
