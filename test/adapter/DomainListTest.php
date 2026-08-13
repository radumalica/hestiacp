<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcessResult;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Unit tests for the "domain.list" operation, mapping to
 * bin/v-list-web-domains (plural — NOT the same script as "domain.get",
 * which maps to bin/v-list-web-domain, singular). Confirmed by direct
 * source read: bin/v-list-web-domains takes only USER [FORMAT] — no
 * domain argument at all, unlike bin/v-list-web-domain's USER DOMAIN
 * [FORMAT]. See CommandRegistry's "domain.list" entry for the citation.
 *
 * These tests reuse the same CommandAdapter/CommandRegistry/
 * ProcessRunnerInterface infrastructure as CommandAdapterTest — there is
 * no second execution path, no domain.list-specific adapter code.
 */
final class DomainListTest {
	private static function buildAdapter(FakeProcessRunner $runner): CommandAdapter {
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
			}
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("domain.list: accepted for a valid user", [self::class, "testAccepted"]);
		$t->test("domain.list: generated command matches v-list-web-domains invocation (no domain arg)", [self::class, "testGeneratedCommand"]);
		$t->test("domain.list: multi-domain JSON collection is parsed into parsed_output", [self::class, "testCollectionParsedOutput"]);
		$t->test("domain.list: empty collection ('{}') parses to an empty array, not a failure", [self::class, "testEmptyCollectionParsedOutput"]);
		$t->test("domain.list: result_shape is 'collection', domain.get's is 'single'", [self::class, "testResultShapeDistinguishesOperations"]);
		$t->test("domain.list: unknown operation rejected (shared registry gate)", [self::class, "testUnknownOperationRejected"]);
		$t->test("domain.list: unexpected parameter rejected ('domain' is not a valid param for this operation)", [self::class, "testUnexpectedDomainParameterRejected"]);
		$t->test("domain.list: missing required 'user' parameter rejected before execution", [self::class, "testMissingUserRejected"]);
		$t->test("domain.list: malformed user rejected before execution, injection-shaped payloads included", [self::class, "testMalformedUserRejected"]);
		$t->test("domain.list: stdout/stderr/exit code captured separately", [self::class, "testSeparateStreamCapture"]);
	}

	public static function testAccepted(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.list", ["user" => "admin"], ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals("v-list-web-domains", $result->resolvedCommand, "resolvedCommand");
		assertEquals(1, count($runner->calls), "exactly one process invocation");
	}

	public static function testGeneratedCommand(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("domain.list", ["user" => "admin"]);

		assertEquals(1, count($runner->calls), "exactly one invocation");
		$call = $runner->calls[0];
		assertEquals("/usr/bin/sudo", $call["binary"], "binary");
		assertEquals(
			["/usr/local/hestia/bin/v-list-web-domains", "admin", "json"],
			$call["argv"],
			"argv must be exactly [script, user, json] — bin/v-list-web-domains takes " .
				"USER [FORMAT] only, confirmed by source read; unlike domain.get there is " .
				"no third positional 'domain' slot to fill"
		);
	}

	public static function testCollectionParsedOutput(): void {
		// Shape actually produced by bin/v-list-web-domains json_list():
		// one JSON object, one key per domain read from $USER_DATA/web.conf,
		// comma-joined — confirmed by direct source read of the script's
		// while-loop and its "if [ "$i" -lt "$objects" ]; then echo ','" join logic.
		$stdout = '{'
			. '"alpha.example.com": {"IP": "203.0.113.5", "SUSPENDED": "no"},'
			. '"beta.example.com": {"IP": "203.0.113.6", "SUSPENDED": "no"},'
			. '"gamma.example.com": {"IP": "203.0.113.7", "SUSPENDED": "yes"}'
			. '}';
		$runner = new FakeProcessRunner(new ProcessResult(0, $stdout, ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.list", ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertTrue(is_array($result->parsedOutput), "parsedOutput must decode to an array");
		assertEquals(3, count($result->parsedOutput), "parsedOutput must contain all three domains as top-level keys");
		assertTrue(array_key_exists("alpha.example.com", $result->parsedOutput), "alpha.example.com present");
		assertTrue(array_key_exists("beta.example.com", $result->parsedOutput), "beta.example.com present");
		assertTrue(array_key_exists("gamma.example.com", $result->parsedOutput), "gamma.example.com present");
		assertEquals("203.0.113.6", $result->parsedOutput["beta.example.com"]["IP"], "nested field of a specific collection member");
		assertEquals("yes", $result->parsedOutput["gamma.example.com"]["SUSPENDED"], "nested field of a specific collection member");
	}

	public static function testEmptyCollectionParsedOutput(): void {
		// bin/v-list-web-domains json_list() on a user with zero web
		// domains: the while-loop over $USER_DATA/web.conf runs zero
		// times, producing literally "{\n}" — an empty JSON object, not
		// an error. Confirmed by direct source read (objects=0, loop body
		// never executes, only the leading "{" and trailing "}" print).
		$runner = new FakeProcessRunner(new ProcessResult(0, "{\n}\n", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.list", ["user" => "admin"]);

		assertEquals("ok", $result->status, "an empty collection is still a successful call, not a Hestia error");
		assertTrue(is_array($result->parsedOutput), "parsedOutput must still decode to an array");
		assertEquals(0, count($result->parsedOutput), "parsedOutput must be an empty array for a user with zero domains");
	}

	public static function testResultShapeDistinguishesOperations(): void {
		$listRunner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$adapter = self::buildAdapter($listRunner);
		$listResult = $adapter->invoke("domain.list", ["user" => "admin"]);
		assertEquals("collection", $listResult->resultShape, "domain.list result_shape");

		$getRunner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{}}', ""));
		$adapter2 = self::buildAdapter($getRunner);
		$getResult = $adapter2->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);
		assertEquals("single", $getResult->resultShape, "domain.get result_shape");
	}

	public static function testUnknownOperationRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "domain.create" used to be the placeholder unregistered
		// operation here, back when this test was written; it is now a
		// real, registered, mutating operation (see DomainCreateTest.php).
		// "domain.delete" took over as the placeholder next, and is now
		// ALSO real (see DomainDeleteTest.php) — "domain.rename" —
		// explicitly NOT implemented anywhere in this codebase — takes
		// over as the "genuinely unknown operation" case instead.
		$result = $adapter->invoke("domain.rename", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNKNOWN_OPERATION", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unknown operation");
	}

	public static function testUnexpectedDomainParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "domain" is a valid parameter for domain.get, but domain.list's
		// registry entry declares no "domain" parameter at all — passing
		// one must be rejected, not silently ignored or silently forwarded.
		$result = $adapter->invoke("domain.list", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when an unexpected parameter is supplied");
	}

	public static function testMissingUserRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.list", []);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when the required user parameter is missing");
	}

	public static function testMalformedUserRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$attempts = [
			"ad min",
			"admin;whoami",
			'admin$(id)',
			"admin`whoami`",
			"admin && rm -rf /",
			"admin\nv-add-user attacker pass",
		];

		foreach ($attempts as $badUser) {
			$result = $adapter->invoke("domain.list", ["user" => $badUser]);
			assertEquals("adapter_error", $result->status, "status for payload " . json_encode($badUser));
			assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode for payload " . json_encode($badUser));
		}

		assertEquals(0, count($runner->calls), "none of the injection-shaped attempts should ever reach the process runner");
	}

	public static function testSeparateStreamCapture(): void {
		$runner = new FakeProcessRunner(
			new ProcessResult(3, "", "user does not exist")
		);
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.list", ["user" => "admin"]);

		assertEquals(3, $result->exitCode, "exitCode");
		assertEquals("", $result->stdout, "stdout");
		assertEquals("user does not exist", $result->stderr, "stderr");
		assertEquals("hestia_error", $result->status, "non-zero exit must map to hestia_error status");
		assertEquals("E_NOTEXIST", $result->hestiaErrorCode, "exit code 3 must map to E_NOTEXIST per func/main.sh's E_* table");
		assertTrue($result->parsedOutput === null, "empty stdout on a failed call must not produce a parsed_output");
	}
}
