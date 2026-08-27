<?php

/**
 * POST /api/v2/execute — API v2 HTTP entry point.
 *
 * Implements exactly the contract defined in
 * dev-docs/api-v2/API_V2_HTTP_CONTRACT_DESIGN.md, per
 * dev-docs/api-v2/API_V2_HTTP_ENTRY_POINT_IMPLEMENTATION.md. This file
 * itself is a thin transport shim: it reads the raw HTTP
 * method/Content-Type/Authorization header/request body, hands them to
 * Hestiacp\Api\ExecuteRequestHandler::handle() — the actual, fully
 * unit-tested request pipeline, see test/api/ — and writes back whatever
 * [$httpStatus, $envelope] that call returns. It contains no
 * authentication, validation, normalization, or command-execution logic
 * of its own, and is not itself part of the unit test suite for the same
 * reason web/api/index.php never has been: it does nothing but wire
 * superglobals to a fully-tested class and echo the result.
 *
 * KNOWN LIMITATION (see the implementation doc's own "Known Limitations"
 * section for the full accounting): this file is served at whatever URL
 * the panel's webserver configuration maps to
 * web/api/v2/index.php — matching web/api/index.php's own existing,
 * unmodified convention (no .htaccess/nginx rewrite exists for that
 * legacy endpoint either). Making the literal path "/api/v2/execute"
 * (rather than "/api/v2/" or "/api/v2/index.php") resolve to this file
 * is a webserver-configuration concern, out of this sprint's scope — no
 * nginx/Apache template was found or modified to produce it.
 *
 * Sprint 4 hardening: the entire body below is wrapped in its own
 * try/catch, as defense-in-depth ABOVE ExecuteRequestHandler::handle()'s
 * own internal catch(\Throwable) — this catches anything that could
 * theoretically throw before handle() is even reached (object
 * construction) or after it returns (response emission), so that even
 * such a failure still produces the same sanitized JSON error envelope
 * this endpoint always returns, never a raw PHP error page. This script
 * still has no test coverage of its own — matching web/api/index.php's
 * and this file's own pre-Sprint-4 convention — because every branch of
 * substantive logic (parsing, auth, validation, mapping) already lives
 * in the fully-tested ExecuteRequestHandler/ResponseMapper; what remains
 * here is transport wiring only.
 */

// Suppress PHP warning/notice HTML output from reaching the response
// body — this endpoint always returns exactly one JSON document, never
// a mix of an inline warning and JSON
// (API_V2_HTTP_CONTRACT_DESIGN.md §19: "no PHP warnings" in a response).
// Local to this single script; no shared php.ini or other entry point is
// affected.
ini_set("display_errors", "0");

require_once __DIR__ . "/../../inc/adapter/bootstrap.php";
require_once __DIR__ . "/../../inc/auth/AccessKeyValidator.php";
require_once __DIR__ . "/../../inc/api/bootstrap.php";

use Hestiacp\Adapter\CommandAdapter;
use Hestiacp\Adapter\CommandRegistry;
use Hestiacp\Adapter\ProcOpenProcessRunner;
use Hestiacp\Api\ExecuteRequestHandler;
use Hestiacp\Api\FilesystemRateLimitStore;
use Hestiacp\Api\RateLimiter;
use Hestiacp\Auth\AccessKeyValidator;

try {
	$method = $_SERVER["REQUEST_METHOD"] ?? "";
	$contentType = $_SERVER["CONTENT_TYPE"] ?? "";
	// REDIRECT_HTTP_AUTHORIZATION is the common PHP-FPM/Apache workaround
	// for a server that otherwise strips the Authorization header before
	// PHP ever sees it under $_SERVER["HTTP_AUTHORIZATION"].
	$authorizationHeader = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? null;
	// Sprint 5: the client's raw network address, used ONLY as the
	// pre-authentication rate-limit bucket key
	// (dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §4/§6).
	// Deliberately REMOTE_ADDR alone — no X-Forwarded-For or similar
	// header is read anywhere in this file, since no trusted-proxy
	// mechanism exists in this repository to validate such a header
	// against (see that doc's §2/§6 for the full reasoning).
	$clientIp = $_SERVER["REMOTE_ADDR"] ?? "";
	$rawBody = file_get_contents("php://input");
	if ($rawBody === false) {
		$rawBody = "";
	}

	// CommandAdapter's own defaults already supply ProcOpenProcessRunner's
	// sibling collaborators (LockManager, SameUserAuthorizer) — nothing
	// about authorization or locking is decided in this file; see
	// CommandAdapter::__construct()'s own default parameters.
	//
	// The RateLimiter is explicitly constructed here, backed by
	// FilesystemRateLimitStore at its own default (system-temp-backed)
	// directory, rather than relying on ExecuteRequestHandler's own
	// convenience default (InMemoryRateLimitStore) — an in-memory store
	// never persists across the separate PHP processes a real
	// PHP-FPM/CGI deployment uses per request, so only an explicit,
	// filesystem-backed store actually rate-limits anything here. See
	// dev-docs/api-v2/API_V2_RATE_LIMITING_IMPLEMENTATION.md §9.
	$handler = new ExecuteRequestHandler(
		new AccessKeyValidator(),
		new CommandAdapter(new CommandRegistry(), new ProcOpenProcessRunner()),
		null,
		new RateLimiter(new FilesystemRateLimitStore())
	);

	[$httpStatus, $envelope] = $handler->handle($method, $contentType, $authorizationHeader, $rawBody, $clientIp);
	$body = json_encode($envelope);

	// json_encode() itself can fail (e.g. non-UTF-8 bytes reaching
	// parsedOutput from an unexpected upstream source) — never let a
	// `false` body silently become an invalid/empty response with an
	// already-committed success status; fall back to the same sanitized
	// internal-error shape the catch block below produces.
	if ($body === false) {
		$httpStatus = 500;
		$body = json_encode([
			"api_version" => "v2",
			"success" => false,
			"outcome" => "failed",
			"data" => null,
			"error" => ["code" => "INTERNAL_ERROR", "message" => "An internal error occurred.", "details" => null],
			"meta" => ["operation" => "", "command_id" => null],
		]);
	}
} catch (\Throwable $exception) {
	// Defense-in-depth only — see this file's own docblock. No
	// $exception->getMessage()/getTrace() is ever included, for the
	// same reason ExecuteRequestHandler's own internal catch(\Throwable)
	// never includes them.
	$httpStatus = 500;
	$body = json_encode([
		"api_version" => "v2",
		"success" => false,
		"outcome" => "failed",
		"data" => null,
		"error" => ["code" => "INTERNAL_ERROR", "message" => "An internal error occurred.", "details" => null],
		"meta" => ["operation" => "", "command_id" => null],
	]);
}

http_response_code($httpStatus);
header("Content-Type: application/json; charset=utf-8");
echo $body;
