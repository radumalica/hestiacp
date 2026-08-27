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
 * Sprint 5 (see dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md)
 * inserts exactly two additional, HTTP-layer-only checks that never
 * touch CommandAdapter/LockManager/AuthorizerInterface: a pre-auth
 * per-IP check as the very first thing handle() does (before even
 * method/content-type/body-size checks, and before authenticate()'s own
 * expensive work), and an authenticated per-credential check
 * immediately after authenticate() succeeds, before operation
 * resolution.
 *
 * Sprint 6 (see dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md)
 * adds exactly ONE additional observation point: after the try/catch
 * below has already produced the final [$httpStatus, $envelope] result
 * — success or failure, from any exit path — handle() builds one
 * AuditEvent from that already-computed result plus the HTTP-layer
 * context gathered along the way (request id, attempted/authenticated
 * credential id, client IP, redacted target) and hands it to the
 * injected AuditLogger. This is purely an observer: it never influences
 * $httpStatus/$envelope, never runs before a security check has already
 * completed, and an audit-write failure (fail-open, see the doc's §13)
 * can never change the response that is returned.
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
	private RateLimiter $rateLimiter;
	private AuditLogger $auditLogger;

	/** @var callable(): float */
	private $clock;

	/** @var callable(): string */
	private $requestIdGenerator;

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
	 * @param RateLimiter|null $rateLimiter Sprint 5. Defaults to a real,
	 *        working RateLimiter backed by InMemoryRateLimitStore — safe
	 *        for the ~100+ pre-existing tests that never mention rate
	 *        limiting at all (each constructs its own
	 *        ExecuteRequestHandler instance, so each gets its own
	 *        isolated in-process counters; no cross-test interference is
	 *        possible). Production (web/api/v2/index.php) ALWAYS passes
	 *        an explicit RateLimiter backed by FilesystemRateLimitStore
	 *        instead — an in-memory store never persists across the
	 *        separate PHP processes a real PHP-FPM/CGI deployment uses
	 *        per request, so relying on this default in production would
	 *        silently rate-limit nothing. See
	 *        dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §9.
	 * @param AuditLogger|null $auditLogger Sprint 6. Defaults to a real
	 *        FileAuditLogger pointed at its own real, production,
	 *        installer-provisioned path — deliberately NOT a permissive
	 *        no-op default (see this class's own docblock and
	 *        AuditLogger's own). Safe for every pre-Sprint-6 test:
	 *        that directory does not exist in a test environment, every
	 *        write fails, and the fail-open policy below simply means no
	 *        event is recorded — it never raises, never affects the
	 *        response. Production (web/api/v2/index.php) relies on this
	 *        very default rather than constructing its own, since the
	 *        production path IS this class's own default.
	 * @param callable(): float|null $clock Test-only injected clock,
	 *        mirroring CommandAdapter's own convention — used only to
	 *        compute AuditEvent's timestamp/duration_ms deterministically
	 *        in tests.
	 * @param callable(): string|null $requestIdGenerator Test-only
	 *        injected id generator, mirroring CommandAdapter's own
	 *        $idGenerator convention.
	 */
	public function __construct(
		AccessKeyValidator $validator,
		CommandAdapter $adapter,
		?array $allowedOperations = null,
		?RateLimiter $rateLimiter = null,
		?AuditLogger $auditLogger = null,
		?callable $clock = null,
		?callable $requestIdGenerator = null
	) {
		$this->validator = $validator;
		$this->adapter = $adapter;
		$this->allowedOperations = $allowedOperations ?? OperationAllowlist::ALLOWED_OPERATIONS;
		$this->rateLimiter = $rateLimiter ?? new RateLimiter(new InMemoryRateLimitStore());
		$this->auditLogger = $auditLogger ?? new FileAuditLogger();
		$this->clock = $clock ?? static function (): float {
			return microtime(true);
		};
		$this->requestIdGenerator = $requestIdGenerator ?? static function (): string {
			return bin2hex(random_bytes(16));
		};
	}

	/**
	 * @param string $clientIp Sprint 5: the client's network address as
	 *        seen at the HTTP boundary (REMOTE_ADDR) — used ONLY as the
	 *        pre-authentication rate-limit bucket key, never trusted for
	 *        anything security-relevant beyond that. Deliberately a
	 *        plain scalar the caller supplies (matching every other
	 *        handle() parameter), not read from $_SERVER here — this
	 *        class remains fully HTTP-transport-independent. Defaults to
	 *        "" so every pre-Sprint-5 test call site keeps working
	 *        unchanged; production (web/api/v2/index.php) always passes
	 *        the real $_SERVER["REMOTE_ADDR"].
	 * @return array{0: int, 1: array<string, mixed>} [$httpStatus, $envelope]
	 */
	public function handle(string $method, string $contentType, ?string $authorizationHeader, string $rawBody, string $clientIp = ""): array {
		$requestId = ($this->requestIdGenerator)();
		$startedAt = ($this->clock)();

		$operation = null;
		$attemptedOperation = null;
		$attemptedCredentialId = null;
		$credentialId = null;
		$actorUser = null;
		$target = null;

		try {
			// Pre-authentication rate limiting runs before ANY other
			// work — including method/content-type/body-size checks —
			// so a flood of requests against this endpoint is bounded
			// regardless of how malformed they are, and strictly before
			// authenticate()'s own expensive password_verify() work
			// (§4/§5 of the rate-limiting doc). The key is REMOTE_ADDR
			// only: it does NOT depend on whether credentials later turn
			// out to be valid, so an unknown credential id and a valid
			// one sharing the same IP always share the same bucket —
			// this layer can never reveal credential existence.
			$this->enforcePreAuthRateLimit($clientIp);

			$this->assertMethod($method);
			$this->assertContentType($contentType);
			$this->assertBodySize($rawBody);
			$body = $this->decodeJson($rawBody);

			if (is_string($body["operation"] ?? null)) {
				// Sprint 6: captured purely for audit purposes — the
				// raw, caller-supplied operation string, even when it
				// turns out to be unknown/not-allowlisted below. Never
				// used for anything else: resolveOperation() below still
				// performs its own, unrelated allowlist check and is the
				// only thing that ever sets $operation itself.
				$attemptedOperation = $body["operation"];
			}

			$credentials = $this->extractBasicCredentials($authorizationHeader);
			$attemptedCredentialId = $credentials[0] ?? null;

			// Authentication strictly before operation resolution — an
			// unauthenticated request never reaches CommandAdapter, and
			// never even learns whether its requested operation exists
			// (§12's own enforced invariant).
			[$credentialId, $actor] = $this->authenticateWithCredentials($credentials);
			$actorUser = $actor["user"];

			// Authenticated rate limiting: only reached once
			// authenticate() has already succeeded, keyed by the
			// AUTHENTICATED credential id (never the raw secret) — a
			// separate bucket per credential, so one authenticated
			// caller can never exhaust another's allowance.
			$this->enforceAuthenticatedRateLimit($credentialId);

			$operation = $this->resolveOperation($body);
			$params = $this->validateEnvelope($body);
			$params = $this->validateOperationParameters($operation, $params);
			$params = ParameterNormalizer::normalize($operation, $params);

			// Sprint 6: the audit target is redacted from exactly the
			// params CommandAdapter is about to receive — never from
			// anything earlier/unvalidated. Set here, before invoke(),
			// so it is still available for the audit event below no
			// matter how invoke() itself concludes (success,
			// authorization denial, Hestia failure, or an unexpected
			// exception).
			$target = AuditTargetRedactor::redact($operation, $params);

			$adapterResult = $this->adapter->invoke($operation, $params, $actor);

			$result = ResponseMapper::fromAdapterResult($adapterResult);
		} catch (ApiException $exception) {
			$result = ResponseMapper::fromApiException($operation ?? "", $exception);
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
			$result = ResponseMapper::fromApiException(
				$operation ?? "",
				new ApiException("INTERNAL_ERROR", 500, "An internal error occurred.")
			);
		}

		$this->recordAudit(
			$requestId,
			$startedAt,
			$attemptedCredentialId,
			$credentialId,
			$actorUser,
			$clientIp,
			$operation ?? $attemptedOperation,
			$target,
			$result
		);

		return $result;
	}

	/**
	 * @param array{0: int, 1: array<string, mixed>} $result
	 */
	private function recordAudit(
		string $requestId,
		float $startedAt,
		?string $attemptedCredentialId,
		?string $credentialId,
		?string $actorUser,
		string $clientIp,
		?string $operation,
		?array $target,
		array $result
	): void {
		[$httpStatus, $envelope] = $result;
		$durationMs = (int) round((($this->clock)() - $startedAt) * 1000);

		$event = new AuditEvent(
			gmdate("c"),
			AuditEvent::eventTypeFor($envelope["success"], $envelope["error"]["code"] ?? null),
			$requestId,
			$attemptedCredentialId,
			$credentialId,
			$actorUser,
			$clientIp !== "" ? $clientIp : null,
			$operation,
			$target,
			$httpStatus,
			$envelope["outcome"],
			$envelope["success"],
			$envelope["error"]["code"] ?? null,
			$envelope["error"]["details"]["hestia_error_code"] ?? null,
			$durationMs
		);

		// Fail-open (see dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md
		// §13): an audit-write failure must never change, delay, or
		// retry the already-computed API response — $result above was
		// already fully built before this method was ever called, and
		// nothing below can influence it.
		try {
			$this->auditLogger->write($event);
		} catch (AuditWriteException $exception) {
			// Deliberately swallowed — see the doc's §13 for why no
			// safe, sufficiently low-noise detection mechanism was
			// implemented this sprint (this is called on literally
			// every request, including this project's own several
			// hundred pre-Sprint-6 tests, which never provision the
			// production audit directory).
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

	/**
	 * @param array{0: string, 1: string}|null $credentials Already
	 *        extracted by extractBasicCredentials() — Sprint 6 needs the
	 *        attempted credential id (credentials[0]) for the audit
	 *        event regardless of whether authentication then succeeds,
	 *        so extraction now happens once in handle() rather than
	 *        being re-derived here.
	 * @return array{0: string, 1: array{user: string}} [$credentialId, $actor]
	 */
	private function authenticateWithCredentials(?array $credentials): array {
		if ($credentials === null) {
			throw $this->authenticationFailed();
		}

		[$id, $secret] = $credentials;
		$user = $this->validator->authenticate($id, $secret);
		if ($user === null) {
			throw $this->authenticationFailed();
		}

		// $id is returned solely as the Sprint 5 authenticated
		// rate-limit bucket key (and, since Sprint 6, the audit event's
		// credential_id) — it is never merged into $actor, which remains
		// exactly {user: ...}, unchanged from Sprints 1-4.
		return [$id, ["user" => $user]];
	}

	/**
	 * Pre-authentication bucket: keyed by client IP only. Fails CLOSED —
	 * see dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §11 for
	 * the full rationale (a remote, unauthenticated caller has no way to
	 * make the counter store itself unavailable, since bucket keys are
	 * always hashed into a fixed-length filename before touching the
	 * filesystem — a genuine storage failure here is an environmental
	 * fault, not something a request can trigger, so denying is safe).
	 */
	private function enforcePreAuthRateLimit(string $clientIp): void {
		try {
			$decision = $this->rateLimiter->checkPreAuth($clientIp);
		} catch (RateLimitStoreUnavailableException $exception) {
			throw $this->rateLimited(null);
		}

		if (!$decision->allowed) {
			throw $this->rateLimited($decision->retryAfterSeconds);
		}
	}

	/**
	 * Authenticated bucket: keyed by the authenticated credential id.
	 * Fails OPEN — an already-authenticated caller who has already paid
	 * the cost of a valid credential is not denied service merely
	 * because a counter file could not be read/written; see the doc's
	 * §11 for the full rationale (the pre-authentication layer above
	 * already provides the fail-closed line of defense against
	 * unauthenticated volumetric abuse).
	 */
	private function enforceAuthenticatedRateLimit(string $credentialId): void {
		try {
			$decision = $this->rateLimiter->checkAuthenticated($credentialId);
		} catch (RateLimitStoreUnavailableException $exception) {
			return;
		}

		if (!$decision->allowed) {
			throw $this->rateLimited($decision->retryAfterSeconds);
		}
	}

	private function rateLimited(?int $retryAfterSeconds): ApiException {
		// $retryAfterSeconds is deterministic (derived only from the
		// fixed window boundary, never from any counter value) and safe
		// to disclose — it reveals nothing about credential existence or
		// internal counters, only "try again in N seconds." Omitted
		// entirely (null details) for the fail-closed storage-failure
		// case, where no window boundary was ever computed.
		$details = $retryAfterSeconds !== null ? ["retry_after_seconds" => $retryAfterSeconds] : null;

		return new ApiException("RATE_LIMITED", 429, "Too many requests. Please try again later.", $details);
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
