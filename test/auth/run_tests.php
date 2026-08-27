<?php

/**
 * Entry point for the web/inc/auth/ unit test suite (AccessKeyValidator,
 * AccessKeyProvisioner, AccessKeyCli).
 *
 * Run with:  php test/auth/run_tests.php
 *
 * Requires only a PHP CLI binary. No real Hestia installation, no HTTP
 * server, no session — every test uses a fresh temp directory as the
 * credential store. Exits 0 on success, 1 on any failure.
 *
 * Reuses test/adapter/MiniTest.php's runner and assert*() functions
 * rather than duplicating that generic, adapter-independent test
 * infrastructure.
 */

require_once __DIR__ . "/../adapter/MiniTest.php";
require_once __DIR__ . "/../../web/inc/auth/AccessKeyValidator.php";
require_once __DIR__ . "/../../web/inc/auth/CredentialProvisioningException.php";
require_once __DIR__ . "/../../web/inc/auth/CredentialCreationResult.php";
require_once __DIR__ . "/../../web/inc/auth/AccessKeyProvisioner.php";
require_once __DIR__ . "/../../web/inc/auth/CliOutcome.php";
require_once __DIR__ . "/../../web/inc/auth/AccessKeyCli.php";
require_once __DIR__ . "/AccessKeyValidatorTest.php";
require_once __DIR__ . "/AccessKeyProvisionerTest.php";
require_once __DIR__ . "/AccessKeyCliTest.php";

use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Auth\Test\AccessKeyCliTest;
use Hestiacp\Auth\Test\AccessKeyProvisionerTest;
use Hestiacp\Auth\Test\AccessKeyValidatorTest;

$t = new MiniTest();
AccessKeyValidatorTest::register($t);
AccessKeyProvisionerTest::register($t);
AccessKeyCliTest::register($t);
exit($t->run());
