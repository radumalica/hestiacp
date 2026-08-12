<?php

/**
 * Standalone subprocess fixture for LockManagerTest's real cross-process
 * flock contention tests (test C / D). Not part of the adapter itself —
 * exists only so a test can prove two independent PHP PROCESSES actually
 * serialize on the same lock file, which an in-process test can never
 * demonstrate (PHP's flock() is only advisory between distinct file
 * descriptors/processes; two calls from the same process on the same
 * handle would not exercise real contention).
 *
 * Usage: php lock_holder.php <lockDirectory> <user> <sentinelFile> <holdSeconds>
 *
 * Behavior: acquires the per-user lock via the real LockManager, then
 * immediately touches <sentinelFile> so the parent test can detect "lock
 * is now held" without guessing timing via sleep, holds the lock for
 * <holdSeconds>, releases it, and exits 0. Exits 1 if the lock could not
 * be acquired within LockManager's own timeout.
 */

require_once __DIR__ . "/../../../web/inc/adapter/bootstrap.php";

use Hestiacp\Adapter\LockManager;

[, $lockDirectory, $user, $sentinelFile, $holdSeconds] = $argv;

$lockManager = new LockManager($lockDirectory);

if (!$lockManager->acquire($user)) {
	fwrite(STDERR, "lock_holder: failed to acquire lock for '$user'\n");
	exit(1);
}

file_put_contents($sentinelFile, "held\n");

usleep((int) round(((float) $holdSeconds) * 1000000));

$lockManager->release();
exit(0);
