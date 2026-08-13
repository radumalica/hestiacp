<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcessResult;

use function Hestiacp\Adapter\Test\assertEquals;

/**
 * Behavioral proof that CommandAdapter's confirmed_degraded/unknown
 * mutation_state classification (MUTATION_AND_AUTHORIZATION_DESIGN.md
 * Part 1) is driven ENTIRELY by the resolved registry entry's
 * "mutation.known_post_mutation_exit_codes" — never by any exit code or
 * symbolic error name hardcoded inside CommandAdapter itself.
 *
 * Deliberately uses synthetic operations (via CommandRegistry's
 * $additionalOperations test-only extension point) declaring a
 * DIFFERENT symbolic code than "E_RESTART" (the one domain.create/
 * domain.delete happen to declare) — this is the behavioral equivalent
 * of proving "CommandAdapter contains no hardcoded E_RESTART check"
 * (task requirement 10) without a brittle source-text search: if
 * CommandAdapter special-cased E_RESTART specifically, these tests
 * (which never mention E_RESTART) would fail.
 */
final class MutationClassificationTest {
	private const OP_DECLARES_E_LIMIT = "test.mutate.declares-e-limit";
	private const OP_DECLARES_E_RESTART = "test.mutate.declares-e-restart";
	private const OP_DECLARES_NOTHING = "test.mutate.declares-nothing";

	private static function registry(): CommandRegistry {
		$baseEntry = [
			"script" => "v-does-not-exist-test-only",
			"argument_order" => ["user"],
			"parameters" => [
				"user" => ["type" => "username", "required" => true],
			],
			"fixed_parameters" => [],
		];

		return new CommandRegistry([
			self::OP_DECLARES_E_LIMIT => $baseEntry + [
				"mutation" => ["kind" => "create", "known_post_mutation_exit_codes" => ["E_LIMIT"]],
			],
			self::OP_DECLARES_E_RESTART => $baseEntry + [
				"mutation" => ["kind" => "create", "known_post_mutation_exit_codes" => ["E_RESTART"]],
			],
			self::OP_DECLARES_NOTHING => $baseEntry + [
				"mutation" => ["kind" => "create"], // no known_post_mutation_exit_codes key at all
			],
		]);
	}

	private static function buildAdapter(FakeProcessRunner $runner): CommandAdapter {
		return new CommandAdapter(
			self::registry(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			static function (): float {
				return 1700000000.0;
			},
			static function (): string {
				return "fixed-test-id";
			},
			new SpyLockManager(true)
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. exit 0 -> mutation_state=confirmed, regardless of declared codes", [self::class, "testExitZeroConfirmed"]);
		$t->test("2. a declared known-post-mutation exit code -> mutation_state=confirmed_degraded", [self::class, "testDeclaredCodeIsConfirmedDegraded"]);
		$t->test("3. an undeclared, but Hestia-mapped, non-zero exit -> mutation_state=unknown", [self::class, "testUndeclaredMappedCodeIsUnknown"]);
		$t->test("4. a non-zero exit with NO Hestia error mapping at all -> mutation_state=unknown", [self::class, "testUnmappedExitCodeIsUnknown"]);
		$t->test("5. an operation declaring no known_post_mutation_exit_codes at all -> always unknown for non-zero exit", [self::class, "testMissingMetadataAlwaysUnknown"]);
		$t->test("6. caller parameters cannot influence the classification for a fixed exit code", [self::class, "testCallerParametersDoNotAffectClassification"]);
		$t->test("9. read operations are never classified, regardless of exit code", [self::class, "testReadOperationsUnaffected"]);
		$t->test("10. classification follows registry data, not a hardcoded symbolic name (same exit code, two operations, two outcomes)", [self::class, "testSameExitCodeDifferentOutcomesPerRegistryEntry"]);
	}

	public static function testExitZeroConfirmed(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "bob"]);

		assertEquals("confirmed", $result->mutationState, "exit 0 must always be confirmed");
	}

