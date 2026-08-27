<?php

namespace Hestiacp\Auth;

/**
 * Standalone, HTTP-independent credential-management primitive: creates
 * and revokes the JSON credential records AccessKeyValidator reads.
 * Implements the design in CREDENTIAL_PROVISIONING_DESIGN.md.
 *
 * Like AccessKeyValidator, this class has no knowledge of HTTP, sessions,
 * CommandAdapter, AuthorizerInterface, Cloud Account, roles, or
 * permissions — it operates on explicit inputs (a username in, a
 * CredentialCreationResult out; an id in, a bool out) and trusts its
 * caller completely. WHO is allowed to call create()/revoke() is
 * explicitly not this class's concern (CREDENTIAL_PROVISIONING_DESIGN.md
 * §2.8) — that belongs to whatever future service/authorization layer
 * calls it.
 *
 * Writes exactly the schema AccessKeyValidator already reads, unchanged:
 *
 *     { "user": "admin", "secret_hash": "$2y$10$..." }
 *
 * No expiration field, no disabled flag — see
 * CREDENTIAL_PROVISIONING_DESIGN.md §2.5/§2.7 and
 * ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md §5/§6 for why.
 */
final class AccessKeyProvisioner {
	private const ID_BYTES = 20; // 160 bits -> 40 hex chars
	private const SECRET_BYTES = 32; // 256 bits -> 64 hex chars
	private const MAX_ID_COLLISION_ATTEMPTS = 5;

	/**
	 * Mirrors func/main.sh's is_user_format_valid() (default branch, no
	 * explicit max length argument, so max length 30) — the same rule
	 * ParameterValidator::isValidUsername() already encodes
	 * (web/inc/adapter/ParameterValidator.php). Deliberately duplicated
	 * here rather than imported: this keeps web/inc/auth/ structurally
	 * independent of web/inc/adapter/ (CREDENTIAL_PROVISIONING_DESIGN.md
	 * §1), at the cost of ~10 lines of intentional duplication rather
	 * than a cross-module dependency.
	 */
	private const USERNAME_PATTERN = '/^[a-zA-Z0-9][-.\_a-zA-Z0-9]{0,28}[a-zA-Z0-9]$/';
	private const USERNAME_SINGLE_CHAR_PATTERN = '/^[a-zA-Z0-9]$/';

	private string $credentialDirectory;

	/** @var callable(): string */
	private $idGenerator;

	/** @var callable(): string */
	private $secretGenerator;

	/**
	 * @param callable(): string|null $idGenerator Injectable for tests
	 *     (deterministic id sequences to exercise collision-retry
	 *     behavior). Defaults to a real CSPRNG generator.
	 * @param callable(): string|null $secretGenerator Injectable for
	 *     tests. Defaults to a real CSPRNG generator.
	 */
	public function __construct(
		string $credentialDirectory = AccessKeyValidator::DEFAULT_CREDENTIAL_DIRECTORY,
		?callable $idGenerator = null,
		?callable $secretGenerator = null
	) {
		$this->credentialDirectory = rtrim($credentialDirectory, "/") . "/";
		$this->idGenerator = $idGenerator ?? static function (): string {
			return bin2hex(random_bytes(AccessKeyProvisioner::ID_BYTES));
		};
		$this->secretGenerator = $secretGenerator ?? static function (): string {
			return bin2hex(random_bytes(AccessKeyProvisioner::SECRET_BYTES));
		};
	}

	/**
	 * Creates a new credential for the given Hestia user and returns its
	 * one-time plaintext secret. Never overwrites an existing credential
	 * record — a freshly generated id is used every call, and creation is
	 * atomic (CREDENTIAL_PROVISIONING_DESIGN.md §2.3).
	 *
	 * @throws \InvalidArgumentException if $user fails the same shape
	 *     check func/main.sh's is_user_format_valid() performs.
	 * @throws CredentialProvisioningException if storage is unavailable,
	 *     id generation collides MAX_ID_COLLISION_ATTEMPTS times in a
	 *     row, or the atomic write does not complete.
	 */
	public function create(string $user): CredentialCreationResult {
		if (!self::isValidUsername($user)) {
			throw new \InvalidArgumentException("Invalid user for credential provisioning.");
		}

		if (!is_dir($this->credentialDirectory) || !is_writable($this->credentialDirectory)) {
			throw CredentialProvisioningException::storageUnavailable(
				$this->credentialDirectory,
				"directory does not exist or is not writable"
			);
		}

		$secret = ($this->secretGenerator)();
		$secretHash = password_hash($secret, PASSWORD_DEFAULT);
		$record = json_encode(["user" => $user, "secret_hash" => $secretHash]);

		$id = $this->createRecordAtomically($record);

		return new CredentialCreationResult($id, $secret, $user);
	}

