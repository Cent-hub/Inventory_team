<?php
/**
 * Test Suite: Stock Operations Unified Page Verification
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

// Test 1: Default Tab (Transfer)
testCase("Default active tab is transfer", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/stock_operations/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    @session_start();
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 6;

    ob_start();
    require __DIR__ . '/../views/stock_operations/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-transfer" class="stock-op-pane" style="display: block;"') !== false, "Transfer pane should be visible");
    assert(strpos($html, 'id="pane-adjustment" class="stock-op-pane" style="display: none;"') !== false, "Adjustment pane should be hidden");
    assert(strpos($html, 'id="pane-stock_card" class="stock-op-pane" style="display: none;"') !== false, "Stock card pane should be hidden");
    assert(strpos($html, 'id="tab-btn-transfer" class="stock-tab-btn active"') !== false, "Transfer tab button should be active");
});

// Test 2: Adjustment Tab via GET
testCase("Adjustment tab active via GET tab=adjustment", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'adjustment'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/stock_operations/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 6;

    ob_start();
    require __DIR__ . '/../views/stock_operations/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-transfer" class="stock-op-pane" style="display: none;"') !== false, "Transfer pane should be hidden");
    assert(strpos($html, 'id="pane-adjustment" class="stock-op-pane" style="display: block;"') !== false, "Adjustment pane should be visible");
    assert(strpos($html, 'id="pane-stock_card" class="stock-op-pane" style="display: none;"') !== false, "Stock card pane should be hidden");
    assert(strpos($html, 'id="tab-btn-adjustment" class="stock-tab-btn active"') !== false, "Adjustment tab button should be active");
});

// Test 3: Stock Card Tab via GET
testCase("Stock Card tab active via GET tab=stock_card", function() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'stock_card'];
    $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/stock_operations/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SESSION['user_id'] = 21;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = 6;

    ob_start();
    require __DIR__ . '/../views/stock_operations/index.php';
    $html = ob_get_clean();

    assert(strpos($html, 'id="pane-transfer" class="stock-op-pane" style="display: none;"') !== false, "Transfer pane should be hidden");
    assert(strpos($html, 'id="pane-adjustment" class="stock-op-pane" style="display: none;"') !== false, "Adjustment pane should be hidden");
    assert(strpos($html, 'id="pane-stock_card" class="stock-op-pane" style="display: block;"') !== false, "Stock card pane should be visible");
    assert(strpos($html, 'id="tab-btn-stock_card" class="stock-tab-btn active"') !== false, "Stock card tab button should be active");
    assert(strpos($html, 'itemSearchInput') !== false, "Item search autocomplete should be present");
});

// Test 4: JavaScript Tab Switching function exists
testCase("switchStockTab JavaScript function present", function() {
    $code = file_get_contents(__DIR__ . '/../views/stock_operations/index.php');
    assert(strpos($code, 'function switchStockTab(tabName)') !== false, "switchStockTab JS function must be present");
    assert(strpos($code, 'history.replaceState') !== false, "URL sync must be present");
});

// Test 5: Verify all CSRF fields in forms
testCase("All modals include CSRF tokens", function() {
    $code = file_get_contents(__DIR__ . '/../views/stock_operations/index.php');
    $count = substr_count($code, '<?= csrfField() ?>');
    assert($count >= 6, "Expected at least 6 CSRF protected forms in modals, found: $count");
});

echo "\nAll stock operations tests completed.\n";
