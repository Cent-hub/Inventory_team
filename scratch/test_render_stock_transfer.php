<?php
// Test both McDo (WH 6) and JABE (WH 7)
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getConnection();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Inventory_Team/views/stock_transfer/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';

foreach ([6 => 'McDo (WH 6, user 21)', 7 => 'JABE (WH 7, user 6)'] as $whId => $label) {
    $userId = ($whId === 6) ? 21 : 6;
    $_SESSION = [];
    $_SESSION['user_id'] = $userId;
    $_SESSION['logged_in'] = true;
    $_SESSION['warehouse_id'] = $whId;

    ob_start();
    try {
        require __DIR__ . '/../views/stock_transfer/index.php';
        $html = ob_get_clean();
        echo "SUCCESS: Rendered cleanly for $label! Length: " . strlen($html) . " bytes\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "FAILED for $label: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
        exit(1);
    }
}
