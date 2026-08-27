<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcessResult;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Unit tests for CommandAdapter, proving the requirements listed in this
 * slice's task: acceptance, rejection paths, argument construction,
 * injection prevention, separate stdout/stderr/exit-code capture, and
 * deterministic results. All run against FakeProcessRunner — no real
 * subprocess, no real Hestia installation required.
 */
final class CommandAdapterTest {
	private static function buildAdapter(FakeProcessRunner $runner, ?callable $clock = null, ?callable $idGen = null): CommandAdapter {
		return new CommandAdapter(
			new CommandRegistry(),
			$runner,
			"/usr/local/hestia/bin/",
			"/usr/bin/sudo",
			$clock ?? static function (): float {
				return 1700000000.0;
			},
			$idGen ?? static function (): string {
				return "fixed-test-id";
			},
			null,
			new \Hestiacp\Adapter\AllowAllAuthorizer()
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. domain.get is accepted for valid parameters", [self::class, "testDomainGetAccepted"]);
		$t->test("2. unknown operation is rejected", [self::class, "testUnknownOperationRejected"]);
		$t->test("3. unexpected parameter is rejected", [self::class, "testUnexpectedParameterRejected"]);
		$t->test("4. malformed user is rejected before execution", [self::class, "testMalformedUserRejected"]);
		$t->test("4. malformed domain is rejected before execution", [self::class, "testMalformedDomainRejected"]);
		$t->test("4. missing required parameter is rejected before execution", [self::class, "testMissingParameterRejected"]);
		$t->test("5. generated command matches expected v-list-web-domain invocation", [self::class, "testGeneratedCommand"]);
		$t->test("6. injection-shaped input is rejected, never reaches the process runner", [self::class, "testInjectionShapedInputRejected"]);
		$t->test("6. argv is always passed as discrete array elements, never a joined string", [self::class, "testArgvNeverJoinedIntoString"]);
		$t->test("7. stdout/stderr/exit code are captured separately", [self::class, "testSeparateStreamCapture"]);
		$t->test("8. structured result is deterministic for identical input", [self::class, "testDeterministicResult"]);
	}

	public static function testDomainGetAccepted(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		assertEquals("ok", $result->status, "status");
		assertTrue($result->isSuccess(), "isSuccess()");
		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("v-list-web-domain", $result->resolvedCommand, "resolvedCommand");
		assertEquals(1, count($runner->calls), "exactly one process invocation");
	}

	public static function testUnknownOperationRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// "domain.delete" used to be the placeholder unregistered
		// operation here; it is now a real, registered, mutating
		// operation (see DomainDeleteTest.php), so "domain.rename" —
		// explicitly not implemented anywhere in this codebase — takes
		// over as the "genuinely unknown operation" case instead.
		$result = $adapter->invoke("domain.rename", ["user" => "admin", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNKNOWN_OPERATION", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for an unknown operation");
	}

	public static function testUnexpectedParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.get", [
			"user" => "admin",
			"domain" => "example.com",
			"extra_flag" => "yes",
		]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("UNEXPECTED_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when an unexpected parameter is supplied");
	}

	public static function testMalformedUserRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// Space and semicolon are not part of Hestia's username charset
		// (func/main.sh is_user_format_valid: alnum plus - . _ only).
		$result = $adapter->invoke("domain.get", ["user" => "ad min;", "domain" => "example.com"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed user");
	}

	public static function testMalformedDomainRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		// func/main.sh is_domain_format_valid() explicitly excludes ';'.
		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com;whoami"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned for a malformed domain");
	}

	public static function testMissingParameterRejected(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "", ""));
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.get", ["user" => "admin"]);

		assertEquals("adapter_error", $result->status, "status");
		assertEquals("MISSING_PARAMETER", $result->adapterErrorCode, "adapterErrorCode");
		assertEquals(0, count($runner->calls), "no process should ever be spawned when a required parameter is missing");
	}

	public static function testGeneratedCommand(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals(1, count($runner->calls), "exactly one invocation");
		$call = $runner->calls[0];
		assertEquals("/usr/bin/sudo", $call["binary"], "binary");
		assertEquals(
			["/usr/local/hestia/bin/v-list-web-domain", "admin", "example.com", "json"],
			$call["argv"],
			"argv must be exactly [script, user, domain, json], matching bin/v-list-web-domain's " .
				"'# options: USER DOMAIN [FORMAT]' contract with JSON output requested"
		);
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
			$result = $adapter->invoke("domain.get", $params);
			assertEquals("adapter_error", $result->status, "status for payload " . json_encode($params));
			assertEquals("VALIDATION_FAILED", $result->adapterErrorCode, "adapterErrorCode for payload " . json_encode($params));
		}

		assertEquals(0, count($runner->calls), "none of the injection-shaped attempts should ever reach the process runner");
	}

	public static function testArgvNeverJoinedIntoString(): void {
		$runner = new FakeProcessRunner(new ProcessResult(0, "{}", ""));
		$adapter = self::buildAdapter($runner);

		$adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		$call = $runner->calls[0];
		assertTrue(is_array($call["argv"]), "argv must be an array, never a pre-joined string");
		foreach ($call["argv"] as $arg) {
			assertTrue(is_string($arg), "every argv element must be a discrete string");
		}
		// Structural proof there is no shell string anywhere in the call:
		// ProcOpenProcessRunner (production runner) passes this exact
		// array straight to proc_open(), which — given an array — never
		// invokes /bin/sh to parse it. See ProcOpenProcessRunner's
		// docblock for the full explanation.
		assertEquals(4, count($call["argv"]), "expected exactly 4 discrete argv elements");
	}

	public static function testSeparateStreamCapture(): void {
		$runner = new FakeProcessRunner(
			new ProcessResult(3, '{"IP":"203.0.113.5"}', "warning: something noncritical happened")
		);
		$adapter = self::buildAdapter($runner);

		$result = $adapter->invoke("domain.get", ["user" => "admin", "domain" => "example.com"]);

		assertEquals(3, $result->exitCode, "exitCode");
		assertEquals('{"IP":"203.0.113.5"}', $result->stdout, "stdout");
		assertEquals("warning: something noncritical happened", $result->stderr, "stderr");
		assertTrue($result->stdout !== $result->stderr, "stdout and stderr must be captured independently, not merged");
		assertEquals("hestia_error", $result->status, "non-zero exit must map to hestia_error status");
		assertEquals("E_NOTEXIST", $result->hestiaErrorCode, "exit code 3 must map to E_NOTEXIST per func/main.sh's E_* table");
	}

	public static function testDeterministicResult(): void {
		$clock = static function (): float {
			return 1700000000.0;
		};
		$idGen = static function (): string {
			return "fixed-test-id";
		};

		$runnerA = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$adapterA = self::buildAdapter($runnerA, $clock, $idGen);
		$resultA = $adapterA->invoke("domain.get", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		$runnerB = new FakeProcessRunner(new ProcessResult(0, '{"example.com":{"IP":"203.0.113.5"}}', ""));
		$adapterB = self::buildAdapter($runnerB, $clock, $idGen);
		$resultB = $adapterB->invoke("domain.get", ["user" => "admin", "domain" => "example.com"], ["user" => "admin"]);

		assertEquals($resultA->toArray(), $resultB->toArray(), "identical input with an injected fixed clock/id generator must produce an identical structured result");
	}
}