	/**
	 * Revokes (deletes) the credential record for the given id. Returns
	 * true if a record existed and was removed, false if no record
	 * existed for that id (mirrors bin/v-delete-access-key's
	 * exists-then-delete semantics, without the E_NOTEXIST exit-code
	 * vocabulary that belongs to the legacy CLI, not this class).
	 *
	 * Unlike AccessKeyValidator::authenticate(), this is a management
	 * operation, not an authentication-boundary one: the caller already
	 * legitimately holds the id (it was returned by a prior create() or
	 * an already-authorized listing), so there is no existence-disclosure
	 * concern to hide behind a uniform result — a malformed id is
	 * reported as a caller-programming error, not silently swallowed.
	 *
	 * @throws \InvalidArgumentException if $id is empty or shaped like a
	 *     path-traversal attempt.
	 */
	public function revoke(string $id): bool {
		if (!self::isSafeId($id)) {
			throw new \InvalidArgumentException("Invalid credential id for revocation.");
		}

		$path = $this->credentialDirectory . $id;
		if (!is_file($path)) {
			return false;
		}

		return @unlink($path);
	}

	private function createRecordAtomically(string $record): string {
		$lastError = null;

		for ($attempt = 0; $attempt < self::MAX_ID_COLLISION_ATTEMPTS; $attempt++) {
			$id = ($this->idGenerator)();
			$path = $this->credentialDirectory . $id;

			error_clear_last();
			$handle = @fopen($path, "xb");
			if ($handle === false) {
				$error = error_get_last();
				$lastError = $error["message"] ?? "unknown error";
				if (file_exists($path)) {
					// Genuine collision on this specific candidate id —
					// discard it and try again with a fresh one.
					continue;
				}
				// fopen() failed for a reason OTHER than "already
				// exists" (e.g. directory vanished, permission denied
				// mid-run) — a storage problem, not a collision, so it
				// is not retried.
				throw CredentialProvisioningException::storageUnavailable($this->credentialDirectory, $lastError);
			}

			$written = @fwrite($handle, $record);
			$closed = @fclose($handle);

			if ($written === false || $written !== strlen($record) || !$closed) {
				@unlink($path);
				throw CredentialProvisioningException::writeFailed($id, "incomplete or failed write");
			}

			// 0640 (owner rw, group r), not 0600 — AccessKeyValidator
			// reads this file as the "hestiaweb" user, never as whatever
			// identity ran this provisioner (real deployments: root, via
			// bin/v-add-api-credential). The production credential
			// directory is provisioned setgid ("hestiaweb" group,
			// CREDENTIAL_PROVISIONING_WIRING_DESIGN.md §2.1), so a file
			// created here by any owner still inherits group "hestiaweb"
			// automatically — 0640 is what actually grants that group
			// read access; 0600 would silently make every credential
			// unreadable by the one process that needs to validate it.
			@chmod($path, 0640);

			return $id;
		}

		throw CredentialProvisioningException::collisionExhausted(self::MAX_ID_COLLISION_ATTEMPTS);
	}

	private static function isValidUsername(string $value): bool {
		if ($value === "") {
			return false;
		}
		if (preg_match('/[^\x00-\x7F]/', $value)) {
			return false;
		}
		if (strlen($value) === 1) {
			return (bool) preg_match(self::USERNAME_SINGLE_CHAR_PATTERN, $value);
		}
		return (bool) preg_match(self::USERNAME_PATTERN, $value);
	}

	private static function isSafeId(string $id): bool {
		if ($id === "") {
			return false;
		}
		return basename($id) === $id;
	}
}
