<?php

namespace Hestiacp\Auth;

/**
 * Standalone, HTTP-independent credential validator implementing the
 * hardened access-key model recommended in
 * API_V2_AUTHENTICATION_DESIGN.md §12/§13, and detailed in
 * ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md.
 *
 * This class answers exactly one question — "does this id+secret pair
 * resolve to a Hestia user?" — and nothing else. It has no knowledge of
 * HTTP, sessions, CommandAdapter, AuthorizerInterface, or any specific
 * Hestia operation. It performs no shell execution and spawns no
 * subprocess of any kind; every secret comparison happens inside this
 * PHP process via
 * `password_verify()`, never passed to a CLI argument (contrast with the
 * legacy `v-check-access-key`'s CLI-argument-passed secret,
 * API_V2_AUTHENTICATION_DESIGN.md §7).
 *
 * This is deliberately a NEW mechanism, not a reader of the legacy
 * `$HESTIA/data/access-keys/` format (ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md
 * §2 explains why that format cannot be safely parsed from PHP without
 * either a fragile custom shell-config parser or shelling back out to
 * `source_conf`). One JSON file per credential:
 *
 *     { "user": "admin", "secret_hash": "$2y$10$..." }
 *
 * `secret_hash` is always the output of `password_hash(..., PASSWORD_DEFAULT)`
 * — never the plaintext secret. Nothing in this class writes that file;
 * credential generation is an explicitly separate, not-yet-built task
 * (ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md §7).
 *
 * Expiration and a soft "disabled" flag are deliberately NOT part of this
 * schema — see ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md §5/§6 for why
 * (revocation is modeled purely as record deletion, mirroring the legacy
 * mechanism's own `bin/v-delete-access-key` semantics exactly).
 */
final class AccessKeyValidator {
	public const DEFAULT_CREDENTIAL_DIRECTORY = "/usr/local/hestia/data/api-credentials/";

	private string $credentialDirectory;

	/**
	 * Lazily computed, process-lifetime decoy hash used when no real
	 * credential record is found, so that `password_verify()` is always
	 * called against a valid-shaped bcrypt hash — a best-effort mitigation
	 * against an unknown-id-vs-wrong-secret timing oracle. See
	 * ACCESS_KEY_VALIDATOR_IMPLEMENTATION.md §4 for exactly what this does
	 * and does not guarantee.
	 */
	private static ?string $dummyHash = null;

	public function __construct(string $credentialDirectory = self::DEFAULT_CREDENTIAL_DIRECTORY) {
		$this->credentialDirectory = rtrim($credentialDirectory, "/") . "/";
	}

	/**
	 * Returns the authenticated Hestia username, or null on ANY failure
	 * (unknown id, wrong secret, malformed record, empty input). The
	 * public contract deliberately collapses every failure reason into
	 * the same null result — internally, distinct reasons short-circuit
	 * at different points, but none of that distinction escapes this
	 * method, per API_V2_AUTHENTICATION_DESIGN.md §8/§9's requirement
	 * that invalid credentials never disclose credential existence.
	 */
	public function authenticate(string $id, string $secret): ?string {
		if ($id === "" || $secret === "") {
			return null;
		}

		$record = $this->readCredentialRecord($id);

		$hash = self::extractSecretHash($record);
		if ($hash === null) {
			$hash = self::dummyHash();
		}

		$verified = password_verify($secret, $hash);

		if ($record === null || !$verified) {
			return null;
		}

		$user = $record["user"] ?? null;
		if (!is_string($user) || $user === "") {
			return null;
		}

		return $user;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function readCredentialRecord(string $id): ?array {
		// basename() alone already neutralizes "../" traversal, but this
		// also rejects any id containing a path separator outright rather
		// than silently remapping it to a different file — the same
		// stricter posture bin/v-check-access-key's own
		// "$(basename "$1")" convention exists to enforce, just made
		// explicit here instead of implicit.
		$safeId = basename($id);
		if ($safeId === "" || $safeId !== $id) {
			return null;
		}

		$path = $this->credentialDirectory . $safeId;
		if (!is_file($path)) {
			return null;
		}

		$contents = @file_get_contents($path);
		if ($contents === false) {
			return null;
		}

		$decoded = json_decode($contents, true);
		if (!is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed>|null $record
	 */
	private static function extractSecretHash(?array $record): ?string {
		if ($record === null) {
			return null;
		}
		$hash = $record["secret_hash"] ?? null;
		if (!is_string($hash) || $hash === "") {
			return null;
		}
		return $hash;
	}

	private static function dummyHash(): string {
		if (self::$dummyHash === null) {
			self::$dummyHash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
		}
		return self::$dummyHash;
	}
}
