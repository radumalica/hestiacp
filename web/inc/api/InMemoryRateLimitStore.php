<?php

namespace Hestiacp\Api;

/**
 * Per-instance, in-process fixed-window counter store. Never persists
 * across separate PHP processes/requests — this is precisely why
 * ExecuteRequestHandler's own constructor default (used whenever no
 * explicit RateLimiter is injected) uses this class: every test that
 * does not care about rate limiting constructs a fresh
 * ExecuteRequestHandler instance, which therefore gets a fresh,
 * automatically isolated counter set with zero cross-test interference,
 * without any test call site needing to change (see
 * dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §9).
 *
 * NEVER used in production: web/api/v2/index.php always explicitly
 * constructs a FilesystemRateLimitStore instead, because a real
 * PHP-FPM/CGI deployment runs a fresh PHP process per request, under
 * which an in-memory store would silently never persist a count across
 * two different requests — i.e. would silently fail to rate-limit
 * anything in production. This class exists purely as a safe,
 * self-contained test/default convenience, not as a production storage
 * option.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface {
	/** @var array<string, array{windowStart: int, count: int}> */
	private array $counters = [];

	public function incrementAndGet(string $bucketKey, int $windowStart): int {
		if (!isset($this->counters[$bucketKey]) || $this->counters[$bucketKey]["windowStart"] !== $windowStart) {
			$this->counters[$bucketKey] = ["windowStart" => $windowStart, "count" => 0];
		}

		$this->counters[$bucketKey]["count"]++;

		return $this->counters[$bucketKey]["count"];
	}
}
