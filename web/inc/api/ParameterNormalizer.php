<?php

namespace Hestiacp\Api;

/**
 * Resource-identifier normalization seam, per
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §11 — deliberately
 * placed here, in the API layer, never inside
 * CommandAdapter/CommandRegistry (§11's own explicit assignment).
 *
 * Sprint 2 scope: "domain.get" is already 1:1 between its public and
 * internal identifier shape (§11's own table — no domain-name
 * transformation exists for any of the three domain operations), so this
 * pass is presently the identity function for the one operation Sprint 2
 * exposes (see OperationAllowlist::ALLOWED_OPERATIONS). The per-operation
 * switch below exists so a future operation needing a real
 * transformation (e.g. database.delete's {user}_ prefixing, §11) has an
 * obvious, already-wired extension point, without this class's shape
 * needing to change.
 */
final class ParameterNormalizer {
	/**
	 * @param array<string, mixed> $params Already envelope-validated
	 *        (§10) — this method performs no shape/type validation of
	 *        its own; CommandAdapter's own parameter validation remains
	 *        the single source of truth for that.
	 * @return array<string, mixed>
	 */
	public static function normalize(string $operation, array $params): array {
		switch ($operation) {
			default:
				return $params;
		}
	}
}
