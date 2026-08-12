<?php

namespace Hestiacp\Adapter;

/**
 * Abstraction over "serialize mutating adapter operations for one Hestia
 * user." One instance manages at most one held lock at a time (matching
 * how CommandAdapter uses it: acquire, run one operation, release).
 *
 * Mirrors the ProcessRunnerInterface/ProcOpenProcessRunner/FakeProcessRunner
 * pattern already established in this codebase — this interface is the
 * seam unit tests use to spy on lock acquisition without depending on real
 * flock/filesystem behavior; LockManager is the real, flock-based
 * implementation.
 */
interface LockManagerInterface {
	/**
	 * Attempt to acquire the per-user lock, blocking (with polling) up to
	 * this instance's configured timeout.
	 *
	 * @return bool true if the lock was acquired, false if the timeout
	 *              elapsed while another operation held it (ordinary
	 *              contention — NOT an exception).
	 * @throws LockUnavailableException if the locking mechanism itself
	 *              could not be used (e.g. the lock directory is missing
	 *              or not writable) — distinct from contention.
	 * @throws \InvalidArgumentException if $user fails shape validation —
	 *              see LockManager's implementation for the exact check.
	 */
	public function acquire(string $user): bool;

	/**
	 * Release the currently held lock, if any. Idempotent: safe to call
	 * even when no lock is held (e.g. acquire() timed out, or release()
	 * was already called) — callers are expected to call this
	 * unconditionally from a finally block, per
	 * WRITE_OPERATION_DESIGN.md Part 2's release-discipline requirement.
	 */
	public function release(): void;
}
