<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockManager;
use Hestiacp\Adapter\ProcessResult;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Unit tests for the "domain.create" operation (bin/v-add-web-domain) —
 * the first real mutating operation registered in CommandRegistry. Proves
 * the full architecture end to end: registry → validation → per-user lock
 * → (faked) v-add-web-domain execution → structured AdapterResult →
 * mutation_state. See DOMAIN_CREATE_IMPLEMENTATION.md for the full design
 * rationale.
 *
 * All tests use FakeProcessRunner — no real subprocess, no real Hestia
 * installation, no root required. Lock behavior is tested against the
 * real LockManager (default constructor arg) for the success/failure
 * paths (M, N) since a real temporary-directory-backed LockManager is
 * just as fast and deterministic as a mock for a single acquire/release
 * pair, and against SpyLockManager only where a specific lock outcome
 * (timeout) must be forced (test I).
 */
final class DomainCreateTest {
	/**
	 * Unlike CommandAdapterTest's buildAdapter(), this MUST always supply
	 * an explicit lock manager: domain.create is mutating, and
	 * CommandAdapter's own default (`new LockManager()`) points at the
	 * real, production `/usr/local/hestia/data/adapter-locks/` path,
	 * which does not exist in this test sandbox (no real Hestia
	 * installation — see LockManagerTest.php's own use of a temp
	 * directory for the same reason). Tests that don't care about lock
	 * *behavior* specifically still get a real, working LockManager
	 * backed by a fresh temp directory, so the mutating code path runs
	 * end to end rather than being skipped.
	 */
	private static function buildAdapter(
		FakeProcessRunner $runner,
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

	private static function tempLockManager(): LockManager {
		$dir = sys_get_temp_dir() . "/adapter-domain-create-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return new LockManager($dir, 5);
	}

	public static function register(MiniTest $t): void {
		$t->test("A. domain.create is registered in CommandRegistry", [self::class, "testRegistered"]);
		$t->test("B. valid parameters generate the expected v-add-web-domain argv", [self::class, "testGeneratedArgv"]);
		$t->test("C. unknown parameter ('ip', not part of the public schema) is rejected", [self::class, "testUnknownParameterRejected"]);
		$t->test("D. missing required parameter ('domain') is rejected", [self::class, "testMissingParameterRejected"]);
		$t->test("E. invalid username is rejected before execution", [self::class, "testInvalidUsernameRejected"]);
		$t->test("F. invalid domain is rejected before execution", [self::class, "testInvalidDomainRejected"]);
		$t->test("G. shell-metacharacter payloads cannot alter argv, never reach the process runner", [self::class, "testInjectionShapedInputRejected"]);
		$t->test("H. validation failures do not acquire the per-user lock", [self::class, "testValidationFailureDoesNotAcquireLock"]);
		$t->test("I. lock timeout prevents v-add-web-domain execution", [self::class, "testLockTimeoutPreventsExecution"]);
		$t->test("J. successful execution: status=ok, mutation_state=confirmed", [self::class, "testSuccessStatusAndMutationState"]);
		$t->test("K. non-zero execution: status=hestia_error, mutation_state=unknown", [self::class, "testFailureStatusAndMutationState"]);
		$t->test("L. exit code/stdout/stderr are preserved on failure", [self::class, "testStreamsPreservedOnFailure"]);
		$t->test("M. the lock is released after a successful execution", [self::class, "testLockReleasedAfterSuccess"]);
		$t->test("N. the lock is released after Hestia returns an error", [self::class, "testLockReleasedAfterFailure"]);
		$t->test("O. registry declares E_RESTART as a known post-mutation exit code", [self::class, "testRegistryDeclaresERestart"]);
		$t->test("P. an E_RESTART failure after domain creation: status=hestia_error, mutation_state=confirmed_degraded", [self::class, "testRestartFailureIsConfirmedDegraded"]);
	}

	public static function testRegistered(): void {
		$registry = new CommandRegistry();
		assertTrue($registry->has("domain.create"), "domain.create must be a registered operation");

		$entry = $registry->get("domain.create");
		assertTrue($entry !== null, "domain.create must resolve to a registry entry");
		assertEquals("v-add-web-domain", $entry["script"], "registry entry's underlying script");
		assertEquals("create", $entry["mutation"]["kind"] ?? null, "registry entry must declare a non-'read' mutation kind");
	}

	public static function testGeneratedArgv(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		assertEquals(1, count($runner->calls), "exactly one invocation");
		$call = $runner->calls[0];
		assertEquals("/usr/bin/sudo", $call["binary"], "binary");
		assertEquals(
			["/usr/local/hestia/bin/v-add-web-domain", "admin", "example.com", "", "yes", "", ""],
			$call["argv"],
			"argv must be exactly [script, user, domain, ip='', restart='yes', aliases='', proxy_ext=''], " .
				"matching bin/v-add-web-domain's USER DOMAIN [IP] [RESTART] [ALIASES] [PROXY_EXTENSIONS] contract " .
				"with only user/domain caller-supplied and the rest at their registry-fixed defaults"
		);
		assertEquals("v-add-web-domain", $result->resolvedCommand, "resolvedCommand");
	}

	public static function testUnknownParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "ip" is a real bin/v-add-web-domain argument, but it is NOT part
		// of domain.create's public parameter schema (it is a
		// registry-fixed value) — supplying it as a caller parameter must
		// be rejected the same way any other unrecognized key would be,
		// not silently accepted/overridden.
		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com", "ip" => "203.0.113.5"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unexpected parameter");
	}

	public static function testMissingParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when 'domain' is missing");
	}

	public static function testInvalidUsernameRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "ad min;", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed user");
	}

	public static function testInvalidDomainRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com;whoami"]);

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
			["user" => "admin", "domain" => "example.com\nv-add-user attacker pass"],
			["user" => "admin`", "domain" => "example.com"],
		];

		foreach ($attempts as $params) {
			$result = $adapter->invoke("domain.create", $params);
			assertEquals("adapter_error", $result->status, "status for payload " . json_encode($params));
			assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode for payload " . json_encode($params));
		}

		assertEquals(0, count($runner->calls), "none of the injection-shaped attempts should ever reach the process runner");
	}

	public static function testValidationFailureDoesNotAcquireLock(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "bad;domain"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("not_attempted", $result->mutationState, "mutation_state for a pre-execution validation failure");
		assertEquals(0, count($lockManager->acquireCalls), "a validation failure must never attempt to acquire the lock");
	}

	public static function testLockTimeoutPreventsExecution(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(false); // acquire() returns false: contention/timeout
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("LOCK_TIMEOUT", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals("not_attempted", $result->mutationState, "mutation_state on lock timeout");
		assertEquals(["admin"], $lockManager->acquireCalls, "the lock must have been attempted, for the correct user, before timing out");
		assertEquals(0, count($runner->calls), "bin/v-add-web-domain must never be spawned when the lock times out");
	}

	public static function testSuccessStatusAndMutationState(): void {
		// bin/v-add-web-domain prints nothing on success and exits 0 (bare
		// `exit` at end of file) — confirmed by source read, not assumed.
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("confirmed", $result->mutationState, "mutation_state on exit 0");
	}

	public static function testFailureStatusAndMutationState(): void {
		// Exit code 4 (E_EXISTS) is what bin/v-add-web-domain's own
		// is_domain_new()/is_web_domain_new() produces for a duplicate
		// domain (func/domain.sh check_result "$E_EXISTS" "Web domain ...
		// exists") — used here as a representative, source-verified
		// non-zero exit, not a special-cased "duplicate domain" branch in
		// the adapter (there is none).
		$runner = new FakeProcessRunner(new ProcessResult(4, "", "Error: Web domain example.com exists"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_EXISTS", $result->hestiaErrorCode, "exit code 4 must map to E_EXISTS per func/main.sh's E_* table");
		assertEquals("unknown", $result->mutationState, "mutation_state on non-zero exit must be 'unknown', never a more specific guess like 'partial_failure'");
	}

	public static function testStreamsPreservedOnFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(8, "", "Error: WEB_DOMAINS - package limit reached"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals(8, $result->exitCode, "exitCode");
		assertEquals("", $result->stdout, "stdout");
		assertEquals("Error: WEB_DOMAINS - package limit reached", $result->stderr, "stderr");
		assertEquals("E_LIMIT", $result->hestiaErrorCode, "exit code 8 must map to E_LIMIT (is_package_full's failure path)");
		assertEquals("Error: WEB_DOMAINS - package limit reached", $result->errorMessage, "errorMessage falls back through stderr, as already proven for domain.get/domain.list");
	}

	public static function testLockReleasedAfterSuccess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released after a successful domain.create invocation");
	}

	public static function testLockReleasedAfterFailure(): void {
		$runner = new FakeProcessRunner(new ProcessResult(4, "", "Error: Web domain example.com exists"));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals(1, $lockManager->releaseCalls, "lock must be released even when bin/v-add-web-domain returns a non-zero exit code");
	}

	public static function testRegistryDeclaresERestart(): void {
		$registry = new CommandRegistry();
		$entry = $registry->get("domain.create");

		assertEquals(["E_RESTART"], $entry["mutation"]["known_post_mutation_exit_codes"] ?? null, "domain.create must declare E_RESTART as a known post-mutation exit code (MUTATION_AND_AUTHORIZATION_DESIGN.md Part 2)");
	}

	public static function testRestartFailureIsConfirmedDegraded(): void {
		// Exit code 20 (E_RESTART) — bin/v-add-web-domain's own restart
		// step runs strictly after the domain's actual creation is
		// already durably complete (confirmed by source read during
		// DOMAIN_CREATE_IMPLEMENTATION.md), so an E_RESTART failure here
		// must classify as confirmed_degraded, not unknown, now that the
		// registry declares it.
		$runner = new FakeProcessRunner(new ProcessResult(20, "", "Error: Restart failed"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.create", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_RESTART", $result->hestiaErrorCode, "exit code 20 must map to E_RESTART");
		assertEquals("confirmed_degraded", $result->mutationState, "mutation_state must be 'confirmed_degraded': the registry declares E_RESTART as a known post-mutation exit code for domain.create, and the domain creation itself is, per source, already complete at this point");
	}
}
