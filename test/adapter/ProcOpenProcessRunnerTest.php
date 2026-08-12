<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\ProcOpenProcessRunner;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Exercises the REAL production ProcessRunner (ProcOpenProcessRunner)
 * against harmless, universally-available binaries (/usr/bin/echo,
 * /usr/bin/false) instead of a fake. This is not the same as an
 * integration test against Hestia itself (see
 * test/adapter/MANUAL_INTEGRATION_TEST.md for that) — it does not touch
 * bin/v-list-web-domain or require a Hestia install — but it does prove
 * the actual proc_open()-with-array-command mechanism CommandAdapter
 * relies on for injection prevention genuinely works on this platform,
 * not only that FakeProcessRunner's bookkeeping is correct.
 */
final class ProcOpenProcessRunnerTest {
	public static function register(MiniTest $t): void {
		$t->test("proc-open: captures stdout and exit code from a real process", [self::class, "testRealStdoutCapture"]);
		$t->test("proc-open: captures non-zero exit code from a real process", [self::class, "testRealNonZeroExit"]);
		$t->test("proc-open: an argv value containing shell metacharacters is passed through literally, not interpreted", [self::class, "testArrayFormPreventsShellInterpretation"]);
	}

	public static function testRealStdoutCapture(): void {
		$runner = new ProcOpenProcessRunner();
		$result = $runner->run("/usr/bin/echo", ["hello", "world"]);

		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals("hello world\n", $result->stdout, "stdout");
		assertEquals("", $result->stderr, "stderr");
	}

	public static function testRealNonZeroExit(): void {
		$runner = new ProcOpenProcessRunner();
		$result = $runner->run("/usr/bin/false", []);

		assertEquals(1, $result->exitCode, "exitCode");
	}

	public static function testArrayFormPreventsShellInterpretation(): void {
		// If this were ever built as a shell string (e.g. "echo " . $payload),
		// a shell would execute `id` via command substitution and the
		// captured stdout would contain a uid=... line instead of the
		// literal payload text. With array-form proc_open, /usr/bin/echo
		// receives the string as a single literal argv element — no
		// shell ever parses it.
		$payload = 'safe$(id)text`whoami`;ls';

		$runner = new ProcOpenProcessRunner();
		$result = $runner->run("/usr/bin/echo", [$payload]);

		assertEquals(0, $result->exitCode, "exitCode");
		assertEquals($payload . "\n", $result->stdout, "echo must print the payload literally, unexpanded");
		assertTrue(strpos($result->stdout, "uid=") === false, "no shell command substitution must have occurred");
	}
}
