<?php

namespace Hestiacp\Api;

/**
 * Thrown by a RateLimitStoreInterface implementation when a counter
 * genuinely could not be read or written (not when a caller is simply
 * over the limit — that is a normal, non-exceptional
 * RateLimitDecision(allowed: false) return value). Never allowed to
 * escape RateLimiter uncaught: RateLimiter's own callers decide the
 * fail-open/fail-closed policy per bucket, documented in
 * dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §11.
 */
final class RateLimitStoreUnavailableException extends \RuntimeException {
}
