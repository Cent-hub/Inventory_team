<?php
/**
 * Test Suite: Inventory Inbound & Outbound Unified Page Verification
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

// Test 1: Default Tab (Inbound)
testCase("Default active tab is inbound", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/inbound_outbound/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    @session_start();
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/inbound_outbound/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-inbound" class="stock-op-pane" style="display: block;"') !== false, "Inbound pane should be visible");
    assert(strpos($html, 'id="pane-outbound" class="stock-op-pane" style="display: none;"') !== false, "Outbound pane should be hidden");
    assert(strpos($html, 'id="tab-btn-inbound" class="stock-tab-btn active"') !== false, "Inbound tab button should be active");
});

// Test 2: Outbound Tab via GET ?tab=outbound
testCase("Outbound tab active via GET tab=outbound", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'outbound'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/inbound_outbound/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/inbound_outbound/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-inbound" class="stock-op-pane" style="display: none;"') !== false, "Inbound pane should be hidden");
    assert(strpos($html, 'id="pane-outbound" class="stock-op-pane" style="display: block;"') !== false, "Outbound pane should be visible");
    assert(strpos($html, 'id="tab-btn-outbound" class="stock-tab-btn active"') !== false, "Outbound tab button should be active");
});

// Test 3: Inbound Elements Preserved
testCase("Inbound elements, filters, table, and modal are preserved", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'inbound'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/inbound_outbound/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/inbound_outbound/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="stockInSearch"') !== false, "stockInSearch filter must exist");
    assert(strpos($html, 'id="sourceTypeFilter"') !== false, "sourceTypeFilter must exist");
    assert(strpos($html, 'id="itemTypeFilter"') !== false, "itemTypeFilter must exist");
    assert(strpos($html, 'id="stockInTable"') !== false, "stockInTable must exist");
    assert(strpos($html, 'id="stockInModal"') !== false, "stockInModal must exist");
    assert(strpos($html, 'function filterStockInTable()') !== false, "filterStockInTable JS must exist");
    assert(strpos($html, 'function openDetailModal(') !== false, "openDetailModal JS must exist");
});

// Test 4: Outbound Elements Preserved
testCase("Outbound elements, filters, table, and modal are preserved", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'outbound'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/inbound_outbound/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 1;

    ob_start();
    require __DIR__ . '/../views/inbound_outbound/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="stockOutSearch"') !== false, "stockOutSearch filter must exist");
    assert(strpos($html, 'id="stockOutDestFilter"') !== false, "stockOutDestFilter must exist");
    assert(strpos($html, 'id="stockOutTypeFilter"') !== false, "stockOutTypeFilter must exist");
    assert(strpos($html, 'id="stockOutTable"') !== false, "stockOutTable must exist");
    assert(strpos($html, 'id="stockOutModal"') !== false, "stockOutModal must exist");
    assert(strpos($html, 'function filterStockOutTable()') !== false, "filterStockOutTable JS must exist");
    assert(strpos($html, 'function openOutDetailModal(') !== false, "openOutDetailModal JS must exist");
});

// Test 5: Sidebar Integration
testCase("Sidebar navigation includes unified Inbound & Outbound link", function() {
    $activePage = 'inbound_outbound';
    $currentUser = ['role' => 'admin', 'warehouse_id' => 1];
    
    ob_start();
    require __DIR__ . '/../views/layouts/sidebar.php';
    $sidebarHtml = ob_get_clean();

    assert(strpos($sidebarHtml, 'views/inbound_outbound/index.php') !== false, "Sidebar should link to views/inbound_outbound/index.php");
    assert(strpos($sidebarHtml, 'Inbound &amp; Outbound') !== false, "Sidebar text should be Inbound & Outbound");
});

// Test 6: Legacy Forwarding Logic
testCase("Legacy stock_in and stock_out files contain forwarding logic", function() {
    $stockInCode = file_get_contents(__DIR__ . '/../views/stock_in/index.php');
    $stockOutCode = file_get_contents(__DIR__ . '/../views/stock_out/index.php');

    assert(strpos($stockInCode, 'views/inbound_outbound/index.php?tab=inbound') !== false, "stock_in should forward to ?tab=inbound");
    assert(strpos($stockOutCode, 'views/inbound_outbound/index.php?tab=outbound') !== false, "stock_out should forward to ?tab=outbound");
});

echo "All test cases ran.\n";
