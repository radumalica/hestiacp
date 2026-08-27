<?php

namespace Hestiacp\Api;

/**
 * The explicit, structural allowlist of adapter operations API v2 will
 * accept over HTTP, per dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md
 * §9. Deliberately NOT derived from CommandRegistry, the filesystem, or
 * caller input — CommandRegistry containing an operation does not
 * automatically make it public API (§9's own reasoning: CommandRegistry
 * entries carry implementation detail like argument_order and
 * fixed_parameters that are not public contract).
 *
 * Sprint 3 scope: exposes the seven core operations supported by the
 * adapter layer (domain.get, domain.list, domain.create, domain.delete,
 * database.create, database.delete, backup.schedule).
 */
final class OperationAllowlist {
	public const ALLOWED_OPERATIONS = [
		"domain.get",
		"domain.list",
		"domain.create",
		"domain.delete",
		"database.create",
		"database.delete",
		"backup.schedule",
	];

	public static function isAllowed(string $operation): bool {
		return in_array($operation, self::ALLOWED_OPERATIONS, true);
	}
}
