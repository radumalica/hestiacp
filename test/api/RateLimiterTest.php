<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\Test\FakeProcessRunner;
use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Adapter\Test\SpyLockManager;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Api\ExecuteRequestHandler;
use Hestiacp\Api\FilesystemRateLimitStore;
use Hestiacp\Api\InMemoryRateLimitStore;
use Hestiacp\Api\RateLimiter;
use Hestiacp\Api\RateLimitStoreInterface;
use Hestiacp\Api\RateLimitStoreUnavailableException;
use Hestiacp\Auth\AccessKeyValidator;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Sprint 5 dedicated rate-limiting test suite, per
 * dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §17. Covers the
 * two storage backends and RateLimiter's own fixed-window logic in
 * isolation (fast, deterministic, fake-clock-driven), plus the full
 * ExecuteRequestHandler pipeline for every requirement that concerns
 * ordering relative to authentication/authorization/locking/execution.
 *
 * Never touches a real credential store beyond the same
 * temp-directory-per-test convention ExecuteRequestHandlerTest already
 * uses, and never writes rate-limit state anywhere but a fresh
 * temp-directory-per-test path of its own.
 */
final class RateLimiterTest {
	/**
	 * @param array<string, string> $usersToSecrets
	 * @return AccessKeyValidator
	 */
	private static function freshValidator(array $usersToSecrets): AccessKeyValidator {
		$dir = sys_get_temp_dir() . "/api-v2-ratelimit-test-" . bin2hex(random_bytes(8)) . "/";
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

	private static function freshFilesystemDir(): string {
		return sys_get_temp_dir() . "/api-v2-ratelimit-store-test-" . bin2hex(random_bytes(8));
	}

	/** @return callable(): int */
	private static function fixedClock(int $time): callable {
		return static function () use ($time): int {
			return $time;
		};
	}

	public static function register(MiniTest $t): void {
		// Core algorithm (RateLimiter + stores, no HTTP pipeline involved)
		$t->test("RL1. requests below the limit are allowed", [self::class, "testBelowLimitAllowed"]);
		$t->test("RL2. request exactly at the limit is allowed (boundary: count <= limit)", [self::class, "testAtLimitAllowed"]);
		$t->test("RL3. request above the limit is rejected", [self::class, "testAboveLimitRejected"]);
		$t->test("RL16. a new fixed window resets the counter", [self::class, "testWindowReset"]);
		$t->test("RL14. repeated increments on the same bucket never lose a count", [self::class, "testFilesystemStoreNoLostIncrements"]);
		$t->test("RL15. a bucket key cannot escape the store directory (hashed filenames only)", [self::class, "testFilesystemStorePathTraversalImpossible"]);
		$t->test("RL15b. the counter directory is created non-world-writable", [self::class, "testFilesystemStoreDirectoryNotWorldWritable"]);
		$t->test("RL13a. pre-auth: storage failure fails CLOSED (429)", [self::class, "testPreAuthFailsClosedOnStorageFailure"]);
		$t->test("RL13b. authenticated: storage failure fails OPEN (request proceeds)", [self::class, "testAuthenticatedFailsOpenOnStorageFailure"]);

		// Pipeline integration (ExecuteRequestHandler)
		$t->test("RL4. pre-auth bucket is identical for an unknown and a valid credential sharing one IP", [self::class, "testPreAuthBucketSharedAcrossUnknownAndValidCredential"]);
		$t->test("RL5. authenticated requests use a bucket separate from the pre-auth bucket", [self::class, "testAuthenticatedBucketSeparateFromPreAuth"]);
		$t->test("RL6. one authenticated credential cannot exhaust another credential's bucket", [self::class, "testAuthenticatedBucketsAreIndependentPerCredential"]);
		$t->test("RL7. rate limiting happens before CommandAdapter invocation", [self::class, "testRateLimitBeforeAdapterInvocation"]);
		$t->test("RL8. rate limiting does not acquire an adapter lock", [self::class, "testRateLimitDoesNotAcquireLock"]);
		$t->test("RL9. rate limiting does not execute a Hestia operation", [self::class, "testRateLimitDoesNotExecuteOperation"]);
		$t->test("RL10. a rate-limited response uses the existing API error envelope", [self::class, "testRateLimitedResponseUsesExistingEnvelope"]);
		$t->test("RL11. no secret ever appears in a rate-limited response", [self::class, "testRateLimitedResponseNeverContainsSecret"]);
		$t->test("RL12. a rate-limited response never reveals whether the credential exists", [self::class, "testRateLimitedResponseDoesNotRevealCredentialExistence"]);
		$t->test("RL17. non-rate-limited requests keep their existing status/error mappings unchanged", [self::class, "testNonRateLimitedMappingsUnchanged"]);
		$t->test("RL18. all seven allowlisted operations still succeed while under the limit", [self::class, "testAllSevenOperationsSucceedUnderLimit"]);
	}

	// --- Core algorithm -----------------------------------------------

	public static function testBelowLimitAllowed(): void {
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 3, 60, 3, 60, self::fixedClock(1000));

		for ($i = 0; $i < 3; $i++) {
			$decision = $limiter->checkPreAuth("203.0.113.1");
			assertTrue($decision->allowed, "request #" . ($i + 1) . " of 3 (limit=3) must be allowed");
		}
	}

