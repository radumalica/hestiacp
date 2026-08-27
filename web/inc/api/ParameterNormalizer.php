<?php

namespace Hestiacp\Api;

/**
 * Resource-identifier normalization seam, per
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §11 — deliberately
 * placed here, in the API layer, never inside
 * CommandAdapter/CommandRegistry.
 *
 * Normalization rules:
 * - "database.delete": The public API accepts ONLY the raw database suffix
 *   (e.g. "wordpress_db"), matching database.create's public representation.
 *   If the database identifier is already prefixed with "{user}_", it is
 *   rejected with VALIDATION_FAILED. When valid, normalizes "database" to
 *   "{user}_{database}" (e.g. "admin_wordpress_db") to satisfy the internal
 *   adapter contract.
 * - All other exposed operations (domain.get, domain.list, domain.create,
 *   domain.delete, backup.schedule, database.create) map their public
 *   parameters directly 1:1 to the adapter contract.
 */
final class ParameterNormalizer {
	/**
	 * @param array<string, mixed> $params Already envelope-validated (§10).
	 * @return array<string, mixed>
	 */
	public static function normalize(string $operation, array $params): array {
		switch ($operation) {
			case "database.delete":
				if (isset($params["user"], $params["database"]) && is_string($params["user"]) && is_string($params["database"])) {
					if ($params["user"] !== "" && strpos($params["database"], $params["user"] . "_") === 0) {
						throw new ApiException(
							"VALIDATION_FAILED",
							422,
							"Database identifier must be a raw suffix without username prefix."
						);
					}
					if ($params["user"] !== "" && $params["database"] !== "") {
						$params["database"] = $params["user"] . "_" . $params["database"];
					}
				}
				return $params;
			default:
				return $params;
		}
	}
}
