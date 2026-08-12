<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\LockManager;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Direct tests of LockManager against a real, temporary lock directory —
 * real flock(), never mocked. Covers requirements C, D, E, I from this
 * task's test list (A, B, F, G, H, J are covered at the CommandAdapter
 * level in MutatingOperationTest.php against SpyLockManager; K is
 * untouched by this file).
 *
 * Uses a fresh temp directory per test (never a real Hestia install path)
 * so the suite needs no root access and cannot collide with a real
 * $HESTIA/data/adapter-locks/ directory.
 */
final class LockManagerTest {
	public static function register(MiniTest $t): void {
		$t->test("E. acquire() times out when another instance already holds the same user's lock", [self::class, "testTimeoutOnContendedLock"]);
		$t->test("acquire() succeeds immediately once the contending lock is released", [self::class, "testAcquireSucceedsAfterRelease"]);
		$t->test("I. path-traversal-shaped username is rejected before touching the filesystem", [self::class, "testInvalidUsernameRejected"]);
		$t->test("release() is idempotent / safe when nothing is held", [self::class, "testReleaseWithoutAcquireIsSafe"]);
		$t->test("C. two real subprocesses for the SAME user are serialized by the lock", [self::class, "testCrossProcessSameUserSerialized"]);
		$t->test("D. two real subprocesses for DIFFERENT users are not blocked by each other", [self::class, "testCrossProcessDifferentUsersConcurrent"]);
	}

	private static function tempLockDirectory(): string {
		$dir = sys_get_temp_dir() . "/adapter-lock-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return $dir;
	}

	private static function removeDirectory(string $dir): void {
		$entries = @scandir($dir);
		if ($entries === false) {
			return;
		}
		foreach ($entries as $entry) {
			if ($entry === "." || $entry === "..") {
				continue;
			}
			@unlink($dir . $entry);
		}
		@rmdir($dir);
	}

	public static function testTimeoutOnContendedLock(): void {
		$dir = self::tempLockDirectory();
		try {
			// Two independent LockManager instances = two independent open
			// file descriptions on the same lock file, which is exactly
			// what real flock() contention looks like between two
			// unrelated CommandAdapter::invoke() calls, even without
			// spawning a second OS process (flock() contends per open file
			// description on Linux, not per process).
			$holder = new LockManager($dir, 10);
			$contender = new LockManager($dir, 1); // 1s timeout, keeps the test fast

			assertTrue($holder->acquire("alice"), "holder must acquire the lock first");

			$start = microtime(true);
			$acquired = $contender->acquire("alice");
			$elapsed = microtime(true) - $start;

			assertTrue(!$acquired, "contender must fail to acquire an already-held lock within its timeout");
			assertTrue($elapsed >= 0.9, "contender must actually wait close to its configured timeout, not fail instantly (elapsed={$elapsed}s)");
			assertTrue($elapsed < 5.0, "contender must not wait meaningfully longer than its configured timeout (elapsed={$elapsed}s)");

			$holder->release();
		} finally {
			self::removeDirectory($dir);
		}
	}

	public static function testAcquireSucceedsAfterRelease(): void {
		$dir = self::tempLockDirectory();
		try {
			$holder = new LockManager($dir, 10);
			$contender = new LockManager($dir, 10);

			assertTrue($holder->acquire("carol"), "holder must acquire the lock first");
			$holder->release();

			assertTrue($contender->acquire("carol"), "a second instance must be able to acquire the lock once it has been released");
			$contender->release();
		} finally {
			self::removeDirectory($dir);
		}
	}

