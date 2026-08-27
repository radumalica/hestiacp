<?php

namespace Hestiacp\Auth;

/**
 * Thin, injectable wrapper around AccessKeyProvisioner for the
 * bin/v-add-api-credential / bin/v-delete-api-credential CLI shims
 * (CREDENTIAL_PROVISIONING_WIRING_DESIGN.md §2.4).
 *
 * Contains ZERO credential-management logic of its own — no random
 * generation, no hashing, no JSON schema, no filesystem access, no
 * revoke logic. It only: (1) rejects an obviously-missing argument
 * before ever calling the provisioner (a CLI-usage concern, not a
 * username-format concern — the actual format rule stays inside
 * AccessKeyProvisioner, not duplicated here), (2) calls
 * AccessKeyProvisioner, (3) formats the result as shell/json/plain text,
 * (4) maps success/failure to an exit code. It knows nothing about HTTP
 * request superglobals, sessions, CommandAdapter, or AuthorizerInterface.
 *
 * Every method returns a CliOutcome instead of calling exit()/echo()
 * itself, so this class is testable via plain dependency injection —
 * see test/auth/AccessKeyCliTest.php — without spawning a real process
 * or capturing real STDOUT/STDERR.
 */
final class AccessKeyCli {
	private AccessKeyProvisioner $provisioner;

	public function __construct(AccessKeyProvisioner $provisioner) {
		$this->provisioner = $provisioner;
	}

	/**
	 * @param string $format One of "shell" (default), "json", "plain".
	 */
	public function create(string $user, string $format = "shell"): CliOutcome {
		if ($user === "") {
			return new CliOutcome(1, "", "Error: not enough arguments\nUsage: v-add-api-credential USER [FORMAT]\n");
		}

		try {
			$result = $this->provisioner->create($user);
		} catch (\InvalidArgumentException $e) {
			return new CliOutcome(1, "", "Error: invalid user\n");
		} catch (CredentialProvisioningException $e) {
			return new CliOutcome(1, "", "Error: " . $e->getMessage() . "\n");
		}

		return new CliOutcome(0, self::formatCreated($result, $format));
	}

	public function revoke(string $id): CliOutcome {
		if ($id === "") {
			return new CliOutcome(1, "", "Error: not enough arguments\nUsage: v-delete-api-credential CREDENTIAL_ID\n");
		}

		try {
			$revoked = $this->provisioner->revoke($id);
		} catch (\InvalidArgumentException $e) {
			return new CliOutcome(1, "", "Error: invalid credential id\n");
		}

		if (!$revoked) {
			return new CliOutcome(1, "", "Error: credential '$id' doesn't exist\n");
		}

		return new CliOutcome(0, "Credential '$id' revoked.\n");
	}

	private static function formatCreated(CredentialCreationResult $result, string $format): string {
		if ($format === "json") {
			return json_encode([
				"ID" => $result->id,
				"SECRET" => $result->secret,
				"USER" => $result->user,
			]) . "\n";
		}

		if ($format === "plain") {
			return $result->id . ":" . $result->secret . "\n";
		}

		return "ID:      {$result->id}\n" . "SECRET:  {$result->secret}\n" . "USER:    {$result->user}\n";
	}
}
