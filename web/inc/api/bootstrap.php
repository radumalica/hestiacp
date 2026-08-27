<?php

/**
 * Manual require-based bootstrap for the API v2 HTTP layer classes,
 * mirroring web/inc/adapter/bootstrap.php's own established convention
 * (no composer autoload entry added for this vertical slice — see that
 * file's own docblock for why the same choice was made there).
 *
 * Does NOT require web/inc/adapter/bootstrap.php or
 * web/inc/auth/AccessKeyValidator.php itself — this layer depends on
 * both, but each consumer (web/api/v2/index.php, test/api/run_tests.php)
 * already requires those independently, matching how test/auth/run_tests.php
 * requires test/adapter/MiniTest.php explicitly rather than this file
 * assuming a require order for classes it does not own.
 */

require_once __DIR__ . "/ApiException.php";
require_once __DIR__ . "/OperationAllowlist.php";
require_once __DIR__ . "/OperationParameterContract.php";
require_once __DIR__ . "/ParameterNormalizer.php";
require_once __DIR__ . "/ResponseMapper.php";
require_once __DIR__ . "/RateLimitStoreInterface.php";
require_once __DIR__ . "/RateLimitStoreUnavailableException.php";
require_once __DIR__ . "/RateLimitDecision.php";
require_once __DIR__ . "/InMemoryRateLimitStore.php";
require_once __DIR__ . "/FilesystemRateLimitStore.php";
require_once __DIR__ . "/RateLimiter.php";
require_once __DIR__ . "/ExecuteRequestHandler.php";
