<?php

namespace Hestiacp\Adapter\Test;

use Hestiacp\Adapter\AuthorizerInterface;

/**
 * Test double for AuthorizerInterface. Mirrors SpyLockManager's shape:
 * records every authorize() call (with its full arguments, so tests can
 * assert CommandAdapter passed the correct, already-validated operation/
 * target/actor) and lets a test script the verdict deterministically.
 *
 * Never encodes any policy (no roles, no scopes) — it only returns
 * whatever verdict the test configured, exactly as
 * MUTATION_AND_AUTHORIZATION_DESIGN.md Part 6 requires of every
 * AuthorizerInterface implementation, test doubles included.
 */
final class SpyAuthorizer implements AuthorizerInterface {
	/** @var array<int, array{operation: string, target: array<string, mixed>, actor: array<string, mixed>}> */
	public array $calls = [];

	private bool $verdict;

	public function __construct(bool $verdict = true) {
		$this->verdict = $verdict;
	}

	public function authorize(string $operation, array $target, array $actor): bool {
		$this->calls[] = ["operation" => $operation, "target" => $target, "actor" => $actor];
		return $this->verdict;
	}
}
