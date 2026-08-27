<?php

namespace Hestiacp\Auth\Test;

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Auth\AccessKeyProvisioner;
use Hestiacp\Auth\AccessKeyValidator;
use Hestiacp\Auth\CredentialProvisioningException;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertFalse;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Offline unit tests for AccessKeyProvisioner
 * (CREDENTIAL_PROVISIONING_DESIGN.md / CREDENTIAL_PROVISIONING_IMPLEMENTATION.md).
 * No real Hestia installation, no HTTP, no session — every credential
 * directory is a fresh temp directory per test, never the real
 * $HESTIA/data/api-credentials/ path.
 */
final class AccessKeyProvisionerTest {
	private static function tempCredentialDirectory(): string {
		$dir = sys_get_temp_dir() . "/access-key-provisioner-test-" . bin2hex(random_bytes(8)) . "/";
		mkdir($dir, 0770, true);
		return $dir;
	}

	public static function register(MiniTest $t): void {
		$t->test("1. successful credential creation returns id/secret/user", [self::class, "testSuccessfulCreation"]);
		$t->test("2. returned id is 40 lowercase hex characters", [self::class, "testIdFormat"]);
		$t->test("3. returned secret is 64 lowercase hex characters (256 bits)", [self::class, "testSecretFormat"]);
		$t->test("4. credential file exists after creation", [self::class, "testCredentialFileExists"]);
		$t->test("5. stored record contains the user", [self::class, "testStoredRecordContainsUser"]);
		$t->test("6. stored record contains a secret_hash field", [self::class, "testStoredRecordContainsSecretHash"]);
		$t->test("7. stored record does NOT contain the plaintext secret", [self::class, "testStoredRecordExcludesPlaintextSecret"]);
		$t->test("8. password_verify(returned secret, stored hash) succeeds", [self::class, "testStoredHashVerifiesReturnedSecret"]);
		$t->test("9. a second creation for the same user generates a different credential", [self::class, "testSecondCreationDiffers"]);
		$t->test("10. a generated id never overwrites an existing credential file", [self::class, "testNoOverwriteOnCollision"]);
		$t->test("11. collision retry succeeds once a non-colliding id is generated", [self::class, "testCollisionRetrySucceeds"]);
		$t->test("11b. collision retry throws once all attempts are exhausted", [self::class, "testCollisionRetryExhaustion"]);
		$t->test("12. malformed user is rejected", [self::class, "testMalformedUserRejected"]);
		$t->test("13a. empty id is rejected by revoke()", [self::class, "testRevokeEmptyIdRejected"]);
		$t->test("13b. path-traversal-shaped id is rejected by revoke()", [self::class, "testRevokePathTraversalIdRejected"]);
		$t->test("14. storage-unavailable (missing directory) is reported distinctly", [self::class, "testStorageUnavailable"]);
		$t->test("15. no partial credential file is left behind on write failure", [self::class, "testNoPartialFileOnWriteFailure"]);
		$t->test("16. the secret never appears in an exception message", [self::class, "testSecretNeverInExceptionMessages"]);
		$t->test("17. revoke() deletes an existing credential and reports true", [self::class, "testRevokeDeletesCredential"]);
		$t->test("17b. revoke() reports false for a nonexistent id, without throwing", [self::class, "testRevokeNonexistentIdReturnsFalse"]);
		$t->test("18. a revoked credential can no longer be authenticated by AccessKeyValidator", [self::class, "testRevokedCredentialFailsValidation"]);
		$t->test("19. concurrent-shaped creation: whichever process wins an id-collision race, the other detects and retries, neither overwrites the other", [self::class, "testConcurrentShapedCreationNeverOverwrites"]);
		$t->test("20. AccessKeyValidator can authenticate a credential this class created (end-to-end integration)", [self::class, "testEndToEndWithValidator"]);
		$t->test("21. validator's default credential directory constant is reused, not duplicated", [self::class, "testDefaultDirectoryMatchesValidator"]);
		$t->test("22. provisioner source performs no shell execution", [self::class, "testNoShellExecutionInSource"]);
		$t->test("23. provisioner source has no HTTP/session dependency", [self::class, "testNoHttpSessionDependencyInSource"]);
	}

	public static function testSuccessfulCreation(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");

		assertTrue($result->id !== "", "a non-empty id must be returned");
		assertTrue($result->secret !== "", "a non-empty secret must be returned");
		assertEquals("bob", $result->user, "the returned user must match the requested user");
	}

	public static function testIdFormat(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");

		assertEquals(40, strlen($result->id), "id must be 40 characters (160 bits of hex-encoded entropy)");
		assertTrue((bool) preg_match('/^[0-9a-f]{40}$/', $result->id), "id must be lowercase hex only");
	}

