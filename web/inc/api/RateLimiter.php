<?php

namespace Hestiacp\Api;

/**
 * HTTP-boundary rate limiting for POST /api/v2/execute (Sprint 5), per
 * dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md.
 *
 * Two independent fixed-window buckets:
 *  - Pre-authentication: keyed ONLY by client IP (never by credential
 *    id/secret) — an unknown credential and a valid one hitting the
 *    same IP always land in the exact same bucket, so this layer can
 *    never leak whether a credential exists.
 *  - Authenticated: keyed by the AUTHENTICATED credential id (never the
 *    raw secret) — only reached after AccessKeyValidator::authenticate()
 *    already succeeded.
 *
 * Deliberately contains no HTTP-response knowledge (no status codes, no
 * envelope shape) — ExecuteRequestHandler alone decides what a "not
 * allowed" RateLimitDecision means for the response; this class only
 * ever answers "is this bucket over its limit right now."
 *
 * Algorithm: a plain fixed-window counter (not sliding-window, not
 * token-bucket) — the simplest option that is both correct under
 * flock()-serialized filesystem access and trivial to reason about/test
 * deterministically via the injected $clock. See the doc's "Algorithm"
 * section for why this is sufficient for this sprint's threat model.
 */
final class RateLimiter {
	/**
	 * Conservative OPERATIONAL DEFAULTS only — not a security guarantee,
	 * not claimed to be an industry standard. Trivially overridable via
	 * the constructor; kept as named constants purely so the default is
	 * one obvious, documented place to change (see the doc's own
	 * "Limits" section).
	 */
	public const DEFAULT_PRE_AUTH_LIMIT = 30;
	public const DEFAULT_PRE_AUTH_WINDOW_SECONDS = 60;
	public const DEFAULT_AUTHENTICATED_LIMIT = 120;
	public const DEFAULT_AUTHENTICATED_WINDOW_SECONDS = 60;

	private RateLimitStoreInterface $store;
	private int $preAuthLimit;
	private int $preAuthWindowSeconds;
	private int $authenticatedLimit;
	private int $authenticatedWindowSeconds;
	/** @var callable(): int */
	private $clock;

	/**
	 * @param callable(): int|null $clock Test-only extension point,
	 *        mirroring CommandAdapter's own established injected-clock
	 *        convention — defaults to the real time() so production
	 *        never passes this argument.
	 */
	public function __construct(
		RateLimitStoreInterface $store,
		int $preAuthLimit = self::DEFAULT_PRE_AUTH_LIMIT,
		int $preAuthWindowSeconds = self::DEFAULT_PRE_AUTH_WINDOW_SECONDS,
		int $authenticatedLimit = self::DEFAULT_AUTHENTICATED_LIMIT,
		int $authenticatedWindowSeconds = self::DEFAULT_AUTHENTICATED_WINDOW_SECONDS,
		?callable $clock = null
	) {
		$this->store = $store;
		$this->preAuthLimit = $preAuthLimit;
		$this->preAuthWindowSeconds = $preAuthWindowSeconds;
		$this->authenticatedLimit = $authenticatedLimit;
		$this->authenticatedWindowSeconds = $authenticatedWindowSeconds;
		$this->clock = $clock ?? static function (): int {
			return time();
		};
	}

	/**
	 * @throws RateLimitStoreUnavailableException Propagated verbatim —
	 *         the caller decides the pre-auth fail-closed policy (§11).
	 */
	public function checkPreAuth(string $clientIp): RateLimitDecision {
		return $this->check("preauth:" . $clientIp, $this->preAuthLimit, $this->preAuthWindowSeconds);
	}

	/**
	 * @throws RateLimitStoreUnavailableException Propagated verbatim —
	 *         the caller decides the authenticated fail-open policy
	 *         (§11).
	 */
	public function checkAuthenticated(string $credentialId): RateLimitDecision {
		return $this->check("auth:" . $credentialId, $this->authenticatedLimit, $this->authenticatedWindowSeconds);
	}

	private function check(string $bucketKey, int $limit, int $windowSeconds): RateLimitDecision {
		$now = ($this->clock)();
		$windowStart = intdiv($now, $windowSeconds) * $windowSeconds;

		$count = $this->store->incrementAndGet($bucketKey, $windowStart);

		$retryAfterSeconds = ($windowStart + $windowSeconds) - $now;

		return new RateLimitDecision($count <= $limit, $retryAfterSeconds);
	}
}