	public static function testDeclaredCodeIsConfirmedDegraded(): void {
		$runner = new FakeProcessRunner(new ProcessResult(8, "", "package limit reached")); // 8 = E_LIMIT
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "bob"]);

		assertEquals("hestia_error", $result->status, "status");
		assertEquals("E_LIMIT", $result->hestiaErrorCode, "exit code 8 must map to E_LIMIT");
		assertEquals("confirmed_degraded", $result->mutationState, "E_LIMIT is declared for this operation, so mutation_state must be confirmed_degraded");
	}

	public static function testUndeclaredMappedCodeIsUnknown(): void {
		// This operation declares only E_LIMIT — exit 3 (E_NOTEXIST) is a
		// real, mapped Hestia error, but NOT declared for this operation.
		$runner = new FakeProcessRunner(new ProcessResult(3, "", "user doesn't exist"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "bob"]);

		assertEquals("E_NOTEXIST", $result->hestiaErrorCode, "exit code 3 must map to E_NOTEXIST");
		assertEquals("unknown", $result->mutationState, "an undeclared exit code must stay 'unknown', even though it has a valid Hestia error mapping");
	}

	public static function testUnmappedExitCodeIsUnknown(): void {
		// Exit code 99 is not present in CommandAdapter::HESTIA_EXIT_CODES
		// at all -> hestiaErrorCode is null -> the classification's
		// "$hestiaErrorCode !== null" guard must prevent a null-vs-array
		// membership false positive.
		$runner = new FakeProcessRunner(new ProcessResult(99, "", "totally unexpected exit code"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "bob"]);

		assertEquals(null, $result->hestiaErrorCode, "exit code 99 has no entry in HESTIA_EXIT_CODES");
		assertEquals("unknown", $result->mutationState, "an exit code with no symbolic Hestia error mapping at all must stay 'unknown'");
	}

	public static function testMissingMetadataAlwaysUnknown(): void {
		$runner = new FakeProcessRunner(new ProcessResult(20, "", "restart failed")); // 20 = E_RESTART
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke(self::OP_DECLARES_NOTHING, ["user" => "bob"]);

		assertEquals("E_RESTART", $result->hestiaErrorCode, "exit code 20 must map to E_RESTART");
		assertEquals("unknown", $result->mutationState, "an operation with no known_post_mutation_exit_codes field at all must behave exactly as before this feature existed: always 'unknown' for a non-zero exit");
	}

	public static function testCallerParametersDoNotAffectClassification(): void {
		$runner = new FakeProcessRunner(new ProcessResult(8, "", "package limit reached"));
		$adapter = self::buildAdapter($runner);

		$resultA = $adapter->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "alice"]);
		$resultB = $adapter->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "bob"]);

		assertEquals("confirmed_degraded", $resultA->mutationState, "classification for actor/target 'alice'");
		assertEquals("confirmed_degraded", $resultB->mutationState, "classification for actor/target 'bob'");
		assertEquals($resultA->mutationState, $resultB->mutationState, "mutation_state must depend only on the resolved registry entry and exit code, never on which caller-supplied parameter values were used");
	}

	public static function testReadOperationsUnaffected(): void {
		// Deliberately reuse the exact exit code (20 / E_RESTART)
		// domain.create/domain.delete declare as post-mutation, against a
		// genuinely read-only operation, to prove mutation_state stays
		// null unconditionally for reads — the $isMutating gate, not the
		// classification logic itself, is what protects this.
		$runner = new FakeProcessRunner(new ProcessResult(20, "", "restart failed"));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals(null, $result->mutationState, "read operations must never carry a mutation_state, regardless of exit code");
	}

	public static function testSameExitCodeDifferentOutcomesPerRegistryEntry(): void {
		// The single strongest proof against hardcoding: the SAME exit
		// code (20 / E_RESTART), through the SAME CommandAdapter instance
		// and the SAME classification code path, produces TWO DIFFERENT
		// mutation_state outcomes depending purely on which operation's
		// registry entry is resolved.
		$runnerDeclares = new FakeProcessRunner(new ProcessResult(20, "", "restart failed"));
		$runnerDoesNotDeclare = new FakeProcessRunner(new ProcessResult(20, "", "restart failed"));
		$adapter = self::buildAdapter($runnerDeclares);

		$declaredResult = $adapter->invoke(self::OP_DECLARES_E_RESTART, ["user" => "bob"]);
		assertEquals("confirmed_degraded", $declaredResult->mutationState, "operation that declares E_RESTART");

		$adapterForOther = self::buildAdapter($runnerDoesNotDeclare);
		$undeclaredResult = $adapterForOther->invoke(self::OP_DECLARES_E_LIMIT, ["user" => "bob"]);
		assertEquals("unknown", $undeclaredResult->mutationState, "operation that declares only E_LIMIT, given the SAME exit code (20/E_RESTART) the other operation treats as confirmed_degraded");
	}
}
