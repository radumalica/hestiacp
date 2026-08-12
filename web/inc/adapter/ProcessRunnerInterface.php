<?php

namespace Hestiacp\Adapter;

/**
 * Abstraction over "run one child process and capture its output."
 *
 * This is the seam unit tests use to avoid ever spawning a real
 * subprocess (see test/adapter/FakeProcessRunner.php). Production code
 * uses ProcOpenProcessRunner.
 */
interface ProcessRunnerInterface {
	/**
	 * @param string $binary Absolute path to the executable to run (e.g. "/usr/bin/sudo").
	 * @param string[] $argv Arguments, in order, passed as a real argv array — never
	 *                       concatenated into a shell string. See ProcOpenProcessRunner
	 *                       for why this matters.
	 */
	public function run(string $binary, array $argv): ProcessResult;
}
