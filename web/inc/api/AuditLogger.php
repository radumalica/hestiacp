<?php

namespace Hestiacp\Api;

/**
 * Sink seam for AuditEvent — the ONLY thing ExecuteRequestHandler
 * depends on to record an audit event. Deliberately as small as
 * RateLimitStoreInterface (Sprint 5's own equivalent seam): one method,
 * no policy of its own about what should or should not be logged (that
 * is entirely ExecuteRequestHandler's/AuditTargetRedactor's job) and no
 * opinion about failure handling (that is entirely
 * ExecuteRequestHandler's fail-open policy, §4 of the audit logging
 * doc).
 */
interface AuditLogger {
	/**
	 * @throws AuditWriteException if the event could not be durably
	 *         recorded for any reason. The caller decides the resulting
	 *         fail-open/fail-closed policy — this interface has none of
	 *         its own.
	 */
	public function write(AuditEvent $event): void;
}
