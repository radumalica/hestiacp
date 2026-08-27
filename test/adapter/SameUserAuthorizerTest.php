<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockManager;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\ProcessRunnerInterface;
use Hestiacp\Adapter\SameUserAuthorizer;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Tests for SameUserAuthorizer, the real authorization policy that is now
 * CommandAdapter's default (see AUTHORIZATION_POLICY_IMPLEMENTATION.md).
 *
 * Part A (1-4) tests the policy in isolation, directly, with no
 * CommandAdapter involved at all — it is a pure function of
 * (operation, target, actor).
 *
 * Part B (5-8) proves the policy's denial is correctly wired through the
 * FULL adapter pipeline: the right AdapterResult shape, and — the
 * properties that matter most for a security seam — that a denial
 * strictly precedes lock acquisition, process execution, and (for
 * operations with a sensitive parameter) temp-file creation. These reuse
 * AuthorizationTest.php's/SensitiveParameterTest.php's existing synthetic-
 * registry and spy infrastructure rather than duplicating it.
 */
final class SameUserAuthorizerTest {
	private const MUTATING_OPERATION = "user.mutate.same-user-authz-test";
	private const SENSITIVE_OPERATION = "user.mutate.same-user-authz-sensitive-test";

	private static function registryWithMutatingOp(): CommandRegistry {
		return new CommandRegistry([
			self::MUTATING_OPERATION => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => ["user"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
			self::SENSITIVE_OPERATION => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => ["user", "secret"],
				"parameters" => [
					"user" => ["type" => "username", "required" => true],
					"secret" => ["type" => "username", "required" => true, "sensitive" => true, "delivery" => "temp_file"],
				],
				"fixed_parameters" => [],
				"mutation" => ["kind" => "create"],
			],
		]);
	}

	private static function tempLockManager(): LockManager {
		$dir = sys_get_temp_dir() . "/adapter-same-user-authz-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return new LockManager($dir, 5);
	}

	private static function buildAdapter(ProcessRunnerInterface $runner, ?\Hestiacp\Adapter\LockManagerInterface $lockManager = null): CommandAdapter {
		// Deliberately NO authorizer argument — this file exists to prove
		// what CommandAdapter's own real default does.
		return new CommandAdapter(
			self::registryWithMutatingOp(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			$lockManager ?? self::tempLockManager()
		);
	}

	private static function countAdapterTempFiles(): int {
		$matches = glob("/tmp/hstadapter*");
		return $matches === false ? 0 : count($matches);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. actor.user === target.user -> allowed", [self::class, "testSameUserAllowed"]);
		$t->test("2. actor.user !== target.user -> denied", [self::class, "testDifferentUserDenied"]);
		$t->test("3. actor missing user -> denied", [self::class, "testMissingActorUserDenied"]);
		$t->test("4. target missing user -> denied", [self::class, "testMissingTargetUserDenied"]);
		$t->test("5. denial returns status=adapter_error, adapterErrorCode=AUTHORIZATION_DENIED", [self::class, "testDenialResultShape"]);
		$t->test("6. denied operation does not acquire the lock", [self::class, "testDenialDoesNotAcquireLock"]);
		$t->test("7. denied operation does not spawn a process", [self::class, "testDenialDoesNotSpawnProcess"]);
		$t->test("8. denied operation does not create a sensitive temp file", [self::class, "testDenialDoesNotCreateTempFile"]);
	}

	// --- Part A: the policy in isolation ---------------------------------

	public static function testSameUserAllowed(): void {
		$authorizer = new SameUserAuthorizer();
		assertTrue($authorizer->authorize("irrelevant.op", ["user" => "bob"], ["user" => "bob"]), "same user must be allowed");
	}

	public static function testDifferentUserDenied(): void {
		$authorizer = new SameUserAuthorizer();
		assertFalse($authorizer->authorize("irrelevant.op", ["user" => "bob"], ["user" => "alice"]), "a different user must be denied");
	}

	public static function testMissingActorUserDenied(): void {
		$authorizer = new SameUserAuthorizer();
		assertFalse($authorizer->authorize("irrelevant.op", ["user" => "bob"], []), "a missing actor.user must be denied");
		assertFalse($authorizer->authorize("irrelevant.op", ["user" => "bob"], ["user" => null]), "an explicit null actor.user must be denied");
	}

	public static function testMissingTargetUserDenied(): void {
		$authorizer = new SameUserAuthorizer();
		assertFalse($authorizer->authorize("irrelevant.op", [], ["user" => "bob"]), "a missing target.user must be denied (fail closed)");
		assertFalse($authorizer->authorize("irrelevant.op", ["user" => null], ["user" => "bob"]), "an explicit null target.user must be denied");
	}

	// --- Part B: wired through the full adapter pipeline ------------------

	public static function testDenialResultShape(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"], ["user" => "alice"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
	}

	public static function testDenialDoesNotAcquireLock(): void {
		$dir = sys_get_temp_dir() . "/adapter-same-user-authz-reallock-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		$lockManager = new LockManager($dir, 5);

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"], ["user" => "alice"]);
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "sanity check: the call must actually be denied");

		// Proven the same way AuthorizationTest.php proves it: an
		// independent LockManager on the same directory must be able to
		// immediately acquire "bob"'s lock.
		$independent = new LockManager($dir, 5);
		assertTrue($independent->acquire("bob"), "an independent LockManager on the same directory must be able to immediately acquire 'bob's lock — proving the denied request never actually held it");
		$independent->release();
	}

	public static function testDenialDoesNotSpawnProcess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"], ["user" => "alice"]);

		assertEquals(0, count($runner->calls), "a denied request must never spawn the underlying process");
	}

	public static function testDenialDoesNotCreateTempFile(): void {
		$before = self::countAdapterTempFiles();

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::SENSITIVE_OPERATION, ["user" => "bob", "secret" => "s3cr3t-val"], ["user" => "alice"]);

		$after = self::countAdapterTempFiles();

		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "sanity check: the call must actually be denied");
		assertEquals($before, $after, "no temp file may be created for a request that authorization denies");
	}
}
