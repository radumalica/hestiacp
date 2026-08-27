<?php

namespace Hestiacp\Auth;

/**
 * The result of one AccessKeyCli operation — an exit code plus the exact
 * text a bin/v-* shim should write to stdout/stderr. Exists so
 * AccessKeyCli itself never calls exit()/echo()/fwrite() directly (which
 * would make it untestable without spawning a real process) — see
 * CREDENTIAL_PROVISIONING_WIRING_DESIGN.md §2.4.
 */
final class CliOutcome {
	public int $exitCode;
	public string $stdout;
	public string $stderr;

	public function __construct(int $exitCode, string $stdout = "", string $stderr = "") {
		$this->exitCode = $exitCode;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
	}
}
