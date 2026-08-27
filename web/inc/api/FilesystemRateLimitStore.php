<?php

namespace Hestiacp\Api;

/**
 * Filesystem-backed fixed-window counter store — the real, production
 * storage backend for RateLimiter (web/api/v2/index.php always
 * constructs this explicitly; see
 * dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §9-§11).
 *
 * Design constraints (all deliberate, all §9-§11 in the doc above):
 *  - The bucket key is NEVER used as, or embedded in, a filename. Every
 *    filename is sha256(bucketKey) — a fixed-length hex string — so a
 *    caller-influenced key (e.g. REMOTE_ADDR, a credential id) can never
 *    produce a path-traversal sequence or an unexpected filename, no
 *    matter what bytes it contains.
 *  - The counter directory is created with mode 0700 (owner rwx only) —
 *    never world- or group-writable — since only this one PHP process
 *    identity ever needs to read or write it (unlike the credential
 *    directory's own 0750/setgid convention, which exists specifically
 *    to bridge a root-run CLI script and the web process; no such
 *    cross-identity requirement exists here).
 *  - Each counter file is opened once with "c+b" (create-if-missing,
 *    read+write, no truncate-on-open) and updated under an exclusive
 *    flock() for the entire read-modify-write — the same
 *    well-established PHP/OS primitive this codebase already trusts
 *    conceptually at the adapter layer (LockManager), used here
 *    completely independently: this store never touches LockManager or
 *    any adapter file, and never blocks/participates in an adapter
 *    lock.
 */
final class FilesystemRateLimitStore implements RateLimitStoreInterface {
	private string $directory;

	/**
	 * @param string|null $directory Defaults to a fixed, stable path
	 *        under the system temp directory, deliberately NOT under
	 *        the credential directory or any installer-provisioned
	 *        path — no installer script is modified this sprint (see
	 *        the doc's own "Storage model" section for why an ephemeral
	 *        temp-backed directory is an accepted, documented choice
	 *        for a rate limiter specifically, as opposed to durable
	 *        state).
	 */
	public function __construct(?string $directory = null) {
		$this->directory = rtrim($directory ?? (sys_get_temp_dir() . "/hestia-api-v2-ratelimit"), "/");
	}

	public function incrementAndGet(string $bucketKey, int $windowStart): int {
		$this->ensureDirectory();

		$path = $this->directory . "/" . hash("sha256", $bucketKey) . ".count";

		$handle = @fopen($path, "c+b");
		if ($handle === false) {
			throw new RateLimitStoreUnavailableException("Unable to open rate-limit counter file.");
		}

		try {
			if (!flock($handle, LOCK_EX)) {
				throw new RateLimitStoreUnavailableException("Unable to lock rate-limit counter file.");
			}

			try {
				$contents = stream_get_contents($handle);
				[$storedWindowStart, $storedCount] = self::parse($contents === false ? "" : $contents);

				if ($storedWindowStart !== $windowStart) {
					$storedCount = 0;
				}
				$storedCount++;

				rewind($handle);
				ftruncate($handle, 0);
				fwrite($handle, $windowStart . ":" . $storedCount);
				fflush($handle);

				return $storedCount;
			} finally {
				flock($handle, LOCK_UN);
			}
		} finally {
			fclose($handle);
		}
	}

	private function ensureDirectory(): void {
		if (is_dir($this->directory)) {
			return;
		}

		// Race-tolerant: two concurrent requests may both reach this at
		// once. @mkdir()'s own failure is only fatal if the directory
		// still does not exist afterward (i.e. it lost the race to a
		// genuine error, not to a sibling process winning the same
		// mkdir).
		@mkdir($this->directory, 0700, true);

		if (!is_dir($this->directory) || !is_writable($this->directory)) {
			throw new RateLimitStoreUnavailableException("Rate-limit counter directory is unavailable.");
		}
	}

	/**
	 * @return array{0: int, 1: int} [$windowStart, $count]
	 */
	private static function parse(string $contents): array {
		if ($contents === "" || strpos($contents, ":") === false) {
			return [0, 0];
		}

		[$windowStart, $count] = explode(":", $contents, 2);
		if (!ctype_digit($windowStart) || !ctype_digit($count)) {
			return [0, 0];
		}

		return [(int) $windowStart, (int) $count];
	}
}
