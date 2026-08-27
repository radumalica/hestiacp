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
 * Unit tests for the "database.create" operation (bin/v-add-database) — the
 * first real mutating operation to declare a "sensitive"/"delivery" =>
 * "temp_file" password parameter. Proves the full architecture end to end:
 * registry -> validation -> target/authorizer scrubbing -> secure temp-file
 * delivery -> per-user lock -> (faked) v-add-database execution -> structured
 * AdapterResult -> mutation_state -> cleanup. See
 * DATABASE_CREATE_IMPLEMENTATION.md for the full design rationale, and
 * SENSITIVE_PARAMETER_DESIGN.md / SENSITIVE_PARAMETER_REVIEW.md /
 * SENSITIVE_PARAMETER_REMEDIATION.md for the generic mechanism this
 * operation is the first real consumer of.
 *
 * All tests use FakeProcessRunner (or a purpose-built anonymous-class probe
 * runner, matching the established SensitiveParameterTest.php/
 * DomainDeleteTest.php technique) — no real subprocess, no real Hestia
 * installation, no root required, no real database server. This file adds
 * no database-specific code anywhere outside itself and the registry
 * entry/type validators — CommandAdapter remains fully generic.
 */
final class DatabaseCreateTest {
	private const REAL_SECRET = "hunter2-db-password";

