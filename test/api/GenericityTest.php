<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\Test\MiniTest;

use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Mechanical, source-level architectural checks for the API v2 HTTP
 * layer, per Sprint 2's own "GENERICITY / ARCHITECTURAL TESTS"
 * requirement: the HTTP layer must never itself execute a shell command,
 * reference a bin/v-* script by name, or contain an operation-specific
 * shell-execution branch. CommandAdapter remains the sole execution
 * boundary (dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §19).
 *
 * Mirrors the exact pattern already established by
 * AccessKeyValidatorTest::testNoShellExecutionInSource() and
 * AccessKeyCliTest's own equivalent coupling check — comment/docblock
 * lines are excluded from the backtick check, since this codebase's
 * docblocks legitimately use backticks for Markdown-style code
 * formatting (e.g. `password_verify()`).
 */
final class GenericityTest {
	/** @var string[] */
	private const API_SOURCE_FILES = [
		"ApiException.php",
		"OperationAllowlist.php",
		"OperationParameterContract.php",
		"ParameterNormalizer.php",
		"ResponseMapper.php",
		"ExecuteRequestHandler.php",
		"RateLimitStoreInterface.php",
		"RateLimitStoreUnavailableException.php",
		"RateLimitDecision.php",
		"InMemoryRateLimitStore.php",
		"FilesystemRateLimitStore.php",
		"RateLimiter.php",
	];

	public static function register(MiniTest $t): void {
		$t->test("22. API v2 source performs no shell execution of any kind", [self::class, "testNoShellExecution"]);
		$t->test("API v2 source references no bin/v-* script name", [self::class, "testNoScriptNameReference"]);
		$t->test("API v2 entry point (web/api/v2/index.php) performs no shell execution", [self::class, "testEntryPointNoShellExecution"]);
		$t->test("API v2 entry point references no bin/v-* script name", [self::class, "testEntryPointNoScriptNameReference"]);
	}

	public static function testNoShellExecution(): void {
		foreach (self::API_SOURCE_FILES as $file) {
			self::assertNoShellExecution(__DIR__ . "/../../web/inc/api/" . $file, $file);
		}
	}

	public static function testNoScriptNameReference(): void {
		foreach (self::API_SOURCE_FILES as $file) {
			self::assertNoScriptNameReference(__DIR__ . "/../../web/inc/api/" . $file, $file);
		}
	}

	public static function testEntryPointNoShellExecution(): void {
		self::assertNoShellExecution(__DIR__ . "/../../web/api/v2/index.php", "web/api/v2/index.php");
	}

	public static function testEntryPointNoScriptNameReference(): void {
		self::assertNoScriptNameReference(__DIR__ . "/../../web/api/v2/index.php", "web/api/v2/index.php");
	}

	private static function assertNoShellExecution(string $path, string $label): void {
		$source = file_get_contents($path);

		foreach (["exec(", "shell_exec(", "proc_open(", "passthru(", "system(", "popen("] as $forbidden) {
			assertFalse(
				strpos($source, $forbidden) !== false,
				"$label must never contain '$forbidden' — it must perform zero shell execution of its own; CommandAdapter is the sole execution boundary"
			);
		}

		foreach (explode("\n", $source) as $line) {
			$trimmed = ltrim($line);
			$isCommentLine = $trimmed === "" || $trimmed[0] === "*" || strpos($trimmed, "//") === 0 || strpos($trimmed, "/*") === 0;
			if (!$isCommentLine) {
				assertFalse(strpos($line, "`") !== false, "$label must never use the backtick shell-exec operator in actual code: '$line'");
			}
		}
	}

	private static function assertNoScriptNameReference(string $path, string $label): void {
		$source = file_get_contents($path);

		// "v-" followed by a lowercase letter is the shape of every
		// bin/v-* script name in this codebase (e.g. v-add-web-domain,
		// v-list-web-domain) — this class must never name one directly;
		// only CommandRegistry (unmodified, untouched by this sprint) is
		// permitted to.
		assertTrue(
			preg_match('/\bv-[a-z]/', $source) !== 1,
			"$label must never reference a bin/v-* script name directly — only CommandRegistry may resolve an operation to a script"
		);
	}
}
