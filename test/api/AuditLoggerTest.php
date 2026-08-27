<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\Test\FakeProcessRunner;
use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Adapter\Test\SpyLockManager;
use Hestiacp\Adapter\Test\ThrowingProcessRunner;
use Hestiacp\Api\AuditEvent;
use Hestiacp\Api\AuditTargetRedactor;
use Hestiacp\Api\ExecuteRequestHandler;
use Hestiacp\Api\FileAuditLogger;
use Hestiacp\Api\InMemoryRateLimitStore;
use Hestiacp\Api\RateLimiter;
use Hestiacp\Auth\AccessKeyValidator;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Sprint 6 dedicated audit-logging test suite, per
 * dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md §15. Uses
 * SpyAuditLogger (an in-process capture, never a real file) for every
 * assertion about event CONTENT, and a temp-directory-backed
 * FileAuditLogger only for the dedicated storage-mechanics tests
 * (concurrency, fail-open on a genuinely broken store) — never the
 * production credential store or the production audit path.
 */
final class AuditLoggerTest {
	/**
	 * @param array<string, string> $usersToSecrets
	 */
	private static function freshValidator(array $usersToSecrets): AccessKeyValidator {
		$dir = sys_get_temp_dir() . "/api-v2-audit-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);

		foreach ($usersToSecrets as $user => $secret) {
			$id = "key-" . $user;
			$record = json_encode(["user" => $user, "secret_hash" => password_hash($secret, PASSWORD_DEFAULT)]);
			file_put_contents($dir . $id, $record);
		}

		return new AccessKeyValidator($dir);
	}

	private static function basicHeader(string $user, string $secret): string {
		return "Basic " . base64_encode("key-" . $user . ":" . $secret);
	}

