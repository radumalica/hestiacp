<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\AuthorizerInterface;
use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockManager;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\ProcessRunnerInterface;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Tests for the two generic capabilities SENSITIVE_PARAMETER_DESIGN.md
 * introduces: a "sensitive" parameter flag (kept out of
 * AdapterResult::$target and AuthorizerInterface::authorize()'s target
 * argument) and a "delivery" => "temp_file" parameter flag (value written
 * to a securely created temp file, whose PATH — never the plaintext —
 * reaches argv).
 *
 * All operations here are SYNTHETIC, registered via
 * CommandRegistry::$additionalOperations, the same test-only extension
 * point CommandRegistryValidationTest.php/AuthorizationTest.php already
 * use. No "database.create" registry entry, no real bin/v-add-database
 * invocation, no real Hestia installation. This file proves the GENERIC
 * mechanism only — see the class-level docblock in
 * SENSITIVE_PARAMETER_DESIGN.md for why database.create itself is
 * deliberately deferred to a later, separate review.
 */
final class SensitiveParameterTest {
	private const OP = "test.sensitive-op";

	/**
	 * One normal parameter ("label"), one sensitive+temp_file parameter
	 * ("secret"). Reuses the existing "username" type validator for both
	 * — no new ParameterValidator type is introduced by this task, since
	 * none is needed to prove the generic mechanism.
	 */
	private static function registryWithSensitiveOp(?array $overrideParameters = null): CommandRegistry {
		$parameters = $overrideParameters ?? [
			"user" => ["type" => "username", "required" => true],
			"secret" => ["type" => "username", "required" => true, "sensitive" => true, "delivery" => "temp_file"],
			"label" => ["type" => "username", "required" => false],
		];

		return new CommandRegistry([
			self::OP => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => array_keys($parameters),
				"parameters" => $parameters,
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
		]);
	}

	private static function tempLockManager(): LockManager {
		$dir = sys_get_temp_dir() . "/adapter-sensitive-param-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return new LockManager($dir, 5);
	}

