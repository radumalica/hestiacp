<?php

namespace Hestiacp\Adapter\Test;

/**
 * Dependency-free micro test runner.
 *
 * No PHPUnit dependency is introduced for this vertical slice: this
 * repository's composer.json declares no PHP test framework today (only
 * runtime dependencies — phpmailer, twofactorauth, etc. — see
 * web/inc/composer.json), and adding one is a build-tooling decision
 * outside this task's scope. This class is intentionally small: register
 * named test functions, run them, print a pass/fail summary, and exit
 * non-zero on any failure so it is CI-friendly without extra tooling.
 */
final class MiniTest {
	/** @var array<int, array{name: string, fn: callable}> */
	private array $tests = [];
	private int $passed = 0;
	private int $failed = 0;

	public function test(string $name, callable $fn): void {
		$this->tests[] = ["name" => $name, "fn" => $fn];
	}

	public function run(): int {
		foreach ($this->tests as $t) {
			try {
				($t["fn"])();
				$this->passed++;
				echo "PASS  {$t['name']}\n";
			} catch (\Throwable $e) {
				$this->failed++;
				echo "FAIL  {$t['name']}\n";
				echo "      " . $e->getMessage() . "\n";
			}
		}

		echo "\n" . $this->passed . " passed, " . $this->failed . " failed\n";
		return $this->failed === 0 ? 0 : 1;
	}
}

function assertTrue(bool $condition, string $message = "expected true"): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

function assertFalse(bool $condition, string $message = "expected false"): void {
	assertTrue(!$condition, $message);
}

function assertEquals($expected, $actual, string $message = ""): void {
	if ($expected !== $actual) {
		$msg = $message !== "" ? $message . " — " : "";
		throw new \RuntimeException(
			$msg . "expected " . var_export($expected, true) . ", got " . var_export($actual, true)
		);
	}
}