	public static function testInvalidUsernameRejected(): void {
		$dir = self::tempLockDirectory();
		try {
			$lockManager = new LockManager($dir, 1);

			$attempts = ["../../etc/passwd", "../escape", "a/b", "", ".", "..", "user with spaces"];
			foreach ($attempts as $badUser) {
				$threw = false;
				try {
					$lockManager->acquire($badUser);
				} catch (\InvalidArgumentException $e) {
					$threw = true;
				}
				assertTrue($threw, "expected InvalidArgumentException for username: " . var_export($badUser, true));
			}

			// No lock file must have been created anywhere outside the
			// intended directory, and nothing inside it either, since
			// every attempt above was rejected before fopen().
			$entries = array_diff(scandir($dir), [".", ".."]);
			assertEquals([], array_values($entries), "no lock files should have been created for any rejected username");
		} finally {
			self::removeDirectory($dir);
		}
	}

	public static function testReleaseWithoutAcquireIsSafe(): void {
		$dir = self::tempLockDirectory();
		try {
			$lockManager = new LockManager($dir, 1);
			// Must not throw or warn.
			$lockManager->release();
			$lockManager->release();
			assertTrue(true, "release() without a prior successful acquire() must be a safe no-op");
		} finally {
			self::removeDirectory($dir);
		}
	}

	private static function spawnHolder(string $dir, string $user, string $sentinelFile, float $holdSeconds) {
		$fixture = __DIR__ . "/fixtures/lock_holder.php";
		$cmd = [PHP_BINARY, $fixture, $dir, $user, $sentinelFile, (string) $holdSeconds];
		$descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
		$process = proc_open($cmd, $descriptors, $pipes);
		assertTrue(is_resource($process), "failed to spawn lock_holder.php subprocess");
		return [$process, $pipes];
	}

	private static function waitForSentinel(string $sentinelFile, float $timeoutSeconds): bool {
		$deadline = microtime(true) + $timeoutSeconds;
		while (microtime(true) < $deadline) {
			if (file_exists($sentinelFile)) {
				return true;
			}
			usleep(10000);
		}
		return false;
	}

	public static function testCrossProcessSameUserSerialized(): void {
		$dir = self::tempLockDirectory();
		$sentinel = $dir . "sentinel-dave";
		try {
			[$process, $pipes] = self::spawnHolder($dir, "dave", $sentinel, 1.0);

			assertTrue(self::waitForSentinel($sentinel, 5.0), "holder subprocess never signaled that it acquired the lock");

			// The holder is now provably holding the lock for "dave" and
			// will release it in ~1s. A contender in THIS process,
			// configured with a generous timeout, must observe a real
			// wait before acquiring — proving the two processes were
			// actually serialized by the lock, not merely coincidentally
			// both successful.
			$contender = new LockManager($dir, 10);
			$start = microtime(true);
			$acquired = $contender->acquire("dave");
			$elapsed = microtime(true) - $start;

			assertTrue($acquired, "contender must eventually acquire the lock once the holder subprocess releases it");
			assertTrue($elapsed >= 0.3, "contender acquiring near-instantly would indicate the two processes were NOT actually serialized (elapsed={$elapsed}s)");

			$contender->release();
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_close($process);
		} finally {
			self::removeDirectory($dir);
		}
	}

	public static function testCrossProcessDifferentUsersConcurrent(): void {
		$dir = self::tempLockDirectory();
		$sentinel = $dir . "sentinel-erin";
		try {
			[$process, $pipes] = self::spawnHolder($dir, "erin", $sentinel, 1.0);

			assertTrue(self::waitForSentinel($sentinel, 5.0), "holder subprocess never signaled that it acquired the lock");

			// A DIFFERENT user's lock must be acquirable immediately,
			// proving locking is per-user, not global/process-wide.
			$contender = new LockManager($dir, 10);
			$start = microtime(true);
			$acquired = $contender->acquire("frank");
			$elapsed = microtime(true) - $start;

			assertTrue($acquired, "a different user's lock must be acquirable while erin's lock is held");
			assertTrue($elapsed < 0.5, "a different user's lock must be acquired quickly, not blocked behind erin's lock (elapsed={$elapsed}s)");

			$contender->release();
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_close($process);
		} finally {
			self::removeDirectory($dir);
		}
	}
}
