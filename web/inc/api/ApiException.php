<?php

namespace Hestiacp\Api;

/**
 * Internal control-flow exception for an EXPECTED API v2 request failure
 * (malformed input, authentication failure, disallowed operation,
 * envelope validation failure). Carries exactly the three things
 * ExecuteRequestHandler needs to build a public error envelope: the
 * stable machine-readable error code
 * (dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §14), the HTTP status
 * to return (§15), and a safe, generic human-readable message.
 *
 * Deliberately NOT used for unexpected/programmer-bug failures — those
 * propagate as their own exception type and are caught only once, at the
 * outermost boundary (ExecuteRequestHandler::handle()'s own top-level
 * catch), and mapped to a single generic INTERNAL_ERROR/500 — never
 * re-labeled as one of THIS exception's more specific codes.
 */
final class ApiException extends \RuntimeException {
	private string $errorCode;
	private int $httpStatus;
	/** @var array<string, mixed>|null */
	private ?array $details;

	public function __construct(string $errorCode, int $httpStatus, string $message, ?array $details = null) {
		parent::__construct($message);
		$this->errorCode = $errorCode;
		$this->httpStatus = $httpStatus;
		$this->details = $details;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function httpStatus(): int {
		return $this->httpStatus;
	}

	/** @return array<string, mixed>|null */
	public function details(): ?array {
		return $this->details;
	}
}
