<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Api\AuditEvent;
use Hestiacp\Api\AuditLogger;
use Hestiacp\Api\AuditWriteException;

/**
 * Test double for AuditLogger — captures every event handed to it so
 * tests can assert on exactly what ExecuteRequestHandler recorded,
 * mirroring SpyLockManager's/SpyAuthorizer's own established pattern
 * (test/adapter/SpyLockManager.php, test/adapter/SpyAuthorizer.php).
 * Never touches a real file.
 */
final class SpyAuditLogger implements AuditLogger {
	/** @var AuditEvent[] */
	public array $events = [];

	private bool $throwOnWrite = false;

	public function write(AuditEvent $event): void {
		if ($this->throwOnWrite) {
			throw new AuditWriteException("simulated audit write failure");
		}
		$this->events[] = $event;
	}

	public function alwaysThrowOnWrite(): void {
		$this->throwOnWrite = true;
	}
}
