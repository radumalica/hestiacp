<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\AdapterResult;
use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Api\ApiException;
use Hestiacp\Api\ResponseMapper;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Direct unit tests for ResponseMapper against synthetic AdapterResult
 * instances, exercising the FULL API_V2_HTTP_CONTRACT_DESIGN.md §14/§15/
 * §16 mapping table — including mutation-state branches
 * ("confirmed_degraded", "unknown", "not_attempted") that
 * "domain.get" (Sprint 2's only allowlisted, read-only operation) can
 * never itself produce. This is deliberately NOT "implementing a
 * mutating API operation": ResponseMapper is a pure data transformation
 * with no operation-specific branch anywhere in its own source (see its
 * docblock) — testing it against a hand-constructed AdapterResult
 * exercises exactly the "response envelope and error-code mapping table"
 * work Sprint 2's own Implementation Boundary
 * (API_V2_HTTP_CONTRACT_DESIGN.md §20 item 6) explicitly authorizes in
 * full, without adding a second operation to OperationAllowlist.
 */
final class ResponseMapperTest {
	private static function baseResult(
		string $status,
		?int $exitCode,
		?string $hestiaErrorCode,
		?string $adapterErrorCode,
		?string $mutationState,
		$parsedOutput = null
	): AdapterResult {
		return new AdapterResult(
			"domain.get",
			"v-list-web-domain",
			"cmd-123",
			$status,
			$exitCode,
			$hestiaErrorCode,
			$adapterErrorCode,
			$status === "ok" ? null : "something went wrong",
			null,
			null,
			$parsedOutput,
			"2024-01-01T00:00:00Z",
			"2024-01-01T00:00:01Z",
			1000,
			["user" => "alice", "acting_as" => null],
			["user" => "alice"],
			"single",
			$mutationState
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("ResponseMapper: status=ok -> succeeded/200/success=true", [self::class, "testOkSucceeded"]);
		$t->test("ResponseMapper: adapter_error/AUTHORIZATION_DENIED -> 403", [self::class, "testAuthorizationDenied"]);
		$t->test("ResponseMapper: adapter_error/LOCK_TIMEOUT -> 409", [self::class, "testLockTimeout"]);
		$t->test("ResponseMapper: adapter_error/LOCK_UNAVAILABLE -> 503", [self::class, "testLockUnavailable"]);
		$t->test("ResponseMapper: adapter_error/MISSING_PARAMETER -> 422 VALIDATION_FAILED", [self::class, "testMissingParameter"]);
		$t->test("ResponseMapper: adapter_error/UNEXPECTED_PARAMETER -> 422 VALIDATION_FAILED", [self::class, "testUnexpectedParameter"]);
		$t->test("ResponseMapper: adapter_error/UNKNOWN_PARAMETER_TYPE -> 422 VALIDATION_FAILED", [self::class, "testUnknownParameterType"]);
		$t->test("ResponseMapper: adapter_error/VALIDATION_FAILED -> 422 VALIDATION_FAILED", [self::class, "testAdapterValidationFailed"]);
		$t->test("ResponseMapper: adapter_error/UNKNOWN_OPERATION -> 500 INTERNAL_ERROR (never verbatim)", [self::class, "testUnknownOperationInternal"]);
		$t->test("ResponseMapper: adapter_error/REGISTRY_ERROR -> 500 INTERNAL_ERROR", [self::class, "testRegistryErrorInternal"]);
		$t->test("ResponseMapper: adapter_error/TEMP_FILE_UNAVAILABLE -> 500 INTERNAL_ERROR", [self::class, "testTempFileUnavailableInternal"]);
		$t->test("ResponseMapper: hestia_error, mutationState null (read failure) -> 422 UPSTREAM_COMMAND_FAILED", [self::class, "testReadFailure"]);
		$t->test("ResponseMapper: hestia_error, mutationState=confirmed_degraded -> 200 succeeded_with_warning", [self::class, "testConfirmedDegraded"]);
		$t->test("ResponseMapper: hestia_error, mutationState=unknown -> 207, outcome=unknown, NEVER failed", [self::class, "testUnknownNeverFailed"]);
		$t->test("ResponseMapper: hestia_error, mutationState=not_attempted -> defensive fallback, never a crash", [self::class, "testNotAttemptedDefensiveFallback"]);
		$t->test("ResponseMapper: error.details carries symbolic hestia_error_code, never raw stdout/stderr", [self::class, "testErrorDetailsSymbolicOnly"]);
		$t->test("ResponseMapper: error.details is null when no hestiaErrorCode is present", [self::class, "testErrorDetailsNullWhenAbsent"]);
		$t->test("ResponseMapper: data is null on every failure envelope", [self::class, "testDataNullOnFailure"]);
		$t->test("ResponseMapper: meta.operation/command_id always populated from the AdapterResult", [self::class, "testMetaAlwaysPopulated"]);
		$t->test("ResponseMapper: fromApiException builds a correctly shaped envelope with command_id=null", [self::class, "testFromApiException"]);
		$t->test("ResponseMapper: success is true only for succeeded/succeeded_with_warning", [self::class, "testSuccessFlagSemantics"]);
	}

	public static function testOkSucceeded(): void {
		$result = self::baseResult("ok", 0, null, null, null, ["example.com" => ["IP" => "203.0.113.5"]]);
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(200, $status);
		assertEquals("succeeded", $envelope["outcome"]);
		assertTrue($envelope["success"] === true);
		assertEquals(["example.com" => ["IP" => "203.0.113.5"]], $envelope["data"]);
		assertEquals(null, $envelope["error"]);
	}

	public static function testAuthorizationDenied(): void {
		$result = self::baseResult("adapter_error", null, null, "AUTHORIZATION_DENIED", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(403, $status);
		assertEquals("AUTHORIZATION_DENIED", $envelope["error"]["code"]);
		assertEquals("failed", $envelope["outcome"]);
		assertTrue($envelope["success"] === false);
	}

	public static function testLockTimeout(): void {
		$result = self::baseResult("adapter_error", null, null, "LOCK_TIMEOUT", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(409, $status);
		assertEquals("LOCK_TIMEOUT", $envelope["error"]["code"]);
	}

	public static function testLockUnavailable(): void {
		$result = self::baseResult("adapter_error", null, null, "LOCK_UNAVAILABLE", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(503, $status);
		assertEquals("LOCK_UNAVAILABLE", $envelope["error"]["code"]);
	}

	public static function testMissingParameter(): void {
		$result = self::baseResult("adapter_error", null, null, "MISSING_PARAMETER", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testUnexpectedParameter(): void {
		$result = self::baseResult("adapter_error", null, null, "UNEXPECTED_PARAMETER", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testUnknownParameterType(): void {
		$result = self::baseResult("adapter_error", null, null, "UNKNOWN_PARAMETER_TYPE", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testAdapterValidationFailed(): void {
		$result = self::baseResult("adapter_error", null, null, "VALIDATION_FAILED", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testUnknownOperationInternal(): void {
		$result = self::baseResult("adapter_error", null, null, "UNKNOWN_OPERATION", null);
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(500, $status);
		assertEquals("INTERNAL_ERROR", $envelope["error"]["code"], "UNKNOWN_OPERATION must never be surfaced verbatim to a caller");
	}

	public static function testRegistryErrorInternal(): void {
		$result = self::baseResult("adapter_error", null, null, "REGISTRY_ERROR", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(500, $status);
		assertEquals("INTERNAL_ERROR", $envelope["error"]["code"]);
	}

	public static function testTempFileUnavailableInternal(): void {
		$result = self::baseResult("adapter_error", null, null, "TEMP_FILE_UNAVAILABLE", "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(500, $status);
		assertEquals("INTERNAL_ERROR", $envelope["error"]["code"]);
	}

	public static function testReadFailure(): void {
		$result = self::baseResult("hestia_error", 3, "E_NOTEXIST", null, null);
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(422, $status);
		assertEquals("UPSTREAM_COMMAND_FAILED", $envelope["error"]["code"]);
		assertEquals("failed", $envelope["outcome"]);
	}

	public static function testConfirmedDegraded(): void {
		$result = self::baseResult("hestia_error", 20, "E_RESTART", null, "confirmed_degraded");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(200, $status);
		assertEquals("succeeded_with_warning", $envelope["outcome"]);
		assertTrue($envelope["success"] === true, "confirmed_degraded is still a transport-level 200 success");
		assertEquals(null, $envelope["error"]);
	}

	public static function testUnknownNeverFailed(): void {
		$result = self::baseResult("hestia_error", 1, "E_ARGS", null, "unknown");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(207, $status);
		assertEquals("unknown", $envelope["outcome"], "mutationState=unknown must NEVER be collapsed into outcome=failed");
		assertTrue($envelope["outcome"] !== "failed");
		assertEquals("UNKNOWN_OUTCOME", $envelope["error"]["code"]);
		assertTrue($envelope["success"] === false);
	}

	public static function testNotAttemptedDefensiveFallback(): void {
		// Not a state CommandAdapter actually produces paired with
		// status=hestia_error (not_attempted only ever pairs with
		// adapter_error) — exercised here only to prove the defensive
		// fallback branch does not crash or misclassify.
		$result = self::baseResult("hestia_error", 1, "E_ARGS", null, "not_attempted");
		[$status, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(422, $status);
		assertEquals("UPSTREAM_COMMAND_FAILED", $envelope["error"]["code"]);
	}

	public static function testErrorDetailsSymbolicOnly(): void {
		$result = self::baseResult("hestia_error", 3, "E_NOTEXIST", null, null);
		[, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(["hestia_error_code" => "E_NOTEXIST"], $envelope["error"]["details"]);
		assertTrue(strpos(json_encode($envelope), "something went wrong") === false, "raw errorMessage/stderr text must never appear in the envelope");
	}

	public static function testErrorDetailsNullWhenAbsent(): void {
		$result = self::baseResult("adapter_error", null, null, "AUTHORIZATION_DENIED", "not_attempted");
		[, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(null, $envelope["error"]["details"]);
	}

	public static function testDataNullOnFailure(): void {
		$result = self::baseResult("adapter_error", null, null, "AUTHORIZATION_DENIED", "not_attempted", ["should" => "never appear"]);
		[, $envelope] = ResponseMapper::fromAdapterResult($result);

		assertEquals(null, $envelope["data"], "data must be null on every failure envelope, even if AdapterResult carried a parsedOutput");
	}

	public static function testMetaAlwaysPopulated(): void {
		$success = self::baseResult("ok", 0, null, null, null, []);
		[, $successEnvelope] = ResponseMapper::fromAdapterResult($success);
		assertEquals("domain.get", $successEnvelope["meta"]["operation"]);
		assertEquals("cmd-123", $successEnvelope["meta"]["command_id"]);

		$failure = self::baseResult("adapter_error", null, null, "AUTHORIZATION_DENIED", "not_attempted");
		[, $failureEnvelope] = ResponseMapper::fromAdapterResult($failure);
		assertEquals("domain.get", $failureEnvelope["meta"]["operation"]);
		assertEquals("cmd-123", $failureEnvelope["meta"]["command_id"]);
	}

	public static function testFromApiException(): void {
		$exception = new ApiException("AUTHENTICATION_FAILED", 401, "Authentication failed.");
		[$status, $envelope] = ResponseMapper::fromApiException("domain.get", $exception);

		assertEquals(401, $status);
		assertEquals("v2", $envelope["api_version"]);
		assertTrue($envelope["success"] === false);
		assertEquals("failed", $envelope["outcome"]);
		assertEquals(null, $envelope["data"]);
		assertEquals("AUTHENTICATION_FAILED", $envelope["error"]["code"]);
		assertEquals("Authentication failed.", $envelope["error"]["message"]);
		assertEquals("domain.get", $envelope["meta"]["operation"]);
		assertEquals(null, $envelope["meta"]["command_id"], "no AdapterResult ever existed for a pre-invoke() rejection");
	}

	public static function testSuccessFlagSemantics(): void {
		$ok = self::baseResult("ok", 0, null, null, null, []);
		[, $okEnvelope] = ResponseMapper::fromAdapterResult($ok);
		assertTrue($okEnvelope["success"] === true);

		$degraded = self::baseResult("hestia_error", 20, "E_RESTART", null, "confirmed_degraded");
		[, $degradedEnvelope] = ResponseMapper::fromAdapterResult($degraded);
		assertTrue($degradedEnvelope["success"] === true);

		$unknown = self::baseResult("hestia_error", 1, "E_ARGS", null, "unknown");
		[, $unknownEnvelope] = ResponseMapper::fromAdapterResult($unknown);
		assertTrue($unknownEnvelope["success"] === false, "unknown must never be reported as success");

		$failed = self::baseResult("adapter_error", null, null, "AUTHORIZATION_DENIED", "not_attempted");
		[, $failedEnvelope] = ResponseMapper::fromAdapterResult($failed);
		assertTrue($failedEnvelope["success"] === false);
	}
}
