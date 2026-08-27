<?php

namespace Hestiacp\Api;

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Auth\AccessKeyValidator;

/**
 * Implements the POST /api/v2/execute contract end to end, per
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §6-§16, strictly
 * following the pipeline ordering §12 defines:
 *
 *   parse -> authenticate -> resolve operation -> validate request
 *     -> normalize target -> CommandAdapter::invoke()
 *       -> resolve -> validate -> normalize -> authorize -> lock -> execute
 *
 * Deliberately HTTP-transport-independent: handle() takes plain scalar
 * inputs (method, content type, Authorization header value, raw body
 * string) rather than reading $_SERVER/php://input directly, so this
 * class is exercised entirely through PHP function calls in tests — no
 * real HTTP server, no real credential storage beyond an injected temp
 * directory (matching AccessKeyValidatorTest/AccessKeyProvisionerTest's
 * own established convention), and no real bin/v-* script (via
 * CommandAdapter's own existing FakeProcessRunner injection point).
 *
 * Contains ZERO command-execution logic of its own: every operational
 * outcome comes from exactly one CommandAdapter::invoke() call; this
 * class never spawns a shell command or subprocess of any kind (no
 * process-execution PHP function appears anywhere in its own source),
 * and references no bin/v-* script name anywhere in its own source
 * either.
 *
 * DEVIATION from a literal reading of §8/§10 (documented in full in
 * dev-docs/api-v2/API_V2_HTTP_ENTRY_POINT_IMPLEMENTATION.md
 * "Deviations"): those sections' prose says a caller-supplied "user"
 * field anywhere in `params` must be rejected outright. Read literally,
 * this would make every registered operation permanently uncallable,
 * since every one of CommandRegistry's seven entries declares a
 * required "user" parameter (the resource owner) — a different concept
 * from actor.user (the authenticated identity), which SameUserAuthorizer
 * already exists specifically to compare against it. §22's own
 * acceptance criterion #3 states the real, testable requirement
 * precisely and without that contradiction: "actor.user is never derived
 * from anything but AccessKeyValidator::authenticate()'s return value...
 * a `user`/`actor` field inside `params` has zero effect on the
 * resulting `actor`." This class implements exactly that: `actor` is
 * built solely from authenticate() below and is never read from, or
 * merged with, $body/$params at any point. A literal `actor` field
 * anywhere at the request's top level is rejected by
 * validateEnvelope()'s unknown-top-level-field check; a `params.actor`
 * (or any other key an operation's registry entry does not declare) is
 * rejected by CommandAdapter's own existing UNEXPECTED_PARAMETER check —
 * both without this class needing any operation-specific field-name
 * denylist.
 */
final class ExecuteRequestHandler {
	/**
	 * Sprint 4 hardening: a purely application-level cap on the raw
	 * request body, enforced before JSON decoding — independent of, and
	 * not a replacement for, any webserver/php.ini body-size limit
	 * (post_max_size etc., which remain a deployment concern outside
	 * this repository — see
	 * dev-docs/api-v2/API_V2_HTTP_HARDENING_IMPLEMENTATION.md §Request
	 * Size). 64 KiB is deliberately generous for this contract's small,
	 * flat JSON envelopes (the largest today, database.create, has four
	 * short string fields) while still closing off an unbounded body
	 * being fully buffered into memory by json_decode().
	 */
	private const MAX_BODY_BYTES = 65536;

	private AccessKeyValidator $validator;
	private CommandAdapter $adapter;

	/** @var string[] */
	private array $allowedOperations;

	/**
	 * @param string[]|null $allowedOperations Test-only extension point,
	 *        mirroring CommandRegistry's own established
	 *        "$additionalOperations... no production caller passes this
	 *        argument" convention (web/inc/adapter/CommandRegistry.php).
	 *        Defaults to OperationAllowlist::ALLOWED_OPERATIONS — the
	 *        real, single source of truth for production. Tests use this
	 *        to exercise the full pipeline (including
	 *        authorization/lock-ordering invariants) against a synthetic
	 *        mutating operation without this sprint exposing one in
	 *        production (web/api/v2/index.php never passes this
	 *        argument).
	 */
	public function __construct(AccessKeyValidator $validator, CommandAdapter $adapter, ?array $allowedOperations = null) {
		$this->validator = $validator;
		$this->adapter = $adapter;
		$this->allowedOperations = $allowedOperations ?? OperationAllowlist::ALLOWED_OPERATIONS;
	}

