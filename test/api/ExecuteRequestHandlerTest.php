<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\ProcessRunnerInterface;
use Hestiacp\Adapter\Test\FakeProcessRunner;
use Hestiacp\Adapter\Test\MiniTest;
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

	private static function buildAdapter(ProcessRunnerInterface $runner, ?CommandRegistry $registry = null): CommandAdapter {
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
			}
			// LockManager and Authorizer both left at their real defaults
			// (LockManager, SameUserAuthorizer) — this suite verifies
			// this class does not bypass or duplicate either.
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
		// (default) allowlist — proves the allowlist gate, not mere
		// CommandRegistry membership, decides public reachability.
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "database.create", "params" => ["user" => "alice", "database" => "db1", "dbuser" => "u1", "password" => "x"]])
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
}
