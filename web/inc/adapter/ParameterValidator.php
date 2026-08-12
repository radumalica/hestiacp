<?php

namespace Hestiacp\Adapter;

/**
 * Shape-only validators for typed adapter parameters.
 *
 * These deliberately mirror the *shape* checks already performed by
 * func/main.sh's is_format_valid() dispatch table, not the business/state
 * checks (existence, ownership, quota) that live alongside them. Per
 * ARCHITECTURE_ADAPTER_DESIGN.md section 3, the adapter validates argument
 * shape only; func/main.sh's is_object_valid() (existence) and every other
 * business rule remain the sole, authoritative check, performed by the
 * underlying v-* script exactly as it is today.
 *
 * Each validator below cites the func/main.sh function it approximates,
 * so a reviewer can compare them side by side rather than trust a
 * from-scratch guess at Hestia's rules.
 */
final class ParameterValidator {
	/**
	 * Approximates func/main.sh is_user_format_valid() (default branch,
	 * no explicit max length argument, so max length 30):
	 *   - single character: must be [[:alnum:]]
	 *   - otherwise: ^[[:alnum:]][-.\_[:alnum:]]{0,28}[[:alnum:]]$ (1-30 chars total)
	 *   - ASCII only
	 *
	 * Does NOT check whether the user actually exists — that is
	 * is_object_valid('user', 'USER', ...) inside v-list-web-domain
	 * itself, and stays there.
	 */
	public static function isValidUsername($value): bool {
		if (!is_string($value) || $value === "") {
			return false;
		}
		if (preg_match('/[^\x00-\x7F]/', $value)) {
			return false;
		}
		if (strlen($value) === 1) {
			return (bool) preg_match('/^[a-zA-Z0-9]$/', $value);
		}
		return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,28}[a-zA-Z0-9]$/', $value);
	}

	/**
	 * Approximates func/main.sh is_domain_format_valid():
	 *   - rejects a fixed set of shell/format-hostile characters
	 *     (the same exclude class func/main.sh uses)
	 *   - rejects all-digit strings
	 *   - rejects leading/trailing '.' or '-'
	 *   - rejects '..', '.-' and '-.'
	 *   - rejects the literal string "www"
	 *   - rejects embedded newlines (func/main.sh's is_no_new_line_format)
	 *
	 * Does NOT check whether the domain actually exists for the user —
	 * that is is_object_valid('web', 'DOMAIN', ...) inside
	 * v-list-web-domain itself, and stays there.
	 */
	public static function isValidDomain($value): bool {
		if (!is_string($value) || $value === "") {
			return false;
		}
		if (preg_match('/[\[\]!@#$^&*()+={},<>?_\/\\\\"|\'`;%\s]/', $value)) {
			return false;
		}
		if (preg_match('/^[0-9]+$/', $value)) {
			return false;
		}
		if (strpos($value, "..") !== false) {
			return false;
		}
		if (preg_match('/^[.-]|[.-]$/', $value)) {
			return false;
		}
		if (strpos($value, ".-") !== false || strpos($value, "-.") !== false) {
			return false;
		}
		if ($value === "www") {
			return false;
		}
		if (strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
			return false;
		}
		return true;
	}
}
