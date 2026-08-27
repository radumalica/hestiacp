<?php

namespace Hestiacp\Api;

/**
 * The result of one RateLimiter::checkPreAuth()/checkAuthenticated()
 * call: whether the request is allowed, and — regardless of $allowed —
 * the number of whole seconds remaining until the current fixed window
 * resets, so a caller can report a deterministic Retry-After value on a
 * 429 without RateLimiter itself knowing anything about HTTP.
 */
final class RateLimitDecision {
	public bool $allowed;
	public int $retryAfterSeconds;

	public function __construct(bool $allowed, int $retryAfterSeconds) {
		$this->allowed = $allowed;
		$this->retryAfterSeconds = $retryAfterSeconds;
	}
}
