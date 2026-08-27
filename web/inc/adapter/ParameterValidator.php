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

	/**
	 * Approximates func/main.sh is_database_format_valid() (func/main.sh
	 * 1206-1212):
	 *   - rejects the fixed set of shell/format-hostile characters
	 *     Hestia's own exclude class lists: ! | @ # $ ^ & * ( ) + = { }
	 *     : , < > ? / \ " ' ; % ` and space
	 *   - rejects strings 64 characters or longer
	 *   - rejects embedded newlines (is_no_new_line_format)
	 *
	 * Deliberately does NOT add an ASCII-only restriction the way
	 * isValidUsername() does — Hestia's own bash regex here only excludes
	 * the specific punctuation class above, not non-ASCII bytes in
	 * general, so adding one would be inventing a restriction the source
	 * does not have.
	 *
	 * One deliberate approximation, documented rather than silently
	 * assumed: this validates the RAW, caller-supplied suffix (e.g.
	 * "wordpress_db"), but bin/v-add-database's own 64-character check
	 * (func/main.sh:1208, `[ 64 -le ${#1} ]`) runs against the
	 * user-prefixed value it builds internally (`database="$user"_"$2"`,
	 * bin/v-add-database:21) — i.e. against a longer string than this
	 * validator ever sees. This validator is therefore intentionally a
	 * LOOSER bound than the script's own real limit, never a tighter
	 * one — the script itself remains the sole authoritative check for
	 * the true, prefixed length, exactly as this class's own philosophy
	 * (shape-only, never business-authoritative) already requires.
	 *
	 * Does NOT check whether the database already exists for the user —
	 * that is is_object_new('db', 'DB', ...) inside v-add-database
	 * itself, and stays there.
	 */
	public static function isValidDatabaseName($value): bool {
		if (!is_string($value) || $value === "") {
			return false;
		}
		if (strlen($value) >= 64) {
			return false;
		}
		if (preg_match('/[!|@#$^&*()+={}:,<>?\/\\\\"\'`;%\s]/', $value)) {
			return false;
		}
		return true;
	}

	/**
	 * Approximates func/main.sh is_dbuser_format_valid() (func/main.sh
	 * 1222-1231):
	 *   - rejects strings 33 characters or longer ("mysql username can be
	 *     up to 32 characters long")
	 *   - rejects the SAME exclude character class isValidDatabaseName()
	 *     uses (func/main.sh reuses the identical `$exclude` variable for
	 *     both functions)
	 *   - rejects embedded newlines (is_no_new_line_format)
	 *
	 * Same raw-suffix-vs-prefixed-value approximation as
	 * isValidDatabaseName() above applies here too
	 * (`dbuser="$user"_"$3"`, bin/v-add-database:22) — this validator's
	 * 32-character bound is checked against the shorter, unprefixed
	 * value, so it is a looser bound than the script's own real limit on
	 * the concatenated value, never a tighter one.
	 *
	 * Does NOT check whether the dbuser already exists for the user —
	 * that is is_object_new('db', 'DBUSER', ...) inside v-add-database
	 * itself, and stays there.
	 */
	public static function isValidDatabaseUsername($value): bool {
		if (!is_string($value) || $value === "") {
			return false;
		}
		if (strlen($value) >= 33) {
			return false;
		}
		if (preg_match('/[!|@#$^&*()+={}:,<>?\/\\\\"\'`;%\s]/', $value)) {
			return false;
		}
		return true;
	}

	/**
	 * The loosest possible shape check for an opaque secret value —
	 * deliberately, because bin/v-add-database applies NO format
	 * validation to its DBPASS argument at all: is_format_valid() is
	 * called there as `is_format_valid 'user' 'database' 'dbuser'
	 * 'charset'` (bin/v-add-database:50) — 'dbpass' is conspicuously
	 * absent from that list, and the only other function that touches
	 * the password (is_password_valid(), func/main.sh:625-633) performs
	 * a temp-file-dereference, never a character/length check. Adding
	 * any restriction beyond "is a non-empty string" here would be
	 * inventing a business rule the source does not have — real
	 * passwords legitimately contain any character.
	 *
	 * The one restriction this does apply — rejecting an empty string —
	 * matches this class's own existing convention (isValidUsername()
	 * and isValidDomain() both already reject "" as their very first
	 * check) rather than a source-verified Hestia rule: Hestia's own
	 * check_args() only validates argument COUNT, not whether an
	 * individual argument is non-empty, so a truly empty password string
	 * would technically reach bin/v-add-database unblocked. This
	 * validator deliberately narrows that one case, consistent with the
	 * existing validators in this file, not consistent with a literal
	 * reading of the shell source.
	 */
	public static function isValidSecret($value): bool {
		return is_string($value) && $value !== "";
	}
}