	/**
	 * @return array{0: int, 1: array<string, mixed>} [$httpStatus, $envelope]
	 */
	public function handle(string $method, string $contentType, ?string $authorizationHeader, string $rawBody): array {
		$operation = null;

		try {
			$this->assertMethod($method);
			$this->assertContentType($contentType);
			$this->assertBodySize($rawBody);
			$body = $this->decodeJson($rawBody);

			// Authentication strictly before operation resolution — an
			// unauthenticated request never reaches CommandAdapter, and
			// never even learns whether its requested operation exists
			// (§12's own enforced invariant).
			$actor = $this->authenticate($authorizationHeader);

			$operation = $this->resolveOperation($body);
			$params = $this->validateEnvelope($body);
			$params = $this->validateOperationParameters($operation, $params);
			$params = ParameterNormalizer::normalize($operation, $params);

			$result = $this->adapter->invoke($operation, $params, $actor);

			return ResponseMapper::fromAdapterResult($result);
		} catch (ApiException $exception) {
			return ResponseMapper::fromApiException($operation ?? "", $exception);
		} catch (\Throwable $exception) {
			// Anything NOT already classified as an ApiException is an
			// unexpected/programmer failure, not an expected operational
			// one (see this class's own docblock and
			// API_V2_HTTP_CONTRACT_DESIGN.md §14's own INTERNAL_ERROR
			// entry, which explicitly covers "a genuine unexpected
			// exception at the HTTP layer itself"). $exception->getMessage()
			// is deliberately NEVER included in the response — it may
			// contain a filesystem path or other internal detail (§19) —
			// only this fixed, generic message is ever returned.
			return ResponseMapper::fromApiException(
				$operation ?? "",
				new ApiException("INTERNAL_ERROR", 500, "An internal error occurred.")
			);
		}
	}

	private function assertMethod(string $method): void {
		if ($method !== "POST") {
			throw new ApiException("METHOD_NOT_ALLOWED", 405, "Only POST is supported for this endpoint.");
		}
	}

	private function assertContentType(string $contentType): void {
		$parts = explode(";", $contentType);
		$normalized = strtolower(trim($parts[0]));
		if ($normalized !== "application/json") {
			throw new ApiException("UNSUPPORTED_MEDIA_TYPE", 415, "Content-Type must be application/json.");
		}
	}

