<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Api\ApiException;
use Hestiacp\Api\ParameterNormalizer;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Unit tests for ParameterNormalizer, verifying resource-identifier
 * normalization rules per dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §11.
 */
final class ParameterNormalizerTest {
	public static function register(MiniTest $t): void {
		$t->test("ParameterNormalizer: database.delete prefixes raw database suffix with {user}_", [self::class, "testDatabaseDeleteNormalization"]);
		$t->test("ParameterNormalizer: database.delete rejects already-prefixed database identifier", [self::class, "testDatabaseDeleteRejectsAlreadyPrefixed"]);
		$t->test("ParameterNormalizer: database.delete does NOT reject a foreign-looking (non-self) prefix — treated as a raw suffix", [self::class, "testDatabaseDeleteAllowsForeignLookingPrefixAsRawSuffix"]);
		$t->test("ParameterNormalizer: database.create preserves raw database suffix without prefixing", [self::class, "testDatabaseCreateNoNormalization"]);
		$t->test("ParameterNormalizer: domain operations pass parameters through 1:1", [self::class, "testDomainOperationsPassThrough"]);
		$t->test("ParameterNormalizer: backup.schedule passes parameters through 1:1", [self::class, "testBackupSchedulePassThrough"]);
		$t->test("ParameterNormalizer: missing/non-string/empty parameters on database.delete pass through safely to adapter validation", [self::class, "testDatabaseDeleteMissingOrNonStringParams"]);
		$t->test("ParameterNormalizer: default passthrough for unknown operations", [self::class, "testDefaultPassthrough"]);
	}

	public static function testDatabaseDeleteNormalization(): void {
		// Case a: user=admin, database=wordpress_db -> admin_wordpress_db
		$params = [
			"user" => "admin",
			"database" => "wordpress_db",
		];
		$normalized = ParameterNormalizer::normalize("database.delete", $params);

		assertEquals("admin", $normalized["user"]);
		assertEquals("admin_wordpress_db", $normalized["database"]);
	}

	public static function testDatabaseDeleteRejectsAlreadyPrefixed(): void {
		// Case b: user=admin, database=admin_wordpress_db -> rejected
		$caughtAdmin = false;
		try {
			ParameterNormalizer::normalize("database.delete", [
				"user" => "admin",
				"database" => "admin_wordpress_db",
			]);
		} catch (ApiException $e) {
			$caughtAdmin = true;
			assertEquals(422, $e->httpStatus());
			assertEquals("VALIDATION_FAILED", $e->errorCode());
		}
		assertTrue($caughtAdmin, "already-prefixed database identifier for admin must be rejected");

		// Case c: user=other, database=other_wordpress_db -> rejected
		$caughtOther = false;
		try {
			ParameterNormalizer::normalize("database.delete", [
				"user" => "other",
				"database" => "other_wordpress_db",
			]);
		} catch (ApiException $e) {
			$caughtOther = true;
			assertEquals(422, $e->httpStatus());
			assertEquals("VALIDATION_FAILED", $e->errorCode());
		}
		assertTrue($caughtOther, "already-prefixed database identifier for other must be rejected");
	}

	public static function testDatabaseDeleteAllowsForeignLookingPrefixAsRawSuffix(): void {
		// Sprint 3 resolution: ONLY an exact self-prefix match
		// ("{the request's own user}_") is ever rejected.
		// ParameterNormalizer performs no existence/business check and
		// makes no attempt to detect a DIFFERENT username embedded in
		// the suffix — doing so would require querying the Hestia user
		// list (a business/existence check that belongs to the
		// underlying v-* script, never this layer) or a syntactic
		// heuristic that would also incorrectly reject entirely
		// ordinary suffixes (e.g. "wordpress_db" itself has a
		// username-shaped leading segment before its own first "_").
		// "other_wordpress_db" is therefore treated as an ordinary raw
		// suffix and normalized like any other.
		$normalized = ParameterNormalizer::normalize("database.delete", [
			"user" => "admin",
			"database" => "other_wordpress_db",
		]);

		assertEquals("admin", $normalized["user"]);
		assertEquals("admin_other_wordpress_db", $normalized["database"]);
	}

	public static function testDatabaseCreateNoNormalization(): void {
		$params = [
			"user" => "admin",
			"database" => "wordpress_db",
			"dbuser" => "wp_user",
			"password" => "secret123",
		];
		$normalized = ParameterNormalizer::normalize("database.create", $params);

		assertEquals($params, $normalized, "database.create must leave raw suffix and other parameters unmodified");
	}

	public static function testDomainOperationsPassThrough(): void {
		$getParams = ["user" => "alice", "domain" => "example.com"];
		assertEquals($getParams, ParameterNormalizer::normalize("domain.get", $getParams));

		$listParams = ["user" => "alice"];
		assertEquals($listParams, ParameterNormalizer::normalize("domain.list", $listParams));

		$createParams = ["user" => "alice", "domain" => "example.com"];
		assertEquals($createParams, ParameterNormalizer::normalize("domain.create", $createParams));

		$deleteParams = ["user" => "alice", "domain" => "example.com"];
		assertEquals($deleteParams, ParameterNormalizer::normalize("domain.delete", $deleteParams));
	}

	public static function testBackupSchedulePassThrough(): void {
		$params = ["user" => "alice"];
		assertEquals($params, ParameterNormalizer::normalize("backup.schedule", $params));
	}

	public static function testDatabaseDeleteMissingOrNonStringParams(): void {
		// Case d: missing user -> passed through safely (adapter validator rejects missing user)
		$noUser = ["database" => "wordpress_db"];
		assertEquals($noUser, ParameterNormalizer::normalize("database.delete", $noUser));

		// Missing database -> passed through safely (adapter validator rejects missing database)
		$noDb = ["user" => "admin"];
		assertEquals($noDb, ParameterNormalizer::normalize("database.delete", $noDb));

		// Case e: non-string database (int/array) -> passed through safely (adapter validator rejects non-string)
		$intDb = ["user" => "admin", "database" => 12345];
		assertEquals($intDb, ParameterNormalizer::normalize("database.delete", $intDb));

		// Non-string user -> passed through safely
		$intUser = ["user" => 123, "database" => "wordpress_db"];
		assertEquals($intUser, ParameterNormalizer::normalize("database.delete", $intUser));

		// Case f: empty database -> passed through safely (adapter validator rejects empty string)
		$emptyDb = ["user" => "admin", "database" => ""];
		assertEquals($emptyDb, ParameterNormalizer::normalize("database.delete", $emptyDb));
	}

	public static function testDefaultPassthrough(): void {
		$params = ["user" => "alice", "foo" => "bar"];
		assertEquals($params, ParameterNormalizer::normalize("some.other.op", $params));
	}
}
