<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\ProcessRunnerInterface;
use Hestiacp\Adapter\ProcessResult;

/**
 * Test double for ProcessRunnerInterface that always throws, simulating a
 * failure in spawning the subprocess itself (as opposed to the subprocess
 * running and exiting non-zero, which FakeProcessRunner already covers).
 * Used to prove CommandAdapter's lock is released even when
 * ProcessRunnerInterface::run() throws rather than returns
 * (test H — WRITE_OPERATION_DESIGN.md Part 2's release-discipline
 * requirement).
 */
final class ThrowingProcessRunner implements ProcessRunnerInterface {
	private \Throwable $exception;

	public function __construct(\Throwable $exception) {
		$this->exception = $exception;
	}

	public function run(string $binary, array $argv): ProcessResult {
		throw $this->exception;
	}
}
