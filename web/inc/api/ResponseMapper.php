<?php

namespace Hestiacp\Api;

use Hestiacp\Adapter\AdapterResult;

/**
 * Translates a resolved Hestiacp\Adapter\AdapterResult into the public
 * API v2 response envelope + HTTP status, per
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §13 (envelope shape),
 * §14 (error vocabulary), §15 (HTTP status mapping), §16 (mutation
 * semantics).
 *
 * AdapterResult itself is never returned to a caller — every field this
 * class reads is translated into the smaller, stable public vocabulary;
 * raw stdout/stderr/exit codes/adapter-internal error codes never appear
 * in the returned envelope (§19/§22 acceptance criteria). The only
 * internal detail ever surfaced is the symbolic hestiaErrorCode (e.g.
 * "E_NOTEXIST") inside error.details, which §14 explicitly permits as an
 * optional diagnostic field — never the raw stdout/stderr/exit code.
 */
final class ResponseMapper {
	/**
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	public static function fromAdapterResult(AdapterResult $result): array {
		if ($result->status === "ok") {
			return self::success($result, "succeeded", 200);
		}

		if ($result->status === "adapter_error") {
			return self::fromAdapterError($result);
		}

		// $result->status === "hestia_error": the underlying bin/v-*
		// process ran and exited non-zero.
		if ($result->mutationState === null) {
			// Read-only operation — §16's own table: no mutation concept
			// applies here, outcome is a plain succeeded/failed split. A
			// failed read has no ambiguity to preserve (nothing was ever
			// going to be "mutated"), so it maps directly to
			// UPSTREAM_COMMAND_FAILED/422 — the same code §15 assigns to
			// "upstream command failure, mutation state known-failed"
			// for a mutating operation, extended here to the read-only
			// case §16's table does not separately enumerate a distinct
			// code for.
			return self::failure(
				$result,
				"failed",
				422,
				"UPSTREAM_COMMAND_FAILED",
				"The requested operation could not be completed."
			);
		}

		if ($result->mutationState === "confirmed_degraded") {
			return self::success($result, "succeeded_with_warning", 200);
		}

		if ($result->mutationState === "unknown") {
			// Never "failed" — §16 explicitly forbids collapsing unknown
			// into failed; 207 is §15's own reasoned choice for this
			// exact situation (see that section's justification).
			return self::failure(
				$result,
				"unknown",
				207,
				"UNKNOWN_OUTCOME",
				"The operation's outcome could not be determined."
			);
		}

		// Defensive fallback only: "confirmed" cannot reach this branch
		// (status would be "ok", handled above), and "not_attempted"
		// cannot reach this branch either (only ever paired with
		// status === "adapter_error", handled above). No currently
		// reachable AdapterResult hits this line.
		return self::failure(
			$result,
			"failed",
			422,
			"UPSTREAM_COMMAND_FAILED",
			"The requested operation could not be completed."
		);
	}

	/**
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	public static function fromApiException(string $operation, ApiException $exception): array {
		return [
			$exception->httpStatus(),
			[
				"api_version" => "v2",
				"success" => false,
				"outcome" => "failed",
				"data" => null,
				"error" => [
					"code" => $exception->errorCode(),
					"message" => $exception->getMessage(),
					"details" => $exception->details(),
				],
				"meta" => [
					"operation" => $operation,
					"command_id" => null,
				],
			],
		];
	}

	/**
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private static function success(AdapterResult $result, string $outcome, int $httpStatus): array {
		return [
			$httpStatus,
			[
				"api_version" => "v2",
				"success" => true,
				"outcome" => $outcome,
				"data" => $result->parsedOutput,
				"error" => null,
				"meta" => [
					"operation" => $result->operation,
					"command_id" => $result->commandId,
				],
			],
		];
	}

	/**
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private static function failure(
		AdapterResult $result,
		string $outcome,
		int $httpStatus,
		string $errorCode,
		string $message
	): array {
		$details = $result->hestiaErrorCode !== null ? ["hestia_error_code" => $result->hestiaErrorCode] : null;

		return [
			$httpStatus,
			[
				"api_version" => "v2",
				"success" => false,
				"outcome" => $outcome,
				"data" => null,
				"error" => [
					"code" => $errorCode,
					"message" => $message,
					"details" => $details,
				],
				"meta" => [
					"operation" => $result->operation,
					"command_id" => $result->commandId,
				],
			],
		];
	}

	/**
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private static function fromAdapterError(AdapterResult $result): array {
		switch ($result->adapterErrorCode) {
			case "AUTHORIZATION_DENIED":
				return self::failure(
					$result,
					"failed",
					403,
					"AUTHORIZATION_DENIED",
					"You are not authorized to perform this operation for the requested user."
				);
			case "LOCK_TIMEOUT":
				return self::failure(
					$result,
					"failed",
					409,
					"LOCK_TIMEOUT",
					"The requested operation timed out waiting for a lock. Please retry."
				);
			case "LOCK_UNAVAILABLE":
				return self::failure(
					$result,
					"failed",
					503,
					"LOCK_UNAVAILABLE",
					"The locking mechanism is temporarily unavailable. Please retry."
				);
			case "MISSING_PARAMETER":
			case "UNEXPECTED_PARAMETER":
			case "UNKNOWN_PARAMETER_TYPE":
			case "VALIDATION_FAILED":
				return self::failure($result, "failed", 422, "VALIDATION_FAILED", "The request could not be validated.");
			default:
				// UNKNOWN_OPERATION, REGISTRY_ERROR, TEMP_FILE_UNAVAILABLE,
				// or any future adapterErrorCode this mapper does not yet
				// recognize — never surfaced verbatim to a caller (§14).
				return self::failure($result, "failed", 500, "INTERNAL_ERROR", "An internal error occurred.");
		}
	}
}
