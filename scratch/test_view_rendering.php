<?php
/**
 * Test view rendering of accountability pages
 */

// Simulate web request session
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/settings/accountability.php';
$_SERVER['HTTP_HOST'] = 'localhost';

session_start();
$_SESSION['user_id'] = 21; // Vince McDo
$_SESSION['user_name'] = 'Vince McDo';
$_SESSION['user_email'] = 'vince.mcdo@inventory.local';
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'Vince McDo';
$_SESSION['email'] = 'vince.mcdo@inventory.local';
$_SESSION['warehouse_id'] = 6;
$_SESSION['logged_in'] = true;
$_SESSION['login_time'] = time();

echo "=== 1. TEST views/settings/accountability.php RENDERING ===\n";
ob_start();
try {
    include 'c:/xampp/htdocs/Inventory_Team/views/settings/accountability.php';
    $html = ob_get_clean();
    assert(!empty($html), "HTML should not be empty");
    assert(str_contains($html, 'Accountability Audit Log'), "Title should be in output");
    assert(str_contains($html, 'fullLogTable'), "fullLogTable should be in output");
    assert(!empty(str_contains($html, 'badge-action')), "Action badges should be rendered");
    echo "PASS: views/settings/accountability.php rendered successfully (" . strlen($html) . " bytes).\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "FAIL in accountability.php: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== 2. TEST views/settings/index.php RENDERING ===\n";
$_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/settings/index.php';
ob_start();
try {
    include 'c:/xampp/htdocs/Inventory_Team/views/settings/index.php';
    $html = ob_get_clean();
    assert(!empty($html), "HTML should not be empty");
    assert(str_contains($html, 'pageAccountabilityTable'), "pageAccountabilityTable should be in output");
    echo "PASS: views/settings/index.php rendered successfully (" . strlen($html) . " bytes).\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "FAIL in index.php: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== 3. TEST views/layouts/settings_modal.php RENDERING ===\n";
ob_start();
try {
    include 'c:/xampp/htdocs/Inventory_Team/views/layouts/settings_modal.php';
    $html = ob_get_clean();
    assert(!empty($html), "HTML should not be empty");
    assert(str_contains($html, 'accountabilityTable'), "accountabilityTable should be in modal output");
    assert(str_contains($html, 'Live Audit'), "Live Audit badge should be in modal output");
    echo "PASS: views/layouts/settings_modal.php rendered successfully (" . strlen($html) . " bytes).\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "FAIL in settings_modal.php: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== ALL VIEW RENDERING TESTS PASSED! ===\n";
