<?php

namespace Hestiacp\Auth;

/**
 * Thrown by AccessKeyProvisioner when a credential-management OPERATION
 * itself fails — storage unavailable, candidate-id collision exhaustion,
 * or an atomic write that did not complete — as distinct from a
 * caller-input-shape problem (invalid user, malformed id), which is
 * reported via \InvalidArgumentException instead. Mirrors
 * LockUnavailableException's own "mechanism failure vs. ordinary
 * caller error" distinction (web/inc/adapter/LockUnavailableException.php),
 * applied here to credential provisioning instead of locking.
 *
 * No message constructed by this class ever contains a plaintext secret
 * — see CREDENTIAL_PROVISIONING_DESIGN.md §2.7 and
 * AccessKeyProvisionerTest.php's dedicated test for this property.
 */
final class CredentialProvisioningException extends \RuntimeException {
	public static function storageUnavailable(string $directory, string $reason): self {
		return new self(sprintf("Credential storage unavailable at '%s': %s", $directory, $reason));
	}

	public static function collisionExhausted(int $attempts): self {
		return new self(sprintf("Unable to generate a unique credential id after %d attempts", $attempts));
	}

	public static function writeFailed(string $id, string $reason): self {
		return new self(sprintf("Unable to write credential record '%s': %s", $id, $reason));
	}
}