	private static function buildAdapter(
		ProcessRunnerInterface $runner,
		?\Hestiacp\Adapter\LockManagerInterface $lockManager = null,
		?AuthorizerInterface $authorizer = null
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

	private static function tempLockManager(): LockManager {
		$dir = sys_get_temp_dir() . "/adapter-database-create-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return new LockManager($dir, 5);
	}

	private static function validParams(): array {
		return [
			"user" => "admin",
			"database" => "wordpress_db",
			"dbuser" => "wp_user",
			"password" => self::REAL_SECRET,
		];
	}

	private static function countAdapterTempFiles(): int {
		$matches = glob("/tmp/hstadapter*");
		return $matches === false ? 0 : count($matches);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. database.create is registered in CommandRegistry, resolves to v-add-database", [self::class, "testRegistered"]);
		$t->test("3. required parameters (user/database/dbuser/password) are enforced", [self::class, "testRequiredParametersEnforced"]);
		$t->test("4. an unknown parameter (e.g. 'type', a registry-fixed value) is rejected", [self::class, "testUnknownParameterRejected"]);
		$t->test("5a. an invalid database name is rejected before execution", [self::class, "testInvalidDatabaseNameRejected"]);
		$t->test("5b. an invalid dbuser is rejected before execution", [self::class, "testInvalidDbUserRejected"]);
		$t->test("5c. an empty password is rejected before execution", [self::class, "testEmptyPasswordRejected"]);
		$t->test("2+B. generated argv matches the expected v-add-database invocation, fixed type/host/charset included", [self::class, "testGeneratedArgv"]);
		$t->test("6. registry declares password as sensitive => true, delivery => temp_file", [self::class, "testPasswordMetadataDeclared"]);
		$t->test("7. the password is absent from AdapterResult::target", [self::class, "testPasswordAbsentFromTarget"]);
		$t->test("8. the password is absent from the authorizer's target input", [self::class, "testPasswordAbsentFromAuthorizerTarget"]);
		$t->test("9. the password does not appear anywhere in process argv", [self::class, "testPasswordAbsentFromArgv"]);
		$t->test("10. argv contains a literal /tmp/... temp-file path for the password slot", [self::class, "testArgvContainsLiteralTmpPath"]);
		$t->test("11+12. the temp file exists with 0600 permissions while the process runner executes", [self::class, "testTempFileExistsWithSecurePermissionsDuringExecution"]);
		$t->test("13. the temp file is removed after a successful execution", [self::class, "testTempFileRemovedAfterSuccess"]);
		$t->test("14. the temp file is removed after the command fails (non-zero exit)", [self::class, "testTempFileRemovedAfterCommandFailure"]);
		$t->test("15. the temp file is removed when lock acquisition fails (timeout)", [self::class, "testTempFileRemovedWhenLockAcquisitionFails"]);
		$t->test("16. authorization denial happens before temp-file creation", [self::class, "testAuthorizationDenialBeforeTempFileCreation"]);
		$t->test("17. authorization denial happens before lock acquisition", [self::class, "testAuthorizationDenialBeforeLockAcquisition"]);
		$t->test("18. the per-user lock is acquired for the correct user", [self::class, "testLockAcquiredForCorrectUser"]);
		$t->test("19. exit code 0 produces mutation_state=confirmed", [self::class, "testExitZeroIsConfirmed"]);
		$t->test("20. registry declares no known_post_mutation_exit_codes (source-verified: none exist for the mysql path)", [self::class, "testNoKnownPostMutationExitCodes"]);
		$t->test("21. a pre-mutation failure (E_EXISTS, duplicate database) produces mutation_state=unknown", [self::class, "testPreMutationFailureIsUnknown"]);
		$t->test("22. the result contains the expected operation/target/status fields", [self::class, "testResultFieldsPopulatedCorrectly"]);
		$t->test("23. no database-specific branch exists anywhere in CommandAdapter.php", [self::class, "testCommandAdapterContainsNoDatabaseSpecificLogic"]);
	}

	public static function testRegistered(): void {
		$registry = new CommandRegistry();
		assertTrue($registry->has("database.create"), "database.create must be a registered operation");

		$entry = $registry->get("database.create");
		assertTrue($entry !== null, "database.create must resolve to a registry entry");
		assertEquals("v-add-database", $entry["script"], "registry entry's underlying script");
		assertEquals("create", $entry["mutation"]["kind"] ?? null, "registry entry must declare a non-'read' mutation kind");
	}

	public static function testRequiredParametersEnforced(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		foreach (["user", "database", "dbuser", "password"] as $missingKey) {
			$params = self::validParams();
			unset($params[$missingKey]);
			$result = $adapter->invoke("database.create", $params);
			assertEquals("adapter_error", $result->status, "status when '$missingKey' is missing");
			assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode when '$missingKey' is missing");
		}
		assertEquals(0, count($runner->calls), "no process should ever be spawned when a required parameter is missing");
	}

	public static function testUnknownParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "type" is a real bin/v-add-database argument, but it is NOT part
		// of database.create's public parameter schema in this slice (it is
		// a registry-fixed value) — supplying it must be rejected the same
		// way any other unrecognized key would be, not silently
		// accepted/overridden.
		$params = self::validParams();
		$params["type"] = "pgsql";
		$result = $adapter->invoke("database.create", $params);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unexpected parameter");
	}

	public static function testInvalidDatabaseNameRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$params = self::validParams();
		$params["database"] = "bad;database";
		$result = $adapter->invoke("database.create", $params);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed database name");
	}

	public static function testInvalidDbUserRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$params = self::validParams();
		$params["dbuser"] = 'wp$(whoami)';
		$result = $adapter->invoke("database.create", $params);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed dbuser");
	}

	public static function testEmptyPasswordRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$params = self::validParams();
		$params["password"] = "";
		$result = $adapter->invoke("database.create", $params);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an empty password");
	}

	public static function testGeneratedArgv(): void {
		$captured = ["argv" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["argv"] = $argv;
				return new ProcessResult(0, "", "");
			}
		};
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.create", self::validParams());

		$argv = $captured["argv"];
		assertTrue($argv !== null, "the process runner must have been called");
		assertEquals("/usr/local/hestia/bin/v-add-database", $argv[0], "argv[0] must be the resolved script path");
		assertEquals("admin", $argv[1], "argv[1] = user");
		assertEquals("wordpress_db", $argv[2], "argv[2] = database (raw, unprefixed suffix)");
		assertEquals("wp_user", $argv[3], "argv[3] = dbuser (raw, unprefixed suffix)");
		// argv[4] is the password's temp-file path, checked separately below.
		assertEquals("mysql", $argv[5], "argv[5] = type, fixed to the script's own default");
		assertEquals("", $argv[6], "argv[6] = host, fixed empty so get_next_dbhost() auto-selects");
		assertEquals("UTF8MB4", $argv[7], "argv[7] = charset, fixed to the script's own default");
		assertEquals(8, count($argv), "argv must have exactly 8 elements: script + 7 positional arguments");
		assertEquals("v-add-database", $result->resolvedCommand, "resolvedCommand");
	}

	public static function testPasswordMetadataDeclared(): void {
		$registry = new CommandRegistry();
		$entry = $registry->get("database.create");
		$passwordDefinition = $entry["parameters"]["password"] ?? null;

		assertTrue($passwordDefinition !== null, "database.create must declare a 'password' parameter");
		assertEquals(true, $passwordDefinition["sensitive"] ?? null, "password must declare sensitive => true");
		assertEquals("temp_file", $passwordDefinition["delivery"] ?? null, "password must declare delivery => temp_file");
	}

	public static function testPasswordAbsentFromTarget(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.create", self::validParams());

		assertFalse(array_key_exists("password", $result->target), "the password must never appear in AdapterResult::target");
		assertTrue(strpos(json_encode($result->toArray()), self::REAL_SECRET) === false, "the plaintext password must not appear anywhere in AdapterResult::toArray()");
		assertEquals("admin", $result->target["user"] ?? null, "non-sensitive parameters must still appear in target");
		assertEquals("wordpress_db", $result->target["database"] ?? null, "non-sensitive parameters must still appear in target");
		assertEquals("wp_user", $result->target["dbuser"] ?? null, "non-sensitive parameters must still appear in target");
	}

	public static function testPasswordAbsentFromAuthorizerTarget(): void {
		$authorizer = new SpyAuthorizer(true);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, $authorizer);

		$adapter->invoke("database.create", self::validParams());

		assertEquals(1, count($authorizer->calls), "authorize() must be called exactly once");
		assertFalse(array_key_exists("password", $authorizer->calls[0]["target"]), "the password must never reach AuthorizerInterface::authorize()'s target argument");
		assertEquals("admin", $authorizer->calls[0]["target"]["user"] ?? null, "non-sensitive parameters must still reach the authorizer");
	}

	public static function testPasswordAbsentFromArgv(): void {
		$captured = ["argv" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["argv"] = $argv;
				return new ProcessResult(0, "", "");
			}
		};
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("database.create", self::validParams());

		assertTrue(!in_array(self::REAL_SECRET, $captured["argv"], true), "the plaintext password must never appear anywhere in argv");
	}

	public static function testArgvContainsLiteralTmpPath(): void {
		$captured = ["argv" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["argv"] = $argv;
				return new ProcessResult(0, "", "");
			}
		};
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("database.create", self::validParams());

		$passwordSlot = $captured["argv"][4];
		assertEquals(0, strpos($passwordSlot, "/tmp/"), "the password's argv slot must begin with the literal '/tmp/' prefix, matching is_password_valid()'s '^/tmp/' contract");
		assertTrue(strpos($passwordSlot, "hstadapter") !== false, "the temp file must use the adapter's own generic prefix — never a database-specific name");
	}

	public static function testTempFileExistsWithSecurePermissionsDuringExecution(): void {
		$captured = ["content" => null, "perms" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$path = $argv[4];
				$this->captured["content"] = file_get_contents($path);
				$this->captured["perms"] = fileperms($path) & 0777;
				return new ProcessResult(0, "", "");
			}
		};
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("database.create", self::validParams());

		assertEquals(self::REAL_SECRET . "\n", $captured["content"], "the temp file must contain exactly the password plus one trailing newline");
		assertEquals(0600, $captured["perms"], "the temp file must be owner-read/write only (0600)");
	}

	public static function testTempFileRemovedAfterSuccess(): void {
		$captured = ["path" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["path"] = $argv[4];
				return new ProcessResult(0, "", "");
			}
		};
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("database.create", self::validParams());

		assertFalse(file_exists($captured["path"]), "the temp file must be deleted after a successful (exit 0) invocation");
	}

	public static function testTempFileRemovedAfterCommandFailure(): void {
		$captured = ["path" => null];
		$runner = new class ($captured) implements ProcessRunnerInterface {
			private array $captured;
			public function __construct(array &$captured) {
				$this->captured = &$captured;
			}
			public function run(string $binary, array $argv): ProcessResult {
				$this->captured["path"] = $argv[4];
				return new ProcessResult(4, "", "Error: DB=admin_wordpress_db already exists");
			}
		};
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.create", self::validParams());

		assertEquals("hestia_error", $result->status, "sanity check: the command must be reported as failed");
		assertFalse(file_exists($captured["path"]), "the temp file must still be deleted even when the underlying command exits non-zero");
	}

	public static function testTempFileRemovedWhenLockAcquisitionFails(): void {
		$before = self::countAdapterTempFiles();

		// A count-equal before/after glob alone does not distinguish "never
		// created" from "created, then removed" — both satisfy it. This probe
		// captures the count AT THE MOMENT acquire() is called (i.e. mid-flight,
		// after argv/temp-file construction but before the lock outcome is
		// known), which only "created before locking" can produce a higher
		// reading for.
		$duringAcquire = ["count" => null];
		$lockManager = new class ($duringAcquire) implements \Hestiacp\Adapter\LockManagerInterface {
			private array $duringAcquire;
			public function __construct(array &$duringAcquire) {
				$this->duringAcquire = &$duringAcquire;
			}
			public function acquire(string $user): bool {
				$matches = glob("/tmp/hstadapter*");
				$this->duringAcquire["count"] = $matches === false ? 0 : count($matches);
				return false; // simulate contention/timeout
			}
			public function release(): void {
			}
		};

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, $lockManager);

		$result = $adapter->invoke("database.create", self::validParams());

		$after = self::countAdapterTempFiles();

		assertEquals("adapter_error", $result->status, "sanity check: the call must actually fail on lock timeout");
		assertEquals("LOCK_TIMEOUT", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "bin/v-add-database must never be spawned when the lock times out");
		assertEquals($before + 1, $duringAcquire["count"], "the temp file must already exist at the moment acquire() is called, proving argv/temp-file construction runs BEFORE lock acquisition (not just that the count matches afterward, which 'never created' would also satisfy)");
		assertEquals($before, $after, "no temp file may be left behind when lock acquisition fails — created during argv construction (before locking), then cleaned up by the outer finally regardless of the lock outcome");
	}

	public static function testAuthorizationDenialBeforeTempFileCreation(): void {
		$before = self::countAdapterTempFiles();

		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, new SpyAuthorizer(false));

		$result = $adapter->invoke("database.create", self::validParams());

		$after = self::countAdapterTempFiles();

		assertEquals("adapter_error", $result->status, "sanity check: the call must actually be denied");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "the process runner must never be invoked for a denied request");
		assertEquals($before, $after, "no temp file may be created for a request that authorization denies — argv construction (where temp files are created) runs strictly after the authorization check");
	}

	public static function testAuthorizationDenialBeforeLockAcquisition(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager, new SpyAuthorizer(false));

		$result = $adapter->invoke("database.create", self::validParams());

		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($lockManager->acquireCalls), "a denied request must never attempt to acquire the lock");
	}

	public static function testLockAcquiredForCorrectUser(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$adapter->invoke("database.create", self::validParams());

		assertEquals(["admin"], $lockManager->acquireCalls, "the lock must be acquired for the caller-supplied 'user' parameter, matching every other mutating operation's locking target");
		assertEquals(1, $lockManager->releaseCalls, "the lock must be released after a successful invocation");
	}

	public static function testExitZeroIsConfirmed(): void {
		// bin/v-add-database's final `exit` (no explicit code) returns the
		// status of the preceding command on a successful run — confirmed
		// by source read (bin/v-add-database:110-113) that nothing after
		// the checked "CREATE DATABASE" query (func/db.sh:312-314) can fail
		// the script.
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.create", self::validParams());

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("confirmed", $result->mutationState, "mutation_state on exit 0");
	}

	public static function testNoKnownPostMutationExitCodes(): void {
		$registry = new CommandRegistry();
		$entry = $registry->get("database.create");

		assertEquals([], $entry["mutation"]["known_post_mutation_exit_codes"] ?? [], "database.create must declare NO known_post_mutation_exit_codes: source read confirms the only checked mutating statement (CREATE DATABASE, func/db.sh:312-314) is the FIRST one, and nothing after it (CREATE USER x2, GRANT x2, md5 retrieval, db.conf append, counters, logging) can fail the script — unlike domain.create/domain.delete, there is no post-mutation exit code to declare");
	}

	public static function testPreMutationFailureIsUnknown(): void {
		// Exit code 4 (E_EXISTS) is what bin/v-add-database's own
		// is_object_new('db', 'DB', ...) / is_object_new('db', 'DBUSER', ...)
		// produce for a duplicate database or dbuser name
		// (func/main.sh:363-374, check_result "$E_EXISTS" "$2=$3 already
		// exists") — BOTH checks run during the "Verifications" section
		// (bin/v-add-database:55-56), strictly before the "Action" section
		// (line 84 onward) that contains the one mutating statement. This is
		// database.create's answer to Phase 7 (idempotency): duplicate
		// creation is explicitly REJECTED before any mutation, never
		// silently treated as success.
		$runner = new FakeProcessRunner(new ProcessResult(4, "", "Error: DB=admin_wordpress_db already exists"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.create", self::validParams());

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_EXISTS", $result->hestiaErrorCode, "exit code 4 must map to E_EXISTS per func/main.sh's E_* table");
		assertEquals("unknown", $result->mutationState, "mutation_state on a pre-mutation E_EXISTS failure must be 'unknown', per this registry's deliberate omission of known_post_mutation_exit_codes — the adapter's generic classification does not special-case 'this exit code is source-verified pre-mutation' into a more specific state; it only ever upgrades a non-zero exit to confirmed_degraded when a registry entry explicitly declares it, which this one does not");
	}

	public static function testResultFieldsPopulatedCorrectly(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.create", self::validParams(), ["user" => "admin"]);

		assertEquals("database.create", $result->operation, "operation");
		assertEquals("v-add-database", $result->resolvedCommand, "resolvedCommand");
		assertEquals("ok", $result->status, "status");
		assertEquals("admin", $result->actor["user"] ?? null, "actor.user");
		assertEquals("admin", $result->target["user"] ?? null, "target.user");
		assertEquals("wordpress_db", $result->target["database"] ?? null, "target.database");
		assertEquals("wp_user", $result->target["dbuser"] ?? null, "target.dbuser");
		assertTrue($result->resultShape === null, "database.create declares no result_shape — bin/v-add-database has no JSON output mode");
		assertTrue($result->parsedOutput === null, "parsedOutput must stay null — no output_format is declared");
	}

	public static function testCommandAdapterContainsNoDatabaseSpecificLogic(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/adapter/CommandAdapter.php");
		assertTrue($source !== false, "sanity check: CommandAdapter.php must be readable");

		foreach (["database.create", "v-add-database", "dbuser"] as $forbiddenTerm) {
			assertEquals(0, substr_count(strtolower($source), strtolower($forbiddenTerm)), "CommandAdapter.php must contain zero references to '$forbiddenTerm' — the operation must be representable entirely through generic registry metadata");
		}
	}
}
