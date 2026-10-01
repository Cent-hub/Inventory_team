<?php
/**
 * Main Test Runner Entrypoint
 * Command: php tests/run_tests.php
 *
 * Runs the isolated automated testing suite for InventoryTeam ERP.
 * Strictly verifies existing system behaviors against business rules
 * without altering production data or existing application code.
 */

require_once __DIR__ . '/TestRunner.php';

// Test Cases
require_once __DIR__ . '/auth/AuthenticationTest.php';
require_once __DIR__ . '/auth/AuthorizationTest.php';
require_once __DIR__ . '/inventory/ProcurementTest.php';
require_once __DIR__ . '/inventory/StockOutTest.php';
require_once __DIR__ . '/production/ProductionTest.php';
require_once __DIR__ . '/inventory/FinishedGoodsTest.php';
require_once __DIR__ . '/sales/SalesTest.php';
require_once __DIR__ . '/transfer/StockTransferTest.php';
require_once __DIR__ . '/adjustment/StockAdjustmentTest.php';
require_once __DIR__ . '/warehouse/WarehouseAccessTest.php';
require_once __DIR__ . '/accountability/AccountabilityLogTest.php';
require_once __DIR__ . '/reports/ReportsTest.php';

$runner = new TestRunner();
$pdo = $runner->getPdo();

// Register the 12 core ERP test suites in workflow order
$runner->registerTest(new AuthenticationTest($pdo));
$runner->registerTest(new AuthorizationTest($pdo));
$runner->registerTest(new ProcurementTest($pdo));
$runner->registerTest(new StockOutTest($pdo));
$runner->registerTest(new ProductionTest($pdo));
$runner->registerTest(new FinishedGoodsTest($pdo));
$runner->registerTest(new SalesTest($pdo));
$runner->registerTest(new StockTransferTest($pdo));
$runner->registerTest(new StockAdjustmentTest($pdo));
$runner->registerTest(new WarehouseAccessTest($pdo));
$runner->registerTest(new AccountabilityLogTest($pdo));
$runner->registerTest(new ReportsTest($pdo));

$allPassed = $runner->runAll();

exit($allPassed ? 0 : 1);
