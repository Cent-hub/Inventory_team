<?php
/**
 * Test Suite: Stock Transaction Reports Unified Page Verification
 */

require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

function testCase(string $name, callable $fn) {
    try {
        $fn();
        echo "[PASS] $name\n";
    } catch (Throwable $e) {
        echo "[FAIL] $name: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

// Test 1: Default Tab (Stock Movement)
testCase("Default active tab is movements (Stock Movement)", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/stock_transactions.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    @session_start();
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/stock_transactions.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-movements" class="stock-op-pane" style="display: block;"') !== false, "Movements pane should be visible");
    assert(strpos($html, 'id="pane-inbound" class="stock-op-pane" style="display: none;"') !== false, "Inbound pane should be hidden");
    assert(strpos($html, 'id="pane-outbound" class="stock-op-pane" style="display: none;"') !== false, "Outbound pane should be hidden");
    assert(strpos($html, 'id="pane-transfers" class="stock-op-pane" style="display: none;"') !== false, "Transfers pane should be hidden");
    assert(strpos($html, 'id="pane-adjustments" class="stock-op-pane" style="display: none;"') !== false, "Adjustments pane should be hidden");
    assert(strpos($html, 'id="tab-btn-movements" class="stock-tab-btn active"') !== false, "Movements tab button should be active");
});

// Test 2: Inbound Tab via GET ?tab=inbound
testCase("Inbound tab active via GET tab=inbound", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'inbound'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/stock_transactions.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/stock_transactions.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-movements" class="stock-op-pane" style="display: none;"') !== false, "Movements pane should be hidden");
    assert(strpos($html, 'id="pane-inbound" class="stock-op-pane" style="display: block;"') !== false, "Inbound pane should be visible");
    assert(strpos($html, 'id="tab-btn-inbound" class="stock-tab-btn active"') !== false, "Inbound tab button should be active");
});

// Test 3: Outbound Tab via GET ?tab=outbound
testCase("Outbound tab active via GET tab=outbound", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'outbound'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/stock_transactions.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/stock_transactions.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-outbound" class="stock-op-pane" style="display: block;"') !== false, "Outbound pane should be visible");
    assert(strpos($html, 'id="tab-btn-outbound" class="stock-tab-btn active"') !== false, "Outbound tab button should be active");
});

// Test 4: Transfers Tab via GET ?tab=transfers
testCase("Transfers tab active via GET tab=transfers", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'transfers'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/stock_transactions.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/stock_transactions.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-transfers" class="stock-op-pane" style="display: block;"') !== false, "Transfers pane should be visible");
    assert(strpos($html, 'id="tab-btn-transfers" class="stock-tab-btn active"') !== false, "Transfers tab button should be active");
});

// Test 5: Adjustments Tab via GET ?tab=adjustments
testCase("Adjustments tab active via GET tab=adjustments", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'adjustments'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/stock_transactions.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/stock_transactions.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-adjustments" class="stock-op-pane" style="display: block;"') !== false, "Adjustments pane should be visible");
    assert(strpos($html, 'id="tab-btn-adjustments" class="stock-tab-btn active"') !== false, "Adjustments tab button should be active");
});

// Test 6: All 5 Tables, Date Range, Search & JS Functions Preserved
testCase("All 5 tables, date range filters, search, and JS controls are preserved", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/stock_transactions.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/stock_transactions.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="table-movements"') !== false, "table-movements must exist");
    assert(strpos($html, 'id="table-inbound"') !== false, "table-inbound must exist");
    assert(strpos($html, 'id="table-outbound"') !== false, "table-outbound must exist");
    assert(strpos($html, 'id="table-transfers"') !== false, "table-transfers must exist");
    assert(strpos($html, 'id="table-adjustments"') !== false, "table-adjustments must exist");

    assert(strpos($html, 'name="start_date"') !== false, "start_date filter must exist");
    assert(strpos($html, 'name="end_date"') !== false, "end_date filter must exist");
    assert(strpos($html, 'id="reportSearchInput"') !== false, "reportSearchInput filter must exist");

    assert(strpos($html, 'function switchTransactionTab(') !== false, "switchTransactionTab JS function must exist");
    assert(strpos($html, 'function filterActiveTransactionTable(') !== false, "filterActiveTransactionTable JS function must exist");
    assert(strpos($html, 'function exportReportCsv(') !== false, "exportReportCsv JS function must exist");
});

// Test 7: Sidebar Navigation for Stock Transaction Reports
testCase("Sidebar navigation includes unified Stock Transaction Reports link", function() {
    $activePage = 'stock_transactions';
    $activeGroup = 'reports';
    $currentReportType = '';
    $isReportsActive = true;
    $currentUser = ['role' => 'admin', 'warehouse_id' => 1];
    
    ob_start();
    require __DIR__ . '/../views/layouts/sidebar.php';
    $sidebarHtml = ob_get_clean();

    assert(strpos($sidebarHtml, 'views/reports/stock_transactions.php') !== false, "Sidebar should link to views/reports/stock_transactions.php");
    assert(strpos($sidebarHtml, '<span>Stock Transaction Reports</span>') !== false, "Sidebar text should be Stock Transaction Reports");
});

// Test 8: Forwarding Logic in views/reports/index.php
testCase("Legacy transaction report requests are forwarded from index.php", function() {
    $indexCode = file_get_contents(__DIR__ . '/../views/reports/index.php');
    assert(strpos($indexCode, 'views/reports/stock_transactions.php') !== false, "index.php should forward transaction reports to stock_transactions.php");
});

echo "All Stock Transaction Reports test cases ran successfully.\n";