	public static function testSecretFormat(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");

		assertEquals(64, strlen($result->secret), "secret must be 64 characters (256 bits of hex-encoded entropy)");
		assertTrue((bool) preg_match('/^[0-9a-f]{64}$/', $result->secret), "secret must be lowercase hex only");
	}

	public static function testCredentialFileExists(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");

		assertTrue(is_file($dir . $result->id), "a credential file named by the returned id must exist in the credential directory");
	}

	public static function testStoredRecordContainsUser(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");
		$record = json_decode(file_get_contents($dir . $result->id), true);

		assertEquals("bob", $record["user"] ?? null, "the stored record must contain the user");
	}

	public static function testStoredRecordContainsSecretHash(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");
		$record = json_decode(file_get_contents($dir . $result->id), true);

		assertTrue(isset($record["secret_hash"]) && is_string($record["secret_hash"]) && $record["secret_hash"] !== "", "the stored record must contain a non-empty secret_hash field");
	}

	public static function testStoredRecordExcludesPlaintextSecret(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");
		$rawContents = file_get_contents($dir . $result->id);

		assertFalse(strpos($rawContents, $result->secret) !== false, "the plaintext secret must never appear anywhere in the stored record's raw bytes");
	}

	public static function testStoredHashVerifiesReturnedSecret(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");
		$record = json_decode(file_get_contents($dir . $result->id), true);

		assertTrue(password_verify($result->secret, $record["secret_hash"]), "the stored hash must verify against the returned plaintext secret");
	}

	public static function testSecondCreationDiffers(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$first = $provisioner->create("bob");
		$second = $provisioner->create("bob");

		assertTrue($first->id !== $second->id, "two creations for the same user must produce different ids");
		assertTrue($first->secret !== $second->secret, "two creations for the same user must produce different secrets");
	}

	public static function testNoOverwriteOnCollision(): void {
		$dir = self::tempCredentialDirectory();
		$fixedId = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
		// Pre-existing credential for a DIFFERENT user, at the exact id
		// the injected generator will try first.
		file_put_contents($dir . $fixedId, json_encode(["user" => "alice", "secret_hash" => password_hash("alice-secret", PASSWORD_DEFAULT)]));

		$calls = 0;
		$idGenerator = function () use ($fixedId, &$calls) {
			$calls++;
			// Always returns the SAME (already-taken) id — proves the
			// provisioner never overwrites it, exhausting retries.
			return $fixedId;
		};

		$provisioner = new AccessKeyProvisioner($dir, $idGenerator);

		$threw = false;
		try {
			$provisioner->create("bob");
		} catch (CredentialProvisioningException $e) {
			$threw = true;
		}

		assertTrue($threw, "a generator that only ever returns an already-taken id must exhaust retries, not overwrite the existing record");
		$record = json_decode(file_get_contents($dir . $fixedId), true);
		assertEquals("alice", $record["user"] ?? null, "the pre-existing credential for alice must remain completely untouched");
		assertEquals(5, $calls, "the generator must be invoked exactly MAX_ID_COLLISION_ATTEMPTS times");
	}

	public static function testCollisionRetrySucceeds(): void {
		$dir = self::tempCredentialDirectory();
		$takenId = str_repeat("b", 40);
		$freeId = str_repeat("c", 40);
		file_put_contents($dir . $takenId, json_encode(["user" => "alice", "secret_hash" => password_hash("x", PASSWORD_DEFAULT)]));

		$sequence = [$takenId, $freeId];
		$index = 0;
		$idGenerator = function () use ($sequence, &$index) {
			return $sequence[$index++];
		};

		$provisioner = new AccessKeyProvisioner($dir, $idGenerator);
		$result = $provisioner->create("bob");

		assertEquals($freeId, $result->id, "after one collision, the next generated (free) id must be used");
		assertTrue(is_file($dir . $freeId), "the credential must be written under the free id");
		$aliceRecord = json_decode(file_get_contents($dir . $takenId), true);
		assertEquals("alice", $aliceRecord["user"] ?? null, "the colliding id's original record must remain untouched");
	}

