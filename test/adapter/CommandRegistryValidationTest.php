<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\CommandRegistry;

use function Hestiacp\Adapter\Test\assertEquals;
use function Hestiacp\Adapter\Test\assertTrue;

/**
 * Tests for CommandRegistry::validateMutationMetadata()
 * (MUTATION_AND_AUTHORIZATION_DESIGN.md Part 3): a typo'd symbolic
 * Hestia error name in a registry entry's
 * "mutation.known_post_mutation_exit_codes" must fail loudly, at
 * CommandRegistry construction time, rather than silently compiling
 * into an operation whose confirmed_degraded classification can never
 * actually trigger.
 */
final class CommandRegistryValidationTest {
	private static function baseEntry(array $mutation): array {
		return [
			"script" => "v-does-not-exist-test-only",
			"argument_order" => ["user"],
			"parameters" => [
				"user" => ["type" => "username", "required" => true],
			],
			"fixed_parameters" => [],
			"mutation" => $mutation,
		];
	}

	public static function register(MiniTest $t): void {
		$t->test("registry validation: a single valid symbolic code is accepted", [self::class, "testValidSingleCode"]);
		$t->test("registry validation: multiple valid symbolic codes are accepted", [self::class, "testMultipleValidCodes"]);
		$t->test("registry validation: an empty list is accepted", [self::class, "testEmptyListAccepted"]);
		$t->test("registry validation: an omitted field is accepted", [self::class, "testOmittedFieldAccepted"]);
		$t->test("registry validation: an invalid/typo'd symbolic code is rejected at construction time", [self::class, "testInvalidCodeRejected"]);
		$t->test("registry validation: a non-array (string) value for the field is rejected at construction time", [self::class, "testNonArrayValueRejected"]);
		$t->test("registry validation: the real domain.create entry declares E_RESTART and passes validation", [self::class, "testDomainCreateDeclaresERestart"]);
		$t->test("registry validation: the real domain.delete entry declares E_RESTART and passes validation", [self::class, "testDomainDeleteDeclaresERestart"]);
		$t->test("registry validation: read operations declare no known_post_mutation_exit_codes", [self::class, "testReadOperationsDeclareNothing"]);
	}

	public static function testValidSingleCode(): void {
		$registry = new CommandRegistry([
			"test.op" => self::baseEntry(["kind" => "create", "known_post_mutation_exit_codes" => ["E_RESTART"]]),
		]);

		assertTrue($registry->has("test.op"), "construction must succeed for a single valid symbolic code");
	}

	public static function testMultipleValidCodes(): void {
		$registry = new CommandRegistry([
			"test.op" => self::baseEntry(["kind" => "create", "known_post_mutation_exit_codes" => ["E_RESTART", "E_LIMIT"]]),
		]);

		$entry = $registry->get("test.op");
		assertEquals(["E_RESTART", "E_LIMIT"], $entry["mutation"]["known_post_mutation_exit_codes"], "construction must succeed and preserve multiple valid codes");
	}

	public static function testEmptyListAccepted(): void {
		$registry = new CommandRegistry([
			"test.op" => self::baseEntry(["kind" => "create", "known_post_mutation_exit_codes" => []]),
		]);

		assertTrue($registry->has("test.op"), "an explicitly empty list must be accepted (equivalent to declaring nothing)");
	}

	public static function testOmittedFieldAccepted(): void {
		$registry = new CommandRegistry([
			"test.op" => self::baseEntry(["kind" => "create"]), // no known_post_mutation_exit_codes key at all
		]);

		assertTrue($registry->has("test.op"), "omitting the field entirely must be accepted — this is the default, backward-compatible case");
	}

	public static function testInvalidCodeRejected(): void {
		$threw = false;
		$message = "";
		try {
			new CommandRegistry([
				"test.op" => self::baseEntry(["kind" => "create", "known_post_mutation_exit_codes" => ["E_RESTAR"]]), // typo: missing the trailing T
			]);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
			$message = $e->getMessage();
		}

		assertTrue($threw, "a typo'd symbolic code must throw InvalidArgumentException at construction time, not fail silently");
		assertTrue(strpos($message, "test.op") !== false, "the exception message must name the offending operation");
		assertTrue(strpos($message, "E_RESTAR") !== false, "the exception message must name the offending (invalid) code");
	}

	public static function testNonArrayValueRejected(): void {
		$threw = false;
		$message = "";
		try {
			new CommandRegistry([
				"test.op" => self::baseEntry(["kind" => "create", "known_post_mutation_exit_codes" => "E_RESTART"]), // string, not an array
			]);
		} catch (\InvalidArgumentException $e) {
			$threw = true;
			$message = $e->getMessage();
		}

		assertTrue($threw, "a non-array value must throw InvalidArgumentException at construction time, not be silently ignored or crash later during classification");
		assertTrue(strpos($message, "test.op") !== false, "the exception message must name the offending operation");
	}

	public static function testDomainCreateDeclaresERestart(): void {
		// Constructing the REAL, production registry (no additionalOperations)
		// must not throw — proves domain.create's own declared code is
		// itself valid against CommandAdapter::HESTIA_EXIT_CODES.
		$registry = new CommandRegistry();
		$entry = $registry->get("domain.create");

		assertEquals(["E_RESTART"], $entry["mutation"]["known_post_mutation_exit_codes"] ?? null, "domain.create must declare exactly E_RESTART as its known post-mutation exit code");
	}

	public static function testDomainDeleteDeclaresERestart(): void {
		$registry = new CommandRegistry();
		$entry = $registry->get("domain.delete");

		assertEquals(["E_RESTART"], $entry["mutation"]["known_post_mutation_exit_codes"] ?? null, "domain.delete must declare exactly E_RESTART as its known post-mutation exit code");
	}

	public static function testReadOperationsDeclareNothing(): void {
		$registry = new CommandRegistry();

		$domainGet = $registry->get("domain.get");
		$domainList = $registry->get("domain.list");

		assertTrue(!isset($domainGet["mutation"]["known_post_mutation_exit_codes"]), "domain.get (read-only) must not declare known_post_mutation_exit_codes");
		assertTrue(!isset($domainList["mutation"]["known_post_mutation_exit_codes"]), "domain.list (read-only) must not declare known_post_mutation_exit_codes");
	}
}
