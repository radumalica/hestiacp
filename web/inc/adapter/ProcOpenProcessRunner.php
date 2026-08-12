<?php

namespace Hestiacp\Adapter;

/**
 * Production ProcessRunner. Executes the given binary+argv via proc_open()
 * using PHP's array command form.
 *
 * Why array form instead of building a command string (the pattern used
 * today by web/inc/main.php and web/api/index.php, which build a string
 * and rely on Hestiacp\quoteshellarg\quoteshellarg()/escapeshellarg() to
 * quote each piece correctly): PHP's proc_open() accepts $command as an
 * array (since PHP 7.4). When given an array, PHP executes the binary
 * directly (execve-style) and never hands the command line to /bin/sh for
 * interpretation at all. There is no shell parsing step in which a
 * malformed or malicious argument could inject a second command,
 * regardless of its content — the class of bug quoting is meant to
 * prevent is structurally absent, not just escaped around. See
 * ARCHITECTURE_ADAPTER_DESIGN.md section 4 and this slice's
 * ADAPTER_VERTICAL_SLICE.md "How command injection is prevented" for the
 * full explanation.
 *
 * Timeouts, signal handling and cancellation (ARCHITECTURE_ADAPTER_DESIGN.md
 * section 4) are intentionally NOT implemented here yet — this vertical
 * slice proves the execution/capture/injection-prevention path only.
 */
final class ProcOpenProcessRunner implements ProcessRunnerInterface {
	public function run(string $binary, array $argv): ProcessResult {
		$command = array_merge([$binary], $argv);

		$descriptorSpec = [
			0 => ["pipe", "r"],
			1 => ["pipe", "w"],
			2 => ["pipe", "w"],
		];

		$process = proc_open($command, $descriptorSpec, $pipes, null, null);

		if (!is_resource($process)) {
			// Could not spawn the process at all (e.g. binary missing).
			// Modeled the same as a process that ran and failed hard,
			// so callers always get a ProcessResult, never an exception,
			// from this layer.
			return new ProcessResult(-1, "", "adapter: failed to start process: " . $binary);
		}

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$exitCode = proc_close($process);

		return new ProcessResult($exitCode, $stdout === false ? "" : $stdout, $stderr === false ? "" : $stderr);
	}
}
