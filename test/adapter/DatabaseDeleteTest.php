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
 * Unit tests for the "database.delete" operation (bin/v-delete-database) —
 * an architecture-generality test: does the existing registry-driven
 * pipeline (resolve -> validate -> normalize -> authorize -> lock ->
 * execute -> classify -> result) represent this operation with ZERO new
 * code in CommandAdapter.php or ParameterValidator.php? Unlike
 * database.create (which needed three new type validators and a
 * sensitive/temp-file registry declaration), database.delete needs only a
 * registry entry — both "username" and "database_name" already exist. See
 * DATABASE_DELETE_IMPLEMENTATION.md for the full design rationale,
 * including the source-verified asymmetry with database.create's own
 * "database" parameter (raw suffix there, full prefixed name here).
 *
 * All tests use FakeProcessRunner or a small purpose-built probe runner —
 * no real subprocess, no real Hestia installation, no root, no real
 * database server.
 */
final class DatabaseDeleteTest {
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
		$dir = sys_get_temp_dir() . "/adapter-database-delete-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return new LockManager($dir, 5);
	}

	private static function validParams(): array {
		// The FULL, PREFIXED database name — database.delete's "database"
		// parameter is NOT the raw suffix database.create accepts for the
		// same-looking parameter name (see DATABASE_DELETE_IMPLEMENTATION.md
		// "Critical Asymmetry"). "admin_wordpress_db" is what a real
		// db.conf DB= field, and therefore is_object_valid()'s own grep,
		// actually expects.
		return [
			"user" => "admin",
			"database" => "admin_wordpress_db",
		];
	}

	public static function register(MiniTest $t): void {
		$t->test("1. database.delete is registered in CommandRegistry, resolves to v-delete-database", [self::class, "testRegistered"]);
		$t->test("3. required parameters (user/database) are enforced", [self::class, "testRequiredParametersEnforced"]);
		$t->test("4. an unknown parameter (e.g. 'dbuser', not part of this operation's contract) is rejected", [self::class, "testUnknownParameterRejected"]);
		$t->test("5. an invalid database name is rejected before execution", [self::class, "testInvalidDatabaseNameRejected"]);
		$t->test("2. generated argv matches the expected v-delete-database invocation", [self::class, "testGeneratedArgv"]);
		$t->test("6. the normalized target contains exactly user/database", [self::class, "testNormalizedTarget"]);
		$t->test("7. the authorizer receives the same normalized target", [self::class, "testAuthorizerTarget"]);
		$t->test("8. the per-user lock is acquired for the correct user", [self::class, "testLockAcquiredForCorrectUser"]);
		$t->test("9. authorization denial happens before lock acquisition", [self::class, "testAuthorizationDenialBeforeLockAcquisition"]);
		$t->test("10. exit code 0 produces mutation_state=confirmed", [self::class, "testExitZeroIsConfirmed"]);
		$t->test("11. a Hestia error (nonexistent database, E_NOTEXIST) produces status=hestia_error", [self::class, "testHestiaErrorExecution"]);
		$t->test("12+13. a pre-mutation E_NOTEXIST failure (nonexistent database) produces mutation_state=unknown — not idempotent, never silently treated as success", [self::class, "testNonexistentDatabaseIsUnknown"]);
		$t->test("14. registry declares no known_post_mutation_exit_codes (source-verified: delete_mysql_database/delete_pgsql_database contain zero check_result calls)", [self::class, "testNoKnownPostMutationExitCodes"]);
		$t->test("15. the result contains the expected operation/target/status fields", [self::class, "testResultFieldsPopulatedCorrectly"]);
		$t->test("16. no database-delete-specific branch exists anywhere in CommandAdapter", [self::class, "testCommandAdapterContainsNoDatabaseDeleteSpecificLogic"]);
	}

	public static function testRegistered(): void {
		$registry = new CommandRegistry();
		assertTrue($registry->has("database.delete"), "database.delete must be a registered operation");

		$entry = $registry->get("database.delete");
		assertTrue($entry !== null, "database.delete must resolve to a registry entry");
		assertEquals("v-delete-database", $entry["script"], "registry entry's underlying script");
		assertEquals("delete", $entry["mutation"]["kind"] ?? null, "registry entry must declare a non-'read' mutation kind");
	}

	public static function testRequiredParametersEnforced(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		foreach (["user", "database"] as $missingKey) {
			$params = self::validParams();
			unset($params[$missingKey]);
			$result = $adapter->invoke("database.delete", $params);
			assertEquals("adapter_error", $result->status, "status when '$missingKey' is missing");
			assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode when '$missingKey' is missing");
		}
		assertEquals(0, count($runner->calls), "no process should ever be spawned when a required parameter is missing");
	}

	public static function testUnknownParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "dbuser" is a real database.create parameter, but bin/v-delete-database
		// has no such argument at all — the script recovers it internally via
		// get_database_values(). Supplying it must be rejected the same way any
		// other unrecognized key would be.
		$params = self::validParams();
		$params["dbuser"] = "wp_user";
		$result = $adapter->invoke("database.delete", $params);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unexpected parameter");
	}

	public static function testInvalidDatabaseNameRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$params = self::validParams();
		$params["database"] = "bad;database";
		$result = $adapter->invoke("database.delete", $params);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed database name");
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

		$result = $adapter->invoke("database.delete", self::validParams());

		$argv = $captured["argv"];
		assertTrue($argv !== null, "the process runner must have been called");
		assertEquals("/usr/local/hestia/bin/v-delete-database", $argv[0], "argv[0] must be the resolved script path");
		assertEquals("admin", $argv[1], "argv[1] = user");
		assertEquals("admin_wordpress_db", $argv[2], "argv[2] = database (full, prefixed name, verbatim)");
		assertEquals(3, count($argv), "argv must have exactly 3 elements: script + 2 positional arguments, no more");
		assertEquals("v-delete-database", $result->resolvedCommand, "resolvedCommand");
	}

	public static function testNormalizedTarget(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.delete", self::validParams());

		assertEquals("admin", $result->target["user"] ?? null, "target.user");
		assertEquals("admin_wordpress_db", $result->target["database"] ?? null, "target.database");
		assertEquals(2, count($result->target), "target must contain exactly user/database — no other keys, since this operation has no sensitive/extra parameters");
	}

	public static function testAuthorizerTarget(): void {
		$authorizer = new SpyAuthorizer(true);
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner, null, $authorizer);

		$adapter->invoke("database.delete", self::validParams());

		assertEquals(1, count($authorizer->calls), "authorize() must be called exactly once");
		assertEquals("admin", $authorizer->calls[0]["target"]["user"] ?? null, "the authorizer must receive the same normalized target as the result");
		assertEquals("admin_wordpress_db", $authorizer->calls[0]["target"]["database"] ?? null, "the authorizer must receive the same normalized target as the result");
	}

	public static function testLockAcquiredForCorrectUser(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager);

		$adapter->invoke("database.delete", self::validParams());

		assertEquals(["admin"], $lockManager->acquireCalls, "the lock must be acquired for the caller-supplied 'user' parameter, matching every other mutating operation's locking target");
		assertEquals(1, $lockManager->releaseCalls, "the lock must be released after a successful invocation");
	}

	public static function testAuthorizationDenialBeforeLockAcquisition(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$lockManager = new SpyLockManager(true);
		$adapter = self::buildAdapter($runner, $lockManager, new SpyAuthorizer(false));

		$result = $adapter->invoke("database.delete", self::validParams());

		assertEquals("adapter_error", $result->status, "sanity check: the call must actually be denied");
		assertEquals("AUTHORIZATION_DENIED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($lockManager->acquireCalls), "a denied request must never attempt to acquire the lock");
		assertEquals(0, count($runner->calls), "the process runner must never be invoked for a denied request");
	}

	public static function testExitZeroIsConfirmed(): void {
		// bin/v-delete-database's final bare `exit` returns the status of
		// the preceding command on a successful run — confirmed by source
		// read that NOTHING after the Verifications section (including the
		// case/esac dispatch to delete_mysql_database/delete_pgsql_database,
		// neither of which contains a single check_result call) can fail
		// the script.
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.delete", self::validParams());

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("confirmed", $result->mutationState, "mutation_state on exit 0");
	}

	public static function testHestiaErrorExecution(): void {
		$runner = new FakeProcessRunner(new ProcessResult(3, "", "Error: db DB admin_wordpress_db doesn't exist"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.delete", self::validParams());

		assertEquals("hestia_error", $result->status, "status");
		assertEquals(3, $result->exitCode, "exitCode");
		assertEquals("E_NOTEXIST", $result->hestiaErrorCode, "exit code 3 must map to E_NOTEXIST per func/main.sh's E_* table");
	}

	public static function testNonexistentDatabaseIsUnknown(): void {
		// is_object_valid('db', 'DB', "$database") (func/main.sh:377-397,
		// bin/v-delete-database:36) fails E_NOTEXIST for a database that
		// doesn't exist in $USER_DATA/db.conf — this runs during
		// "Verifications," strictly before the case/esac mutation dispatch.
		// This is database.delete's answer to Phase 5/idempotency:
		// deleting a database that doesn't exist is explicitly REJECTED
		// before any mutation is attempted, never silently treated as a
		// successful no-op.
		$runner = new FakeProcessRunner(new ProcessResult(3, "", "Error: db DB admin_wordpress_db doesn't exist"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.delete", self::validParams());

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_NOTEXIST", $result->hestiaErrorCode, "hestiaErrorCode");
		assertEquals("unknown", $result->mutationState, "mutation_state on a pre-mutation E_NOTEXIST failure must be 'unknown', per this registry's deliberate omission of known_post_mutation_exit_codes — the adapter's generic classification does not special-case 'this exit code is source-verified pre-mutation' into a more specific state");
	}

	public static function testNoKnownPostMutationExitCodes(): void {
		$registry = new CommandRegistry();
		$entry = $registry->get("database.delete");

		assertEquals([], $entry["mutation"]["known_post_mutation_exit_codes"] ?? [], "database.delete must declare NO known_post_mutation_exit_codes: source read confirms delete_mysql_database()/delete_pgsql_database() contain zero check_result calls at all, and nothing after the case/esac dispatch (db.conf removal, counter decrements, logging) can fail the script either — even more airtight than database.create's own 'no post-mutation exit code' finding");
	}

	public static function testResultFieldsPopulatedCorrectly(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("database.delete", self::validParams(), ["user" => "admin"]);

		assertEquals("database.delete", $result->operation, "operation");
		assertEquals("v-delete-database", $result->resolvedCommand, "resolvedCommand");
		assertEquals("ok", $result->status, "status");
		assertEquals("admin", $result->actor["user"] ?? null, "actor.user");
		assertEquals("admin", $result->target["user"] ?? null, "target.user");
		assertEquals("admin_wordpress_db", $result->target["database"] ?? null, "target.database");
		assertTrue($result->resultShape === null, "database.delete declares no result_shape — bin/v-delete-database has no JSON output mode");
		assertTrue($result->parsedOutput === null, "parsedOutput must stay null — no output_format is declared");
	}

	public static function testCommandAdapterContainsNoDatabaseDeleteSpecificLogic(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/adapter/CommandAdapter.php");
		assertTrue($source !== false, "sanity check: CommandAdapter.php must be readable");

		foreach (["database.delete", "v-delete-database"] as $forbiddenTerm) {
			assertEquals(0, substr_count(strtolower($source), strtolower($forbiddenTerm)), "CommandAdapter.php must contain zero references to '$forbiddenTerm' — the operation must be representable entirely through generic registry metadata");
		}

		// database.delete required no new type-validator methods (unlike
		// database.create's three): assert ParameterValidator.php was not
		// modified with any operation-specific method either — this file
		// itself introduces no new class or method to prove that.
		$validatorSource = file_get_contents(__DIR__ . "/../../web/inc/adapter/ParameterValidator.php");
		assertTrue($validatorSource !== false, "sanity check: ParameterValidator.php must be readable");
		foreach (["database.delete", "v-delete-database"] as $forbiddenTerm) {
			assertEquals(0, substr_count(strtolower($validatorSource), strtolower($forbiddenTerm)), "ParameterValidator.php must contain zero references to '$forbiddenTerm' — database.delete introduced no new validator at all");
		}
	}
}