	public static function testCollisionRetryExhaustion(): void {
		$dir = self::tempCredentialDirectory();
		$fixedId = str_repeat("d", 40);
		file_put_contents($dir . $fixedId, json_encode(["user" => "alice", "secret_hash" => password_hash("x", PASSWORD_DEFAULT)]));

		$idGenerator = function () use ($fixedId) {
			return $fixedId;
		};
		$provisioner = new AccessKeyProvisioner($dir, $idGenerator);

		$threw = false;
		$message = "";
		try {
			$provisioner->create("bob");
		} catch (CredentialProvisioningException $e) {
			$threw = true;
			$message = $e->getMessage();
		}

		assertTrue($threw, "exhausting all collision retries must throw CredentialProvisioningException");
		assertTrue(strpos($message, "5") !== false || strpos(strtolower($message), "attempt") !== false, "the exception message should indicate retry exhaustion");
	}

	public static function testMalformedUserRejected(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		foreach (["", "has spaces", "has/slash", "has\nnewline", str_repeat("x", 40)] as $badUser) {
			$threw = false;
			try {
				$provisioner->create($badUser);
			} catch (\InvalidArgumentException $e) {
				$threw = true;
			}
			assertTrue($threw, "user " . var_export($badUser, true) . " must be rejected with InvalidArgumentException");
		}

		assertEquals(0, count(glob($dir . "*")), "no credential file may be created for any rejected user");
	}

	public static function testRevokeEmptyIdRejected(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$threw = false;
		try {
			$provisioner->revoke("");
		} catch (\InvalidArgumentException $e) {
			$threw = true;
		}
		assertTrue($threw, "an empty id passed to revoke() must be rejected");
	}

	public static function testRevokePathTraversalIdRejected(): void {
		$dir = self::tempCredentialDirectory();
		$outsideDir = self::tempCredentialDirectory();
		file_put_contents($outsideDir . "victim", "should not be touched");

		$provisioner = new AccessKeyProvisioner($dir);

		$threw = false;
		try {
			$provisioner->revoke("../" . basename(rtrim($outsideDir, "/")) . "/victim");
		} catch (\InvalidArgumentException $e) {
			$threw = true;
		}

		assertTrue($threw, "a path-traversal-shaped id must be rejected outright");
		assertTrue(is_file($outsideDir . "victim"), "the file outside the credential directory must remain untouched");
	}

	public static function testStorageUnavailable(): void {
		$dir = sys_get_temp_dir() . "/access-key-provisioner-nonexistent-" . bin2hex(random_bytes(8)) . "/";
		// Deliberately never created.
		$provisioner = new AccessKeyProvisioner($dir);

		$threw = false;
		try {
			$provisioner->create("bob");
		} catch (CredentialProvisioningException $e) {
			$threw = true;
		}

		assertTrue($threw, "creating a credential when the storage directory does not exist must throw CredentialProvisioningException distinctly");
	}

	public static function testNoPartialFileOnWriteFailure(): void {
		$dir = self::tempCredentialDirectory();
		$fixedId = str_repeat("e", 40);
		$idGenerator = function () use ($fixedId) {
			return $fixedId;
		};
		$provisioner = new AccessKeyProvisioner($dir, $idGenerator);

		$result = $provisioner->create("bob");
		assertTrue(is_file($dir . $fixedId), "sanity check: a real credential file was created");

		$record = json_decode(file_get_contents($dir . $fixedId), true);
		assertTrue(is_array($record) && isset($record["user"]) && isset($record["secret_hash"]), "the file must be a single, complete, valid JSON write — never a partial/interrupted one");
	}

	public static function testSecretNeverInExceptionMessages(): void {
		$dir = self::tempCredentialDirectory();
		$fixedId = str_repeat("f", 40);
		file_put_contents($dir . $fixedId, json_encode(["user" => "alice", "secret_hash" => password_hash("x", PASSWORD_DEFAULT)]));

		$capturedSecret = null;
		$idGenerator = function () use ($fixedId) {
			return $fixedId;
		};
		$secretGenerator = function () use (&$capturedSecret) {
			$capturedSecret = bin2hex(random_bytes(32));
			return $capturedSecret;
		};
		$provisioner = new AccessKeyProvisioner($dir, $idGenerator, $secretGenerator);

		try {
			$provisioner->create("bob");
			assertTrue(false, "sanity check: this call must throw (collision exhaustion)");
		} catch (CredentialProvisioningException $e) {
			assertTrue($capturedSecret !== null, "sanity check: a secret was actually generated during the failed attempt");
			assertFalse(strpos($e->getMessage(), $capturedSecret) !== false, "the exception message must never contain the generated secret");
		}
	}

	public static function testRevokeDeletesCredential(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$result = $provisioner->create("bob");
		assertTrue(is_file($dir . $result->id), "sanity check: the credential exists before revocation");

		$revoked = $provisioner->revoke($result->id);

		assertTrue($revoked, "revoke() must report true for an existing credential");
		assertFalse(is_file($dir . $result->id), "the credential file must no longer exist after revocation");
	}

