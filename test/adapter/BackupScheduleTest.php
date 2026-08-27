<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockManager;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\ProcessRunnerInterface;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Unit tests for the "backup.schedule" operation (bin/v-schedule-user-backup)
 * — the third real mutating operation, and the first NOT named after what
 * the underlying Hestia object ends up being ("domain.create" creates a
 * domain; "backup.schedule" does NOT create a backup — it queues one, per
 * BACKUP_CREATE_DESIGN.md's finding that the actual archive is produced,
 * up to five minutes later, by a separate, cron-driven, fully detached run
 * of bin/v-backup-user this operation never touches).
 *
 * Structured identically to DomainCreateTest.php/DomainDeleteTest.php on
 * purpose — same test shape, different operation, same generic
 * architecture (registry -> validation -> authorization -> per-user lock
 * -> real Hestia CLI -> AdapterResult -> mutation_state), stress-tested a
 * third time. See BACKUP_SCHEDULE_IMPLEMENTATION.md for the full design
 * rationale.
 *
 * All tests use FakeProcessRunner (or a small anonymous ProcessRunnerInterface
 * for the one concurrency test) — no real subprocess for
 * bin/v-schedule-user-backup, no real Hestia installation, no root
 * required, and bin/v-backup-user (the actual worker) is never invoked or
 * referenced by any test here — the adapter's contract ends at the queue
 * append, per this task's explicit instruction. Locking is tested against
 * the REAL LockManager throughout, exactly as domain.create/domain.delete
 * already do — no fake/synthetic locking logic is introduced.
 */
final class BackupScheduleTest {
	private static function buildAdapter(
		ProcessRunnerInterface $runner,
		?\Hestiacp\Adapter\LockManagerInterface $lockManager = null,
		?\Hestiacp\Adapter\AuthorizerInterface $authorizer = null
	): CommandAdapter {
		return new CommandAdapter(
			new CommandRegistry(),
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

	private static function tempLockDirectory(): string {
		$dir = sys_get_temp_dir() . "/adapter-backup-schedule-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return $dir;
	}

	private static function tempLockManager(?string $dir = null): LockManager {
		return new LockManager($dir ?? self::tempLockDirectory(), 5);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. successful scheduling: status=ok, mutation_state=confirmed", [self::class, "testSuccessfulScheduling"]);
		$t->test("2. registry resolves backup.schedule to v-schedule-user-backup", [self::class, "testScriptResolution"]);
		$t->test("3. generated argv is exactly [script, user]", [self::class, "testGeneratedArgv"]);
		$t->test("4. authorization denial occurs before lock acquisition", [self::class, "testDenialBeforeLock"]);
		$t->test("5. authorization denial occurs before process execution", [self::class, "testDenialBeforeProcess"]);
		$t->test("6. the per-user lock is actually acquired for backup.schedule", [self::class, "testLockAcquiredForUser"]);
		$t->test("7. E_EXISTS (exit 4, already scheduled) is propagated correctly", [self::class, "testAlreadyScheduledPropagated"]);
		$t->test("8. a non-zero exit does not claim a confirmed mutation", [self::class, "testNonZeroExitNotConfirmed"]);
		$t->test("9. the real, production registry accepts backup.schedule without throwing", [self::class, "testRegistryConstructionAcceptsOperation"]);
		$t->test("10. no known_post_mutation_exit_codes are declared for this operation", [self::class, "testNoKnownPostMutationExitCodes"]);
		$t->test("11. concurrency: a second adapter-routed backup.schedule call for the SAME user is blocked while the first is executing (real flock)", [self::class, "testConcurrentCallsForSameUserAreSerialized"]);
		$t->test("validation: unexpected parameter is rejected", [self::class, "testUnknownParameterRejected"]);
		$t->test("validation: missing required parameter ('user') is rejected", [self::class, "testMissingParameterRejected"]);
		$t->test("validation: a rejected request never acquires the lock", [self::class, "testValidationFailureDoesNotAcquireLock"]);
		$t->test("locking: the lock is released after successful scheduling", [self::class, "testLockReleasedAfterSuccess"]);
		$t->test("locking: the lock is released after a Hestia-reported failure", [self::class, "testLockReleasedAfterFailure"]);
	}

	public static function testSuccessfulScheduling(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"], ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("confirmed", $result->mutationState, "mutation_state on exit 0 -- confirmed means the backup job was successfully QUEUED, not that a backup archive exists yet");
	}

	public static function testScriptResolution(): void {
		$registry = new CommandRegistry();
		assertTrue($registry->has("backup.schedule"), "backup.schedule must be a registered operation");

		$entry = $registry->get("backup.schedule");
		assertTrue($entry !== null, "backup.schedule must resolve to a registry entry");
		assertEquals("v-schedule-user-backup", $entry["script"], "registry entry's underlying script must be v-schedule-user-backup, never v-backup-user (the worker)");
		assertEquals("create", $entry["mutation"]["kind"] ?? null, "registry entry must declare a non-'read' mutation kind");
	}

	public static function testGeneratedArgv(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals(1, count($runner->calls), "exactly one invocation");
		$call = $runner->calls[0];
		assertEquals("/usr/bin/sudo", $call["binary"], "binary");
		assertEquals(
			["/usr/local/hestia/bin/v-schedule-user-backup", "admin"],
			$call["argv"],
			"argv must be exactly [script, user] -- no fixed parameters, matching bin/v-schedule-user-backup's single-argument USER contract"
		);
		assertEquals("v-schedule-user-backup", $result->resolvedCommand, "resolvedCommand");
	}

	public static function testDenialBeforeLock(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$authorizer = new SpyAuthorizer(false);
		$adapter = self::buildAdapter($runner, $lockManager, $authorizer);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(1, count($authorizer->calls), "the authorizer must have been consulted exactly once");
		assertEquals(0, count($lockManager->acquireCalls), "a denied request must never attempt to acquire the per-user lock");
	}

	public static function testDenialBeforeProcess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$authorizer = new SpyAuthorizer(false);
		$adapter = self::buildAdapter($runner, null, $authorizer);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "a denied request must never spawn bin/v-schedule-user-backup");
	}

