<?php

/**
 * Entry point for the Command Adapter unit test suite.
 *
 * Run with:  php test/adapter/run_tests.php
 *
 * Requires only a PHP CLI binary. Does NOT require a real Hestia
 * installation, sudo access, or the bin/v-* scripts to be present —
 * every test in CommandAdapterTest uses FakeProcessRunner instead of
 * spawning a real process. Exits 0 on success, 1 on any failure, so it
 * is usable as-is from CI.
 */

require_once __DIR__ . "/../../web/inc/adapter/bootstrap.php";
require_once __DIR__ . "/MiniTest.php";
require_once __DIR__ . "/FakeProcessRunner.php";
require_once __DIR__ . "/ThrowingProcessRunner.php";
require_once __DIR__ . "/SpyLockManager.php";
require_once __DIR__ . "/CommandAdapterTest.php";
require_once __DIR__ . "/ProcOpenProcessRunnerTest.php";
require_once __DIR__ . "/DomainListTest.php";
require_once __DIR__ . "/LockManagerTest.php";
require_once __DIR__ . "/MutatingOperationTest.php";
require_once __DIR__ . "/DomainCreateTest.php";
require_once __DIR__ . "/DomainDeleteTest.php";

use Hestiacp\Adapter\Test\CommandAdapterTest;
use Hestiacp\Adapter\Test\DomainCreateTest;
use Hestiacp\Adapter\Test\DomainDeleteTest;
use Hestiacp\Adapter\Test\DomainListTest;
use Hestiacp\Adapter\Test\LockManagerTest;
use Hestiacp\Adapter\Test\MiniTest;
use Hestiacp\Adapter\Test\MutatingOperationTest;
use Hestiacp\Adapter\Test\ProcOpenProcessRunnerTest;

$t = new MiniTest();
CommandAdapterTest::register($t);
ProcOpenProcessRunnerTest::register($t);
DomainListTest::register($t);
MutatingOperationTest::register($t);
LockManagerTest::register($t);
DomainCreateTest::register($t);
DomainDeleteTest::register($t);
exit($t->run());
