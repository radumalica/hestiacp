<?php

namespace Hestiacp\Auth\Test;

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Auth\AccessKeyValidator;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Offline unit tests for AccessKeyValidator
 * (ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md). No real Hestia installation,
 * no HTTP, no session, no shell execution — every credential record is a
 * JSON file written directly to a fresh temp directory per test.
 *
 * Reuses test/adapter/MiniTest.php's MiniTest runner and assert*()
 * functions (test-infrastructure reuse only — this file has no
 * production dependency on the adapter).
 */
final class AccessKeyValidatorTest {
	private static function tempCredentialDirectory(): string {
		$dir = sys_get_temp_dir() . "/access-key-validator-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return $dir;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function writeRecord(string $dir, string $id, array $record): void {
		file_put_contents($dir . $id, json_encode($record));
	}

	private static function hashOf(string $secret): string {
		return password_hash($secret, PASSWORD_DEFAULT);
	}

	public static function register(MiniTest $t): void {
		$t->test("1. valid id + valid secret -> username", [self::class, "testValidCredentialAuthenticates"]);
		$t->test("2. valid id + wrong secret -> null", [self::class, "testWrongSecretDenied"]);
		$t->test("3. unknown id -> null", [self::class, "testUnknownIdDenied"]);
		$t->test("4. empty id -> null", [self::class, "testEmptyIdDenied"]);
		$t->test("5. empty secret -> null", [self::class, "testEmptySecretDenied"]);
		$t->test("6a. malformed record (invalid JSON) -> null", [self::class, "testInvalidJsonDenied"]);
		$t->test("6b. malformed record (JSON array, not object) -> null", [self::class, "testJsonArrayDenied"]);
		$t->test("6c. malformed record (missing secret_hash) -> null", [self::class, "testMissingSecretHashDenied"]);
		$t->test("6d. malformed record (missing user) -> null", [self::class, "testMissingUserDenied"]);
		$t->test("6e. malformed record (non-string user) -> null", [self::class, "testNonStringUserDenied"]);
		$t->test("7. secret_hash is verified via password_verify(), not a literal comparison", [self::class, "testHashedVerification"]);
		$t->test("8. a plaintext secret stored directly as secret_hash is never accepted", [self::class, "testPlaintextStoredSecretRejected"]);
		$t->test("9. an unrecognized/future field (e.g. expires_at) in the record is ignored, not enforced", [self::class, "testUnknownFieldIgnored"]);
		$t->test("10. a revoked credential (record file removed) behaves exactly like an unknown id", [self::class, "testRevokedCredentialDenied"]);
		$t->test("11. multiple credentials/users cannot cross-authenticate", [self::class, "testNoCrossAuthentication"]);
		$t->test("12. no plaintext secret appears anywhere in a successful authenticate() result", [self::class, "testNoPlaintextSecretInOutput"]);
		$t->test("13. validator source performs no shell execution", [self::class, "testNoShellExecutionInSource"]);
		$t->test("14. validator source has no HTTP/session dependency", [self::class, "testNoHttpSessionDependencyInSource"]);
		$t->test("15. path-traversal-shaped id is rejected", [self::class, "testPathTraversalIdDenied"]);
	}

	public static function testValidCredentialAuthenticates(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals("bob", $validator->authenticate("key123", "correct-secret"), "a correct id+secret pair must resolve to the stored username");
	}

