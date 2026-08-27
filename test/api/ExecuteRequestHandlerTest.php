<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\AuthorizerInterface;
use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockManagerInterface;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\ProcessRunnerInterface;
use Hestiacp\Adapter\Test\FakeProcessRunner;
use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Adapter\Test\SpyAuthorizer;
use Hestiacp\Adapter\Test\SpyLockManager;
use Hestiacp\Adapter\Test\ThrowingProcessRunner;
use Hestiacp\Api\ExecuteRequestHandler;
use Hestiacp\Auth\AccessKeyValidator;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * End-to-end tests for POST /api/v2/execute's real request pipeline
 * (ExecuteRequestHandler), per Sprint 2's own required test list and
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md. No real HTTP server,
 * no real Hestia installation: AccessKeyValidator uses a fresh temp
 * credential directory per test (matching AccessKeyValidatorTest's own
 * convention) and CommandAdapter uses FakeProcessRunner (matching every
 * existing test/adapter/*Test.php file's own convention) — the ONLY
 * operation exercised through the real, unmodified CommandRegistry is
 * "domain.get", Sprint 2's sole allowlisted proving-ground operation;
 * "test.mutate" (used only in the lock-acquisition test) is registered
 * via CommandRegistry's own pre-existing test-only extension point and
 * allowlisted only for that one test via
 * ExecuteRequestHandler's own equivalent test-only constructor
 * parameter — never through production wiring.
 */
final class ExecuteRequestHandlerTest {
	/**
	 * @param array<string, string> $usersToSecrets
	 * @return array{0: string, 1: AccessKeyValidator}
	 */
	private static function freshValidator(array $usersToSecrets): array {
		$dir = sys_get_temp_dir() . "/api-v2-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);

		foreach ($usersToSecrets as $user => $secret) {
			$id = "key-" . $user;
			$record = json_encode(["user" => $user, "secret_hash" => password_hash($secret, PASSWORD_DEFAULT)]);
			file_put_contents($dir . $id, $record);
		}

		return [$dir, new AccessKeyValidator($dir)];
	}

	private static function basicHeader(string $user, string $secret): string {
		return "Basic " . base64_encode("key-" . $user . ":" . $secret);
	}

	private static function buildAdapter(
		ProcessRunnerInterface $runner,
		?CommandRegistry $registry = null,
		?LockManagerInterface $lockManager = null,
		?AuthorizerInterface $authorizer = null
	): CommandAdapter {
		return new CommandAdapter(
			$registry ?? new CommandRegistry(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			$lockManager ?? new SpyLockManager(),
			$authorizer
		);
	}


	public static function register(MiniTest $t): void {
		// Authentication
		$t->test("1. valid credentials authenticate -> request proceeds", [self::class, "testValidCredentialsAuthenticate"]);
		$t->test("2. invalid credential ID -> AUTHENTICATION_FAILED", [self::class, "testInvalidCredentialId"]);
		$t->test("3. invalid secret -> AUTHENTICATION_FAILED", [self::class, "testInvalidSecret"]);
		$t->test("4. malformed Basic header -> AUTHENTICATION_FAILED", [self::class, "testMalformedBasicHeader"]);
		$t->test("5. missing Authorization header -> AUTHENTICATION_FAILED", [self::class, "testMissingAuthorizationHeader"]);
		$t->test("6. authentication failure does not reveal credential existence", [self::class, "testAuthFailureUniform"]);
		$t->test("7. authentication failure does not invoke CommandAdapter", [self::class, "testAuthFailureNoAdapterInvocation"]);

		// HTTP contract
		$t->test("8. POST accepted", [self::class, "testPostAccepted"]);
		$t->test("9. wrong HTTP method rejected", [self::class, "testWrongMethodRejected"]);
		$t->test("10. malformed JSON rejected", [self::class, "testMalformedJsonRejected"]);
		$t->test("11. missing operation rejected", [self::class, "testMissingOperationRejected"]);
		$t->test("12. unknown/non-allowlisted operation rejected", [self::class, "testUnknownOperationRejected"]);
		$t->test("13. invalid params rejected (adapter validation passthrough)", [self::class, "testInvalidParamsRejected"]);
		$t->test("14. caller-supplied actor field rejected", [self::class, "testCallerSuppliedActorRejected"]);
		$t->test("15. caller-supplied actor.acting_as rejected", [self::class, "testCallerSuppliedActingAsRejected"]);
		$t->test("wrong Content-Type rejected", [self::class, "testWrongContentTypeRejected"]);
		$t->test("params must be a JSON object, not a JSON array", [self::class, "testParamsMustBeObject"]);
		$t->test("null-valued params field treated as absent", [self::class, "testNullParamTreatedAsAbsent"]);

		// Authorization
		$t->test("16. authenticated user becomes actor.user (mismatched target denied)", [self::class, "testAuthenticatedUserBecomesActor"]);
		$t->test("17. params.user has zero effect on actor.user", [self::class, "testParamsUserCannotOverrideActor"]);
		$t->test("18. SameUserAuthorizer receives the authenticated user (matching target allowed)", [self::class, "testSameUserAuthorizerReceivesAuthenticatedUser"]);
		$t->test("19. authorization denial prevents execution", [self::class, "testAuthorizationDenialPreventsExecution"]);
		$t->test("20. authorization denial prevents lock acquisition", [self::class, "testAuthorizationDenialPreventsLockAcquisition"]);

		// Execution
		$t->test("21. valid request reaches CommandAdapter exactly once", [self::class, "testValidRequestReachesAdapterOnce"]);
		$t->test("23. operation allowlist cannot be bypassed by a real, registered adapter operation", [self::class, "testAllowlistCannotBeBypassed"]);
		$t->test("24. arbitrary script names cannot be supplied as an operation", [self::class, "testArbitraryScriptNameRejected"]);
		$t->test("25. arbitrary/shell-metacharacter-laden operation strings cannot be supplied", [self::class, "testShellMetacharacterOperationRejected"]);

		// Response
		$t->test("26. successful AdapterResult becomes correct API success envelope", [self::class, "testSuccessEnvelopeShape"]);
		$t->test("27. adapter validation failure becomes correct API error", [self::class, "testAdapterValidationFailureEnvelope"]);
		$t->test("28. adapter authorization failure becomes correct API error", [self::class, "testAdapterAuthorizationFailureEnvelope"]);
		$t->test("29. an unexpected internal exception does not leak implementation details", [self::class, "testUnexpectedExceptionDoesNotLeak"]);
		$t->test("30. sensitive values (the secret used to authenticate) never appear in the response", [self::class, "testSecretNeverInResponse"]);

		// Sprint 3 — operation exposure
		$t->test("31. domain.list operation returns collection JSON data", [self::class, "testDomainListOperation"]);
		$t->test("32. domain.create operation success acquires lock and returns succeeded", [self::class, "testDomainCreateOperationSuccess"]);
		$t->test("33. domain.create operation degraded (E_RESTART) returns succeeded_with_warning", [self::class, "testDomainCreateOperationDegraded"]);
		$t->test("34. domain.delete operation success acquires lock and returns succeeded", [self::class, "testDomainDeleteOperationSuccess"]);
		$t->test("35. domain.delete operation degraded (E_RESTART) returns succeeded_with_warning", [self::class, "testDomainDeleteOperationDegraded"]);
		$t->test("36. database.create delivers password via temp file and protects secret", [self::class, "testDatabaseCreateOperationAndSensitivePassword"]);
		$t->test("37. database.delete normalizes raw database suffix to prefixed name in argv and lock", [self::class, "testDatabaseDeleteOperationNormalized"]);
		$t->test("38. database.delete rejects already-prefixed database identifier", [self::class, "testDatabaseDeleteRejectsAlreadyPrefixed"]);
		$t->test("39. database.delete sends normalized identifier to authorizer and target", [self::class, "testDatabaseDeleteSendsNormalizedTargetToAuthorizer"]);
		$t->test("40. backup.schedule operation success returns succeeded (queued)", [self::class, "testBackupScheduleOperationSuccess"]);
		$t->test("41. backup.schedule E_EXISTS produces mutationState=unknown / 207 (not failed)", [self::class, "testBackupScheduleAlreadyScheduledUnknown"]);
		$t->test("42. table-driven: SameUserAuthorizer denies cross-user access across all 7 operations", [self::class, "testCrossUserAccessDeniedAcrossOperations"]);
		$t->test("43. database.delete does NOT reject a foreign-looking (non-self) prefix; normalizes it as a raw suffix", [self::class, "testDatabaseDeleteForeignLookingSuffixNormalizedNotRejected"]);
		$t->test("44. an embedded username-shaped segment in the database identifier can never become actor.user or target.user", [self::class, "testDatabaseIdentifierCannotInfluenceActorOrTarget"]);
		$t->test("45. authorization denial for database.create creates no sensitive temp file and leaks no password", [self::class, "testDatabaseCreateAuthorizationDenialCreatesNoTempFileAndNoPasswordLeak"]);
		$t->test("46. unknown parameter name for an operation is rejected by the API-owned contract", [self::class, "testUnknownParameterNameRejectedByContract"]);
		$t->test("47. missing required parameter name for an operation is rejected by the API-owned contract", [self::class, "testMissingParameterNameRejectedByContract"]);

		// Sprint 4 — HTTP hardening & error semantics
		$t->test("48. empty request body is MALFORMED_JSON", [self::class, "testEmptyBodyRejected"]);
		$t->test("49. bare JSON scalar body is VALIDATION_FAILED, not MALFORMED_JSON", [self::class, "testJsonScalarBodyRejected"]);
		$t->test("50. literal JSON null body is VALIDATION_FAILED, not MALFORMED_JSON", [self::class, "testJsonNullBodyRejected"]);
		$t->test("51. top-level JSON array body is VALIDATION_FAILED", [self::class, "testJsonArrayBodyRejected"]);
		$t->test("52. oversized request body is rejected before JSON parsing (413 PAYLOAD_TOO_LARGE)", [self::class, "testOversizedBodyRejected"]);
		$t->test("53. missing Content-Type header is rejected the same as a wrong one", [self::class, "testMissingContentTypeRejected"]);
		$t->test("54. table-driven: every authentication failure reason produces an identical 401 AUTHENTICATION_FAILED envelope, including a revoked credential", [self::class, "testAuthenticationFailureUniformityTable"]);
		$t->test("55. every response, success or failure, carries Content-Type-appropriate, self-consistent envelope fields (response shape stability)", [self::class, "testResponseEnvelopeShapeStabilityAcrossOutcomes"]);
		$t->test("56. a read operation's genuine hestia_error (not degraded, not unknown) maps to outcome=failed / 422 / UPSTREAM_COMMAND_FAILED, never silently to 200", [self::class, "testReadOperationGenuineFailureOutcome"]);
		$t->test("57. an unexpected exception thrown mid-pipeline never leaks the operation's own parameters (e.g. a password) in the sanitized response", [self::class, "testUnexpectedExceptionDuringMutatingOperationDoesNotLeakParams"]);
	}

	public static function testValidCredentialsAuthenticate(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals(1, count($runner->calls), "a valid, authenticated, authorized request must reach CommandAdapter exactly once");
	}

	public static function testInvalidCredentialId(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			"Basic " . base64_encode("key-does-not-exist:whatever"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(401, $status);
		assertEquals("AUTHENTICATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testInvalidSecret(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "wrong-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(401, $status);
		assertEquals("AUTHENTICATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testMalformedBasicHeader(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		$attempts = [
			"NotBasic abc123",
			"Basic not-valid-base64!!!",
			"Basic " . base64_encode("no-colon-at-all"),
			"Basic " . base64_encode(":secret-with-empty-id"),
			"Basic",
		];

		foreach ($attempts as $header) {
			[$status, $envelope] = $handler->handle(
				"POST",
				"application/json",
				$header,
				json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
			);
			assertEquals(401, $status, "status for header: " . $header);
			assertEquals("AUTHENTICATION_FAILED", $envelope["error"]["code"], "error code for header: " . $header);
		}
	}

	public static function testMissingAuthorizationHeader(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			null,
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(401, $status);
		assertEquals("AUTHENTICATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testAuthFailureUniform(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);

		[$unknownStatus, $unknownEnvelope] = $handler->handle(
			"POST",
			"application/json",
			"Basic " . base64_encode("key-does-not-exist:whatever"),
			$body
		);
		[$wrongSecretStatus, $wrongSecretEnvelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "wrong-secret"),
			$body
		);

		assertEquals($unknownStatus, $wrongSecretStatus, "unknown-id and wrong-secret must produce the identical HTTP status");
		assertEquals($unknownEnvelope, $wrongSecretEnvelope, "unknown-id and wrong-secret must produce a byte-identical envelope — no existence disclosure");
	}

	public static function testAuthFailureNoAdapterInvocation(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "wrong-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(0, count($runner->calls), "an authentication failure must never reach the process runner");
	}

	public static function testPostAccepted(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
	}

	public static function testWrongMethodRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		foreach (["GET", "PUT", "DELETE", "PATCH"] as $method) {
			[$status, $envelope] = $handler->handle(
				$method,
				"application/json",
				self::basicHeader("alice", "alice-secret"),
				json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
			);
			assertEquals(405, $status, "method: $method");
			assertEquals("METHOD_NOT_ALLOWED", $envelope["error"]["code"], "method: $method");
		}
		assertEquals(0, count($runner->calls), "no non-POST method should ever reach CommandAdapter");
	}

	public static function testMalformedJsonRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			"{not valid json"
		);

		assertEquals(400, $status);
		assertEquals("MALFORMED_JSON", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testMissingOperationRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["params" => ["user" => "alice"]])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testUnknownOperationRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.rename", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(404, $status);
		assertEquals("OPERATION_NOT_ALLOWED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testInvalidParamsRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// "domain" is a required parameter for domain.get — omitted here,
		// so CommandAdapter's own MISSING_PARAMETER must surface as the
		// public VALIDATION_FAILED code.
		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice"]])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls), "a request CommandAdapter itself rejects must never spawn a process");
	}

	public static function testCallerSuppliedActorRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "domain.get",
				"params" => ["user" => "alice", "domain" => "example.com"],
				"actor" => ["user" => "root"],
			])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testCallerSuppliedActingAsRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "domain.get",
				"params" => ["user" => "alice", "domain" => "example.com"],
				"actor" => ["acting_as" => "root"],
			])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testWrongContentTypeRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/x-www-form-urlencoded",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(415, $status);
		assertEquals("UNSUPPORTED_MEDIA_TYPE", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testParamsMustBeObject(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			'{"operation":"domain.get","params":["alice","example.com"]}'
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testNullParamTreatedAsAbsent(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// "domain" is required; supplying it as JSON null must behave
		// exactly like omitting it -> MISSING_PARAMETER -> VALIDATION_FAILED,
		// not a type-validation failure against a literal null value.
		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => null]])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
	}

	public static function testAuthenticatedUserBecomesActor(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// Authenticated as alice, but the target ("user" param) is bob —
		// SameUserAuthorizer must deny, proving actor.user really is
		// "alice" (an actor of "bob" would have matched and succeeded).
		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]])
		);

		assertEquals(403, $status);
		assertEquals("AUTHORIZATION_DENIED", $envelope["error"]["code"]);
	}

	public static function testParamsUserCannotOverrideActor(): void {
		// Identical scenario to the above, phrased as its own explicit
		// acceptance-criterion test (API_V2_HTTP_CONTRACT_DESIGN.md §22
		// item 3): a params.user value different from the authenticated
		// identity has ZERO effect on actor.user — it is simply the
		// operation's own "target" parameter, evaluated by
		// SameUserAuthorizer exactly as it would be for any other
		// mismatched target.
		self::testAuthenticatedUserBecomesActor();
	}

	public static function testSameUserAuthorizerReceivesAuthenticatedUser(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true, "a target matching the authenticated actor must be allowed");
	}

	public static function testAuthorizationDenialPreventsExecution(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]])
		);

		assertEquals(0, count($runner->calls), "a denied authorization must never spawn a process");
	}

	public static function testAuthorizationDenialPreventsLockAcquisition(): void {
		// domain.get itself is read-only and never acquires a lock
		// regardless of outcome, so this invariant is exercised here
		// against a synthetic MUTATING operation, registered only via
		// CommandRegistry's own pre-existing test-only extension point
		// (web/inc/adapter/CommandRegistry.php's own $additionalOperations
		// parameter) and allowlisted only for this one test via
		// ExecuteRequestHandler's equivalent test-only constructor
		// parameter — production (web/api/v2/index.php) never does
		// either.
		$registry = new CommandRegistry([
			"test.mutate" => [
				"script" => "v-test-mutate",
				"argument_order" => ["user"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
		]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$adapter = new CommandAdapter($registry, $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);

		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$handler = new ExecuteRequestHandler($validator, $adapter, ["test.mutate"]);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "test.mutate", "params" => ["user" => "bob"]])
		);

		assertEquals(403, $status);
		assertEquals("AUTHORIZATION_DENIED", $envelope["error"]["code"]);
		assertEquals(0, count($lockManager->acquireCalls), "a denied authorization must never acquire the per-user lock");
		assertEquals(0, count($runner->calls), "a denied authorization must never spawn a process");
	}

	public static function testValidRequestReachesAdapterOnce(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(1, count($runner->calls));
	}

	public static function testAllowlistCannotBeBypassed(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		// A real, registered CommandRegistry operation NOT in the
		// (production) allowlist — proves the allowlist gate, not mere
		// CommandRegistry membership, decides public reachability.
		$registry = new CommandRegistry([
			"test.unlisted" => [
				"script" => "v-test-unlisted",
				"argument_order" => ["user"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "read"],
			],
		]);
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner, $registry));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "test.unlisted", "params" => ["user" => "alice"]])
		);

		assertEquals(404, $status);
		assertEquals("OPERATION_NOT_ALLOWED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}


	public static function testArbitraryScriptNameRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "v-add-web-domain", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(404, $status);
		assertEquals("OPERATION_NOT_ALLOWED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testShellMetacharacterOperationRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		$attempts = ["; rm -rf /", "domain.get; whoami", "\$(id)", "domain.get`whoami`", "/bin/bash"];

		foreach ($attempts as $operation) {
			[$status, $envelope] = $handler->handle(
				"POST",
				"application/json",
				self::basicHeader("alice", "alice-secret"),
				json_encode(["operation" => $operation, "params" => ["user" => "alice"]])
			);
			assertEquals(404, $status, "operation payload: " . json_encode($operation));
			assertEquals("OPERATION_NOT_ALLOWED", $envelope["error"]["code"], "operation payload: " . json_encode($operation));
		}
		assertEquals(0, count($runner->calls));
	}

	public static function testSuccessEnvelopeShape(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertEquals("v2", $envelope["api_version"]);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertEquals(["example.com" => ["IP" => "203.0.113.5"]], $envelope["data"]);
		assertEquals(null, $envelope["error"]);
		assertEquals("domain.get", $envelope["meta"]["operation"]);
		assertTrue(is_string($envelope["meta"]["command_id"]) && $envelope["meta"]["command_id"] !== "");
	}

	public static function testAdapterValidationFailureEnvelope(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice"]])
		);

		assertEquals(422, $status);
		assertTrue($envelope["success"] === false);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertTrue(is_string($envelope["error"]["message"]) && $envelope["error"]["message"] !== "");
	}

	public static function testAdapterAuthorizationFailureEnvelope(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]])
		);

		assertEquals(403, $status);
		assertEquals("AUTHORIZATION_DENIED", $envelope["error"]["code"]);
	}

	public static function testUnexpectedExceptionDoesNotLeak(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$secretPath = "/var/secret/internal-path-should-never-leak";
		$runner = new ThrowingProcessRunner(new \RuntimeException("boom at $secretPath"));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(500, $status);
		assertEquals("INTERNAL_ERROR", $envelope["error"]["code"]);
		assertFalse(strpos(json_encode($envelope), $secretPath) !== false, "the raw exception message/path must never appear in the response");
		assertFalse(strpos(json_encode($envelope), "RuntimeException") !== false, "the exception class name must never appear in the response");
	}

	public static function testSecretNeverInResponse(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret-value-xyz"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[, $wrongSecretEnvelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "wrong-guess-of-secret-value-xyz"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);
		[, $successEnvelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret-value-xyz"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertFalse(strpos(json_encode($wrongSecretEnvelope), "alice-secret-value-xyz") !== false, "the real secret must never appear in a failed-auth response");
		assertFalse(strpos(json_encode($successEnvelope), "alice-secret-value-xyz") !== false, "the real secret must never appear in a successful response either");
	}

	// ── Sprint 3 tests ─────────────────────────────────────────────

	public static function testDomainListOperation(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"1.2.3.4"},"test.org":{"IP":"5.6.7.8"}}', ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.list", "params" => ["user" => "alice"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertTrue(is_array($envelope["data"]));
		assertTrue(isset($envelope["data"]["example.com"]));
	}

	public static function testDomainCreateOperationSuccess(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertEquals(1, count($runner->calls));
		assertEquals(1, count($lockManager->acquireCalls));
		assertEquals("alice", $lockManager->acquireCalls[0]);
	}

	public static function testDomainCreateOperationDegraded(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		// Exit code 20 = E_RESTART, which domain.create declares as a known_post_mutation_exit_code
		$runner = new FakeProcessRunner(new ProcessResult(20, "", "Restart failed"));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded_with_warning", $envelope["outcome"]);
	}

	public static function testDomainDeleteOperationSuccess(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.delete", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertEquals(1, count($runner->calls));
		assertEquals(1, count($lockManager->acquireCalls));
	}

	public static function testDomainDeleteOperationDegraded(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(20, "", "Restart failed"));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.delete", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded_with_warning", $envelope["outcome"]);
	}

	public static function testDatabaseCreateOperationAndSensitivePassword(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$plainPassword = "Super_Secret_P@ssw0rd_" . bin2hex(random_bytes(4));
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$spyAuth = new SpyAuthorizer(true);
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager, $spyAuth);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "database.create",
				"params" => [
					"user" => "alice",
					"database" => "wordpress_db",
					"dbuser" => "wp_user",
					"password" => $plainPassword,
				],
			])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertEquals(1, count($runner->calls));
		assertEquals(1, count($lockManager->acquireCalls));
		assertEquals("alice", $lockManager->acquireCalls[0]);

		// Verify runner argv: password was delivered via temp file, NOT plaintext in argv
		$argv = $runner->calls[0]["argv"];
		assertFalse(in_array($plainPassword, $argv, true), "plaintext password must never appear in argv");
		$passwordArg = $argv[4];
		assertTrue(strpos($passwordArg, "/tmp/") === 0, "password arg must be a /tmp/ file path, got: " . $passwordArg);

		// Verify password is absent from authorizer target
		assertEquals(1, count($spyAuth->calls));
		$authTarget = $spyAuth->calls[0]["target"];
		assertFalse(in_array($plainPassword, $authTarget, true), "password must not appear in authorizer target");
		assertFalse(array_key_exists("password", $authTarget), "password key must not exist in authorizer target");

		// Verify password does not appear in response
		assertFalse(strpos(json_encode($envelope), $plainPassword) !== false, "password must not appear in success envelope");
	}

	public static function testDatabaseDeleteOperationNormalized(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "database.delete",
				"params" => [
					"user" => "alice",
					"database" => "wordpress_db",
				],
			])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertEquals(1, count($runner->calls));
		assertEquals(1, count($lockManager->acquireCalls));
		assertEquals("alice", $lockManager->acquireCalls[0]);

		// Verify runner argv received the normalized "alice_wordpress_db"
		$argv = $runner->calls[0]["argv"];
		assertEquals("alice", $argv[1]);
		assertEquals("alice_wordpress_db", $argv[2]);
	}

	public static function testDatabaseDeleteRejectsAlreadyPrefixed(): void {
		[, $validator] = self::freshValidator(["admin" => "admin-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// Already-prefixed: user=admin, database=admin_wordpress_db -> rejected
		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("admin", "admin-secret"),
			json_encode([
				"operation" => "database.delete",
				"params" => [
					"user" => "admin",
					"database" => "admin_wordpress_db",
				],
			])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls), "rejected normalization must not execute process");
	}

	public static function testDatabaseDeleteSendsNormalizedTargetToAuthorizer(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$spyAuth = new SpyAuthorizer(true);
		$lockManager = new SpyLockManager();
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager, $spyAuth);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "database.delete",
				"params" => [
					"user" => "alice",
					"database" => "wordpress_db",
				],
			])
		);

		assertEquals(200, $status);
		assertEquals(1, count($spyAuth->calls));

		// The authorizer must receive the NORMALIZED database identifier
		$authTarget = $spyAuth->calls[0]["target"];
		assertEquals("alice", $authTarget["user"]);
		assertEquals("alice_wordpress_db", $authTarget["database"]);

		// And the actor must come from authentication, not params
		$authActor = $spyAuth->calls[0]["actor"];
		assertEquals("alice", $authActor["user"]);
	}

	public static function testBackupScheduleOperationSuccess(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "backup.schedule", "params" => ["user" => "alice"]])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true);
		assertEquals("succeeded", $envelope["outcome"]);
		assertEquals(1, count($runner->calls));
		assertEquals(1, count($lockManager->acquireCalls));
	}

	public static function testBackupScheduleAlreadyScheduledUnknown(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		// Exit code 4 = E_EXISTS. backup.schedule has NO known_post_mutation_exit_codes,
		// so CommandAdapter classifies mutationState as "unknown" for all non-zero exits.
		// ResponseMapper maps hestia_error + mutationState=unknown to HTTP 207 / UNKNOWN_OUTCOME.
		// NOTE: semantically E_EXISTS fires *before* the mutation (queue append) in
		// v-schedule-user-backup, but the adapter cannot distinguish pre- from post-mutation
		// exit codes without known_post_mutation_exit_codes being declared. Sprint 3 does
		// not alter these semantics; the honest classification is "unknown".
		$runner = new FakeProcessRunner(new ProcessResult(4, "", "Backup already scheduled"));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "backup.schedule", "params" => ["user" => "alice"]])
		);

		assertEquals(207, $status);
		assertTrue($envelope["success"] === false);
		assertEquals("unknown", $envelope["outcome"]);
		assertEquals("UNKNOWN_OUTCOME", $envelope["error"]["code"]);
		assertEquals(["hestia_error_code" => "E_EXISTS"], $envelope["error"]["details"]);
	}

	public static function testCrossUserAccessDeniedAcrossOperations(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);

		// Table-driven: every exposed operation with cross-user params
		$cases = [
			["domain.get", ["user" => "bob", "domain" => "example.com"]],
			["domain.list", ["user" => "bob"]],
			["domain.create", ["user" => "bob", "domain" => "example.com"]],
			["domain.delete", ["user" => "bob", "domain" => "example.com"]],
			["database.create", ["user" => "bob", "database" => "db1", "dbuser" => "u1", "password" => "pass"]],
			["database.delete", ["user" => "bob", "database" => "db1"]],
			["backup.schedule", ["user" => "bob"]],
		];

		foreach ($cases as [$op, $params]) {
			$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
			$lockManager = new SpyLockManager();
			$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);
			$handler = new ExecuteRequestHandler($validator, $adapter);

			[$status, $envelope] = $handler->handle(
				"POST",
				"application/json",
				self::basicHeader("alice", "alice-secret"),
				json_encode(["operation" => $op, "params" => $params])
			);

			assertEquals(403, $status, "cross-user access for $op must be 403");
			assertEquals("AUTHORIZATION_DENIED", $envelope["error"]["code"], "error code for $op");
			assertEquals(0, count($runner->calls), "denied $op must not execute process");
			assertEquals(0, count($lockManager->acquireCalls), "denied $op must not acquire lock");
		}
	}

	public static function testDatabaseDeleteForeignLookingSuffixNormalizedNotRejected(): void {
		[, $validator] = self::freshValidator(["admin" => "admin-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("admin", "admin-secret"),
			json_encode([
				"operation" => "database.delete",
				"params" => ["user" => "admin", "database" => "other_wordpress_db"],
			])
		);

		assertEquals(200, $status);
		assertTrue($envelope["success"] === true, "a database identifier merely resembling a foreign prefix must NOT be rejected");
		$argv = $runner->calls[0]["argv"];
		assertEquals("admin", $argv[1]);
		assertEquals("admin_other_wordpress_db", $argv[2], "the raw suffix must be prefixed with the REQUEST's own user, unconditionally");
	}

	public static function testDatabaseIdentifierCannotInfluenceActorOrTarget(): void {
		// Security regression for the case-(c) resolution: an embedded,
		// foreign-looking username inside the database identifier (here
		// "other_") must NEVER become actor.user or target.user — both
		// are derived exclusively from authentication (actor) and the
		// literal params.user value (target), never parsed out of the
		// database string.
		[, $validator] = self::freshValidator(["admin" => "admin-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$spyAuth = new SpyAuthorizer(true);
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, null, $spyAuth);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("admin", "admin-secret"),
			json_encode([
				"operation" => "database.delete",
				"params" => ["user" => "admin", "database" => "other_wordpress_db"],
			])
		);

		assertEquals(1, count($spyAuth->calls));
		assertEquals("admin", $spyAuth->calls[0]["actor"]["user"], "actor.user must remain 'admin', never parsed from the database identifier");
		assertEquals("admin", $spyAuth->calls[0]["target"]["user"], "target.user must remain 'admin', never parsed from the database identifier");
		assertEquals("admin_other_wordpress_db", $spyAuth->calls[0]["target"]["database"]);
	}

	public static function testDatabaseCreateAuthorizationDenialCreatesNoTempFileAndNoPasswordLeak(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$plainPassword = "Denied_Secret_" . bin2hex(random_bytes(4));
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		// Real (default) SameUserAuthorizer — actor "alice" vs target
		// "bob" must deny before any argv/temp-file construction is
		// ever attempted (CommandAdapter's own documented ordering:
		// authorize strictly before temp-file creation/lock/execute).
		$adapter = new CommandAdapter(new CommandRegistry(), $runner, "/usr/local/hestia/bin/", "/usr/bin/sudo", null, null, $lockManager);
		$handler = new ExecuteRequestHandler($validator, $adapter);

		$tempFilesBefore = glob(sys_get_temp_dir() . "/hstadapter*") ?: [];

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "database.create",
				"params" => ["user" => "bob", "database" => "db1", "dbuser" => "u1", "password" => $plainPassword],
			])
		);

		$tempFilesAfter = glob(sys_get_temp_dir() . "/hstadapter*") ?: [];

		assertEquals(403, $status);
		assertEquals("AUTHORIZATION_DENIED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls), "a denied database.create must never spawn a process");
		assertEquals(0, count($lockManager->acquireCalls), "a denied database.create must never acquire the lock");
		assertEquals(
			count($tempFilesBefore),
			count($tempFilesAfter),
			"a denied database.create must never create a sensitive temp file"
		);
		assertFalse(
			strpos(json_encode($envelope), $plainPassword) !== false,
			"the plaintext password must never appear anywhere in a denial envelope"
		);
	}

	public static function testUnknownParameterNameRejectedByContract(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// "dbuser" is a valid parameter name for a DIFFERENT operation
		// (database.create) but is not part of domain.get's own
		// declared public contract — proving the API-owned,
		// per-operation contract rejects it independently of whatever
		// CommandRegistry's own "domain.get" schema happens to allow.
		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "domain.get",
				"params" => ["user" => "alice", "domain" => "example.com", "dbuser" => "sneaky"],
			])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testMissingParameterNameRejectedByContract(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// "dbuser" and "password" are both required by database.create's
		// own public contract but are entirely absent here.
		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "database.create", "params" => ["user" => "alice", "database" => "db1"]])
		);

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	// ── Sprint 4 tests ─────────────────────────────────────────────

	public static function testEmptyBodyRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), "");

		assertEquals(400, $status);
		assertEquals("MALFORMED_JSON", $envelope["error"]["code"], "an empty body is a JSON syntax error, not a shape problem");
		assertEquals(0, count($runner->calls));
	}

	public static function testJsonScalarBodyRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		foreach (["42", '"a string"', "true"] as $scalarBody) {
			[$status, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $scalarBody);
			assertEquals(422, $status, "body: $scalarBody");
			assertEquals("VALIDATION_FAILED", $envelope["error"]["code"], "body: $scalarBody — a bare JSON scalar is syntactically valid JSON, so it must NOT be MALFORMED_JSON");
		}
		assertEquals(0, count($runner->calls));
	}

	public static function testJsonNullBodyRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), "null");

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"], "the literal JSON null is syntactically valid JSON, so it must NOT be MALFORMED_JSON");
		assertEquals(0, count($runner->calls));
	}

	public static function testJsonArrayBodyRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), '["domain.get", {"user":"alice"}]');

		assertEquals(422, $status);
		assertEquals("VALIDATION_FAILED", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testOversizedBodyRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		// Oversized but otherwise well-formed JSON — proves rejection
		// happens on raw byte length, strictly before JSON parsing is
		// even attempted (a body this large would also fail
		// downstream validation, but the point of this test is that it
		// never reaches that far).
		$oversizedDomain = str_repeat("a", 100000);
		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => $oversizedDomain]]);

		[$status, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body);

		assertEquals(413, $status);
		assertEquals("PAYLOAD_TOO_LARGE", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testMissingContentTypeRejected(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]])
		);

		assertEquals(415, $status);
		assertEquals("UNSUPPORTED_MEDIA_TYPE", $envelope["error"]["code"]);
		assertEquals(0, count($runner->calls));
	}

	public static function testAuthenticationFailureUniformityTable(): void {
		[$dir, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		// A second, real credential that will be revoked below, to
		// prove revocation produces the identical envelope too.
		$revokedId = "key-revoked-user";
		file_put_contents($dir . $revokedId, json_encode(["user" => "revoked-user", "secret_hash" => password_hash("revoked-secret", PASSWORD_DEFAULT)]));
		$revokedHeader = "Basic " . base64_encode($revokedId . ":revoked-secret");
		unlink($dir . $revokedId); // revoke: the credential worked a moment ago, now it does not

		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);

		$cases = [
			"missing header" => null,
			"malformed header (not Basic)" => "NotBasic abc123",
			"malformed header (bad base64)" => "Basic not-valid-base64!!!",
			"malformed header (no colon)" => "Basic " . base64_encode("no-colon-at-all"),
			"unknown credential id" => "Basic " . base64_encode("key-does-not-exist:whatever"),
			"wrong secret" => self::basicHeader("alice", "totally-wrong-secret"),
			"revoked credential" => $revokedHeader,
		];

		$results = [];
		foreach ($cases as $label => $header) {
			[$status, $envelope] = $handler->handle("POST", "application/json", $header, $body);
			assertEquals(401, $status, "status for: $label");
			assertEquals("AUTHENTICATION_FAILED", $envelope["error"]["code"], "error code for: $label");
			$results[$label] = $envelope;
		}

		$reference = $results["missing header"];
		foreach ($results as $label => $envelope) {
			assertEquals($reference, $envelope, "every authentication failure envelope must be byte-identical — case '$label' differed, which would disclose which failure reason occurred");
		}
		assertEquals(0, count($runner->calls), "no authentication failure of any kind may ever reach CommandAdapter");
	}

	public static function testResponseEnvelopeShapeStabilityAcrossOutcomes(): void {
		// Every envelope this endpoint ever returns — success or
		// failure — must carry exactly this fixed key set, nothing
		// more, nothing less (dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §13).
		$expectedKeys = ["api_version", "success", "outcome", "data", "error", "meta"];

		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);

		$scenarios = [
			"success" => static function () use ($validator) {
				$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]));
			},
			"warning" => static function () use ($validator) {
				$runner = new FakeProcessRunner(new ProcessResult(20, "", "Restart failed"));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), json_encode(["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"]]));
			},
			"unknown" => static function () use ($validator) {
				$runner = new FakeProcessRunner(new ProcessResult(4, "", "Backup already scheduled"));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), json_encode(["operation" => "backup.schedule", "params" => ["user" => "alice"]]));
			},
			"validation error" => static function () use ($validator) {
				$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), json_encode(["operation" => "domain.get", "params" => ["user" => "alice"]]));
			},
			"authentication error" => static function () use ($validator) {
				$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", null, json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]));
			},
			"authorization error" => static function () use ($validator) {
				$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]]));
			},
			"internal error" => static function () use ($validator) {
				$runner = new ThrowingProcessRunner(new \RuntimeException("boom"));
				$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));
				return $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]));
			},
		];

		foreach ($scenarios as $label => $run) {
			[, $envelope] = $run();
			$actualKeys = array_keys($envelope);
			sort($actualKeys);
			$expected = $expectedKeys;
			sort($expected);
			assertEquals($expected, $actualKeys, "envelope key set for scenario '$label'");
		}
	}

	public static function testReadOperationGenuineFailureOutcome(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		// Exit code 3 = E_NOTEXIST. domain.get is read-only
		// (mutationState is always null for it), so ResponseMapper's
		// dedicated read-failure branch applies: this must be a plain,
		// honest "failed" — never silently reported as a 200 success,
		// and never "unknown" (a read has no partial-mutation ambiguity
		// to preserve).
		$runner = new FakeProcessRunner(new ProcessResult(3, "", "Domain does not exist"));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "nonexistent.example"]])
		);

		assertEquals(422, $status);
		assertTrue($envelope["success"] === false);
		assertEquals("failed", $envelope["outcome"]);
		assertEquals("UPSTREAM_COMMAND_FAILED", $envelope["error"]["code"]);
		assertEquals(["hestia_error_code" => "E_NOTEXIST"], $envelope["error"]["details"]);
	}

	public static function testUnexpectedExceptionDuringMutatingOperationDoesNotLeakParams(): void {
		[, $validator] = self::freshValidator(["alice" => "alice-secret"]);
		$plainPassword = "Crash_Secret_" . bin2hex(random_bytes(4));
		$runner = new ThrowingProcessRunner(new \RuntimeException("process spawn failed for user alice with password $plainPassword"));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode([
				"operation" => "database.create",
				"params" => ["user" => "alice", "database" => "db1", "dbuser" => "u1", "password" => $plainPassword],
			])
		);

		assertEquals(500, $status);
		assertEquals("INTERNAL_ERROR", $envelope["error"]["code"]);
		$encoded = json_encode($envelope);
		assertFalse(strpos($encoded, $plainPassword) !== false, "the password must never leak through an unsanitized exception message");
		assertFalse(strpos($encoded, "RuntimeException") !== false);
	}
}