	private static function buildAdapter(
		ProcessRunnerInterface $runner,
		?CommandRegistry $registry = null,
		?\Hestiacp\Adapter\LockManagerInterface $lockManager = null,
		?AuthorizerInterface $authorizer = null
	): CommandAdapter {
		return new CommandAdapter(
			$registry ?? self::registryWithSensitiveOp(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			$lockManager ?? self::tempLockManager(),
			$authorizer ?? new \Hestiacp\Adapter\AllowAllAuthorizer()
		);
	}

	/**
	 * Counts files under the system temp directory matching the
	 * adapter's own temp-file prefix (CommandAdapter::TEMP_FILE_PREFIX,
	 * "hstadapter" — private, so this test relies on the documented
	 * prefix string rather than reflection). Used only for leak
	 * detection (before/after counts), never as the primary proof that a
	 * file existed WHILE the command ran — that proof comes from the
	 * probe-runner technique (see testTempFileContainsExpectedSecret and
	 * neighbors), the same pattern DomainDeleteTest/BackupScheduleTest
	 * already use for real-flock proof.
	 */
	private static function countAdapterTempFiles(): int {
		// Literal "/tmp", not sys_get_temp_dir() — matches
		// CommandAdapter::TEMP_FILE_DIRECTORY exactly (SENSITIVE_PARAMETER_REVIEW.md
		// finding F-2). A dynamic sys_get_temp_dir() lookup here would
		// reintroduce, in the leak detector itself, the same
		// dynamic-vs-literal split F-2 removed from production code.
		$matches = glob("/tmp/hstadapter*");
		return $matches === false ? 0 : count($matches);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. a normal (non-sensitive) parameter still appears in AdapterResult::target", [self::class, "testNormalParameterAppearsInTarget"]);
		$t->test("2. a sensitive parameter does NOT appear in AdapterResult::target", [self::class, "testSensitiveParameterExcludedFromTarget"]);
		$t->test("3. a sensitive parameter does NOT reach AuthorizerInterface::authorize()'s target argument", [self::class, "testSensitiveParameterExcludedFromAuthorizerTarget"]);
		$t->test("4. a temp_file-delivered parameter reaches the process runner as a file path, not the plaintext value", [self::class, "testTempFileDeliveryReachesRunnerAsFilePath"]);
		$t->test("5. the temp file contains exactly the expected secret (plus the documented trailing newline)", [self::class, "testTempFileContainsExpectedSecret"]);
		$t->test("6. the temp file has restrictive (0600) permissions", [self::class, "testTempFilePermissionsAreRestrictive"]);
		$t->test("7. the temp file is deleted after successful execution", [self::class, "testTempFileDeletedAfterSuccess"]);
		$t->test("8. the temp file is deleted after the underlying command exits non-zero", [self::class, "testTempFileDeletedAfterCommandFailure"]);
		$t->test("9. the temp file is deleted even when the process runner throws", [self::class, "testTempFileDeletedWhenProcessRunnerThrows"]);
		$t->test("10. authorization denial creates no temp file at all", [self::class, "testAuthorizationDenialCreatesNoTempFile"]);
		$t->test("11. multiple temp_file-delivered parameters are each cleaned up", [self::class, "testMultipleTempFilesAllCleanedUp"]);
		$t->test("12. registry rejects sensitive=true with no delivery mode declared", [self::class, "testSensitiveWithoutDeliveryRejectedAtConstruction"]);
		$t->test("13. registry rejects an unknown/typo'd delivery mode", [self::class, "testUnknownDeliveryModeRejectedAtConstruction"]);
		$t->test("14. delivery=temp_file WITHOUT sensitive=true is allowed (the two flags are independent)", [self::class, "testDeliveryWithoutSensitiveIsAllowed"]);
		$t->test("15. omitting both fields is accepted — this is the default, backward-compatible case", [self::class, "testOmittingBothFieldsAccepted"]);
		$t->test("16. existing real operations (domain.create, domain.delete, backup.schedule) declare no sensitive/delivery metadata and remain unaffected", [self::class, "testExistingOperationsUnaffected"]);
		$t->test("17. F-1: sensitive => true is accepted at construction", [self::class, "testSensitiveTrueAccepted"]);
		$t->test("18. F-1: sensitive => false is accepted at construction", [self::class, "testSensitiveFalseAccepted"]);
		$t->test("19. F-1: sensitive => 1 (int) is rejected at construction", [self::class, "testSensitiveIntOneRejected"]);
		$t->test("20. F-1: sensitive => \"true\" (string) is rejected at construction", [self::class, "testSensitiveStringTrueRejected"]);
		$t->test("21. F-1: sensitive => \"1\" (string) is rejected at construction", [self::class, "testSensitiveStringOneRejected"]);
		$t->test("22. F-1: sensitive => null is treated as absent/false, not rejected", [self::class, "testSensitiveNullTreatedAsAbsent"]);
		$t->test("23. F-1: sensitive => true without delivery is rejected at construction", [self::class, "testSensitiveTrueWithoutDeliveryRejected"]);
		$t->test("24. F-1: sensitive => true with delivery => temp_file is accepted and enforced end to end", [self::class, "testSensitiveTrueWithDeliveryAcceptedAndEnforced"]);
		$t->test("25. F-1: a malformed sensitive value can never result in plaintext reaching argv (no fail-open state exists)", [self::class, "testNoFailOpenStateForMalformedSensitive"]);
		$t->test("26. F-2: the generated temp file path begins with the literal '/tmp/' prefix, not merely 'exists'", [self::class, "testTempFilePathBeginsWithLiteralTmpPrefix"]);
	}

	/**
	 * Builds a single-parameter synthetic registry entry with the given
	 * raw "sensitive" value (whatever type the caller wants to probe),
	 * optionally with a "delivery" key attached. Used only by the F-1
	 * malformed-value tests below.
	 */
	private static function registryWithRawSensitiveValue($sensitiveValue, bool $withDelivery): CommandRegistry {
		$secretDefinition = ["type" => "username", "required" => true, "sensitive" => $sensitiveValue];
		if ($withDelivery) {
			$secretDefinition["delivery"] = "temp_file";
		}

		return new CommandRegistry([
			"test.f1-op" => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => ["user", "secret"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
					"secret" => $secretDefinition,
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
		]);
	}

	public static function testNormalParameterAppearsInTarget(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals("myLabel", $result->target["label"] ?? null, "a normal parameter must still be copied into target, unchanged");
		assertEquals("admin", $result->target["user"] ?? null, "the user parameter (never marked sensitive) must still appear in target");
	}

	public static function testSensitiveParameterExcludedFromTarget(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertFalse(array_key_exists("secret", $result->target), "a sensitive parameter must never appear in AdapterResult::target");
		assertTrue(strpos(json_encode($result->toArray()), "s3cr3t-val") === false, "the plaintext secret must not appear anywhere in AdapterResult::toArray()");
	}

	public static function testSensitiveParameterExcludedFromAuthorizerTarget(): void {
		$authorizer = new SpyAuthorizer(true);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, null, $authorizer);

		$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals(1, count($authorizer->calls), "authorize() must be called exactly once");
		assertFalse(array_key_exists("secret", $authorizer->calls[0]["target"]), "the sensitive parameter must never reach AuthorizerInterface::authorize()'s target argument");
		assertEquals("myLabel", $authorizer->calls[0]["target"]["label"] ?? null, "non-sensitive parameters must still reach the authorizer normally");
	}

	public static function testTempFileDeliveryReachesRunnerAsFilePath(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals(1, count($runner->calls), "the process runner must have been called exactly once");
		$argv = $runner->calls[0]["argv"];
		// argument_order is [user, secret, label]; argv[0] is the script
		// path itself (CommandAdapter prepends it), so the secret slot is
		// argv[2].
		assertTrue(!in_array("s3cr3t-val", $argv, true), "the plaintext secret must never appear anywhere in argv");
		assertTrue(strpos($argv[2], sys_get_temp_dir()) === 0, "the secret's argv slot must be a path inside the system temp directory, not the plaintext value");
		assertTrue(strpos($argv[2], "hstadapter") !== false, "the temp file must use the adapter's own generic prefix — never an operation-specific name");
	}

	/**
	 * Reads the temp file's content and permissions DURING execution —
	 * i.e. from inside the (faked) process runner's own run() method,
	 * the exact point CommandAdapter's real, unmodified lock/execute
	 * flow guarantees the file still exists — using the same
	 * probe-during-run() technique DomainDeleteTest/BackupScheduleTest
	 * already established for proving real flock contention. This is
	 * deliberately NOT a check performed after invoke() returns, since
	 * the file is guaranteed to be deleted by then (see the cleanup
	 * tests below) — checking content/permissions requires observing the
	 * file while it still exists.
	 */
	public static function testTempFileContainsExpectedSecret(): void {
		$captured = ["content" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["content"] = file_get_contents($argv[2]);
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner);
		$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals("s3cr3t-val\n", $captured["content"], "the temp file must contain exactly the secret value plus one trailing newline, matching Hestia's own head -n1 reader contract and the existing production caller's own convention");
	}

	public static function testTempFilePermissionsAreRestrictive(): void {
		$captured = ["perms" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["perms"] = fileperms($argv[2]) & 0777;
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner);
		$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals(0600, $captured["perms"], "the temp file must be owner-read/write only (0600) — not group- or world-readable");
	}

	public static function testTempFileDeletedAfterSuccess(): void {
		$captured = ["path" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["path"] = $argv[2];
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner);
		$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertFalse(file_exists($captured["path"]), "the temp file must be deleted after a successful (exit 0) invocation");
	}

	public static function testTempFileDeletedAfterCommandFailure(): void {
		$captured = ["path" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["path"] = $argv[2];
				return new ProcessResult(2, "", "Error: invalid something");
			}
		};

		$adapter = self::buildAdapter($runner);
		$result = $adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals("hestia_error", $result->status, "sanity check: the command must be reported as failed");
		assertFalse(file_exists($captured["path"]), "the temp file must still be deleted even when the underlying command exits non-zero");
	}

