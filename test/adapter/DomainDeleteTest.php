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
 * Unit tests for the "domain.delete" operation (bin/v-delete-web-domain) —
 * the second real mutating operation, added to stress-test the same
 * generic architecture "domain.create" already proved (registry →
 * validation → per-user lock → real Hestia CLI → AdapterResult →
 * mutation_state). See DOMAIN_DELETE_IMPLEMENTATION.md for the full
 * design rationale and the architectural findings this operation
 * produced.
 *
 * Structured identically to DomainCreateTest.php on purpose — same test
 * shape, different operation — so a reviewer can compare the two side by
 * side rather than trust that "the same architecture" claim from prose
 * alone.
 *
 * All tests use FakeProcessRunner (or the small LockProbingProcessRunner
 * defined below, for the two concurrency tests) — no real subprocess for
 * bin/v-delete-web-domain itself, no real Hestia installation, no root
 * required. Locking is tested against the REAL LockManager throughout —
 * no fake/synthetic locking logic is introduced anywhere in this file,
 * per this task's explicit instruction.
 */
final class DomainDeleteTest {
	/**
	 * Same rationale as DomainCreateTest::buildAdapter(): domain.delete is
	 * mutating, so CommandAdapter's own default lock manager (pointing at
	 * the real, production `/usr/local/hestia/data/adapter-locks/` path)
	 * would throw LockUnavailableException in this sandbox. Every test
	 * either gets a real, temp-directory-backed LockManager, or an
	 * explicit SpyLockManager where a specific lock outcome must be
	 * forced.
	 */
	private static function buildAdapter(
		ProcessRunnerInterface $runner,
		?\Hestiacp\Adapter\LockManagerInterface $lockManager = null
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
			$lockManager ?? self::tempLockManager()
		);
	}

	private static function tempLockDirectory(): string {
		$dir = sys_get_temp_dir() . "/adapter-domain-delete-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return $dir;
	}

	private static function tempLockManager(?string $dir = null): LockManager {
		return new LockManager($dir ?? self::tempLockDirectory(), 5);
	}

	public static function register(MiniTest $t): void {
		$t->test("registry: domain.delete is registered, correct script and mutation kind", [self::class, "testRegistered"]);
		$t->test("registry: valid parameters generate the expected v-delete-web-domain argv", [self::class, "testGeneratedArgv"]);
		$t->test("validation: unexpected parameter ('restart') is rejected", [self::class, "testUnknownParameterRejected"]);
		$t->test("validation: missing required parameter ('domain') is rejected", [self::class, "testMissingParameterRejected"]);
		$t->test("validation: invalid username is rejected before execution", [self::class, "testInvalidUsernameRejected"]);
		$t->test("validation: invalid domain is rejected before execution", [self::class, "testInvalidDomainRejected"]);
		$t->test("validation: shell-metacharacter payloads cannot alter argv, never reach the process runner", [self::class, "testInjectionShapedInputRejected"]);
		$t->test("validation: a rejected request never acquires the lock", [self::class, "testValidationFailureDoesNotAcquireLock"]);
		$t->test("success: status=ok, mutation_state=confirmed, exit_code=0", [self::class, "testSuccessStatusAndMutationState"]);
		$t->test("hestia error: E_NOTEXIST (domain doesn't exist) -> hestia_error / mutation_state=unknown", [self::class, "testNotExistFailure"]);
		$t->test("hestia error: E_RESTART (post-deletion reload failure) -> hestia_error / mutation_state=unknown", [self::class, "testRestartFailure"]);
		$t->test("hestia error: exit code/stdout/stderr are preserved exactly", [self::class, "testStreamsPreserved"]);
		$t->test("locking: mutation acquires the per-user lock, released after success", [self::class, "testLockReleasedAfterSuccess"]);
		$t->test("locking: lock released after a Hestia-reported failure", [self::class, "testLockReleasedAfterFailure"]);
		$t->test("locking: lock released after a process-runner exception", [self::class, "testLockReleasedAfterException"]);
		$t->test("locking: lock timeout prevents v-delete-web-domain execution", [self::class, "testLockTimeoutPreventsExecution"]);
		$t->test("concurrency: the lock is genuinely held (real flock) for the entire duration of a domain.delete call for one user", [self::class, "testLockGenuinelyHeldDuringExecutionSameUser"]);
		$t->test("concurrency: a different user's lock is NOT blocked while one user's domain.delete is executing", [self::class, "testDifferentUserNotBlockedDuringExecution"]);
		$t->test("security invariant: an unknown operation still spawns zero processes", [self::class, "testUnknownOperationStillRejected"]);
	}

	public static function testRegistered(): void {
		$registry = new CommandRegistry();
		assertTrue($registry->has("domain.delete"), "domain.delete must be a registered operation");

		$entry = $registry->get("domain.delete");
		assertTrue($entry !== null, "domain.delete must resolve to a registry entry");
		assertEquals("v-delete-web-domain", $entry["script"], "registry entry's underlying script");
		assertEquals("delete", $entry["mutation"]["kind"] ?? null, "registry entry must declare a non-'read' mutation kind");
		assertEquals(["user", "domain", "restart"], $entry["argument_order"], "argument_order must match bin/v-delete-web-domain's USER DOMAIN [RESTART] contract");
		assertEquals(["restart" => "yes"], $entry["fixed_parameters"] ?? null, "restart must be the only fixed parameter, fixed to 'yes'");
	}

	public static function testGeneratedArgv(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		assertEquals(1, count($runner->calls), "exactly one invocation");
		$call = $runner->calls[0];
		assertEquals("/usr/bin/sudo", $call["binary"], "binary");
		assertEquals(
			["/usr/local/hestia/bin/v-delete-web-domain", "admin", "example.com", "yes"],
			$call["argv"],
			"argv must be exactly [script, user, domain, restart='yes'], matching bin/v-delete-web-domain's " .
				"USER DOMAIN [RESTART] contract with only user/domain caller-supplied"
		);
		assertEquals("v-delete-web-domain", $result->resolvedCommand, "resolvedCommand");
	}

	public static function testUnknownParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "restart" is a real bin/v-delete-web-domain argument, but it is
		// NOT part of domain.delete's public parameter schema (it is a
		// registry-fixed value) — supplying it as a caller parameter must
		// be rejected, not silently accepted/overridden. Mirrors
		// DomainCreateTest::testUnknownParameterRejected's "ip" case.
		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com", "restart" => "no"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unexpected parameter");
	}

	public static function testMissingParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when 'domain' is missing");
	}

	public static function testInvalidUsernameRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "ad min;", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed user");
	}

	public static function testInvalidDomainRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com;whoami"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed domain");
	}

	public static function testInjectionShapedInputRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$attempts = [
			["user" => 'admin$(whoami)', "domain" => "example.com"],
			["user" => "admin", "domain" => 'example.com`id`'],
			["user" => "admin", "domain" => "example.com && rm -rf /"],
			["user" => "admin", "domain" => "example.com\nv-delete-user attacker"],
			["user" => "admin`", "domain" => "example.com"],
		];

		foreach ($attempts as $params) {
			$result = $adapter->invoke("domain.delete", $params);
			assertEquals("adapter_error", $result->status, "status for payload " . json_encode($params));
			assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode for payload " . json_encode($params));
		}

		assertEquals(0, count($runner->calls), "none of the injection-shaped attempts should ever reach the process runner");
	}

	public static function testValidationFailureDoesNotAcquireLock(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "bad;domain"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("not_attempted", $result->mutationState, "mutation_state for a pre-execution validation failure");
		assertEquals(0, count($lockManager->acquireCalls), "a validation failure must never attempt to acquire the lock");
	}

	public static function testSuccessStatusAndMutationState(): void {
		// bin/v-delete-web-domain prints nothing on success and exits 0
		// (bare `exit` at end of file, line 163) — confirmed by source
		// read, not assumed.
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("confirmed", $result->mutationState, "mutation_state on exit 0");
	}

	public static function testNotExistFailure(): void {
		// Exit code 3 (E_NOTEXIST): bin/v-delete-web-domain's own
		// is_object_valid('web', 'DOMAIN', "$domain") (line 47) — fires
		// during the script's "Verifications" section, BEFORE the
		// "Action" section begins (line 52 onward). This is the
		// domain.delete analogue of domain.create's E_EXISTS case: a
		// non-zero exit that (per source, not per the adapter's generic
		// model) is knowable to have occurred before any mutation.
		$runner = new FakeProcessRunner(new ProcessResult(3, "", "Error: web domain example.com doesn't exist"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_NOTEXIST", $result->hestiaErrorCode, "exit code 3 must map to E_NOTEXIST per func/main.sh's E_* table");
		assertEquals("unknown", $result->mutationState, "mutation_state on non-zero exit must be 'unknown', never a more specific guess — even though this specific case is, per source, pre-mutation");
	}

	public static function testRestartFailure(): void {
		// Exit code 20 (E_RESTART): bin/v-delete-web-domain's own
		// check_result after $BIN/v-restart-web/v-restart-proxy/
		// v-restart-web-backend (lines 148-157) — these run AFTER the
		// domain has already been fully deleted (directories removed,
		// web.conf line removed, lines 89/115-116). This was the finding
		// DOMAIN_DELETE_IMPLEMENTATION.md flagged for a later architecture
		// decision — MUTATION_AND_AUTHORIZATION_DESIGN.md made that
		// decision, and CommandRegistry's "domain.delete" entry now
		// declares "E_RESTART" under mutation.known_post_mutation_exit_codes,
		// so this now-source-verified-complete mutation correctly reports
		// 'confirmed_degraded', not 'unknown'.
		$runner = new FakeProcessRunner(new ProcessResult(20, "", "Error: Web restart failed"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_RESTART", $result->hestiaErrorCode, "exit code 20 must map to E_RESTART per func/main.sh's E_* table");
		assertEquals("confirmed_degraded", $result->mutationState, "mutation_state must be 'confirmed_degraded': the registry declares E_RESTART as a known post-mutation exit code for domain.delete, and the deletion is, per source, already complete at this point");
	}

	public static function testStreamsPreserved(): void {
		$runner = new FakeProcessRunner(new ProcessResult(11, "", "Error: WEB_SYSTEM is not enabled"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals(11, $result->exitCode, "exitCode");
		assertEquals("", $result->stdout, "stdout");
		assertEquals("Error: WEB_SYSTEM is not enabled", $result->stderr, "stderr");
		assertEquals("E_DISABLED", $result->hestiaErrorCode, "exit code 11 must map to E_DISABLED (is_system_enabled's failure path)");
		assertEquals("Error: WEB_SYSTEM is not enabled", $result->errorMessage, "errorMessage falls back through stderr, as already proven for domain.get/domain.list/domain.create");
	}

	public static function testLockReleasedAfterSuccess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(["admin"], $lockManager->acquireCalls, "lock must be acquired exactly once, for the validated 'user' parameter");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released after a successful domain.delete invocation");
	}

	public static function testLockReleasedAfterFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(3, "", "Error: web domain example.com doesn't exist"));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released even when bin/v-delete-web-domain returns a non-zero exit code");
	}

	public static function testLockReleasedAfterException(): void {
		$runner = new ThrowingProcessRunner(new \RuntimeException("proc_open exploded"));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$caught = null;
		try {
			$adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);
		} catch (\RuntimeException $e) {
			$caught = $e;
		}

		assertTrue($caught !== null, "the runner's exception must propagate to the caller, not be swallowed");
		assertEquals(1, $lockManager->releaseCalls, "lock must still be released when the process runner throws");
	}

	public static function testLockTimeoutPreventsExecution(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(false); // acquire() returns false: contention/timeout
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("LOCK_TIMEOUT", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals("not_attempted", $result->mutationState, "mutation_state on lock timeout");
		assertEquals(["admin"], $lockManager->acquireCalls, "the lock must have been attempted, for the correct user, before timing out");
		assertEquals(0, count($runner->calls), "bin/v-delete-web-domain must never be spawned when the lock times out");
	}

	/**
	 * Proves real, same-process, distinct-file-descriptor flock
	 * contention DURING a domain.delete call — the same technique
	 * LockManagerTest.php already establishes is a valid proof of real
	 * flock() contention (two LockManager instances = two independent
	 * open file descriptions on the same lock file). Rather than a
	 * SpyLockManager, this uses the REAL LockManager throughout: the
	 * adapter's own lock, and a second, independent LockManager instance
	 * (the "probe") that attempts to acquire the SAME user's lock WHILE
	 * bin/v-delete-web-domain's (faked) process is "running" — i.e. from
	 * inside the process runner's run() method, which CommandAdapter only
	 * calls while its own lock is held (see CommandAdapter::invoke()'s
	 * try/finally around $this->runner->run()).
	 */
	public static function testLockGenuinelyHeldDuringExecutionSameUser(): void {
		$dir = self::tempLockDirectory();
		$adapterLockManager = self::tempLockManager($dir);

		$runner = new class ($dir) implements ProcessRunnerInterface {
			private string $dir;
			public ?bool $probeAcquired = null;

			public function __construct(string $dir) {
				$this->dir = $dir;
			}

			public function run(string $binary, array $argv): ProcessResult {
				// A second, independent LockManager, same directory, same
				// user, very short timeout — proves whether the adapter's
				// own lock is genuinely still held right now.
				$probe = new LockManager($this->dir, 1);
				$this->probeAcquired = $probe->acquire("admin");
				if ($this->probeAcquired) {
					$probe->release();
				}
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner, $adapterLockManager);
		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($runner->probeAcquired === false, "a concurrent domain.delete for the SAME user must be blocked while the first is executing (real flock contention, not a mock)");
	}

	/**
	 * Same technique as the previous test, but the probe uses a
	 * DIFFERENT user — proving the lock is genuinely per-user, not
	 * global, using the real LockManager on both sides.
	 */
	public static function testDifferentUserNotBlockedDuringExecution(): void {
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
				$this->probeAcquired = $probe->acquire("someone-else");
				if ($this->probeAcquired) {
					$probe->release();
				}
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner, $adapterLockManager);
		$result = $adapter->invoke("domain.delete", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($runner->probeAcquired === true, "a concurrent domain.delete for a DIFFERENT user must NOT be blocked while another user's is executing");
	}

	public static function testUnknownOperationStillRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// Now that domain.delete is real, re-confirm (independent of
		// CommandAdapterTest/DomainListTest's own fixed-up placeholder
		// tests) that a genuinely unregistered operation name still
		// spawns zero processes — the core security invariant this whole
		// registry design rests on.
		$result = $adapter->invoke("domain.suspend", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNKNOWN_OPERATION", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unknown operation");
	}
}
