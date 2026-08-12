<?php

namespace Hestiacp\Adapter;

/**
 * Per-Hestia-user flock-based lock, implementing the design in
 * WRITE_OPERATION_DESIGN.md Part 2 and LOCK_PERMISSION_REVIEW.md's
 * finalized location.
 *
 * Location: $HESTIA/data/adapter-locks/<username>.lock — deliberately NOT
 * under $HESTIA/data/users/, which LOCK_PERMISSION_REVIEW.md confirmed
 * the adapter's PHP-FPM process (running as hestiaweb) has no filesystem
 * access to at all (mode 750/770, root:root, no ACL grant found anywhere
 * in the installer). $HESTIA/data/adapter-locks is created and
 * chown'd hestiaweb:hestiaweb during installation (see
 * install/hst-install-{ubuntu,debian}.sh), mirroring the existing
 * $HESTIA/data/sessions convention.
 *
 * Mechanism: real kernel-level flock(), never a custom lock protocol and
 * never a PID file — this is what gives the "released automatically on
 * process death, no stale-lock detection code needed" property
 * (WRITE_OPERATION_DESIGN.md Part 2's "stale locks / process death"
 * section) for free, from the OS, rather than something this class has
 * to implement.
 *
 * One instance manages at most one held lock at a time (acquire() returns
 * false immediately if this instance is already holding one — callers,
 * i.e. CommandAdapter, are expected to acquire/execute/release in strict
 * sequence, never nested).
 */
final class LockManager implements LockManagerInterface {
	public const DEFAULT_LOCK_DIRECTORY = "/usr/local/hestia/data/adapter-locks/";
	private const DEFAULT_TIMEOUT_SECONDS = 10;
	private const DEFAULT_POLL_INTERVAL_MICROSECONDS = 20000; // 20ms

	private string $lockDirectory;
	private int $timeoutSeconds;
	private int $pollIntervalMicroseconds;

	/** @var resource|null Open handle for the currently held lock, if any. */
	private $handle = null;

	public function __construct(
		string $lockDirectory = self::DEFAULT_LOCK_DIRECTORY,
		int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
		int $pollIntervalMicroseconds = self::DEFAULT_POLL_INTERVAL_MICROSECONDS
	) {
		$this->lockDirectory = rtrim($lockDirectory, "/") . "/";
		$this->timeoutSeconds = $timeoutSeconds;
		$this->pollIntervalMicroseconds = $pollIntervalMicroseconds;
	}

	public function acquire(string $user): bool {
		if ($this->handle !== null) {
			// This instance is already holding a lock. CommandAdapter
			// never does this (acquire/execute/release is strictly
			// sequential per invoke() call), but fail closed rather than
			// silently leak or replace the existing handle if it ever did.
			return false;
		}

		$path = $this->lockFilePath($user);

		// "c" mode: create the file if it doesn't exist; if it does,
		// neither truncate its contents nor error — exactly what a lock
		// file needs (its content, if any, is irrelevant; only the flock
		// state on the shared inode matters).
		error_clear_last();
		$handle = @fopen($path, "c");
		if ($handle === false) {
			$lastError = error_get_last();
			$reason = $lastError["message"] ?? "unknown error";
			throw new LockUnavailableException(
				sprintf("Unable to open lock file '%s': %s", $path, $reason)
			);
		}

		$deadline = microtime(true) + $this->timeoutSeconds;
		do {
			if (flock($handle, LOCK_EX | LOCK_NB)) {
				$this->handle = $handle;
				return true;
			}
			if (microtime(true) >= $deadline) {
				fclose($handle);
				return false;
			}
			usleep($this->pollIntervalMicroseconds);
		} while (true);
	}

	public function release(): void {
		if ($this->handle === null) {
			return;
		}
		flock($this->handle, LOCK_UN);
		fclose($this->handle);
		$this->handle = null;
	}

	/**
	 * Builds $lockDirectory/<user>.lock, refusing to do so for anything
	 * that isn't already a shape-valid Hestia username.
	 *
	 * Reuses ParameterValidator::isValidUsername() — the SAME validator
	 * CommandAdapter already applies to the "user" parameter before a
	 * mutating operation's lock is ever acquired (WRITE_OPERATION_DESIGN.md
	 * Part 2's "how the user identity is derived" — never a raw,
	 * independently-invented check). basename() is then applied as a
	 * second, independent, redundant guard — the same defense-in-depth
	 * pattern already used by bin/v-check-access-key for a different
	 * identifier (access_key_id="$(basename "$1")"), per
	 * WRITE_OPERATION_DESIGN.md Part 2's "filename/path safety" section.
	 */
	private function lockFilePath(string $user): string {
		if (!ParameterValidator::isValidUsername($user)) {
			throw new \InvalidArgumentException(
				"Refusing to build a lock path from a username that fails shape validation: " . $user
			);
		}

		$safeUser = basename($user);
		if ($safeUser !== $user || $safeUser === "" || $safeUser === "." || $safeUser === "..") {
			// Should be unreachable given isValidUsername()'s charset
			// already excludes '/' and '..' sequences — kept as an
			// independent, redundant check rather than trusted to be
			// implied by the check above.
			throw new \InvalidArgumentException(
				"Refusing to build a lock path from a username that changed under basename(): " . $user
			);
		}

		return $this->lockDirectory . $safeUser . ".lock";
	}
}
