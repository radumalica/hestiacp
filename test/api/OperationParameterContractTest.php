<?php

namespace Hestiacp\Api\Test;

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Api\OperationAllowlist;
use Hestiacp\Api\OperationParameterContract;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Unit tests for OperationParameterContract (Sprint 3, Part B) — the
 * explicit, API-owned, name-level public parameter contract per
 * operation. Verifies the declared contract for all seven exposed
 * operations, and that OperationAllowlist and OperationParameterContract
 * can never silently drift apart (every allowlisted operation has a
 * contract, and vice versa) — the structural guarantee that a future
 * CommandRegistry change cannot accidentally expose a new parameter
 * through API v2.
 */
final class OperationParameterContractTest {
	public static function register(MiniTest $t): void {
		$t->test("OperationParameterContract: domain.get requires user, domain", [self::class, "testDomainGet"]);
		$t->test("OperationParameterContract: domain.list requires user only", [self::class, "testDomainList"]);
		$t->test("OperationParameterContract: domain.create requires user, domain (no fixed-param leakage)", [self::class, "testDomainCreate"]);
		$t->test("OperationParameterContract: domain.delete requires user, domain (no fixed-param leakage)", [self::class, "testDomainDelete"]);
		$t->test("OperationParameterContract: backup.schedule requires user only", [self::class, "testBackupSchedule"]);
		$t->test("OperationParameterContract: database.create requires user, database, dbuser, password (no fixed-param leakage)", [self::class, "testDatabaseCreate"]);
		$t->test("OperationParameterContract: database.delete requires user, database only (no dbuser)", [self::class, "testDatabaseDelete"]);
		$t->test("OperationParameterContract: undeclared operation returns empty required/allowed sets", [self::class, "testUndeclaredOperation"]);
		$t->test("OperationParameterContract: isDeclared() is accurate for declared and undeclared operations", [self::class, "testIsDeclared"]);
		$t->test("OperationParameterContract and OperationAllowlist can never silently drift apart", [self::class, "testNoDriftBetweenAllowlistAndContract"]);
	}

	private static function assertContract(string $operation, array $expected): void {
		assertEquals($expected, OperationParameterContract::requiredParameters($operation), "$operation required parameters");
		assertEquals($expected, OperationParameterContract::allowedParameters($operation), "$operation allowed parameters");
	}

	public static function testDomainGet(): void {
		self::assertContract("domain.get", ["user", "domain"]);
	}

	public static function testDomainList(): void {
		self::assertContract("domain.list", ["user"]);
	}

	public static function testDomainCreate(): void {
		// CommandRegistry's own "domain.create" fixed_parameters
		// (ip/restart/aliases/proxy_ext) must never appear here — a
		// caller can only ever supply user/domain.
		self::assertContract("domain.create", ["user", "domain"]);
	}

	public static function testDomainDelete(): void {
		// CommandRegistry's own fixed "restart" must never appear here.
		self::assertContract("domain.delete", ["user", "domain"]);
	}

	public static function testBackupSchedule(): void {
		self::assertContract("backup.schedule", ["user"]);
	}

	public static function testDatabaseCreate(): void {
		// CommandRegistry's own fixed type/host/charset must never
		// appear here — a caller can only ever supply
		// user/database/dbuser/password.
		self::assertContract("database.create", ["user", "database", "dbuser", "password"]);
	}

	public static function testDatabaseDelete(): void {
		// Unlike database.create, database.delete's underlying
		// CommandRegistry entry has no "dbuser" parameter at all — the
		// public contract must not invent one.
		self::assertContract("database.delete", ["user", "database"]);
	}

	public static function testUndeclaredOperation(): void {
		assertEquals([], OperationParameterContract::requiredParameters("database.get"));
		assertEquals([], OperationParameterContract::allowedParameters("database.get"));
	}

	public static function testIsDeclared(): void {
		foreach (OperationAllowlist::ALLOWED_OPERATIONS as $operation) {
			assertTrue(OperationParameterContract::isDeclared($operation), "$operation must have a declared contract");
		}
		assertTrue(!OperationParameterContract::isDeclared("database.get"), "an unexposed operation must not be declared");
		assertTrue(!OperationParameterContract::isDeclared("test.mutate"), "a test-only synthetic operation must not be declared");
	}

	public static function testNoDriftBetweenAllowlistAndContract(): void {
		$declaredOperations = [
			"domain.get",
			"domain.list",
			"domain.create",
			"domain.delete",
			"backup.schedule",
			"database.create",
			"database.delete",
		];

		sort($declaredOperations);
		$allowlisted = OperationAllowlist::ALLOWED_OPERATIONS;
		sort($allowlisted);

		assertEquals(
			$declaredOperations,
			$allowlisted,
			"every allowlisted operation must have a declared parameter contract, and vice versa"
		);
	}
}
