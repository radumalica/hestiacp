<?php

/**
 * Manual require-based bootstrap for the adapter classes.
 *
 * No composer autoload entry is added for this vertical slice — the
 * existing web/inc/composer.json is not modified (out of scope per this
 * task's instructions), and adding a PSR-4 mapping is a one-line,
 * low-risk follow-up whenever the adapter actually gets wired into
 * web/inc/main.php or web/api/index.php. Until then, any consumer
 * (including the test suite) requires this one file.
 */

require_once __DIR__ . "/AdapterResult.php";
require_once __DIR__ . "/ParameterValidator.php";
require_once __DIR__ . "/ProcessResult.php";
require_once __DIR__ . "/ProcessRunnerInterface.php";
require_once __DIR__ . "/ProcOpenProcessRunner.php";
require_once __DIR__ . "/LockUnavailableException.php";
require_once __DIR__ . "/LockManagerInterface.php";
require_once __DIR__ . "/LockManager.php";
require_once __DIR__ . "/CommandRegistry.php";
require_once __DIR__ . "/CommandAdapter.php";