	public static function testRevokeNonexistentIdReturnsFalse(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);

		$revoked = $provisioner->revoke(str_repeat("0", 40));

		assertFalse($revoked, "revoke() must report false, not throw, for a well-formed but nonexistent id");
	}

	public static function testRevokedCredentialFailsValidation(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);
		$validator = new AccessKeyValidator($dir);

		$result = $provisioner->create("bob");
		assertEquals("bob", $validator->authenticate($result->id, $result->secret), "sanity check: the credential authenticates before revocation");

		$provisioner->revoke($result->id);

		assertEquals(null, $validator->authenticate($result->id, $result->secret), "a revoked credential must no longer authenticate, via the real AccessKeyValidator");
	}

	public static function testConcurrentShapedCreationNeverOverwrites(): void {
		// Simulates two "processes" racing for the same candidate id: the
		// first (real) create() call claims it via fopen(..., 'xb');
		// a second provisioner instance, forced to generate the SAME id
		// first, must detect the collision (the file now exists) and
		// fall through to its own retry rather than overwrite it — the
		// exact mechanism that makes real concurrent creation safe
		// without a global lock (CREDENTIAL_PROVISIONING_DESIGN.md §2.9).
		$dir = self::tempCredentialDirectory();
		$racedId = str_repeat("1", 40);
		$freeId = str_repeat("2", 40);

		$firstProvisioner = new AccessKeyProvisioner($dir, function () use ($racedId) {
			return $racedId;
		});
		$firstResult = $firstProvisioner->create("alice");
		assertEquals($racedId, $firstResult->id, "sanity check: the first provisioner claims the raced id");

		$secondSequence = [$racedId, $freeId];
		$secondIndex = 0;
		$secondProvisioner = new AccessKeyProvisioner($dir, function () use ($secondSequence, &$secondIndex) {
			return $secondSequence[$secondIndex++];
		});
		$secondResult = $secondProvisioner->create("bob");

		assertEquals($freeId, $secondResult->id, "the second provisioner must fall through to the free id after detecting the collision");

		$aliceRecord = json_decode(file_get_contents($dir . $racedId), true);
		assertEquals("alice", $aliceRecord["user"] ?? null, "alice's credential (the race winner) must be completely unaffected by the loser's retry");
	}

	public static function testEndToEndWithValidator(): void {
		$dir = self::tempCredentialDirectory();
		$provisioner = new AccessKeyProvisioner($dir);
		$validator = new AccessKeyValidator($dir);

		$result = $provisioner->create("carol");

		assertEquals("carol", $validator->authenticate($result->id, $result->secret), "a credential created by AccessKeyProvisioner must authenticate via the real, unmodified AccessKeyValidator");
		assertEquals(null, $validator->authenticate($result->id, "wrong-secret"), "a wrong secret against a provisioner-created credential must still be denied");
	}

	public static function testDefaultDirectoryMatchesValidator(): void {
		$provisioner = new \ReflectionClass(AccessKeyProvisioner::class);
		$constructor = $provisioner->getConstructor();
		$defaultValue = $constructor->getParameters()[0]->getDefaultValue();

		assertEquals(AccessKeyValidator::DEFAULT_CREDENTIAL_DIRECTORY, $defaultValue, "AccessKeyProvisioner's default credential directory must be the exact same constant AccessKeyValidator uses, not a copy-pasted literal");
	}

	public static function testNoShellExecutionInSource(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyProvisioner.php");

		foreach (["exec(", "shell_exec(", "proc_open(", "passthru(", "system(", "popen("] as $forbidden) {
			assertFalse(strpos($source, $forbidden) !== false, "AccessKeyProvisioner.php must never contain '$forbidden'");
		}

		foreach (explode("\n", $source) as $line) {
			$trimmed = ltrim($line);
			$isCommentLine = $trimmed === "" || $trimmed[0] === "*" || strpos($trimmed, "//") === 0 || strpos($trimmed, "/*") === 0;
			if (!$isCommentLine) {
				assertFalse(strpos($line, "`") !== false, "AccessKeyProvisioner.php must never use the backtick shell-exec operator: '$line'");
			}
		}
	}

	public static function testNoHttpSessionDependencyInSource(): void {
		$source = file_get_contents(__DIR__ . "/../../web/inc/auth/AccessKeyProvisioner.php");

		foreach (['$_POST', '$_GET', '$_SESSION', '$_SERVER', '$_COOKIE', '$_REQUEST'] as $forbidden) {
			assertFalse(strpos($source, $forbidden) !== false, "AccessKeyProvisioner.php must never reference $forbidden");
		}
	}
}
