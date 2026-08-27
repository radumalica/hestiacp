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
use Hestiacp\Auth\AccessKeyValidator;

$method = $_SERVER["REQUEST_METHOD"] ?? "";
$contentType = $_SERVER["CONTENT_TYPE"] ?? "";
// REDIRECT_HTTP_AUTHORIZATION is the common PHP-FPM/Apache workaround for
// a server that otherwise strips the Authorization header before PHP
// ever sees it under $_SERVER["HTTP_AUTHORIZATION"].
$authorizationHeader = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? null;
$rawBody = file_get_contents("php://input");
if ($rawBody === false) {
	$rawBody = "";
}

// CommandAdapter's own defaults already supply ProcOpenProcessRunner's
// sibling collaborators (LockManager, SameUserAuthorizer) — nothing
// about authorization or locking is decided in this file; see
// CommandAdapter::__construct()'s own default parameters.
$handler = new ExecuteRequestHandler(
	new AccessKeyValidator(),
	new CommandAdapter(new CommandRegistry(), new ProcOpenProcessRunner())
);

[$httpStatus, $envelope] = $handler->handle($method, $contentType, $authorizationHeader, $rawBody);

http_response_code($httpStatus);
header("Content-Type: application/json; charset=utf-8");
echo json_encode($envelope);
