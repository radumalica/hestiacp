#=========================================================================#
# Default Web Domain Template                                             #
# DO NOT MODIFY THIS FILE! CHANGES WILL BE LOST WHEN REBUILDING DOMAINS   #
# https://hestiacp.com/docs/server-administration/web-templates.html      #
#=========================================================================#

server {
	listen      %ip%:%web_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	index       index.php index.html index.htm;
	access_log  /var/log/nginx/domains/%domain%.log combined;
	access_log  /var/log/nginx/domains/%domain%.bytes bytes;
	error_log   /var/log/nginx/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	location = /favicon.ico {
		log_not_found off;
		access_log off;
	}

	location = /robots.txt {
		try_files $uri $uri/ /index.php?$args;
		log_not_found off;
		access_log off;
	}

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	location / {
		try_files $uri $uri/ /index.php?$args;

		location ~* ^.+\.(ogg|ogv|svg|svgz|swf|eot|otf|woff|woff2|mov|mp3|mp4|webm|flv|ttf|rss|atom|jpg|jpeg|gif|png|webp|ico|bmp|mid|midi|wav|rtf|css|js|jar)$ {
			expires 30d;
			fastcgi_hide_header "Set-Cookie";
		}

		# WordPress security profile (baseline, always on for this
		# template): block direct access to files that never need to be
		# requested over HTTP but commonly leak configuration/version
		# information or data if left reachable. Safe for stock WordPress,
		# every major plugin, and WooCommerce — none of these paths are
		# ever legitimately fetched by a browser/REST client; wp-config.php
		# itself is never served by WordPress's own routing either (PHP
		# always includes it, never requests it as a URL). Also covers
		# common backup/editor-leftover variants of wp-config.php and
		# root-level SQL dump files (Sprint 9A — both frequent accidental-
		# exposure patterns); .env is already covered by the dotfile-deny
		# rule above, so it is deliberately not repeated here. Nested
		# inside location / (not a server-level sibling) so it reliably
		# takes priority over the general PHP-execution location below —
		# empirically confirmed during Sprint 8 testing that a
		# server-level regex location here can lose to a nested regex
		# location matching the same URI; nesting matches nginx's own
		# location-matching resolution for this exact case.
		location ~* ^/(?:wp-config\.php(?:\.(?:bak|save|old|orig|swp)|~)?|wp-config-sample\.php|readme\.html|license\.txt|wp-content/debug\.log|[^/]+\.sql(?:\.gz)?)$ {
			deny all;
			return 404;
		}

		# WordPress security profile: PHP execution is never legitimate
		# inside wp-content/uploads (WordPress's own media library) or
		# wp-content/cache (written by every major caching plugin) —
		# both are writable-by-the-app directories, the classic path for
		# an uploaded/planted PHP file to be executed. This rejects the
		# request at the Nginx boundary (before PHP-FPM ever runs) and
		# never touches, moves, or deletes anything on disk. Deliberately
		# scoped to these two directories only — wp-admin, wp-includes,
		# and wp-content/plugins/themes all legitimately execute PHP and
		# are untouched by this rule.
		location ~* ^/wp-content/(?:uploads|cache)/.*\.php$ {
			deny all;
			return 404;
		}

		location ~ [^/]\.php(/|$) {
			try_files $uri =404;

			# WordPress auth-endpoint rate limiting (Sprint 9A —
			# dev-docs/nginx/NGINX_WORDPRESS_HARDENING_IMPLEMENTATION.md).
			# The zone is scoped to /wp-login.php and /xmlrpc.php only, via
			# a $request_uri map in hestia-wp-auth-rate-limit.conf; every
			# other request maps to an empty key and is never rate-limited
			# by this directive (empirically verified with a live nginx
			# instance, not just `nginx -t`) — so this line has no effect
			# on ordinary WordPress traffic.
			limit_req zone=hestia_wp_auth_rl burst=15 nodelay;

			include /etc/nginx/fastcgi_params;

			fastcgi_index index.php;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			fastcgi_param HTTP_HOST $host;

			fastcgi_pass %backend_lsnr%;

			include %home%/%user%/conf/web/%domain%/nginx.fastcgi_cache.conf*;

			if ($request_uri ~* "/wp-admin/|/wp-json/|wp-.*.php|xmlrpc.php|index.php|/store.*|/cart.*|/my-account.*|/checkout.*") {
				set $no_cache 1;
			}

			if ($http_cookie ~* "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in|woocommerce_items_in_cart|woocommerce_cart_hash|PHPSESSID") {
				set $no_cache 1;
			}
		}
	}

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	location /vstats/ {
		alias   %home%/%user%/web/%domain%/stats/;
		include %home%/%user%/web/%domain%/stats/auth.conf*;
	}

	include /etc/nginx/conf.d/phpmyadmin.inc*;
	include /etc/nginx/conf.d/phppgadmin.inc*;

	# Composable feature extension point (Sprint 8 —
	# dev-docs/nginx/NGINX_SECURITY_EXTENSIBILITY_IMPLEMENTATION.md):
	# any file an administrator places at
	# %home%/%user%/conf/web/%domain%/nginx.features.conf_<name> is
	# included here automatically. This is how optional, composable
	# features (security headers, rate limiting, a WAF/ruleset provider,
	# a cache policy, a future application profile add-on) are enabled
	# per domain, without ever editing this template. Matches zero files
	# — and therefore changes nothing — until a feature snippet is
	# actually placed here; see install/deb/templates/web/nginx/snippets/
	# for the reference snippets shipped this sprint.
	include %home%/%user%/conf/web/%domain%/nginx.features.conf_*;

	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
