<?php

namespace Hestiacp\Adapter;

/**
 * Authorization seam — the ONLY question CommandAdapter asks before
 * proceeding with an otherwise-valid, resolved request:
 * "is $actor allowed to run $operation against $target?"
 *
 * Per MUTATION_AND_AUTHORIZATION_DESIGN.md Part 4 (option D) and Part 6:
 * this interface intentionally carries NO concept of roles, scopes,
 * delegation, "reseller"/"admin"/"hosting user", or any other policy
 * vocabulary. It answers one yes/no question for one already-resolved
 * request. Every policy decision behind that answer belongs to whatever
 * concrete implementation is injected — this interface, and
 * CommandAdapter's use of it, must never know how that decision was
 * made.
 *
 * Mirrors the existing ProcessRunnerInterface/LockManagerInterface
 * dependency-injection pattern already established in this codebase:
 * CommandAdapter depends only on this interface, never on a concrete
 * implementation, so production code can inject a real policy-checking
 * authorizer while tests inject a scripted one (see
 * test/adapter/SpyAuthorizer.php) without CommandAdapter itself
 * changing.
 */
interface AuthorizerInterface {
	/**
	 * @param string $operation The registry operation name, e.g. "domain.create".
	 * @param array<string, mixed> $target The SAME already-validated
	 *        target CommandAdapter has built from the caller's
	 *        parameters (e.g. ["user" => "admin", "domain" => "example.com"]) —
	 *        never raw, unvalidated $params.
	 * @param array{user?: ?string, acting_as?: ?string} $actor The SAME
	 *        normalized actor structure already present on AdapterResult
	 *        — this interface does not introduce a new actor shape.
	 *
	 * @return bool true to allow the operation to proceed, false to deny
	 *         it. A denial is an ordinary, expected outcome (an
	 *         unprivileged actor requesting a privileged operation) — not
	 *         an exceptional one — so it is reported via a return value,
	 *         not an exception, mirroring LockManagerInterface::acquire()'s
	 *         "false is a normal outcome" convention rather than
	 *         LockManager's separate exception for a MECHANISM failure
	 *         (this interface has no equivalent mechanism-failure concept
	 *         to distinguish; a real implementation that itself fails —
	 *         e.g. cannot reach a policy store — is free to throw its own
	 *         exception type, which CommandAdapter does not catch,
	 *         exactly like an unexpected ProcessRunnerInterface::run()
	 *         failure today).
	 */
	public function authorize(string $operation, array $target, array $actor): bool;
}