	private static function buildAdapter(\Hestiacp\Adapter\ProcessRunnerInterface $runner, ?SpyLockManager $lockManager = null) {
		return new \Hestiacp\Adapter\CommandAdapter(
			new \Hestiacp\Adapter\CommandRegistry(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			$lockManager ?? new SpyLockManager()
		);
	}

	/** Generous default rate limits so these tests never trip Sprint 5's own limiter. */
	private static function permissiveRateLimiter(): RateLimiter {
		return new RateLimiter(new InMemoryRateLimitStore(), 1000, 60, 1000, 60);
	}

	public static function register(MiniTest $t): void {
		$t->test("AL1. a successful request generates exactly one audit event", [self::class, "testSuccessGeneratesExactlyOneEvent"]);
		$t->test("AL2. a failed request generates exactly one audit event with the expected error code", [self::class, "testFailureGeneratesExpectedEvent"]);
		$t->test("AL3. authentication failure generates an audit event", [self::class, "testAuthFailureGeneratesEvent"]);
		$t->test("AL4. rate-limit rejection generates an audit event", [self::class, "testRateLimitedGeneratesEvent"]);
		$t->test("AL5. authorization denial generates an audit event", [self::class, "testAuthorizationDenialGeneratesEvent"]);
		$t->test("AL6. malformed JSON generates an audit event", [self::class, "testMalformedJsonGeneratesEvent"]);
		$t->test("AL7. an unknown operation generates an audit event and still records the attempted operation name", [self::class, "testUnknownOperationGeneratesEvent"]);
		$t->test("AL8. an unexpected internal exception generates an audit event", [self::class, "testUnexpectedExceptionGeneratesEvent"]);
		$t->test("AL9. every audit event carries a non-empty request id, and two requests never share one", [self::class, "testEventsCarryUniqueRequestId"]);
		$t->test("AL10. the authenticated user is recorded when available, and left null when not", [self::class, "testAuthenticatedUserRecordedWhenAvailable"]);
		$t->test("AL11. the credential secret never appears in any audit event", [self::class, "testSecretNeverInAuditEvent"]);
		$t->test("AL12. the raw Authorization header value never appears in any audit event", [self::class, "testAuthorizationHeaderNeverInAuditEvent"]);
		$t->test("AL13. database.create's password never appears in any audit event", [self::class, "testDatabaseCreatePasswordNeverInAuditEvent"]);
		$t->test("AL14. the raw request body never appears in any audit event", [self::class, "testRawBodyNeverInAuditEvent"]);
		$t->test("AL15. an audit-write failure never leaks a filesystem path or exception message into the API response", [self::class, "testAuditWriteFailureDoesNotLeakIntoResponse"]);
		$t->test("AL16. audit logging never causes a process to be spawned", [self::class, "testAuditLoggingNeverSpawnsAProcess"]);
		$t->test("AL17. audit logging never bypasses or influences authorization", [self::class, "testAuditLoggingNeverBypassesAuthorization"]);
		$t->test("AL18. audit logging never acquires the adapter lock", [self::class, "testAuditLoggingNeverAcquiresLock"]);
		$t->test("AL19. audit-write failure is fail-open: the API response is unaffected", [self::class, "testAuditWriteFailureIsFailOpen"]);
		$t->test("AL20. concurrent (sequential, same-process) audit writes never corrupt a record: every line is valid, independently parseable JSON", [self::class, "testConcurrentWritesDoNotCorruptRecords"]);
		$t->test("AL21. table-driven: all 13 required outcomes map to the documented event_type/outcome/http_status", [self::class, "testOutcomeTableMapsToExpectedEventType"]);
		$t->test("AL22. database.create's target never contains the password, but does contain user/database/dbuser", [self::class, "testDatabaseCreateTargetRedaction"]);
		$t->test("AL23. database.delete's target contains the normalized (prefixed) database identifier", [self::class, "testDatabaseDeleteTargetIsNormalized"]);
		$t->test("AL24. no target is recorded for a request that never reached a normalized-params stage", [self::class, "testNoTargetForPreParamsFailures"]);
	}

	public static function testSuccessGeneratesExactlyOneEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($audit->events));
		assertEquals("OPERATION_SUCCEEDED", $audit->events[0]->eventType);
		assertTrue($audit->events[0]->success);
	}

	public static function testFailureGeneratesExpectedEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(1, "", "not found"));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($audit->events));
		assertEquals("UPSTREAM_COMMAND_FAILED", $audit->events[0]->eventType);
		assertFalse($audit->events[0]->success);
	}

	public static function testAuthFailureGeneratesEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "wrong-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($audit->events));
		assertEquals("AUTHENTICATION_FAILED", $audit->events[0]->eventType);
		assertTrue($audit->events[0]->attemptedCredentialId === "key-alice", "the attempted (unvalidated) credential id must still be recorded");
		assertTrue($audit->events[0]->credentialId === null, "the validated credential_id must be null for a failed authentication");
	}

	public static function testRateLimitedGeneratesEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 1000, 60);
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter, $audit);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(2, count($audit->events));
		assertEquals("RATE_LIMITED", $audit->events[1]->eventType);
	}

	public static function testAuthorizationDenialGeneratesEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		// alice authenticates but targets bob's domain — SameUserAuthorizer
		// (CommandAdapter's own default, untouched) denies this.
		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($audit->events));
		assertEquals("AUTHORIZATION_DENIED", $audit->events[0]->eventType);
		assertEquals("alice", $audit->events[0]->user);
	}

	public static function testMalformedJsonGeneratesEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), "not valid json", "203.0.113.9");

		assertEquals(1, count($audit->events));
		assertEquals("MALFORMED_JSON", $audit->events[0]->eventType);
	}

	public static function testUnknownOperationGeneratesEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.destroy_everything", "params" => ["user" => "alice"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($audit->events));
		assertEquals("OPERATION_NOT_ALLOWED", $audit->events[0]->eventType);
		assertEquals("domain.destroy_everything", $audit->events[0]->operation, "the attempted operation name must still be recorded for audit purposes even though it was rejected");
	}

	public static function testUnexpectedExceptionGeneratesEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new ThrowingProcessRunner(new \RuntimeException("simulated unexpected failure with a fake /etc/secret path"));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($audit->events));
		assertEquals("INTERNAL_ERROR", $audit->events[0]->eventType);
	}

	public static function testEventsCarryUniqueRequestId(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(2, count($audit->events));
		assertTrue($audit->events[0]->requestId !== "", "request id must be non-empty");
		assertTrue($audit->events[0]->requestId !== $audit->events[1]->requestId, "two separate requests must never share a request id");
	}

	public static function testAuthenticatedUserRecordedWhenAvailable(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);
		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "wrong-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals("alice", $audit->events[0]->user, "user must be recorded once authenticated");
		assertTrue($audit->events[1]->user === null, "user must be null when authentication never succeeded");
	}

	public static function testSecretNeverInAuditEvent(): void {
		$validator = self::freshValidator(["alice" => "super-secret-value"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "super-secret-value"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		$serialized = json_encode($audit->events[0]->toArray());
		assertFalse(strpos($serialized, "super-secret-value") !== false);
	}

	public static function testAuthorizationHeaderNeverInAuditEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$header = self::basicHeader("alice", "alice-secret");
		$handler->handle(
			"POST",
			"application/json",
			$header,
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		$serialized = json_encode($audit->events[0]->toArray());
		assertFalse(strpos($serialized, $header) !== false, "the raw Authorization header value must never appear in an audit event");
		assertFalse(strpos($serialized, "Basic ") !== false, "the Basic auth scheme prefix must never appear in an audit event");
	}

	public static function testDatabaseCreatePasswordNeverInAuditEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "database.create", "params" => [
				"user" => "alice",
				"database" => "wp_db",
				"dbuser" => "alice_wp",
				"password" => "TopSecretDbPassword123!",
			]]),
			"203.0.113.9"
		);

		$serialized = json_encode($audit->events[0]->toArray());
		assertFalse(strpos($serialized, "TopSecretDbPassword123!") !== false);
	}

	public static function testRawBodyNeverInAuditEvent(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$marker = "UNIQUE_RAW_BODY_MARKER_998877";
		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "_marker" => $marker]),
			"203.0.113.9"
		);

		$serialized = json_encode($audit->events[0]->toArray());
		assertFalse(strpos($serialized, $marker) !== false, "the raw request body/its arbitrary fields must never leak into an audit event");
	}

	public static function testAuditWriteFailureDoesNotLeakIntoResponse(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$audit = new SpyAuditLogger();
		$audit->alwaysThrowOnWrite();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(200, $status);
		$serialized = json_encode($envelope);
		assertFalse(strpos($serialized, "simulated audit write failure") !== false);
		assertFalse(strpos($serialized, "AuditWriteException") !== false, "the audit exception's own class/message must never leak into the API response");
	}

	public static function testAuditLoggingNeverSpawnsAProcess(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($runner->calls), "exactly one process for the operation itself — audit logging spawns none");
	}

	public static function testAuditLoggingNeverBypassesAuthorization(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		[$status] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(403, $status, "authorization denial must still occur exactly as before — audit logging observes it, never influences it");
		assertEquals(0, count($runner->calls), "a denied request must still never reach the process runner");
	}

	public static function testAuditLoggingNeverAcquiresLock(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$lockManager = new SpyLockManager();
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner, $lockManager), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(1, count($lockManager->acquireCalls), "exactly one lock acquisition for the mutating operation itself — audit logging never acquires one of its own");
	}

	public static function testAuditWriteFailureIsFailOpen(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$audit = new SpyAuditLogger();
		$audit->alwaysThrowOnWrite();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.9"
		);

		assertEquals(200, $status, "a broken audit sink must never block or alter an otherwise-successful API response (fail-open)");
		assertTrue($envelope["success"] === true);
	}

	public static function testConcurrentWritesDoNotCorruptRecords(): void {
		$dir = sys_get_temp_dir() . "/api-v2-audit-store-test-" . bin2hex(random_bytes(8));
		mkdir($dir, 0700, true);
		$logger = new FileAuditLogger($dir);

		for ($i = 0; $i < 50; $i++) {
			$logger->write(new AuditEvent(
				gmdate("c"),
				"OPERATION_SUCCEEDED",
				"req-" . $i,
				null,
				"key-alice",
				"alice",
				"203.0.113.9",
				"domain.get",
				["user" => "alice", "domain" => "example.com"],
				200,
				"succeeded",
				true,
				null,
				null,
				5
			));
		}

		$lines = array_filter(explode("\n", file_get_contents($dir . "/audit.log")));
		assertEquals(50, count($lines), "every one of 50 sequential writes must produce exactly one line, none lost or merged");

		foreach ($lines as $index => $line) {
			$decoded = json_decode($line, true);
			assertTrue($decoded !== null && json_last_error() === JSON_ERROR_NONE, "line $index must be independently valid JSON, never a partial/corrupted write");
		}
	}

	public static function testOutcomeTableMapsToExpectedEventType(): void {
		$rows = [
			["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(1, "", "not found"), "expectEventType" => "UPSTREAM_COMMAND_FAILED", "expectHttp" => 422],
			["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => "not json", "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "MALFORMED_JSON", "expectHttp" => 400],
			["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "header" => "Basic not-base64!!!", "body_override" => null, "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "AUTHENTICATION_FAILED", "expectHttp" => 401],
			["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "wrong-secret"), "body_override" => null, "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "AUTHENTICATION_FAILED", "expectHttp" => 401],
			["operation" => "domain.destroy_everything", "params" => ["user" => "alice"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "OPERATION_NOT_ALLOWED", "expectHttp" => 404],
			["operation" => "domain.get", "params" => ["user" => "alice"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "VALIDATION_FAILED", "expectHttp" => 422],
			["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "AUTHORIZATION_DENIED", "expectHttp" => 403],
			["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""), "expectEventType" => "OPERATION_SUCCEEDED", "expectHttp" => 200],
			["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(0, "{}", ""), "expectEventType" => "OPERATION_SUCCEEDED", "expectHttp" => 200],
			["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(20, "", "Restart failed"), "expectEventType" => "OPERATION_SUCCEEDED", "expectHttp" => 200],
			["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"], "header" => self::basicHeader("alice", "alice-secret"), "body_override" => null, "process" => new ProcessResult(1, "", "hard failure"), "expectEventType" => "UPSTREAM_COMMAND_FAILED", "expectHttp" => 422],
		];

		$validator = self::freshValidator(["alice" => "alice-secret"]);

		foreach ($rows as $i => $row) {
			$runner = new FakeProcessRunner($row["process"]);
			$audit = new SpyAuditLogger();
			$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

			$body = $row["body_override"] ?? json_encode(["operation" => $row["operation"], "params" => $row["params"]]);
			[$status] = $handler->handle("POST", "application/json", $row["header"], $body, "203.0.113." . (100 + $i));

			assertEquals($row["expectHttp"], $status, "row $i http status");
			assertEquals(1, count($audit->events), "row $i must produce exactly one audit event");
			assertEquals($row["expectEventType"], $audit->events[0]->eventType, "row $i event_type");
		}

		// Rate-limited and unexpected-exception rows are covered by their
		// own dedicated tests above (testRateLimitedGeneratesEvent,
		// testUnexpectedExceptionGeneratesEvent) rather than duplicated
		// into this table, since both need bespoke collaborators (a
		// tiny limit, a throwing runner) that don't fit this table's
		// shared shape.
	}

	public static function testDatabaseCreateTargetRedaction(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "database.create", "params" => [
				"user" => "alice",
				"database" => "wp_db",
				"dbuser" => "alice_wp",
				"password" => "TopSecretDbPassword123!",
			]]),
			"203.0.113.9"
		);

		$target = $audit->events[0]->target;
		assertEquals(["user" => "alice", "database" => "wp_db", "dbuser" => "alice_wp"], $target);
		assertFalse(array_key_exists("password", $target), "target must never contain the password key at all, not even redacted");
	}

	public static function testDatabaseDeleteTargetIsNormalized(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "database.delete", "params" => ["user" => "alice", "database" => "wp_db"]]),
			"203.0.113.9"
		);

		assertEquals(["user" => "alice", "database" => "alice_wp_db"], $audit->events[0]->target);
	}

	public static function testNoTargetForPreParamsFailures(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$audit = new SpyAuditLogger();
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, self::permissiveRateLimiter(), $audit);

		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), "not valid json", "203.0.113.9");

		assertTrue($audit->events[0]->target === null);
	}
}
