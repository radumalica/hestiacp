<?php

namespace Hestiacp\Auth;

/**
 * The one-time result of AccessKeyProvisioner::create() — carries the
 * PLAINTEXT secret exactly once, on its way back to whatever caller must
 * display or transmit it to the credential's owner. Nothing in this
 * codebase persists this object; AccessKeyProvisioner itself never stores
 * it or logs it (CREDENTIAL_PROVISIONING_DESIGN.md §2.6/§2.7).
 *
 * A dedicated typed class rather than a generic array so that
 * "$secret is the one-time plaintext value" is a property of the type
 * system at the declaration site, not a bare array key a caller could
 * typo or accidentally serialize wholesale. Mirrors AdapterResult's own
 * "small, public-property, final class" convention
 * (web/inc/adapter/AdapterResult.php) rather than this codebase's
 * non-existent readonly-property style (§1 of the design doc — this
 * repository targets PHP 7.4-compatible syntax throughout).
 */
final class CredentialCreationResult {
	/** @var string The generated, filesystem-safe credential id. */
	public string $id;

	/** @var string The generated PLAINTEXT secret. Exists only for the caller's immediate use — never persisted by this class or by AccessKeyProvisioner. */
	public string $secret;

	/** @var string The Hestia username this credential authenticates as. */
	public string $user;

	public function __construct(string $id, string $secret, string $user) {
		$this->id = $id;
		$this->secret = $secret;
		$this->user = $user;
	}
}
