<?php

namespace Hestiacp\Api;

/**
 * Explicit, per-operation allowlist of which already-validated,
 * already-normalized parameter names are safe to place in an audit
 * event's $target — per
 * dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md §8.
 *
 * Deliberately its OWN table, not derived from
 * OperationParameterContract::allowedParameters() by filtering out
 * "sensitive" names at read time — a future contract change (a new
 * parameter added to database.create, for example) must never silently
 * become loggable without a deliberate, reviewed change here, exactly
 * the same reasoning OperationParameterContract's own docblock already
 * gives for why it is not derived from CommandRegistry.
 *
 * ONLY ever called by ExecuteRequestHandler AFTER
 * ParameterNormalizer::normalize() has already run — $params here is
 * therefore always the exact value CommandAdapter::invoke() itself
 * receives, never raw/unvalidated caller input. database.delete's
 * "database" field is consequently already the normalized,
 * user-prefixed identifier (e.g. "alice_wordpress_db"), not the raw
 * public suffix the caller supplied — considered safe to log because it
 * is exactly the identifier the adapter itself will act on.
 *
 * database.create's "password" is deliberately absent from every
 * allowlist entry below — it must NEVER reach an AuditEvent in any
 * form, plaintext or otherwise.
 */
final class AuditTargetRedactor {
	/** @var array<string, string[]> */
	private const SAFE_TARGET_FIELDS = [
		"domain.get" => ["user", "domain"],
		"domain.list" => ["user"],
		"domain.create" => ["user", "domain"],
		"domain.delete" => ["user", "domain"],
		"backup.schedule" => ["user"],
		"database.create" => ["user", "database", "dbuser"],
		"database.delete" => ["user", "database"],
	];

	/**
	 * @param array<string, mixed> $normalizedParams Already
	 *        contract-validated and normalized — the exact params
	 *        CommandAdapter::invoke() itself receives.
	 * @return array<string, string>|null Null when $operation has no
	 *         declared safe-target entry (e.g. an operation only ever
	 *         reachable via a test-only allowlist override).
	 */
	public static function redact(string $operation, array $normalizedParams): ?array {
		if (!array_key_exists($operation, self::SAFE_TARGET_FIELDS)) {
			return null;
		}

		$target = [];
		foreach (self::SAFE_TARGET_FIELDS[$operation] as $field) {
			if (array_key_exists($field, $normalizedParams) && is_string($normalizedParams[$field])) {
				$target[$field] = $normalizedParams[$field];
			}
		}

		return $target;
	}
}
