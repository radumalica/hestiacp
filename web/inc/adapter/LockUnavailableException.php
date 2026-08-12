<?php

namespace Hestiacp\Adapter;

/**
 * Thrown by LockManager::acquire() when the locking MECHANISM itself
 * fails — e.g. the lock directory doesn't exist or isn't writable by the
 * current process — as distinct from ordinary lock CONTENTION (another
 * operation currently holds the lock), which is reported by acquire()
 * returning false, not by throwing.
 *
 * This distinction matters operationally: contention is a routine,
 * expected condition under real concurrency (see
 * WRITE_OPERATION_DESIGN.md Part 3's LOCK_TIMEOUT), while a mechanism
 * failure is an operational problem worth surfacing distinctly
 * (LOCK_UNAVAILABLE) rather than being silently indistinguishable from
 * "someone else is using it, try again".
 */
final class LockUnavailableException extends \RuntimeException {
}
