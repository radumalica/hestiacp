<?php

namespace Hestiacp\Api;

/**
 * Storage seam for RateLimiter's fixed-window counters (Sprint 5, see
 * dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §9-§11).
 *
 * Deliberately the ONLY thing RateLimiter depends on for persistence —
 * it performs no time arithmetic itself; the caller (RateLimiter) always
 * computes $windowStart from its own injected clock and passes it in
 * already resolved, exactly like CommandAdapter's own injected
 * ProcessRunner/LockManager collaborators never decide policy on their
 * own.
 */
interface RateLimitStoreInterface {
	/**
	 * Atomically increments the counter for $bucketKey within the fixed
	 * window starting at $windowStart (a Unix timestamp already aligned
	 * to the window boundary by the caller). If the previously stored
	 * window for this bucket differs from $windowStart, the counter is
	 * reset to zero before incrementing — i.e. a new window always
	 * starts the count at 1, never carries a stale count forward.
	 *
	 * Must never lose an increment under repeated/concurrent calls
	 * against the same $bucketKey (§10 Atomicity/Concurrency).
	 *
	 * @return int The count AFTER this increment (always >= 1).
	 * @throws RateLimitStoreUnavailableException if the counter could
	 *         not be read or written for any reason (filesystem
	 *         failure, permission failure, etc.) — the caller decides
	 *         the resulting fail-open/fail-closed policy; this
	 *         interface has no opinion of its own (§11).
	 */
	public function incrementAndGet(string $bucketKey, int $windowStart): int;
}