	public static function testWrongSecretDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "wrong-secret"), "a wrong secret for a real id must be denied");
	}

	public static function testUnknownIdDenied(): void {
		$dir = self::tempCredentialDirectory();
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("does-not-exist", "anything"), "an id with no stored record must be denied");
	}

	public static function testEmptyIdDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("", "correct-secret"), "an empty id must be denied without touching storage");
	}

	public static function testEmptySecretDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", ""), "an empty secret must be denied");
	}

	public static function testInvalidJsonDenied(): void {
		$dir = self::tempCredentialDirectory();
		file_put_contents($dir . "key123", "{not valid json");
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "anything"), "a record that fails to JSON-decode must be denied, not throw");
	}

	public static function testJsonArrayDenied(): void {
		$dir = self::tempCredentialDirectory();
		file_put_contents($dir . "key123", json_encode(["not", "an", "object"]));
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "anything"), "a JSON array record (no user/secret_hash keys) must be denied");
	}

	public static function testMissingSecretHashDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob"]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "anything"), "a record missing secret_hash must be denied");
	}

	public static function testMissingUserDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "correct-secret"), "a record missing user must be denied even if the secret matches");
	}

	public static function testNonStringUserDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => 12345, "secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "correct-secret"), "a non-string user field must be denied even if the secret matches");
	}

	public static function testHashedVerification(): void {
		$dir = self::tempCredentialDirectory();
		$hash = self::hashOf("correct-secret");
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => $hash]);

		assertTrue($hash !== "correct-secret", "sanity check: password_hash() output must differ from the plaintext input");
		assertTrue(strpos($hash, '$') === 0, "sanity check: the stored value must look like a crypt-format hash, not a raw secret");

		$validator = new AccessKeyValidator($dir);
		assertEquals("bob", $validator->authenticate("key123", "correct-secret"), "authentication must succeed via password_verify() against the hash");
	}

	public static function testPlaintextStoredSecretRejected(): void {
		$dir = self::tempCredentialDirectory();
		// Simulates a malformed/legacy-style record where secret_hash was
		// (incorrectly) set to the plaintext secret itself, rather than a
		// real password_hash() output.
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => "correct-secret"]);
		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("key123", "correct-secret"), "a plaintext value stored in secret_hash must never be accepted as a valid hash — password_verify() must reject it, proving no plain-string-comparison fallback exists");
	}

	public static function testUnknownFieldIgnored(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", [
			"user" => "bob",
			"secret_hash" => self::hashOf("correct-secret"),
			"expires_at" => "2000-01-01T00:00:00Z", // far in the past; NOT enforced per ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md §5
		]);
		$validator = new AccessKeyValidator($dir);

		assertEquals("bob", $validator->authenticate("key123", "correct-secret"), "an unrecognized field (including a past-dated expires_at) must be silently ignored, not enforced — expiration enforcement is intentionally deferred");
	}

	public static function testRevokedCredentialDenied(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => self::hashOf("correct-secret")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals("bob", $validator->authenticate("key123", "correct-secret"), "sanity check: the credential works before revocation");

		unlink($dir . "key123"); // revocation modeled as record deletion, mirroring bin/v-delete-access-key

		assertEquals(null, $validator->authenticate("key123", "correct-secret"), "a revoked (deleted) credential must be denied exactly like an unknown id");
	}

	public static function testNoCrossAuthentication(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "keyA", ["user" => "alice", "secret_hash" => self::hashOf("secret-a")]);
		self::writeRecord($dir, "keyB", ["user" => "bob", "secret_hash" => self::hashOf("secret-b")]);
		$validator = new AccessKeyValidator($dir);

		assertEquals("alice", $validator->authenticate("keyA", "secret-a"), "keyA's own secret must authenticate as alice");
		assertEquals("bob", $validator->authenticate("keyB", "secret-b"), "keyB's own secret must authenticate as bob");
		assertEquals(null, $validator->authenticate("keyA", "secret-b"), "keyB's secret must not authenticate keyA");
		assertEquals(null, $validator->authenticate("keyB", "secret-a"), "keyA's secret must not authenticate keyB");
	}

	public static function testNoPlaintextSecretInOutput(): void {
		$dir = self::tempCredentialDirectory();
		self::writeRecord($dir, "key123", ["user" => "bob", "secret_hash" => self::hashOf("s3cr3t-plaintext")]);
		$validator = new AccessKeyValidator($dir);

		$result = $validator->authenticate("key123", "s3cr3t-plaintext");

		assertEquals("bob", $result, "sanity check: authentication succeeded");
		assertTrue($result !== "s3cr3t-plaintext", "the return value must be the username, never the secret");
		assertFalse(strpos((string) $result, "s3cr3t-plaintext") !== false, "the secret must not appear anywhere inside the returned string");
	}

	public static function testNoShellExecutionInSource(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyValidator.php");

		// Backtick shell-exec syntax is checked separately below, since
		// docblocks in this file legitimately use backticks for Markdown
		// code formatting (e.g. `password_verify()`), which would
		// otherwise produce a false positive here.
		foreach (["exec(", "shell_exec(", "proc_open(", "passthru(", "system(", "popen("] as $forbidden) {
			assertFalse(
				strpos($source, $forbidden) !== false,
				"AccessKeyValidator.php must never contain '$forbidden' — it must perform zero shell execution"
			);
		}

		// PHP's backtick shell-exec operator, checked only against actual
		// code lines (docblock/comment lines are excluded, since they
		// legitimately use backticks for Markdown formatting).
		foreach (explode("\n", $source) as $line) {
			$trimmed = ltrim($line);
			$isCommentLine = $trimmed === "" || $trimmed[0] === "*" || strpos($trimmed, "//") === 0 || strpos($trimmed, "/*") === 0;
			if (!$isCommentLine) {
				assertFalse(strpos($line, "`") !== false, "AccessKeyValidator.php must never use the backtick shell-exec operator in actual code: '$line'");
			}
		}
	}

	public static function testNoHttpSessionDependencyInSource(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyValidator.php");

		foreach (['$_POST', '$_GET', '$_SESSION', '$_SERVER', '$_COOKIE', '$_REQUEST'] as $forbidden) {
			assertFalse(
				strpos($source, $forbidden) !== false,
				"AccessKeyValidator.php must never reference $forbidden — it must be fully HTTP/session-independent"
			);
		}
	}

	public static function testPathTraversalIdDenied(): void {
		$dir = self::tempCredentialDirectory();
		// A real record OUTSIDE the credential directory, at the path a
		// naive implementation's traversal might resolve to.
		$outsideDir = self::tempCredentialDirectory();
		self::writeRecord($outsideDir, "secret-target", ["user" => "root-equivalent", "secret_hash" => self::hashOf("whatever")]);

		$validator = new AccessKeyValidator($dir);

		assertEquals(null, $validator->authenticate("../" . basename(rtrim($outsideDir, "/")) . "/secret-target", "whatever"), "an id shaped like a path-traversal attempt must be denied outright, never resolved against a file outside the credential directory");
	}
}