	public static function testTempFileDeletedWhenProcessRunnerThrows(): void {
		$captured = ["path" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["path"] = $argv[2];
				throw new \RuntimeException("simulated process-spawn failure");
			}
		};

		$adapter = self::buildAdapter($runner);

		$threw = false;
		try {
			$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);
		} catch (\RuntimeException $e) {
			$threw = true;
		}

		assertTrue($threw, "sanity check: the exception must propagate to the caller, unchanged, exactly as CommandAdapter's existing contract already guarantees for lock release");
		assertFalse(file_exists($captured["path"]), "the temp file must still be deleted even when the process runner itself throws");
	}

	public static function testAuthorizationDenialCreatesNoTempFile(): void {
		$before = self::countAdapterTempFiles();

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, null, new SpyAuthorizer(false));

		$result = $adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		$after = self::countAdapterTempFiles();

		assertEquals("adapter_error", $result->status, "sanity check: the call must actually be denied");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "sanity check: the denial must be the authorization one, not some other rejection");
		assertEquals(0, count($runner->calls), "the process runner must never be invoked for a denied request");
		assertEquals($before, $after, "no temp file may be created for a request that authorization denies — temp-file creation happens strictly after the authorization check succeeds");
	}

	/**
	 * A second synthetic operation with TWO temp_file-delivered
	 * parameters, proving the cleanup loop (which iterates every path
	 * collected during argv construction) handles more than one file,
	 * not just the single-parameter case every other test here uses.
	 */
	public static function testMultipleTempFilesAllCleanedUp(): void {
		$parameters = [
			"user" => ["type" => "username", "required" => true],
			"secretOne" => ["type" => "username", "required" => true, "sensitive" => true, "delivery" => "temp_file"],
			"secretTwo" => ["type" => "username", "required" => true, "sensitive" => true, "delivery" => "temp_file"],
		];
		$registry = self::registryWithSensitiveOp($parameters);

		$captured = ["paths" => []];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				// argv[0] = script, argv[1] = user, argv[2] = secretOne path, argv[3] = secretTwo path
				$this->captured["paths"] = [$argv[2], $argv[3]];
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner, $registry);
		$adapter->invoke(self::OP, ["user" => "admin", "secretOne" => "first-secret", "secretTwo" => "second-secret"]);

		assertTrue($captured["paths"][0] !== $captured["paths"][1], "each sensitive parameter must get its own, distinct temp file");
		assertFalse(file_exists($captured["paths"][0]), "the first temp file must be deleted after execution");
		assertFalse(file_exists($captured["paths"][1]), "the second temp file must be deleted after execution");
	}

	public static function testSensitiveWithoutDeliveryRejectedAtConstruction(): void {
		$threw = false;
		$message = "";
		try {
			new CommandRegistry([
				"test.bad-op" => [
					"script" => "v-does-not-exist-test-only",
					"argument_order" => ["user", "secret"],
					"parameters" => [
						"user" => ["type" => "username", "required" => true],
						"secret" => ["type" => "username", "required" => true, "sensitive" => true],
					],
					"fixed_parameters" => [],
					"mutation" => ["kind" => "create"],
				],
			]);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
			$message = $e->getMessage();
		}

		assertTrue($threw, "sensitive=true without a declared delivery mode must throw InvalidArgumentException at construction time");
		assertTrue(strpos($message, "test.bad-op") !== false, "the exception message must name the offending operation");
		assertTrue(strpos($message, "secret") !== false, "the exception message must name the offending parameter");
	}

	public static function testUnknownDeliveryModeRejectedAtConstruction(): void {
		$threw = false;
		$message = "";
		try {
			new CommandRegistry([
				"test.bad-op-2" => [
					"script" => "v-does-not-exist-test-only",
					"argument_order" => ["user", "secret"],
					"parameters" => [
						"user" => ["type" => "username", "required" => true],
						"secret" => ["type" => "username", "required" => true, "sensitive" => true, "delivery" => "env_var"],
					],
					"fixed_parameters" => [],
					"mutation" => ["kind" => "create"],
				],
			]);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
			$message = $e->getMessage();
		}

		assertTrue($threw, "an unsupported delivery mode must throw InvalidArgumentException at construction time");
		assertTrue(strpos($message, "env_var") !== false, "the exception message must name the offending, unsupported delivery mode");
	}

	public static function testDeliveryWithoutSensitiveIsAllowed(): void {
		$registry = new CommandRegistry([
			"test.delivery-only" => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => ["user", "value"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
					"value" => ["type" => "username", "required" => true, "delivery" => "temp_file"],
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
		]);

		assertTrue($registry->has("test.delivery-only"), "delivery=temp_file without sensitive=true must be accepted at construction time — the two flags are independent");
	}

	public static function testOmittingBothFieldsAccepted(): void {
		$registry = new CommandRegistry([
			"test.plain-op" => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => ["user"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
		]);

		assertTrue($registry->has("test.plain-op"), "omitting both sensitive and delivery entirely must be accepted — this is the default, backward-compatible case every pre-existing operation already relies on");
	}

	public static function testExistingOperationsUnaffected(): void {
		$registry = new CommandRegistry();

		foreach (["domain.create", "domain.delete", "backup.schedule"] as $operation) {
			$entry = $registry->get($operation);
			foreach ($entry["parameters"] as $name => $definition) {
				assertFalse($definition["sensitive"] ?? false, "$operation parameter '$name' must not be marked sensitive — no existing operation has a secret parameter");
				assertTrue(($definition["delivery"] ?? null) === null, "$operation parameter '$name' must not declare a delivery mode — no existing operation needs one");
			}
		}

		// End-to-end sanity: domain.create's own target-building behavior
		// (every declared parameter appears in target) is unchanged.
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockDir = sys_get_temp_dir() . "/adapter-sensitive-param-existing-op-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($lockDir, 0770, true);
		$adapter = new CommandAdapter(
			$registry,
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			new LockManager($lockDir, 5),
			new \Hestiacp\Adapter\AllowAllAuthorizer()
		);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("admin", $result->target["user"] ?? null, "domain.create's target-building must be unchanged");
		assertEquals("example.com", $result->target["domain"] ?? null, "domain.create's target-building must be unchanged");
	}

	public static function testSensitiveTrueAccepted(): void {
		$registry = self::registryWithRawSensitiveValue(true, true);
		assertTrue($registry->has("test.f1-op"), "sensitive => true (with delivery) must be accepted at construction time");
	}

	public static function testSensitiveFalseAccepted(): void {
		$registry = self::registryWithRawSensitiveValue(false, false);
		assertTrue($registry->has("test.f1-op"), "sensitive => false must be accepted at construction time — it is not sensitive, no delivery required");
	}

	public static function testSensitiveIntOneRejected(): void {
		$threw = false;
		$message = "";
		try {
			self::registryWithRawSensitiveValue(1, false);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
			$message = $e->getMessage();
		}
		assertTrue($threw, "sensitive => 1 (int) must be rejected at construction — only an actual boolean is accepted");
		assertTrue(strpos($message, "secret") !== false, "the exception message must name the offending parameter");
	}

	public static function testSensitiveStringTrueRejected(): void {
		$threw = false;
		try {
			self::registryWithRawSensitiveValue("true", false);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
		}
		assertTrue($threw, "sensitive => \"true\" (string) must be rejected at construction — only an actual boolean is accepted");
	}

	public static function testSensitiveStringOneRejected(): void {
		$threw = false;
		try {
			self::registryWithRawSensitiveValue("1", false);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
		}
		assertTrue($threw, "sensitive => \"1\" (string) must be rejected at construction — only an actual boolean is accepted");
	}

	/**
	 * Existing metadata contract (established before this task, e.g.
	 * "delivery" => null, "mutation.known_post_mutation_exit_codes"
	 * defaulting via ?? []): an explicit null on an optional field is
	 * already treated identically to the field being entirely absent,
	 * via PHP's own "??" operator. This test preserves that existing
	 * distinction for "sensitive" specifically — null must NOT be
	 * rejected (it is not a malformed value; it is the same as omitting
	 * the field) and must NOT be treated as sensitive.
	 */
	public static function testSensitiveNullTreatedAsAbsent(): void {
		$registry = self::registryWithRawSensitiveValue(null, false);
		assertTrue($registry->has("test.f1-op"), "sensitive => null must be accepted at construction — treated the same as the field being absent");

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockDir = sys_get_temp_dir() . "/adapter-sensitive-null-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($lockDir, 0770, true);
		$adapter = new CommandAdapter(
			$registry,
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float { return 1700000000.0; },
			static function (): string { return "fixed-test-id"; },
			new LockManager($lockDir, 5),
			new \Hestiacp\Adapter\AllowAllAuthorizer()
		);

		$result = $adapter->invoke("test.f1-op", ["user" => "admin", "secret" => "notActuallySecret"]);
		assertEquals("notActuallySecret", $result->target["secret"] ?? null, "sensitive => null must behave exactly like sensitive being absent — the value must still appear in target");
	}

	public static function testSensitiveTrueWithoutDeliveryRejected(): void {
		$threw = false;
		try {
			self::registryWithRawSensitiveValue(true, false);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
		}
		assertTrue($threw, "sensitive => true without a declared delivery mode must still be rejected at construction time");
	}

	public static function testSensitiveTrueWithDeliveryAcceptedAndEnforced(): void {
		$registry = self::registryWithRawSensitiveValue(true, true);

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockDir = sys_get_temp_dir() . "/adapter-sensitive-enforced-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($lockDir, 0770, true);
		$adapter = new CommandAdapter(
			$registry,
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float { return 1700000000.0; },
			static function (): string { return "fixed-test-id"; },
			new LockManager($lockDir, 5),
			new \Hestiacp\Adapter\AllowAllAuthorizer()
		);

		$result = $adapter->invoke("test.f1-op", ["user" => "admin", "secret" => "realSecretValue"]);
		assertFalse(array_key_exists("secret", $result->target), "sensitive => true (with delivery) must exclude the value from target");
		assertEquals(1, count($runner->calls), "the process runner must have been called exactly once");
		assertTrue(!in_array("realSecretValue", $runner->calls[0]["argv"], true), "the plaintext secret must never appear in argv");
	}

	/**
	 * The central property F-1 exists to guarantee, stated directly:
	 * for EVERY value "sensitive" can legally hold after registry
	 * construction succeeds (true, false, or absent/null — malformed
	 * values are now rejected before this point, per the tests above),
	 * there is no state in which CommandAdapter considers the parameter
	 * sensitive (excludes it from target) while argv still receives the
	 * plaintext. This test exercises the two reachable "considered
	 * sensitive" states (true+temp_file, and — impossible to construct,
	 * confirming there is no third state) directly against argv content.
	 */
	public static function testNoFailOpenStateForMalformedSensitive(): void {
		// sensitive => true always requires delivery (enforced at
		// construction), so the only way to reach "excluded from target"
		// is also the only way to reach "routed through a temp file."
		$registry = self::registryWithRawSensitiveValue(true, true);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockDir = sys_get_temp_dir() . "/adapter-sensitive-nofailopen-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($lockDir, 0770, true);
		$adapter = new CommandAdapter(
			$registry,
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float { return 1700000000.0; },
			static function (): string { return "fixed-test-id"; },
			new LockManager($lockDir, 5),
			new \Hestiacp\Adapter\AllowAllAuthorizer()
		);

		$result = $adapter->invoke("test.f1-op", ["user" => "admin", "secret" => "s3cr3t-no-leak"]);
		$excludedFromTarget = !array_key_exists("secret", $result->target);
		$plaintextInArgv = in_array("s3cr3t-no-leak", $runner->calls[0]["argv"], true);

		assertTrue($excludedFromTarget, "sanity check: the sensitive parameter must be excluded from target");
		assertFalse($plaintextInArgv, "the property under test: whenever a parameter is excluded from target for being sensitive, its plaintext must never be in argv — no malformed-metadata state can now separate these two facts");
	}

	public static function testTempFilePathBeginsWithLiteralTmpPrefix(): void {
		$captured = ["path" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["path"] = $argv[2];
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner);
		$adapter->invoke(self::OP, ["user" => "admin", "secret" => "s3cr3t-val", "label" => "myLabel"]);

		assertEquals(0, strpos($captured["path"], "/tmp/"), "the temp file path must begin with the literal '/tmp/' prefix — not merely exist, and not merely be inside whatever sys_get_temp_dir() happens to resolve to — matching Hestia's is_password_valid() '^/tmp/' contract exactly");
	}
}