	private function assertBodySize(string $rawBody): void {
		if (strlen($rawBody) > self::MAX_BODY_BYTES) {
			throw new ApiException("PAYLOAD_TOO_LARGE", 413, "Request body exceeds the maximum allowed size.");
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeJson(string $rawBody): array {
		$decoded = json_decode($rawBody, true);

		// A genuine JSON syntax error (including an empty body — an
		// empty string is not valid JSON) is MALFORMED_JSON. This is
		// checked and thrown BEFORE the shape check below, so a syntax
		// error is never misreported as a shape problem.
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new ApiException("MALFORMED_JSON", 400, "Request body is not valid JSON.");
		}

		// Syntactically valid JSON that is not an object — a bare
		// scalar (`42`, `"x"`, `true`), the literal `null`, or a JSON
		// array (`[1,2,3]`) — is a SHAPE problem, not a syntax one, and
		// is therefore VALIDATION_FAILED, not MALFORMED_JSON (matching
		// dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md §10's own
		// stated distinction: "a client debugging a malformed-JSON
		// failure needs a different fix... than a client debugging a
		// validation failure"). An empty JSON object (`{}`) is NOT
		// rejected here — it correctly falls through to
		// resolveOperation()'s own "operation is required" check next,
		// exactly like any other object missing that field.
		if (!is_array($decoded) || self::isList($decoded)) {
			throw new ApiException("VALIDATION_FAILED", 422, "Request body must be a JSON object.");
		}

		return $decoded;
	}

	/** @return array{user: string} */
	private function authenticate(?string $authorizationHeader): array {
		$credentials = $this->extractBasicCredentials($authorizationHeader);
		if ($credentials === null) {
			throw $this->authenticationFailed();
		}

		[$id, $secret] = $credentials;
		$user = $this->validator->authenticate($id, $secret);
		if ($user === null) {
			throw $this->authenticationFailed();
		}

		return ["user" => $user];
	}

	private function authenticationFailed(): ApiException {
		// Deliberately the SAME code/message/status for every
		// authentication failure reason (missing header, malformed
		// header, unknown credential id, wrong secret, revoked
		// credential) — §7's own existence-non-disclosure requirement,
		// inherited unchanged from AccessKeyValidator::authenticate()'s
		// own collapsed ?string contract.
		return new ApiException("AUTHENTICATION_FAILED", 401, "Authentication failed.");
	}

	/** @return array{0: string, 1: string}|null */
	private function extractBasicCredentials(?string $header): ?array {
		if ($header === null || $header === "") {
			return null;
		}
		if (stripos($header, "Basic ") !== 0) {
			return null;
		}

		$encoded = trim(substr($header, 6));
		$decoded = base64_decode($encoded, true);
		if ($decoded === false) {
			return null;
		}

		$separator = strpos($decoded, ":");
		if ($separator === false) {
			return null;
		}

		$id = substr($decoded, 0, $separator);
		$secret = substr($decoded, $separator + 1);
		if ($id === "") {
			return null;
		}

		return [$id, $secret];
	}

	/** @param array<string, mixed> $body */
	private function resolveOperation(array $body): string {
		$operation = $body["operation"] ?? null;
		if (!is_string($operation) || $operation === "") {
			throw new ApiException("VALIDATION_FAILED", 422, "A non-empty 'operation' field is required.");
		}
		if (!in_array($operation, $this->allowedOperations, true)) {
			// Deliberately generic — never reveals whether "operation"
			// exists as an internal (but non-public) adapter operation
			// (§9: allowlist membership is the only question this
			// answers).
			throw new ApiException("OPERATION_NOT_ALLOWED", 404, "Unknown API operation.");
		}
		return $operation;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function validateEnvelope(array $body): array {
		$unknownKeys = array_diff(array_keys($body), ["operation", "params"]);
		if (!empty($unknownKeys)) {
			throw new ApiException(
				"VALIDATION_FAILED",
				422,
				"Unknown top-level field(s): " . implode(", ", $unknownKeys)
			);
		}

		if (!array_key_exists("params", $body)) {
			throw new ApiException("VALIDATION_FAILED", 422, "A 'params' field is required.");
		}

		$params = $body["params"];
		if (!is_array($params) || self::isList($params)) {
			throw new ApiException("VALIDATION_FAILED", 422, "'params' must be a JSON object.");
		}

		// §10: a null value for a declared params field is treated as
		// "not provided" (absent), not as a distinct value — matching
		// SensitiveParameterTest.php's already-established convention,
		// applied here at the envelope layer so CommandAdapter's own
		// array_key_exists()-based "was a value supplied" check sees an
		// absent key, not a present null.
		foreach ($params as $key => $value) {
			if ($value === null) {
				unset($params[$key]);
			}
		}

		return $params;
	}

	/**
	 * Name-level parameter contract check (Sprint 3,
	 * OperationParameterContract) — rejects any params key not
	 * explicitly declared public for this operation, and any declared
	 * required key that is missing. Runs after envelope validation
	 * (§10) and before normalization, so normalization only ever
	 * operates on an already name-validated params set. Performs no
	 * value/type/shape checking of its own — see
	 * OperationParameterContract's own docblock for why that stays
	 * CommandAdapter's job.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function validateOperationParameters(string $operation, array $params): array {
		if (!OperationParameterContract::isDeclared($operation)) {
			return $params;
		}

		$unknownKeys = array_diff(array_keys($params), OperationParameterContract::allowedParameters($operation));
		if (!empty($unknownKeys)) {
			throw new ApiException(
				"VALIDATION_FAILED",
				422,
				"Unknown parameter(s) for '" . $operation . "': " . implode(", ", $unknownKeys)
			);
		}

		foreach (OperationParameterContract::requiredParameters($operation) as $name) {
			if (!array_key_exists($name, $params)) {
				throw new ApiException(
					"VALIDATION_FAILED",
					422,
					"Missing required parameter '" . $name . "' for '" . $operation . "'."
				);
			}
		}

		return $params;
	}

	/** @param array<int|string, mixed> $value */
	private static function isList(array $value): bool {
		if ($value === []) {
			return false;
		}
		return array_keys($value) === range(0, count($value) - 1);
	}
}
