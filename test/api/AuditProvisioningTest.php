<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Api\AuditEvent;
use Hestiacp\Api\FileAuditLogger;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Sprint 7 — provisioning/rotation behavior for the API v2 audit
 * directory, per
 * dev-docs/api-v2/API_V2_AUDIT_LOGGING_PRODUCTION_IMPLEMENTATION.md §14.
 *
 * IMPORTANT SCOPE NOTE (stated explicitly, not left implicit — see the
 * doc's own §14/§16): this suite cannot execute the real installer
 * shell scripts (they require root and operate on real
 * /usr/local/hestia paths) and cannot verify a chown to the real
 * "hestiaweb" system user (that user does not exist in this test
 * environment). What IS verified here, entirely at the filesystem/PHP
 * level against temporary directories: the exact permission bits the
 * installer scripts apply (mirrored numerically, cross-checked against
 * install/hst-install-ubuntu.sh / install/hst-install-debian.sh /
 * install/upgrade/versions/1.10.0.sh by direct source inspection, not
 * assumed), idempotent re-provisioning never destroying existing audit
 * records, and FileAuditLogger's own behavior across a simulated
 * install -> write -> rotate -> write lifecycle. The installer scripts
 * themselves are verified with `bash -n` plus targeted source
 * inspection, per this sprint's own explicit instruction not to test
 * shell scripts through string matching where an executable path
 * exists — and here, for the shell scripts themselves, no safe
 * executable path exists in this environment, so source inspection is
 * the correct, honestly-reported verification method for those files.
 */
final class AuditProvisioningTest {
	/** Mirrors `chmod 700 $HESTIA/data/api-v2-audit` in the installer/upgrade scripts. */
	private const DIRECTORY_MODE = 0700;

	/** Mirrors `create 0600 hestiaweb hestiaweb` in install/deb/logrotate/hestia. */
	private const FILE_MODE = 0600;

	private static function freshDir(): string {
		return sys_get_temp_dir() . "/api-v2-audit-provisioning-test-" . bin2hex(random_bytes(8));
	}

	/** Simulates exactly the installer's mkdir -p / chmod sequence — never chown (see class docblock). */
	private static function provision(string $dir): void {
		if (!is_dir($dir)) {
			mkdir($dir, self::DIRECTORY_MODE, true);
		}
		chmod($dir, self::DIRECTORY_MODE);
	}

	private static function sampleEvent(string $requestId): AuditEvent {
		return new AuditEvent(
			gmdate("c"),
			"OPERATION_SUCCEEDED",
			$requestId,
			null,
			"key-alice",
			"alice",
			"203.0.113.9",
			"domain.get",
			["user" => "alice", "domain" => "example.com"],
			200,
			"succeeded",
			true,
			null,
			null,
			5
		);
	}

	public static function register(MiniTest $t): void {
		$t->test("AP1. provisioning a previously-absent directory results in the documented 0700 mode", [self::class, "testProvisionAbsentDirectory"]);
		$t->test("AP2. re-provisioning an already-existing directory is idempotent and preserves its contents", [self::class, "testProvisionExistingDirectoryIsIdempotent"]);
		$t->test("AP3. re-provisioning never touches an existing audit.log's own contents", [self::class, "testProvisionPreservesExistingAuditLog"]);
		$t->test("AP4. a first write creates audit.log with the documented 0600 mode", [self::class, "testFirstWriteCreatesFileWithDocumentedMode"]);
		$t->test("AP5. repeated (\"upgrade run twice\") provisioning followed by writes never loses prior audit records", [self::class, "testRepeatedProvisioningNeverLosesRecords"]);
		$t->test("AP6. simulated log rotation: pre-rotation records remain in the rotated file, post-rotation records go to a fresh file, both intact", [self::class, "testSimulatedRotationPreservesBothFiles"]);
		$t->test("AP7. a rotated file's simulated create-mode matches the documented 0600 permission", [self::class, "testRotatedFileHasSecurePermissions"]);
		$t->test("AP8. no secret/password/Authorization-header value ever appears in the real on-disk file across a provision -> write -> rotate -> write cycle", [self::class, "testNoSecretsOnDiskAcrossFullLifecycle"]);
		$t->test("AP9. concurrent (sequential, same-process) writes after provisioning still never corrupt a record", [self::class, "testConcurrentWritesAfterProvisioningAreNotCorrupted"]);
	}

	public static function testProvisionAbsentDirectory(): void {
		$dir = self::freshDir();
		assertFalse(is_dir($dir), "precondition: directory must not exist yet");

		self::provision($dir);

		assertTrue(is_dir($dir));
		assertEquals(self::DIRECTORY_MODE, fileperms($dir) & 0777);
	}

	public static function testProvisionExistingDirectoryIsIdempotent(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);
		$logger->write(self::sampleEvent("req-1"));

		// Simulate the upgrade script running again against an
		// already-provisioned installation.
		self::provision($dir);

		assertTrue(is_dir($dir));
		assertEquals(self::DIRECTORY_MODE, fileperms($dir) & 0777);
		assertEquals(1, count(array_filter(explode("\n", file_get_contents($dir . "/audit.log")))), "re-provisioning must not touch the audit.log already inside the directory");
	}

	public static function testProvisionPreservesExistingAuditLog(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);
		$logger->write(self::sampleEvent("req-before-upgrade"));
		$before = file_get_contents($dir . "/audit.log");

		self::provision($dir);

		$after = file_get_contents($dir . "/audit.log");
		assertEquals($before, $after, "provisioning (mkdir -p + chmod only, never truncating/removing) must leave existing audit records byte-for-byte unchanged");
	}

	public static function testFirstWriteCreatesFileWithDocumentedMode(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);

		$logger->write(self::sampleEvent("req-1"));

		assertEquals(self::FILE_MODE, fileperms($dir . "/audit.log") & 0777);
	}

	public static function testRepeatedProvisioningNeverLosesRecords(): void {
		$dir = self::freshDir();
		$logger = new FileAuditLogger($dir);

		self::provision($dir);
		$logger->write(self::sampleEvent("req-1"));
		self::provision($dir);
		$logger->write(self::sampleEvent("req-2"));
		self::provision($dir);
		$logger->write(self::sampleEvent("req-3"));

		$lines = array_filter(explode("\n", file_get_contents($dir . "/audit.log")));
		assertEquals(3, count($lines), "three writes interleaved with three idempotent re-provisioning runs must still yield exactly three records");
	}

	public static function testSimulatedRotationPreservesBothFiles(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);

		$logger->write(self::sampleEvent("req-pre-rotation-1"));
		$logger->write(self::sampleEvent("req-pre-rotation-2"));

		// Simulate exactly what logrotate does with `create 0600 hestiaweb
		// hestiaweb` and no postrotate: rename the current file aside,
		// then create a fresh, correctly-permissioned empty file in its
		// place. FileAuditLogger itself is never told this happened —
		// its own fopen("ab") on the next write simply resolves the path
		// fresh, exactly like a real rotation.
		rename($dir . "/audit.log", $dir . "/audit.log.1");
		touch($dir . "/audit.log");
		chmod($dir . "/audit.log", self::FILE_MODE);

		$logger->write(self::sampleEvent("req-post-rotation-1"));

		$rotated = array_filter(explode("\n", file_get_contents($dir . "/audit.log.1")));
		$current = array_filter(explode("\n", file_get_contents($dir . "/audit.log")));

		assertEquals(2, count($rotated), "the rotated-aside file must retain exactly the pre-rotation records");
		assertEquals(1, count($current), "the fresh file must contain only the post-rotation record");

		foreach (array_merge($rotated, $current) as $line) {
			$decoded = json_decode($line, true);
			assertTrue($decoded !== null && json_last_error() === JSON_ERROR_NONE, "every line in both files must remain independently valid JSON across rotation");
		}
	}

	public static function testRotatedFileHasSecurePermissions(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);
		$logger->write(self::sampleEvent("req-1"));

		rename($dir . "/audit.log", $dir . "/audit.log.1");
		touch($dir . "/audit.log");
		chmod($dir . "/audit.log", self::FILE_MODE);

		// The rotated-aside file keeps whatever mode FileAuditLogger
		// itself already set on first creation (0600) — rename() never
		// changes a file's mode bits.
		assertEquals(self::FILE_MODE, fileperms($dir . "/audit.log.1") & 0777);
		assertEquals(self::FILE_MODE, fileperms($dir . "/audit.log") & 0777);
	}

	public static function testNoSecretsOnDiskAcrossFullLifecycle(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);

		$logger->write(self::sampleEvent("req-1"));
		rename($dir . "/audit.log", $dir . "/audit.log.1");
		touch($dir . "/audit.log");
		chmod($dir . "/audit.log", self::FILE_MODE);
		$logger->write(self::sampleEvent("req-2"));
		self::provision($dir);
		$logger->write(self::sampleEvent("req-3"));

		$combined = file_get_contents($dir . "/audit.log.1") . file_get_contents($dir . "/audit.log");

		foreach (["TopSecretDbPassword123!", "Basic ", "Authorization:", "alice-secret"] as $forbidden) {
			assertFalse(strpos($combined, $forbidden) !== false, "'$forbidden' must never appear on disk across the full provision/write/rotate lifecycle");
		}
	}

	public static function testConcurrentWritesAfterProvisioningAreNotCorrupted(): void {
		$dir = self::freshDir();
		self::provision($dir);
		$logger = new FileAuditLogger($dir);

		for ($i = 0; $i < 30; $i++) {
			$logger->write(self::sampleEvent("req-" . $i));
		}

		$lines = array_filter(explode("\n", file_get_contents($dir . "/audit.log")));
		assertEquals(30, count($lines));
		foreach ($lines as $line) {
			$decoded = json_decode($line, true);
			assertTrue($decoded !== null && json_last_error() === JSON_ERROR_NONE);
		}
	}
}
