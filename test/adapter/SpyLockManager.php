<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\LockManagerInterface;

/**
 * Test double for LockManagerInterface. Never touches a real file or
 * flock() — this is what lets CommandAdapter-level tests (whether a lock
 * is acquired, for which user, whether it's released after success/
 * failure/exception) run deterministically and without a filesystem,
 * mirroring the FakeProcessRunner pattern already used for
 * ProcessRunnerInterface.
 *
 * Records every acquire()/release() call so tests can assert on exactly
 * what CommandAdapter did, and lets a test script the return value
 * (true/false) or force an exception, deterministically.
 */
final class SpyLockManager implements LockManagerInterface {
	/** @var array<int, string> Every user passed to acquire(), in order. */
	public array $acquireCalls = [];

	/** @var int Number of times release() was called. */
	public int $releaseCalls = 0;

	private bool $nextAcquireResult;
	private ?\Throwable $nextAcquireException;
	private bool $held = false;

	public function __construct(bool $nextAcquireResult = true, ?\Throwable $nextAcquireException = null) {
		$this->nextAcquireResult = $nextAcquireResult;
		$this->nextAcquireException = $nextAcquireException;
	}

	public function setNextAcquireResult(bool $result): void {
		$this->nextAcquireResult = $result;
		$this->nextAcquireException = null;
	}

	public function setNextAcquireException(\Throwable $exception): void {
		$this->nextAcquireException = $exception;
	}

	public function acquire(string $user): bool {
		$this->acquireCalls[] = $user;

		if ($this->nextAcquireException !== null) {
			$exception = $this->nextAcquireException;
			$this->nextAcquireException = null;
			throw $exception;
		}

		if ($this->nextAcquireResult) {
			$this->held = true;
		}
		return $this->nextAcquireResult;
	}

	public function release(): void {
		$this->releaseCalls++;
		$this->held = false;
	}

	public function isHeld(): bool {
		return $this->held;
	}
}
