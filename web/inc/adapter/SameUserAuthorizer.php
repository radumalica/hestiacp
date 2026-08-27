<?php

namespace Hestiacp\Adapter;

/**
 * Minimal, real authorization policy: an actor may operate on a target if
 * and only if they are the same Hestia user.
 *
 *     actor.user === target.user  ->  allowed
 *     actor.user !== target.user  ->  denied
 *     actor.user missing/null     ->  denied
 *     target.user missing/null    ->  denied (fail closed — see below)
 *
 * This is deliberately the smallest policy that is still a REAL policy
 * (not a stub): it answers "is this principal allowed to touch this
 * target" using only the {operation, target, actor} shape
 * AuthorizerInterface already defines — no roles, no scopes, no
 * delegation, no tenancy, no per-operation knowledge of any kind. Per
 * API_V2_ARCHITECTURE_REVIEW.md section 15 step 2 and
 * AUTHORIZATION_POLICY_IMPLEMENTATION.md, this is intentionally the
 * FIRST authorization policy, not the final one — administrative roles
 * (admin/superadmin/cloud_account_owner/tenant_admin) are explicitly out
 * of scope for this class and belong to a later policy, not an extension
 * of this one.
 *
 * "target.user missing -> denied" is a fail-closed default, not an
 * oversight: every operation registered in CommandRegistry today has a
 * "user" parameter, so this branch is inert against the current registry
 * (see AUTHORIZATION_POLICY_IMPLEMENTATION.md "Future Limitations" for
 * what happens if a future operation's target legitimately has no
 * concept of a Hestia user).
 */
final class SameUserAuthorizer implements AuthorizerInterface {
	public function authorize(string $operation, array $target, array $actor): bool {
		$actorUser = $actor["user"] ?? null;
		$targetUser = $target["user"] ?? null;

		if (!is_string($actorUser) || $actorUser === "") {
			return false;
		}
		if (!is_string($targetUser) || $targetUser === "") {
			return false;
		}

		return $actorUser === $targetUser;
	}
}
