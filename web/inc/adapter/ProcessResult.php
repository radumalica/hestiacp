<?php

namespace Hestiacp\Adapter;

/**
 * Raw result of running one child process, before the adapter maps it
 * onto AdapterResult. Deliberately dumb/flat: no interpretation of exit
 * codes or output format happens here.
 */
final class ProcessResult {
	public int $exitCode;
	public string $stdout;
	public string $stderr;

	public function __construct(int $exitCode, string $stdout, string $stderr) {
		$this->exitCode = $exitCode;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
	}
}
