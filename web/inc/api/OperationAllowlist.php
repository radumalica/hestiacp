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
 * Sprint 2 scope: exactly one operation, "domain.get" — the sprint's
 * proving-ground operation. §9's own illustrative example lists all
 * seven currently-registered operations; Sprint 2's own brief explicitly
 * narrows this to one ("Do NOT implement additional API operations in
 * this sprint... For Sprint 2 expose ONLY the single proving-ground
 * operation"). Adding the remaining six is a mechanical,
 * one-line-per-operation follow-up for a future sprint, not a redesign
 * of this class.
 */
final class OperationAllowlist {
	public const ALLOWED_OPERATIONS = ["domain.get"];

	public static function isAllowed(string $operation): bool {
		return in_array($operation, self::ALLOWED_OPERATIONS, true);
	}
}
