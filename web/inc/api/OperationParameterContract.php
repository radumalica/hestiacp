<?php

namespace Hestiacp\Api;

/**
 * Explicit, API-owned public parameter contract per operation — the
 * name-level counterpart to OperationAllowlist. Declares exactly which
 * parameter NAMES a caller may supply, and which of those are required,
 * for each operation OperationAllowlist exposes.
 *
 * Deliberately NOT derived from CommandRegistry: a registry entry's own
 * "parameters" schema is internal implementation detail the API must not
 * blindly mirror — CommandRegistry additionally carries fixed_parameters
 * (e.g. domain.create's "restart"/"aliases"/"proxy_ext", database.create's
 * "type"/"host"/"charset") that a caller must never be able to set, and a
 * future CommandRegistry change (a new parameter, a loosened requirement)
 * must never silently widen or loosen what API v2 accepts without a
 * deliberate, reviewed change to the table below.
 *
 * Performs NAME-LEVEL validation only — which keys exist, which are
 * required. It does not check parameter VALUES (type, shape, emptiness,
 * identifier validity) — that remains CommandAdapter's/ParameterValidator's
 * sole, unduplicated responsibility, exactly as established in Sprint 2's
 * own API-level-vs-adapter-level split (see
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §10).
 *
 * Every parameter declared below is required — no operation in this
 * sprint has an optional public parameter. requiredParameters() and
 * allowedParameters() are identical today; kept as two distinct methods
 * (rather than one, reused for both purposes) purely so a future
 * operation with a genuinely optional parameter needs no restructuring
 * of this class's shape, only a data change.
 */
final class OperationParameterContract {
	/** @var array<string, string[]> */
	private const CONTRACTS = [
		"domain.get" => ["user", "domain"],
		"domain.list" => ["user"],
		"domain.create" => ["user", "domain"],
		"domain.delete" => ["user", "domain"],
		"backup.schedule" => ["user"],
		"database.create" => ["user", "database", "dbuser", "password"],
		"database.delete" => ["user", "database"],
	];

	/**
	 * Whether this operation has an explicit contract declared. Used by
	 * ExecuteRequestHandler to decide whether the name-level check
	 * applies at all — an operation with no declared contract (only ever
	 * reachable in tests, via ExecuteRequestHandler's own test-only
	 * allowlist-override constructor parameter; production's
	 * OperationAllowlist contains only the seven keys above) is passed
	 * through unchanged, matching Sprint 2's pre-Sprint-3 behavior.
	 */
	public static function isDeclared(string $operation): bool {
		return array_key_exists($operation, self::CONTRACTS);
	}

	/** @return string[] */
	public static function requiredParameters(string $operation): array {
		return self::CONTRACTS[$operation] ?? [];
	}

	/** @return string[] */
	public static function allowedParameters(string $operation): array {
		return self::CONTRACTS[$operation] ?? [];
	}
}