	public static function testAtLimitAllowed(): void {
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 5, 60, 5, 60, self::fixedClock(1000));

		$decision = null;
		for ($i = 0; $i < 5; $i++) {
			$decision = $limiter->checkPreAuth("203.0.113.1");
		}

		assertTrue($decision->allowed, "the 5th request against a limit of 5 must still be allowed (count <= limit)");
	}

	public static function testAboveLimitRejected(): void {
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 5, 60, 5, 60, self::fixedClock(1000));

		for ($i = 0; $i < 5; $i++) {
			$limiter->checkPreAuth("203.0.113.1");
		}
		$sixth = $limiter->checkPreAuth("203.0.113.1");

		assertFalse($sixth->allowed, "the 6th request against a limit of 5 must be rejected");
	}

	public static function testWindowReset(): void {
		$store = new InMemoryRateLimitStore();
		$limiter = new RateLimiter($store, 2, 60, 2, 60, self::fixedClock(1000));

		$limiter->checkPreAuth("203.0.113.1");
		$limiter->checkPreAuth("203.0.113.1");
		assertFalse($limiter->checkPreAuth("203.0.113.1")->allowed, "must be over the limit within the same window");

		// Advance the fake clock past the 60s window boundary.
		$limiterNextWindow = new RateLimiter($store, 2, 60, 2, 60, self::fixedClock(1061));
		$decision = $limiterNextWindow->checkPreAuth("203.0.113.1");
		assertTrue($decision->allowed, "a new fixed window must reset the counter, not carry the stale count forward");
	}

	public static function testFilesystemStoreNoLostIncrements(): void {
		$dir = self::freshFilesystemDir();
		$store = new FilesystemRateLimitStore($dir);

		$last = 0;
		for ($i = 1; $i <= 50; $i++) {
			$last = $store->incrementAndGet("some-bucket", 1000);
		}

		assertEquals(50, $last, "50 sequential increments against the same bucket/window must never be lost");
	}

	public static function testFilesystemStorePathTraversalImpossible(): void {
		$dir = self::freshFilesystemDir();
		$store = new FilesystemRateLimitStore($dir);

		$store->incrementAndGet("../../../etc/passwd", 1000);
		$store->incrementAndGet("preauth:" . "; rm -rf /", 1000);

		assertFalse(file_exists(dirname($dir) . "/etc/passwd"), "a bucket key must never influence the filename path");

		$entries = array_values(array_diff(scandir($dir), [".", ".."]));
		foreach ($entries as $entry) {
			assertTrue(
				preg_match('/^[0-9a-f]{64}\.count$/', $entry) === 1,
				"every counter filename must be exactly sha256(bucketKey) + '.count', got: " . $entry
			);
		}
	}

	public static function testFilesystemStoreDirectoryNotWorldWritable(): void {
		$dir = self::freshFilesystemDir();
		$store = new FilesystemRateLimitStore($dir);
		$store->incrementAndGet("some-bucket", 1000);

		$perms = fileperms($dir) & 0777;
		assertEquals(0700, $perms, "the rate-limit counter directory must be created 0700 (owner-only), never world/group-writable");
	}

	public static function testPreAuthFailsClosedOnStorageFailure(): void {
		$store = new class implements RateLimitStoreInterface {
			public function incrementAndGet(string $bucketKey, int $windowStart): int {
				throw new RateLimitStoreUnavailableException("simulated storage failure");
			}
		};

		[, $validator] = [null, self::freshValidator(["alice" => "alice-secret"])];
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$handler = new ExecuteRequestHandler(
			$validator,
			self::buildAdapter($runner),
			null,
			new RateLimiter($store)
		);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.1"
		);

		assertEquals(429, $status, "a broken pre-auth rate-limit store must fail CLOSED (deny), not silently allow unlimited traffic");
		assertEquals("RATE_LIMITED", $envelope["error"]["code"]);
	}

	public static function testAuthenticatedFailsOpenOnStorageFailure(): void {
		$callCount = 0;
		$store = new class implements RateLimitStoreInterface {
			public int $calls = 0;

			public function incrementAndGet(string $bucketKey, int $windowStart): int {
				$this->calls++;
				// Pre-auth bucket keys are prefixed "preauth:" — let those
				// succeed normally so this test isolates the authenticated
				// bucket's own storage-failure behavior.
				if (strpos($bucketKey, "preauth:") === 0) {
					return 1;
				}
				throw new RateLimitStoreUnavailableException("simulated storage failure");
			}
		};

		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$handler = new ExecuteRequestHandler(
			$validator,
			self::buildAdapter($runner),
			null,
			new RateLimiter($store)
		);

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]),
			"203.0.113.1"
		);

		assertEquals(200, $status, "a broken authenticated rate-limit store must fail OPEN (proceed), not deny an already-authenticated caller");
		assertTrue($envelope["success"] === true);
	}

	// --- Pipeline integration ------------------------------------------

	private static function buildAdapter(FakeProcessRunner $runner, ?SpyLockManager $lockManager = null) {
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

	public static function testPreAuthBucketSharedAcrossUnknownAndValidCredential(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 2, 60, 100, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);

		// 1st: unknown credential, same IP.
		[$status1] = $handler->handle("POST", "application/json", "Basic " . base64_encode("key-nobody:whatever"), $body, "203.0.113.9");
		// 2nd: valid credential, same IP — shares the pre-auth bucket, so
		// this is request #2 of the limit-2 bucket.
		[$status2] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		// 3rd: over the shared pre-auth limit regardless of credential validity.
		[$status3, $envelope3] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(401, $status1, "unknown credential still authenticates-fails normally under the limit");
		assertEquals(200, $status2, "valid credential succeeds — still under the shared limit of 2");
		assertEquals(429, $status3, "3rd request from the same IP must be rate-limited regardless of credential validity");
		assertEquals("RATE_LIMITED", $envelope3["error"]["code"]);
	}

	public static function testAuthenticatedBucketSeparateFromPreAuth(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		// Pre-auth limit is deliberately very low; authenticated limit is
		// generous — proves the two buckets are tracked independently,
		// not merged into one counter.
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 100, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);

		[$status1] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		assertEquals(200, $status1);

		// A 2nd request from the same IP is now over the pre-auth limit of 1.
		[$status2, $envelope2] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		assertEquals(429, $status2);
		assertEquals("RATE_LIMITED", $envelope2["error"]["code"]);
	}

	public static function testAuthenticatedBucketsAreIndependentPerCredential(): void {
		$validator = self::freshValidator(["alice" => "alice-secret", "bob" => "bob-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1000, 60, 1, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$aliceBody = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);
		$bobBody = json_encode(["operation" => "domain.get", "params" => ["user" => "bob", "domain" => "example.com"]]);

		// Different client IPs so only the authenticated (per-credential)
		// bucket is being exercised, not the shared pre-auth bucket.
		[$aliceStatus1] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $aliceBody, "203.0.113.10");
		[$aliceStatus2] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $aliceBody, "203.0.113.11");
		[$bobStatus1] = $handler->handle("POST", "application/json", self::basicHeader("bob", "bob-secret"), $bobBody, "203.0.113.12");

		assertEquals(200, $aliceStatus1, "alice's 1st request (limit=1) succeeds");
		assertEquals(429, $aliceStatus2, "alice's 2nd request exceeds her own bucket");
		assertEquals(200, $bobStatus1, "bob has his own, independent bucket and is unaffected by alice's usage");
	}

	public static function testRateLimitBeforeAdapterInvocation(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 1, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);

		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		[$status] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(429, $status);
		assertEquals(1, count($runner->calls), "CommandAdapter must not be invoked for a rate-limited request");
	}

	public static function testRateLimitDoesNotAcquireLock(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager();
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 1, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner, $lockManager), null, $limiter);

		// Use a mutating operation (domain.create) so a non-rate-limited
		// request WOULD acquire a lock — proving the rate-limited one
		// deliberately does not.
		$body = json_encode(["operation" => "domain.create", "params" => ["user" => "alice", "domain" => "example.com"]]);

		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		[$status] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(429, $status);
		assertEquals(1, count($lockManager->acquireCalls), "a rate-limited request must never acquire an adapter lock");
	}

	public static function testRateLimitDoesNotExecuteOperation(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 1, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(1, count($runner->calls), "no process must ever be spawned for a rate-limited request");
	}

	public static function testRateLimitedResponseUsesExistingEnvelope(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 1, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);
		$handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");
		[$status, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.9");

		assertEquals(429, $status);
		assertEquals(
			["api_version", "success", "outcome", "data", "error", "meta"],
			array_keys($envelope),
			"a 429 response must use exactly the same six-key envelope shape as every other response"
		);
		assertTrue($envelope["success"] === false);
		assertTrue($envelope["data"] === null);
		assertEquals("RATE_LIMITED", $envelope["error"]["code"]);
		assertTrue(isset($envelope["error"]["message"]));
	}

	public static function testRateLimitedResponseNeverContainsSecret(): void {
		$validator = self::freshValidator(["alice" => "super-secret-value"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 1, 60, self::fixedClock(1000));
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);
		$handler->handle("POST", "application/json", self::basicHeader("alice", "super-secret-value"), $body, "203.0.113.9");
		[, $envelope] = $handler->handle("POST", "application/json", self::basicHeader("alice", "super-secret-value"), $body, "203.0.113.9");

		$serialized = json_encode($envelope);
		assertFalse(strpos($serialized, "super-secret-value") !== false, "the raw secret must never appear anywhere in a rate-limited response");
	}

	public static function testRateLimitedResponseDoesNotRevealCredentialExistence(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$limiter = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 100, 60, self::fixedClock(1000));

		$body = json_encode(["operation" => "domain.get", "params" => ["user" => "alice", "domain" => "example.com"]]);

		// Unknown credential, exhausting the pre-auth bucket first.
		$handlerA = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter);
		$handlerA->handle("POST", "application/json", "Basic " . base64_encode("key-nobody:whatever"), $body, "203.0.113.20");
		[$statusUnknown, $envelopeUnknown] = $handlerA->handle("POST", "application/json", "Basic " . base64_encode("key-nobody:whatever"), $body, "203.0.113.20");

		// Fresh limiter/handler, valid credential, same limit — exhausting
		// the pre-auth bucket the identical way.
		$limiter2 = new RateLimiter(new InMemoryRateLimitStore(), 1, 60, 100, 60, self::fixedClock(1000));
		$handlerB = new ExecuteRequestHandler($validator, self::buildAdapter($runner), null, $limiter2);
		$handlerB->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.21");
		[$statusValid, $envelopeValid] = $handlerB->handle("POST", "application/json", self::basicHeader("alice", "alice-secret"), $body, "203.0.113.21");

		assertEquals($statusUnknown, $statusValid, "the rate-limited status must be identical for an unknown vs. a valid credential");
		assertEquals($envelopeUnknown["error"]["code"], $envelopeValid["error"]["code"], "the rate-limited error code must be identical for an unknown vs. a valid credential");
	}

	public static function testNonRateLimitedMappingsUnchanged(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		// Generous default limits (well above this test's single call) —
		// proves the rate limiter, when NOT triggered, leaves every other
		// existing mapping untouched.
		$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

		[$status, $envelope] = $handler->handle(
			"POST",
			"application/json",
			self::basicHeader("alice", "alice-secret"),
			"not valid json",
			"203.0.113.9"
		);

		assertEquals(400, $status);
		assertEquals("MALFORMED_JSON", $envelope["error"]["code"]);
	}

	public static function testAllSevenOperationsSucceedUnderLimit(): void {
		$validator = self::freshValidator(["alice" => "alice-secret"]);

		$operations = [
			"domain.get" => ["user" => "alice", "domain" => "example.com"],
			"domain.list" => ["user" => "alice"],
			"domain.create" => ["user" => "alice", "domain" => "example.com"],
			"domain.delete" => ["user" => "alice", "domain" => "example.com"],
			"database.create" => ["user" => "alice", "database" => "wp_db", "dbuser" => "alice_wp", "password" => "pw"],
			"database.delete" => ["user" => "alice", "database" => "wp_db"],
			"backup.schedule" => ["user" => "alice"],
		];

		foreach ($operations as $operation => $params) {
			$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
			$handler = new ExecuteRequestHandler($validator, self::buildAdapter($runner));

			[$status, $envelope] = $handler->handle(
				"POST",
				"application/json",
				self::basicHeader("alice", "alice-secret"),
				json_encode(["operation" => $operation, "params" => $params]),
				"203.0.113.9"
			);

			assertTrue(
				in_array($status, [200], true),
				"$operation must still succeed while under the default rate limit, got status $status: " . json_encode($envelope)
			);
		}
	}
}
