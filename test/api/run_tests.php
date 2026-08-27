<?php

/**
 * Entry point for the API v2 HTTP layer unit test suite
 * (web/inc/api/*.php), per Sprint 2 —
 * dev-docs/api-v2/API_V2_HTTP_ENTRY_POINT_IMPLEMENTATION.md.
 *
 * Run with:  php test/api/run_tests.php
 *
 * Requires only a PHP CLI binary. No real Hestia installation, no real
 * HTTP server, no sudo access, no bin/v-* scripts: AccessKeyValidator
 * uses a fresh temp credential directory per test, CommandAdapter uses
 * FakeProcessRunner (or ThrowingProcessRunner where a failure needs to
 * be simulated), exactly like every existing test/adapter/*Test.php and
 * test/auth/*Test.php file. Exits 0 on success, 1 on any failure.
 */

require_once __DIR__ . "/../../web/inc/adapter/bootstrap.php";
require_once __DIR__ . "/../../web/inc/auth/AccessKeyValidator.php";
require_once __DIR__ . "/../../web/inc/api/bootstrap.php";
require_once __DIR__ . "/../adapter/MiniTest.php";
require_once __DIR__ . "/../adapter/FakeProcessRunner.php";
require_once __DIR__ . "/../adapter/ThrowingProcessRunner.php";
require_once __DIR__ . "/../adapter/SpyLockManager.php";
require_once __DIR__ . "/../adapter/SpyAuthorizer.php";
require_once __DIR__ . "/OperationParameterContractTest.php";
require_once __DIR__ . "/ParameterNormalizerTest.php";
require_once __DIR__ . "/ResponseMapperTest.php";
require_once __DIR__ . "/ExecuteRequestHandlerTest.php";
require_once __DIR__ . "/RateLimiterTest.php";
require_once __DIR__ . "/GenericityTest.php";

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Api\Test\ExecuteRequestHandlerTest;
use Hestiacp\Api\Test\GenericityTest;
use Hestiacp\Api\Test\OperationParameterContractTest;
use Hestiacp\Api\Test\ParameterNormalizerTest;
use Hestiacp\Api\Test\RateLimiterTest;
use Hestiacp\Api\Test\ResponseMapperTest;

$t = new MiniTest();
OperationParameterContractTest::register($t);
ParameterNormalizerTest::register($t);
ResponseMapperTest::register($t);
ExecuteRequestHandlerTest::register($t);
RateLimiterTest::register($t);
GenericityTest::register($t);
exit($t->run());
