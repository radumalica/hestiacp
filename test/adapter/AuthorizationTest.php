<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\AuthorizerInterface;
use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\LockManager;
use Hestiacp\Adapter\ProcessResult;
use Hestiacp\Adapter\ProcessRunnerInterface;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Tests for the authorization seam (MUTATION_AND_AUTHORIZATION_DESIGN.md
 * Parts 4-9, 11-12): CommandAdapter structurally guarantees
 * AuthorizerInterface::authorize() is consulted for every resolved,
 * validated request — read or mutating — strictly after parameter
 * validation and strictly before lock acquisition/process execution,
 * while containing zero authorization POLICY itself.
 *
 * Uses the REAL LockManager (temp-directory-backed, mirroring
 * DomainCreateTest/DomainDeleteTest's own pattern) wherever locking
 * behavior is exercised — per this task's explicit instruction, no
 * existing real-lock test is replaced with a mock, and no NEW fake
 * locking logic is introduced. SpyLockManager is used only where a
 * specific lock-acquisition CALL COUNT (zero calls) is being asserted,
 * the same convention already established by
 * MutatingOperationTest/DomainCreateTest/DomainDeleteTest.
 */
final class AuthorizationTest {
	private const MUTATING_OPERATION = "user.mutate.authz-test";

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
		]);
	}

	private static function tempLockManager(): LockManager {
		$dir = sys_get_temp_dir() . "/adapter-authz-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return new LockManager($dir, 5);
	}

	private static function buildAdapter(
		ProcessRunnerInterface $runner,
		?CommandRegistry $registry = null,
		?\Hestiacp\Adapter\LockManagerInterface $lockManager = null,
		?AuthorizerInterface $authorizer = null
	): CommandAdapter {
		// $authorizer intentionally has no fallback expression here (no
		// "?? new AllowAllAuthorizer()") — omitting it entirely from the
		// constructor call, which several tests below do on purpose, is
		// exactly how "the default" is tested: CommandAdapter's OWN
		// constructor default must kick in, not a default supplied by
		// this test helper.
		if ($authorizer !== null) {
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
				$lockManager ?? self::tempLockManager(),
				$authorizer
			);
		}

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
			$lockManager ?? self::tempLockManager()
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("default AllowAllAuthorizer preserves the existing trusted-caller path for a mutating operation", [self::class, "testDefaultAuthorizerAllowsMutatingOperation"]);
		$t->test("default AllowAllAuthorizer preserves the existing trusted-caller path for a read operation", [self::class, "testDefaultAuthorizerAllowsReadOperation"]);
		$t->test("authorizer is consulted for a read operation, with the normalized actor and validated target", [self::class, "testAuthorizerConsultedForReadOperation"]);
		$t->test("authorizer is consulted for a mutating operation", [self::class, "testAuthorizerConsultedForMutatingOperation"]);
		$t->test("invariant 1+2: a denied mutating request never spawns the process AND never acquires the lock", [self::class, "testDeniedMutatingRequestBlocksLockAndProcess"]);
		$t->test("invariant 2 (real LockManager): a denied mutating request never actually holds the lock, proven by immediate re-acquisition", [self::class, "testDeniedMutatingRequestNeverActuallyHoldsTheRealLock"]);
		$t->test("a denied read request never spawns the process", [self::class, "testDeniedReadRequestBlocksProcess"]);
		$t->test("invariant 3: a validation failure never reaches the authorizer", [self::class, "testValidationFailureNeverConsultsAuthorizer"]);
		$t->test("an unknown operation never reaches the authorizer", [self::class, "testUnknownOperationNeverConsultsAuthorizer"]);
		$t->test("invariant 4+5: authorize -> lock -> process, in that exact order, for an authorized mutating request", [self::class, "testOrderingAuthorizedRequest"]);
		$t->test("a denial short-circuits before the lock and before the process, in that order", [self::class, "testOrderingDeniedRequest"]);
		$t->test("denial result: status=adapter_error, adapter_error_code=AUTHORIZATION_DENIED, mutation_state=not_attempted for a mutating op", [self::class, "testDenialResultShapeMutating"]);
		$t->test("denial result: mutation_state=null for a read op", [self::class, "testDenialResultShapeRead"]);
		$t->test("invariant 10: read operations still work correctly end to end", [self::class, "testReadOperationEndToEnd"]);
		$t->test("invariant 9: existing behavior (no authorizer argument at all) is unchanged for a real, successful mutating call", [self::class, "testExistingBehaviorUnchangedUnderDefault"]);
		$t->test("an authorizer's local mutation of the arrays it receives cannot influence the adapter's actual argv/execution", [self::class, "testAuthorizerCannotInfluenceExecutionViaReceivedArrays"]);
	}

	public static function testDefaultAuthorizerAllowsMutatingOperation(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		// No $authorizer argument passed at all -> CommandAdapter's own
		// constructor default (AllowAllAuthorizer) must apply.
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(1, count($runner->calls), "the operation must proceed to execution under the default authorizer");
	}

	public static function testDefaultAuthorizerAllowsReadOperation(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$adapter = self::buildAdapter($runner, new CommandRegistry());

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("ok", $result->status, "status");
	}

	public static function testAuthorizerConsultedForReadOperation(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$authorizer = new SpyAuthorizer(true);
		$adapter = self::buildAdapter($runner, new CommandRegistry(), null, $authorizer);

		$adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		assertEquals(1, count($authorizer->calls), "the authorizer must be consulted even for a read-only operation");
		assertEquals("domain.get", $authorizer->calls[0]["operation"], "operation passed to the authorizer");
		assertEquals(["user" => "admin", "domain" => "example.com"], $authorizer->calls[0]["target"], "the FULL, already-validated target must be passed, not raw params");
		assertEquals(["user" => "admin", "acting_as" => null], $authorizer->calls[0]["actor"], "the SAME normalized actor structure already used elsewhere in AdapterResult must be passed, with acting_as defaulted to null");
	}

	public static function testAuthorizerConsultedForMutatingOperation(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$authorizer = new SpyAuthorizer(true);
		$adapter = self::buildAdapter($runner, null, null, $authorizer);

		$adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"], ["user" => "bob", "acting_as" => "support-agent"]);

		assertEquals(1, count($authorizer->calls), "the authorizer must be consulted for a mutating operation");
		assertEquals(self::MUTATING_OPERATION, $authorizer->calls[0]["operation"], "operation passed to the authorizer");
		assertEquals(["user" => "bob", "acting_as" => "support-agent"], $authorizer->calls[0]["actor"], "actor.acting_as must be preserved and passed through unchanged");
	}

	public static function testDeniedMutatingRequestBlocksLockAndProcess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$authorizer = new SpyAuthorizer(false);
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, null, $lockManager, $authorizer);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(1, count($authorizer->calls), "the authorizer must still have been consulted exactly once");
		assertEquals(0, count($lockManager->acquireCalls), "invariant: a denied request must never attempt to acquire the mutation lock");
		assertEquals(0, count($runner->calls), "invariant: a denied request must never spawn the underlying process");
	}

	public static function testDeniedMutatingRequestNeverActuallyHoldsTheRealLock(): void {
		// Stronger than a call-count assertion: uses a REAL, temp-directory-
		// backed LockManager (per Part 12's explicit instruction), then
		// proves the lock was never taken by having a SECOND, independent
		// LockManager instance on the SAME directory immediately acquire
		// that exact user's lock — this is only possible if the denied
		// request's CommandAdapter never actually flock()'d it, mirroring
		// the same real-flock-contention technique DomainDeleteTest.php's
		// concurrency tests already use.
		$dir = sys_get_temp_dir() . "/adapter-authz-reallock-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		$adapterLockManager = new LockManager($dir, 5);

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, $adapterLockManager, new SpyAuthorizer(false));

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);
		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");

		$independentLockManager = new LockManager($dir, 5);
		$acquired = $independentLockManager->acquire("bob");
		assertTrue($acquired, "an independent LockManager on the same directory must be able to immediately acquire 'bob's lock — proving the denied request never actually held it");
		$independentLockManager->release();
	}

	public static function testDeniedReadRequestBlocksProcess(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$authorizer = new SpyAuthorizer(false);
		$adapter = self::buildAdapter($runner, new CommandRegistry(), null, $authorizer);

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "a denied READ request must also never spawn the underlying process");
	}

	public static function testValidationFailureNeverConsultsAuthorizer(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$authorizer = new SpyAuthorizer(true);
		$adapter = self::buildAdapter($runner, null, null, $authorizer);

		// Missing the required "user" parameter.
		$result = $adapter->invoke(self::MUTATING_OPERATION, []);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($authorizer->calls), "invariant: a validation failure must never reach the authorizer at all");
	}

	public static function testUnknownOperationNeverConsultsAuthorizer(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$authorizer = new SpyAuthorizer(true);
		$adapter = self::buildAdapter($runner, null, null, $authorizer);

		$result = $adapter->invoke("domain.rename", ["user" => "admin"]);

		assertEquals("UNKNOWN_OPERATION", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($authorizer->calls), "an operation that never resolves to a registry entry must never reach the authorizer");
	}

	public static function testOrderingAuthorizedRequest(): void {
		$order = [];

		$authorizer = new class ($order) implements AuthorizerInterface {
			public function __construct(private array &$sharedOrder) {
				$this->sharedOrder = &$sharedOrder;
			}
			public function authorize(string $operation, array $target, array $actor): bool {
				$this->sharedOrder[] = "authorize";
				return true;
			}
		};

		$lockManager = new class ($order) implements \Hestiacp\Adapter\LockManagerInterface {
			public function __construct(private array &$sharedOrder) {
			}
			public function acquire(string $user): bool {
				$this->sharedOrder[] = "acquire";
				return true;
			}
			public function release(): void {
				$this->sharedOrder[] = "release";
			}
		};

		$runner = new class ($order) implements ProcessRunnerInterface {
			public function __construct(private array &$sharedOrder) {
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->sharedOrder[] = "run";
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner, null, $lockManager, $authorizer);
		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("ok", $result->status, "status");
		assertEquals(["authorize", "acquire", "run", "release"], $order, "authorization must happen before lock acquisition, which must happen before process execution");
	}

	public static function testOrderingDeniedRequest(): void {
		$order = [];

		$authorizer = new class ($order) implements AuthorizerInterface {
			public function __construct(private array &$sharedOrder) {
			}
			public function authorize(string $operation, array $target, array $actor): bool {
				$this->sharedOrder[] = "authorize";
				return false;
			}
		};

		$lockManager = new class ($order) implements \Hestiacp\Adapter\LockManagerInterface {
			public function __construct(private array &$sharedOrder) {
			}
			public function acquire(string $user): bool {
				$this->sharedOrder[] = "acquire";
				return true;
			}
			public function release(): void {
				$this->sharedOrder[] = "release";
			}
		};

		$runner = new class ($order) implements ProcessRunnerInterface {
			public function __construct(private array &$sharedOrder) {
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->sharedOrder[] = "run";
				return new ProcessResult(0, "", "");
			}
		};

		$adapter = self::buildAdapter($runner, null, $lockManager, $authorizer);
		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals(["authorize"], $order, "a denial must short-circuit before lock acquisition and before process execution — neither must ever run");
	}

	public static function testDenialResultShapeMutating(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, null, new SpyAuthorizer(false));

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals("not_attempted", $result->mutationState, "a denied mutating request's mutation_state must be not_attempted — nothing ran");
		assertTrue($result->errorMessage !== null && $result->errorMessage !== "", "errorMessage must be populated");
	}

	public static function testDenialResultShapeRead(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$adapter = self::buildAdapter($runner, new CommandRegistry(), null, new SpyAuthorizer(false));

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(null, $result->mutationState, "a denied READ request's mutation_state must remain null, exactly like every other rejection of a read operation");
	}

	public static function testReadOperationEndToEnd(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$adapter = self::buildAdapter($runner, new CommandRegistry());

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(["example.com" => ["IP" => "203.0.113.5"]], $result->parsedOutput, "parsed_output must still be correctly populated for a read operation under the authorization seam");
	}

	public static function testExistingBehaviorUnchangedUnderDefault(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		// Constructor called with EXACTLY the same 7 arguments the
		// locking-pass tests already used, before this task existed — no
		// 8th ($authorizer) argument at all.
		$adapter = new CommandAdapter(
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
			$lockManager
		);

		$result = $adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals("ok", $result->status, "status");
		assertEquals("confirmed", $result->mutationState, "mutation_state");
		assertEquals(["bob"], $lockManager->acquireCalls, "the lock must still be acquired exactly as before this task's changes");
		assertEquals(1, $lockManager->releaseCalls, "the lock must still be released exactly as before this task's changes");
	}

	public static function testAuthorizerCannotInfluenceExecutionViaReceivedArrays(): void {
		// PHP arrays are pass-by-value: an authorizer that mutates the
		// $target/$actor arrays it received mutates only ITS OWN local
		// copies. This test proves that structurally — not merely
		// asserts it — by having the authorizer attempt exactly that
		// mutation, then confirming CommandAdapter's actual argv
		// construction and lock key are entirely unaffected.
		$maliciousAuthorizer = new class implements AuthorizerInterface {
			public function authorize(string $operation, array $target, array $actor): bool {
				$target["user"] = "attacker-controlled-value";
				$actor["user"] = "attacker-controlled-value";
				unset($target["user"]);
				// Whatever this method does to its local $target/$actor
				// copies from here has no path back into CommandAdapter.
				return true;
			}
		};

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, null, $lockManager, $maliciousAuthorizer);

		$adapter->invoke(self::MUTATING_OPERATION, ["user" => "bob"]);

		assertEquals(["bob"], $lockManager->acquireCalls, "the lock must still be acquired for the REAL, validated user — unaffected by the authorizer's local array mutations");
		assertEquals(1, count($runner->calls), "exactly one process invocation");
		assertEquals(["/usr/local/hestia/bin/v-does-not-exist-test-only", "bob"], $runner->calls[0]["argv"], "argv must still reflect the REAL, validated target — unaffected by the authorizer's local array mutations");
	}
}