	public static function testLockAcquiredForUser(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals(["admin"], $lockManager->acquireCalls, "the lock must be acquired for the correct, validated user -- the same per-user LockManager already used by domain.create/domain.delete, with no backup-specific locking logic");
	}

	public static function testAlreadyScheduledPropagated(): void {
		// Exit code 4 (E_EXISTS) is what bin/v-schedule-user-backup's own
		// is_backup_scheduled() produces (func/main.sh check_result
		// "$E_EXISTS" "... is already scheduled") when a queue entry for
		// this user already exists -- confirmed by source read, and this
		// is the operation's actual, pre-existing idempotency guard (see
		// BACKUP_CREATE_DESIGN.md Part 2/Part 8). The adapter does not
		// special-case this code in any way; it is propagated through the
		// same, unmodified, generic exit-code mapping every other
		// operation already uses.
		$runner = new FakeProcessRunner(new ProcessResult(4, "", "backup is already scheduled"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_EXISTS", $result->hestiaErrorCode, "exit code 4 must map to E_EXISTS per func/main.sh's E_* table -- the SAME table every prior operation already uses");
		assertEquals("unknown", $result->mutationState, "mutation_state on a pre-mutation rejection like E_EXISTS must be 'unknown', never 'confirmed' -- no known_post_mutation_exit_codes are declared for this operation (see test 10)");
	}

	public static function testNonZeroExitNotConfirmed(): void {
		$runner = new FakeProcessRunner(new ProcessResult(11, "", "user backup is disabled")); // 11 = E_DISABLED
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_DISABLED", $result->hestiaErrorCode, "exit code 11 must map to E_DISABLED");
		assertTrue($result->mutationState !== "confirmed", "a non-zero exit must never be reported as a confirmed mutation");
		assertEquals("unknown", $result->mutationState, "mutation_state on any non-zero exit must be 'unknown' -- this operation declares no known_post_mutation_exit_codes at all (unlike domain.create/domain.delete's E_RESTART), so there is no exit code that could ever classify as confirmed_degraded here");
	}

	public static function testRegistryConstructionAcceptsOperation(): void {
		// Constructing the REAL, production registry (no additionalOperations)
		// must not throw -- proves backup.schedule's own registry entry
		// (in particular, its absent known_post_mutation_exit_codes field)
		// passes CommandRegistry::validateMutationMetadata() cleanly,
		// exactly like every other operation.
		$registry = new CommandRegistry();
		assertTrue($registry->has("backup.schedule"), "backup.schedule must be present in the real, production registry");
	}

	public static function testNoKnownPostMutationExitCodes(): void {
		$registry = new CommandRegistry();
		$entry = $registry->get("backup.schedule");

		assertTrue(!isset($entry["mutation"]["known_post_mutation_exit_codes"]), "backup.schedule must declare NO known_post_mutation_exit_codes -- every non-zero exit bin/v-schedule-user-backup can produce fires strictly before its one mutating line (see BACKUP_CREATE_DESIGN.md Part 5)");
	}

	/**
	 * Same real-flock-probe technique DomainDeleteTest.php's own
	 * concurrency tests already use: a second, independent LockManager on
	 * the SAME directory, invoked from INSIDE the first call's process
	 * runner, proves whether the adapter's own lock is genuinely still
	 * held right now -- not a mock, not a call-count assertion. This is
	 * the adapter-level proof that two concurrent backup.schedule calls
	 * for the same user cannot both pass through to
	 * is_backup_scheduled()/the queue append at the same time, closing
	 * the source-verified TOCTOU race BACKUP_CREATE_DESIGN.md Part 4
	 * identified in bin/v-schedule-user-backup itself.
	 */
	public static function testConcurrentCallsForSameUserAreSerialized(): void {
		$dir = self::tempLockDirectory();
		$adapterLockManager = self::tempLockManager($dir);

		$runner = new class ($dir) implements ProcessRunnerInterface {
			private string $dir;
			public ?bool $probeAcquired = null;

			public function __construct(string $dir) {
				$this->dir = $dir;
			}

			public function run(string $binary, array $argv): ProcessResult {
				$probe = new LockManager($this->dir, 1);
				$this->probeAcquired = $probe->acquire("admin");
				if ($this->probeAcquired) {
					$probe->release();
				}
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner, $adapterLockManager);
		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($runner->probeAcquired === false, "a concurrent backup.schedule call for the SAME user must be blocked while the first is executing (real flock contention, not a mock) -- this is what prevents two adapter-routed calls from both queuing duplicate work");
	}

	public static function testUnknownParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "notify" is a real bin/v-backup-user argument, but it is not
		// part of backup.schedule's public parameter schema at all --
		// bin/v-schedule-user-backup itself takes only USER (confirmed by
		// source read) -- so supplying it must be rejected the same way
		// any other unrecognized key would be.
		$result = $adapter->invoke("backup.schedule", ["user" => "admin", "notify" => "yes"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unexpected parameter");
	}

	public static function testMissingParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("backup.schedule", []);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when 'user' is missing");
	}

	public static function testValidationFailureDoesNotAcquireLock(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("backup.schedule", ["user" => "ad min;"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("not_attempted", $result->mutationState, "mutation_state for a pre-execution validation failure");
		assertEquals(0, count($lockManager->acquireCalls), "a validation failure must never attempt to acquire the lock");
	}

	public static function testLockReleasedAfterSuccess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released after a successful backup.schedule invocation");
	}

	public static function testLockReleasedAfterFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(4, "", "backup is already scheduled"));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("backup.schedule", ["user" => "admin"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released even when bin/v-schedule-user-backup returns a non-zero exit code");
	}
}
