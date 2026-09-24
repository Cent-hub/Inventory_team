<?php
/**
 * Test: Verify Sidebar Active Indicators for Reports
 */

require_once __DIR__ . '/../config/database.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/Inventory_Team/');
}

function testSidebar(string $desc, string $page, string $group, callable $validator) {
    $activePage = $page;
    $activeGroup = $group;
    $currentUser = ['role' => 'admin', 'warehouse_id' => 1];
    
    ob_start();
    require __DIR__ . '/../views/layouts/sidebar.php';
    $html = ob_get_clean();

    try {
        $validator($html);
        echo "[PASS] $desc\n";
    } catch (Throwable $e) {
        echo "[FAIL] $desc: " . $e->getMessage() . "\n";
    }
}

// 1. On Inventory Reports page
testSidebar("On Inventory Reports page, only Inventory Reports has active indicator", 'reports', 'reports', function($html) {
    preg_match('#<a[^>]*views/reports/index\.php[^>]*class="([^"]*)"#', $html, $m1);
    preg_match('#<a[^>]*views/reports/stock_transactions\.php[^>]*class="([^"]*)"#', $html, $m2);

    $invClasses = $m1[1] ?? '';
    $stkClasses = $m2[1] ?? '';

    assert(strpos($invClasses, 'active') !== false, "Inventory Reports MUST be active (was: $invClasses)");
    assert(strpos($stkClasses, 'active') === false, "Stock Transaction Reports MUST NOT be active (was: $stkClasses)");
});

// 2. On Stock Transaction Reports page
testSidebar("On Stock Transaction Reports page, only Stock Transaction Reports has active indicator", 'stock_transactions', 'reports', function($html) {
    preg_match('#<a[^>]*views/reports/index\.php[^>]*class="([^"]*)"#', $html, $m1);
    preg_match('#<a[^>]*views/reports/stock_transactions\.php[^>]*class="([^"]*)"#', $html, $m2);

    $invClasses = $m1[1] ?? '';
    $stkClasses = $m2[1] ?? '';

    assert(strpos($invClasses, 'active') === false, "Inventory Reports MUST NOT be active (was: $invClasses)");
    assert(strpos($stkClasses, 'active') !== false, "Stock Transaction Reports MUST be active (was: $stkClasses)");
});

// 3. On other pages (e.g. raw_materials)
testSidebar("On non-report pages, neither report has active indicator", 'raw_materials', 'inventory', function($html) {
    preg_match('#<a[^>]*views/reports/index\.php[^>]*class="([^"]*)"#', $html, $m1);
    preg_match('#<a[^>]*views/reports/stock_transactions\.php[^>]*class="([^"]*)"#', $html, $m2);

    $invClasses = $m1[1] ?? '';
    $stkClasses = $m2[1] ?? '';

    assert(strpos($invClasses, 'active') === false, "Inventory Reports MUST NOT be active");
    assert(strpos($stkClasses, 'active') === false, "Stock Transaction Reports MUST NOT be active");
});

echo "All Sidebar indicator tests completed.\n";
