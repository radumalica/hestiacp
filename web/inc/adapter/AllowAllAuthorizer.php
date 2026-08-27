<?php

namespace Hestiacp\Adapter;

/**
 * Fully-permissive AuthorizerInterface implementation. TEST / DEVELOPMENT
 * POLICY ONLY — this must never be the production default.
 *
 * Every request is allowed, exactly as if no authorization seam existed
 * at all. As of AUTHORIZATION_POLICY_IMPLEMENTATION.md, CommandAdapter's
 * constructor no longer defaults to this class — it defaults to
 * SameUserAuthorizer, a real policy. This class remains only so that
 * tests exercising concerns other than authorization (validation, argv
 * construction, locking, mutation classification, etc.) can explicitly
 * inject permissive behavior instead of relying on omission.
 *
 * Do not construct this in any code path a non-trusted caller can reach.
 */
final class AllowAllAuthorizer implements AuthorizerInterface {
	public function authorize(string $operation, array $target, array $actor): bool {
		return true;
	}
}
