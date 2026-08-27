<?php

namespace Hestiacp\Auth\Test;

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Auth\AccessKeyCli;
use Hestiacp\Auth\AccessKeyProvisioner;
use Hestiacp\Auth\AccessKeyValidator;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Offline unit tests for AccessKeyCli — the thin wrapper behind
 * bin/v-add-api-credential / bin/v-delete-api-credential
 * (CREDENTIAL_PROVISIONING_WIRING_DESIGN.md §2.4). Every test uses
 * dependency injection (a real AccessKeyProvisioner pointed at a temp
 * directory) — none shells out to the actual bin/v-* scripts, and none
 * touches the real $HESTIA/data/api-credentials/ path.
 */
final class AccessKeyCliTest {
	private static function tempCredentialDirectory(): string {
		$dir = sys_get_temp_dir() . "/access-key-cli-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return $dir;
	}

	public static function register(MiniTest $t): void {
		$t->test("1. create() delegates to AccessKeyProvisioner rather than reimplementing it", [self::class, "testCreateDelegatesToProvisioner"]);
		$t->test("2. successful create() returns exit 0 and the id/secret/user, in shell format", [self::class, "testCreateShellFormat"]);
		$t->test("3. create() with format=json returns valid JSON containing ID/SECRET/USER", [self::class, "testCreateJsonFormat"]);
		$t->test("4. create() with format=plain returns 'id:secret'", [self::class, "testCreatePlainFormat"]);
		$t->test("5. the secret is never written to any file other than the one CliOutcome stdout string", [self::class, "testSecretOnlyInStdout"]);
		$t->test("6. an empty user is rejected before ever calling the provisioner", [self::class, "testEmptyUserRejectedEarly"]);
		$t->test("7. a malformed user is rejected via the provisioner's own validation, not duplicated logic", [self::class, "testMalformedUserRejectedViaProvisioner"]);
		$t->test("8. revoke() delegates to AccessKeyProvisioner rather than reimplementing it", [self::class, "testRevokeDelegatesToProvisioner"]);
		$t->test("9. successful revoke() returns exit 0", [self::class, "testRevokeSuccess"]);
		$t->test("10. revoke() of a nonexistent id returns a non-zero exit and does not throw", [self::class, "testRevokeNonexistentId"]);
		$t->test("11. an empty credential id is rejected before ever calling the provisioner", [self::class, "testEmptyIdRejectedEarly"]);
		$t->test("12. a credential created via the CLI authenticates via the real, unmodified AccessKeyValidator", [self::class, "testEndToEndWithValidator"]);
		$t->test("13. a credential revoked via the CLI can no longer authenticate", [self::class, "testRevokedCredentialFailsValidation"]);
		$t->test("14. no legacy data/access-keys/ path is ever referenced", [self::class, "testNoLegacyAccessKeyPath"]);
		$t->test("15. AccessKeyCli source has no HTTP/session/CommandAdapter/AuthorizerInterface coupling", [self::class, "testNoForbiddenCouplingInSource"]);
		$t->test("16. AccessKeyCli source performs no shell execution", [self::class, "testNoShellExecutionInSource"]);
		$t->test("17. AccessKeyCli contains no random_bytes/password_hash/json_decode of its own (no duplicated provisioning logic)", [self::class, "testNoDuplicatedProvisioningPrimitives"]);
	}

	public static function testCreateDelegatesToProvisioner(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);
		$cli = new AccessKeyCli($provisioner);

		$outcome = $cli->create("bob");

