<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\ProcessRunnerInterface;
use Hestiacp\Adapter\ProcessResult;

/**
 * Test double for ProcessRunnerInterface. Never spawns a real process —
 * this is what makes the adapter's unit tests runnable without a real
 * Hestia installation (bin/v-list-web-domain, sudo, etc. do not need to
 * exist on the machine running the tests).
 *
 * Records every call it receives (binary + argv) so tests can assert on
 * exactly what the adapter tried to execute, and returns a
 * pre-configured ProcessResult so tests can control exit code/stdout/
 * stderr deterministically.
 */
final class FakeProcessRunner implements ProcessRunnerInterface {
	/** @var array<int, array{binary: string, argv: string[]}> */
	public array $calls = [];

	private ProcessResult $nextResult;

	public function __construct(ProcessResult $nextResult) {
		$this->nextResult = $nextResult;
	}

	public function setNextResult(ProcessResult $result): void {
		$this->nextResult = $result;
	}

	public function run(string $binary, array $argv): ProcessResult {
		$this->calls[] = ["binary" => $binary, "argv" => $argv];
		return $this->nextResult;
	}
}
