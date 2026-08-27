<?php

namespace Hestiacp\Api;

/**
 * The real, production AuditLogger. Follows
 * web/inc/adapter/LockManager.php's own established convention exactly
 * (see that class's docblock, "Location:" paragraph): the directory is
 * a fixed, documented production path that this class NEVER creates
 * itself — it must already exist, owned by the web process identity,
 * provisioned during installation. If it does not exist yet in a given
 * deployment (e.g. this sprint's own dev/test environment, where no
 * installer has run), every write fails with AuditWriteException, and
 * ExecuteRequestHandler's documented fail-open policy takes over — the
 * API request is never affected either way. See
 * dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md §10/§16 for the
 * full reasoning, including why this sprint does not itself add the
 * matching installer provisioning step.
 *
 * One append-only file, one JSON object per line. Each write is a
 * single fopen("ab")/flock(LOCK_EX)/fwrite/flock(LOCK_UN)/fclose cycle —
 * the same concurrency-safe pattern
 * web/inc/api/FilesystemRateLimitStore.php already established in
 * Sprint 5, applied here to a pure append rather than a
 * read-modify-write.
 */
final class FileAuditLogger implements AuditLogger {
	/**
	 * Mirrors LockManager::DEFAULT_LOCK_DIRECTORY's own naming
	 * ("$HESTIA/data/adapter-locks/") — a sibling directory under the
	 * same $HESTIA/data root, for the same reason: $HESTIA/data is the
	 * one location an installer-provisioned, web-process-writable
	 * directory can live (unlike $HESTIA/log, which the installer sets
	 * up as root-only — see the doc's §10 for why that rules it out
	 * without an installer change this sprint does not make).
	 */
	public const DEFAULT_AUDIT_DIRECTORY = "/usr/local/hestia/data/api-v2-audit/";
	private const AUDIT_FILE_NAME = "audit.log";

	private string $path;

	public function __construct(?string $directory = null) {
		$dir = rtrim($directory ?? self::DEFAULT_AUDIT_DIRECTORY, "/");
		$this->path = $dir . "/" . self::AUDIT_FILE_NAME;
	}

	public function write(AuditEvent $event): void {
		$line = json_encode($event->toArray());
		if ($line === false) {
			throw new AuditWriteException("Unable to encode audit event.");
		}

		$isNewFile = !file_exists($this->path);

		error_clear_last();
		$handle = @fopen($this->path, "ab");
		if ($handle === false) {
			throw new AuditWriteException("Unable to open audit log file.");
		}

		try {
			if (!flock($handle, LOCK_EX)) {
				throw new AuditWriteException("Unable to lock audit log file.");
			}

			try {
				if (fwrite($handle, $line . "\n") === false) {
					throw new AuditWriteException("Unable to write audit event.");
				}
				fflush($handle);
			} finally {
				flock($handle, LOCK_UN);
			}
		} finally {
			fclose($handle);
		}

		// Set the file's own mode explicitly on first creation rather
		// than relying on umask — mirrors the existing
		// "chmod 660 /var/log/hestia/*.log"-shaped convention
		// (install/hst-install-ubuntu.sh), tightened to owner-only
		// (0600) since only this one process identity ever needs to
		// read or write it.
		if ($isNewFile) {
			@chmod($this->path, 0600);
		}
	}
}
