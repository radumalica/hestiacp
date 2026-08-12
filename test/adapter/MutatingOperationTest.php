<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockUnavailableException;
use Hestiacp\Adapter\ProcessResult;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * CommandAdapter-level locking behavior, tested via a synthetic mutating
 * operation registered through CommandRegistry's test-only
 * $additionalOperations constructor parameter (LOCK_IMPLEMENTATION.md /
 * WRITE_OPERATION_DESIGN.md). domain.create is deliberately NOT
 * implemented anywhere in this codebase — "user.mutate.test" below is not
 * mapped to any real bin/v-* script behavior beyond what FakeProcessRunner
 * is told to return, and exists purely to exercise the mutating code path
 * in CommandAdapter::invoke() without a real write operation.
 *
 * Covers requirements A, B, F, G, H, J from this task's test list.
 * (C, D, E, I are covered in LockManagerTest.php against the real
 * flock-based LockManager; K is covered by the existing
 * CommandAdapterTest/DomainListTest suites, unmodified.)
 */
final class MutatingOperationTest {
	private const MUTATING_OPERATION = "user.mutate.test";

	private static function registryWithMutatingOp(): CommandRegistry {
		return new CommandRegistry([
			self::MUTATING_OPERATION => [
				"script" => "v-does-not-exist-test-only",
				"argument_order" => ["user"],
				"parameters" => [
					"user" => [
						"type" => "username",
						"required" => true,
					],
				],
				"fixed_parameters" => [],
				"output_format" => "json",
				"result_shape" => "single",
				"mutation" => ["kind" => "create"],
			],
		]);
	}

	private static function buildAdapter(
		\Hestiacp\Adapter\ProcessRunnerInterface $runner,
		SpyLockManager $lockManager,
		?CommandRegistry $registry = null
	): CommandAdapter {
		return new CommandAdapter(
			$registry ?? self::registryWithMutatingOp(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			$lockManager
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("A. read-only operation does not acquire a lock", [self::class, "testReadOnlyDoesNotLock"]);
		$t->test("B. mutating operation acquires the lock for the correct user", [self::class, "testMutatingAcquiresCorrectUserLock"]);
		$t->test("F. lock is released after successful execution", [self::class, "testLockReleasedAfterSuccess"]);
		$t->test("G. lock is released after command failure (non-zero exit)", [self::class, "testLockReleasedAfterCommandFailure"]);
		$t->test("H. lock is released after a subprocess/runner exception", [self::class, "testLockReleasedAfterException"]);
		$t->test("J. lock timeout returns an adapter-level error and never executes the command", [self::class, "testLockTimeoutNeverExecutes"]);
		$t->test("J. lock mechanism failure (LockUnavailableException) returns LOCK_UNAVAILABLE and never executes", [self::class, "testLockUnavailableNeverExecutes"]);
		$t->test("result model: successful mutating op reports mutation_state=confirmed", [self::class, "testMutationStateConfirmed"]);
		$t->test("result model: failed mutating op reports mutation_state=unknown, never partial_failure", [self::class, "testMutationStateUnknownOnFailure"]);
		$t->test("result model: pre-execution rejection reports mutation_state=not_attempted", [self::class, "testMutationStateNotAttemptedOnValidationFailure"]);
	}

	public static function testReadOnlyDoesNotLock(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager, new CommandRegistry());

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(0, count($lockManager->acquireCalls), "read-only operation must never call acquire()");
		assertEquals(0, $lockManager->releaseCalls, "read-only operation must never call release()");
	}

	public static function testMutatingAcquiresCorrectUserLock(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals(["bob"], $lockManager->acquireCalls, "lock must be acquired exactly once, for the validated 'user' parameter");
	}

	public static function testLockReleasedAfterSuccess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released after a successful invocation");
	}

	public static function testLockReleasedAfterCommandFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(1, "", "some failure"));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released even when the underlying command fails");
	}

	public static function testLockReleasedAfterException(): void {
		$runner = new ThrowingProcessRunner(new \RuntimeException("proc_open exploded"));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$caught = null;
		try {
			$adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);
		} catch (\RuntimeException $e) {
			$caught = $e;
		}

		assertTrue($caught !== null, "the runner's exception must propagate to the caller, not be swallowed");
		assertEquals(1, $lockManager->releaseCalls, "lock must still be released when the process runner throws");
	}

	public static function testLockTimeoutNeverExecutes(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$lockManager = new SpyLockManager(false); // acquire() returns false: ordinary timeout
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("LOCK_TIMEOUT", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "the underlying command must never be spawned when the lock times out");
		assertEquals(0, $lockManager->releaseCalls, "release() must not be called for a lock that was never acquired");
	}

	public static function testLockUnavailableNeverExecutes(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$lockManager = new SpyLockManager(true, new LockUnavailableException("lock dir missing"));
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("LOCK_UNAVAILABLE", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "the underlying command must never be spawned when the locking mechanism itself fails");
	}

	public static function testMutationStateConfirmed(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$adapter = self::buildAdapter($runner, new SpyLockManager(true));

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("confirmed", $result->mutationState, "mutation_state on exit 0");
	}

	public static function testMutationStateUnknownOnFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(1, "", "failed midway"));
		$adapter = self::buildAdapter($runner, new SpyLockManager(true));

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("unknown", $result->mutationState, "mutation_state on non-zero exit must be 'unknown', never a more specific guess");
	}

	public static function testMutationStateNotAttemptedOnValidationFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		// Missing required "user" parameter — rejected before any lock is
		// even attempted.
		$result = $adapter->invoke(self::MUTATING_OPERATION, []);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("not_attempted", $result->mutationState, "mutation_state for a pre-execution rejection of a mutating operation");
		assertEquals(0, count($lockManager->acquireCalls), "validation failures must be rejected before any lock is attempted");
	}
}
