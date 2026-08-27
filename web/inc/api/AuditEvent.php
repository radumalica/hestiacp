<?php

namespace Hestiacp\Api;

/**
 * Sprint 6 — the explicit, small audit record model for
 * POST /api/v2/execute, per
 * dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md §4/§6/§7.
 *
 * Every property here is deliberately a SAFE-TO-LOG value — this class
 * declares no property capable of holding a raw secret, an Authorization
 * header, a plaintext password, or a raw request body; there is nothing
 * for a caller to accidentally populate with sensitive data, because no
 * such field exists (see test/api/GenericityTest.php's
 * testAuditEventModelDeclaresNoSensitiveFields(), which asserts this
 * class's own source never declares a property with one of those
 * names). $target itself is never the raw request `params` — it is only
 * ever what AuditTargetRedactor::redact() already reduced it to (see
 * that class's own docblock for the explicit per-operation allowlist).
 */
final class AuditEvent {
	/** ISO 8601, UTC. */
	public string $timestamp;

	/** One of the small, fixed vocabulary — see AuditEvent::eventTypeFor(). */
	public string $eventType;

	/** Server-generated, unpredictable; see RequestId. */
	public string $requestId;

	/**
	 * The Basic-auth credential id as EXTRACTED from the Authorization
	 * header, regardless of whether it turned out to be valid —
	 * deliberately named distinctly from $credentialId so a reader can
	 * never mistake an unvalidated, caller-supplied value for a
	 * validated one. Safe to log: this is the public credential
	 * identifier (comparable to a username), never the secret half.
	 */
	public ?string $attemptedCredentialId;

	/**
	 * The credential id ONLY once AccessKeyValidator::authenticate() has
	 * actually confirmed it — null for every event that never reached a
	 * successful authentication (auth failure, pre-auth rate limit,
	 * malformed request).
	 */
	public ?string $credentialId;

	/** The authenticated Hestia user (actor.user) — null until authenticated. */
	public ?string $user;

	/** REMOTE_ADDR, when supplied by the transport layer. */
	public ?string $clientIp;

	/**
	 * The operation name. Populated even when the operation was never
	 * allowlisted/resolved (the raw, caller-supplied string), so an
	 * "unknown operation" event still records what was attempted — see
	 * ExecuteRequestHandler's own handling. Still just a string; never
	 * executed, never used to resolve a script.
	 */
	public ?string $operation;

	/**
	 * @var array<string, string>|null Already redacted by
	 *      AuditTargetRedactor — never the raw params array.
	 */
	public ?array $target;

	public int $httpStatus;

	/** The envelope's own "outcome" field (succeeded/succeeded_with_warning/failed/unknown). */
	public string $outcome;

	public bool $success;

	/** The envelope's error.code, when the request failed. */
	public ?string $errorCode;

	/** The envelope's error.details.hestia_error_code, when present. */
	public ?string $hestiaErrorCode;

	public ?int $durationMs;

	public function __construct(
		string $timestamp,
		string $eventType,
		string $requestId,
		?string $attemptedCredentialId,
		?string $credentialId,
		?string $user,
		?string $clientIp,
		?string $operation,
		?array $target,
		int $httpStatus,
		string $outcome,
		bool $success,
		?string $errorCode,
		?string $hestiaErrorCode,
		?int $durationMs
	) {
		$this->timestamp = $timestamp;
		$this->eventType = $eventType;
		$this->requestId = $requestId;
		$this->attemptedCredentialId = $attemptedCredentialId;
		$this->credentialId = $credentialId;
		$this->user = $user;
		$this->clientIp = $clientIp;
		$this->operation = $operation;
		$this->target = $target;
		$this->httpStatus = $httpStatus;
		$this->outcome = $outcome;
		$this->success = $success;
		$this->errorCode = $errorCode;
		$this->hestiaErrorCode = $hestiaErrorCode;
		$this->durationMs = $durationMs;
	}

	/**
	 * The audit vocabulary is deliberately just "OPERATION_SUCCEEDED" plus
	 * the API's own already-small, already-reviewed error-code taxonomy
	 * (ApiException codes + ResponseMapper's adapter-error codes) —
	 * never a second, parallel vocabulary that could drift from it. See
	 * the doc's §5 for the full outcome -> eventType table.
	 */
	public static function eventTypeFor(bool $success, ?string $errorCode): string {
		if ($success) {
			return "OPERATION_SUCCEEDED";
		}
		return $errorCode ?? "INTERNAL_ERROR";
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			"timestamp" => $this->timestamp,
			"event_type" => $this->eventType,
			"request_id" => $this->requestId,
			"attempted_credential_id" => $this->attemptedCredentialId,
			"credential_id" => $this->credentialId,
			"user" => $this->user,
			"client_ip" => $this->clientIp,
			"operation" => $this->operation,
			"target" => $this->target,
			"http_status" => $this->httpStatus,
			"outcome" => $this->outcome,
			"success" => $this->success,
			"error_code" => $this->errorCode,
			"hestia_error_code" => $this->hestiaErrorCode,
			"duration_ms" => $this->durationMs,
		];
	}
}