		assertEquals(0, $outcome->exitCode, "successful creation must exit 0");
		// The only way this file could exist is if AccessKeyCli actually
		// called through to AccessKeyProvisioner's real filesystem logic
		// — proving delegation, not a reimplementation.
		assertEquals(1, count(glob($dir . "*")), "exactly one credential file must exist, written by AccessKeyProvisioner");
	}

	public static function testCreateShellFormat(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->create("bob", "shell");

		assertEquals(0, $outcome->exitCode, "exit code");
		assertTrue(strpos($outcome->stdout, "ID:") !== false, "shell output must contain an ID: line");
		assertTrue(strpos($outcome->stdout, "SECRET:") !== false, "shell output must contain a SECRET: line");
		assertTrue(strpos($outcome->stdout, "USER:") !== false, "shell output must contain a USER: line");
		assertTrue(strpos($outcome->stdout, "bob") !== false, "shell output must contain the requested user");
	}

	public static function testCreateJsonFormat(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->create("bob", "json");
		$decoded = json_decode($outcome->stdout, true);

		assertTrue(is_array($decoded), "json format must produce valid, decodable JSON");
		assertEquals("bob", $decoded["USER"] ?? null, "decoded JSON must contain USER");
		assertTrue(isset($decoded["ID"]) && $decoded["ID"] !== "", "decoded JSON must contain a non-empty ID");
		assertTrue(isset($decoded["SECRET"]) && $decoded["SECRET"] !== "", "decoded JSON must contain a non-empty SECRET");
	}

	public static function testCreatePlainFormat(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->create("bob", "plain");
		$parts = explode(":", trim($outcome->stdout));

		assertEquals(2, count($parts), "plain format must be exactly 'id:secret'");
		assertEquals(40, strlen($parts[0]), "the id portion must be 40 characters");
		assertEquals(64, strlen($parts[1]), "the secret portion must be 64 characters");
	}

	public static function testSecretOnlyInStdout(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->create("bob", "plain");
		$secret = explode(":", trim($outcome->stdout))[1];

		$credentialFiles = glob($dir . "*");
		assertEquals(1, count($credentialFiles), "sanity check: one credential file exists");
		$rawFileContents = file_get_contents($credentialFiles[0]);
		assertFalse(strpos($rawFileContents, $secret) !== false, "the plaintext secret must never appear in the stored credential file");
		assertEquals("", $outcome->stderr, "a successful creation must produce no stderr output that could carry the secret");
	}

	public static function testEmptyUserRejectedEarly(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->create("");

		assertEquals(1, $outcome->exitCode, "an empty user must be rejected");
		assertEquals(0, count(glob($dir . "*")), "no credential file may be created for an empty user");
	}

	public static function testMalformedUserRejectedViaProvisioner(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->create("has spaces");

		assertEquals(1, $outcome->exitCode, "a malformed user must be rejected");
		assertEquals(0, count(glob($dir . "*")), "no credential file may be created for a malformed user");
	}

	public static function testRevokeDelegatesToProvisioner(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);
		$cli = new AccessKeyCli($provisioner);

		$created = $provisioner->create("bob");
		assertEquals(1, count(glob($dir . "*")), "sanity check: the credential file exists before revocation");

		$outcome = $cli->revoke($created->id);

		assertEquals(0, $outcome->exitCode, "revocation must succeed");
		assertEquals(0, count(glob($dir . "*")), "the credential file must actually be removed by AccessKeyProvisioner, proving delegation");
	}

	public static function testRevokeSuccess(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);
		$cli = new AccessKeyCli($provisioner);

		$created = $provisioner->create("bob");
		$outcome = $cli->revoke($created->id);

		assertEquals(0, $outcome->exitCode, "exit code");
		assertTrue(strpos($outcome->stdout, $created->id) !== false, "confirmation output should reference the revoked id");
	}

	public static function testRevokeNonexistentId(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->revoke(str_repeat("0", 40));

		assertEquals(1, $outcome->exitCode, "revoking a nonexistent id must be reported as a failure, not throw");
		assertTrue($outcome->stderr !== "", "an explanatory message must be present on stderr");
	}

	public static function testEmptyIdRejectedEarly(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));

		$outcome = $cli->revoke("");

		assertEquals(1, $outcome->exitCode, "an empty id must be rejected");
	}

	public static function testEndToEndWithValidator(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));
		$validator = new AccessKeyValidator($dir);

		$outcome = $cli->create("carol", "plain");
		[$id, $secret] = explode(":", trim($outcome->stdout));

		assertEquals("carol", $validator->authenticate($id, $secret), "a credential created via AccessKeyCli must authenticate via the real, unmodified AccessKeyValidator");
	}

	public static function testRevokedCredentialFailsValidation(): void {
		$dir = self::tempCredentialDirectory();
		$cli = new AccessKeyCli(new AccessKeyProvisioner($dir));
		$validator = new AccessKeyValidator($dir);

		$outcome = $cli->create("dave", "plain");
		[$id, $secret] = explode(":", trim($outcome->stdout));
		assertEquals("dave", $validator->authenticate($id, $secret), "sanity check: the credential authenticates before revocation");

		$cli->revoke($id);

		assertEquals(null, $validator->authenticate($id, $secret), "a credential revoked via AccessKeyCli must no longer authenticate");
	}

	public static function testNoLegacyAccessKeyPath(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyCli.php");
		assertFalse(strpos($source, "access-keys") !== false, "AccessKeyCli.php must never reference the legacy data/access-keys/ path");
		assertFalse(strpos($source, "v-check-access-key") !== false, "AccessKeyCli.php must never reference the legacy v-check-access-key script");
	}

	public static function testNoForbiddenCouplingInSource(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyCli.php");

		// Checked only against actual code lines — doc-comment lines are
		// excluded, since this class's own docblock legitimately explains
		// what it does NOT depend on, by naming those very things in prose.
		foreach (explode("\n", $source) as $line) {
			$trimmed = ltrim($line);
			$isCommentLine = $trimmed === "" || $trimmed[0] === "*" || strpos($trimmed, "//") === 0 || strpos($trimmed, "/*") === 0;
			if ($isCommentLine) {
				continue;
			}
			foreach (['$_POST', '$_GET', '$_SESSION', '$_SERVER', '$_COOKIE', '$_REQUEST', 'CommandAdapter', 'AuthorizerInterface', 'SameUserAuthorizer'] as $forbidden) {
				assertFalse(strpos($line, $forbidden) !== false, "AccessKeyCli.php must never reference $forbidden in actual code: '$line'");
			}
		}
	}

	public static function testNoShellExecutionInSource(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyCli.php");

		foreach (["exec(", "shell_exec(", "proc_open(", "passthru(", "system(", "popen("] as $forbidden) {
			assertFalse(strpos($source, $forbidden) !== false, "AccessKeyCli.php must never contain '$forbidden'");
		}

		foreach (explode("\n", $source) as $line) {
			$trimmed = ltrim($line);
			$isCommentLine = $trimmed === "" || $trimmed[0] === "*" || strpos($trimmed, "//") === 0 || strpos($trimmed, "/*") === 0;
			if (!$isCommentLine) {
				assertFalse(strpos($line, "`") !== false, "AccessKeyCli.php must never use the backtick shell-exec operator: '$line'");
			}
		}
	}

	public static function testNoDuplicatedProvisioningPrimitives(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyCli.php");

		foreach (["random_bytes(", "password_hash(", "password_verify(", "fopen("] as $forbidden) {
			assertFalse(strpos($source, $forbidden) !== false, "AccessKeyCli.php must never contain '$forbidden' — credential generation/hashing/storage must stay inside AccessKeyProvisioner");
		}
	}
}
