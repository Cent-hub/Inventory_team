<?php
/**
 * Test Suite: Inventory Reports Unified Page Verification
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

// Test 1: Default Tab (Raw Materials)
testCase("Default active tab is raw_materials", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    @session_start();
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-raw_materials" class="stock-op-pane" style="display: block;"') !== false, "Raw materials pane should be visible");
    assert(strpos($html, 'id="pane-finished_goods" class="stock-op-pane" style="display: none;"') !== false, "Finished goods pane should be hidden");
    assert(strpos($html, 'id="pane-current_balance" class="stock-op-pane" style="display: none;"') !== false, "Current balance pane should be hidden");
    assert(strpos($html, 'id="tab-btn-raw_materials" class="stock-tab-btn active"') !== false, "Raw materials tab button should be active");
});

// Test 2: Finished Goods Tab via GET ?type=finished_goods
testCase("Finished goods tab active via GET type=finished_goods", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['type' => 'finished_goods'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-raw_materials" class="stock-op-pane" style="display: none;"') !== false, "Raw materials pane should be hidden");
    assert(strpos($html, 'id="pane-finished_goods" class="stock-op-pane" style="display: block;"') !== false, "Finished goods pane should be visible");
    assert(strpos($html, 'id="pane-current_balance" class="stock-op-pane" style="display: none;"') !== false, "Current balance pane should be hidden");
    assert(strpos($html, 'id="tab-btn-finished_goods" class="stock-tab-btn active"') !== false, "Finished goods tab button should be active");
});

// Test 3: Current Balance Tab via GET ?type=current_stock
testCase("Current balance tab active via GET type=current_stock", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['type' => 'current_stock'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-raw_materials" class="stock-op-pane" style="display: none;"') !== false, "Raw materials pane should be hidden");
    assert(strpos($html, 'id="pane-finished_goods" class="stock-op-pane" style="display: none;"') !== false, "Finished goods pane should be hidden");
    assert(strpos($html, 'id="pane-current_balance" class="stock-op-pane" style="display: block;"') !== false, "Current balance pane should be visible");
    assert(strpos($html, 'id="tab-btn-current_balance" class="stock-tab-btn active"') !== false, "Current balance tab button should be active");
});

// Test 4: All 3 Tables & Elements Preserved
testCase("All 3 report tables, filters, and export/print controls are preserved", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="reportTable_raw_materials"') !== false, "reportTable_raw_materials must exist");
    assert(strpos($html, 'id="reportTable_finished_goods"') !== false, "reportTable_finished_goods must exist");
    assert(strpos($html, 'id="reportTable_current_balance"') !== false, "reportTable_current_balance must exist");
    assert(strpos($html, 'id="reportSearchInput"') !== false, "reportSearchInput filter must exist");
    assert(strpos($html, 'onclick="window.print()"') !== false, "Print button must exist");
    assert(strpos($html, 'onclick="exportReportCsv()"') !== false, "Export CSV button must exist");
    assert(strpos($html, 'function switchReportTab(') !== false, "switchReportTab JS must exist");
    assert(strpos($html, 'function filterActiveReportTable()') !== false, "filterActiveReportTable JS must exist");
});

// Test 5: Sidebar Navigation
testCase("Sidebar navigation includes unified Inventory Reports link", function() {
    $activePage = 'reports';
    $activeGroup = 'reports';
    $currentReportType = '';
    $isReportsActive = true;
    $currentUser = ['role' => 'admin', 'warehouse_id' => 1];
    
    ob_start();
    require __DIR__ . '/../views/layouts/sidebar.php';
    $sidebarHtml = ob_get_clean();

    assert(strpos($sidebarHtml, 'views/reports/index.php') !== false, "Sidebar should link to views/reports/index.php");
    assert(strpos($sidebarHtml, '<span>Inventory Reports</span>') !== false, "Sidebar text should be Inventory Reports");
});

// Test 6: Transaction Reports Backward Compatibility
testCase("Transaction reports (stock_movements, stock_ins, etc.) remain fully functional", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['type' => 'stock_movements'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/reports/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/reports/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'Stock Movement Ledger Report') !== false, "Stock movement ledger report title should render");
    assert(strpos($html, 'name="start_date"') !== false, "Date range filter should render for transaction reports");
    assert(strpos($html, 'id="reportTable"') !== false, "reportTable should render for transaction reports");
});

echo "All Inventory Reports test cases ran successfully.\n";
