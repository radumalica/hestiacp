<?php

namespace Hestiacp\Adapter;

/**
 * Default, fully-permissive AuthorizerInterface implementation.
 *
 * This is what preserves CommandAdapter's existing, fully-trusted
 * internal-caller behavior with ZERO change: every request is allowed,
 * exactly as if no authorization seam existed at all. Per
 * MUTATION_AND_AUTHORIZATION_DESIGN.md Part 7, CommandAdapter's
 * constructor defaults to this implementation rather than making the
 * authorization call conditional on an authorizer being present — the
 * seam is ALWAYS consulted (see CommandAdapter::invoke()); only the
 * POLICY behind it is permissive by default.
 *
 * A future API v2 service layer is expected to inject a real,
 * policy-checking AuthorizerInterface implementation instead of this
 * one — this class is not meant to ever be used once a non-trusted
 * caller exists.
 */
final class AllowAllAuthorizer implements AuthorizerInterface {
	public function authorize(string $operation, array $target, array $actor): bool {
		return true;
	}
}
