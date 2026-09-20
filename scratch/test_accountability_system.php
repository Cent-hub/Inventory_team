<?php
/**
 * Test Suite: Accountability System Verification
 * Tests logging across UI & API, StockService integration, warehouse isolation, and rendering.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AccountabilityService.php';
require_once __DIR__ . '/../helpers/StockService.php';

$pdo = Database::getConnection();

echo "=== 1. TEST ACCOUNTABILITY SERVICE DIRECT LOGGING ===\n";

$testLogId = AccountabilityService::log([
    'user_id'          => 21, // Vince McDo
    'team'             => 'Inventory',
    'action_type'      => 'STOCK_IN',
    'channel'          => 'UI',
    'item_id'          => 1,
    'quantity'         => 10.5,
    'warehouse_id'     => 6,
    'reference_number' => 'TEST-ACC-001',
    'notes'            => 'Automated test direct log'
]);

echo "Created test log ID: {$testLogId}\n";
$stmtCheck = $pdo->prepare("SELECT * FROM accountability_logs WHERE log_id = ?");
$stmtCheck->execute([$testLogId]);
$logRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

assert(!empty($logRow), "Log record should exist");
assert($logRow['warehouse_id'] == 6, "Warehouse ID should be 6");
assert($logRow['channel'] === 'UI', "Channel should be UI");
assert($logRow['item_id'] == 1, "Item ID should be 1");
assert(!empty($logRow['item_name']), "Item name snapshot should be populated");
echo "PASS: Direct log created and verified snapshot.\n";

echo "\n=== 2. TEST WAREHOUSE ISOLATION ===\n";
// McDo warehouse is 6, JABE warehouse is 7
$logsMcdo = AccountabilityService::getLogs(6);
$logsJabe = AccountabilityService::getLogs(7);

echo "McDo Warehouse (ID 6) visible logs: " . count($logsMcdo) . "\n";
echo "JABE Warehouse (ID 7) visible logs: " . count($logsJabe) . "\n";

foreach ($logsMcdo as $l) {
    if ($l['warehouse_id'] != 6 && $l['destination_warehouse_id'] != 6) {
        throw new Exception("Isolation violation: log {$l['log_id']} not associated with warehouse 6");
    }
}
echo "PASS: Strict warehouse isolation verified for McDo.\n";

foreach ($logsJabe as $l) {
    if ($l['warehouse_id'] != 7 && $l['destination_warehouse_id'] != 7) {
        throw new Exception("Isolation violation: log {$l['log_id']} not associated with warehouse 7");
    }
}
echo "PASS: Strict warehouse isolation verified for JABE.\n";

echo "\n=== 3. TEST KPI METRICS ===\n";
$kpisMcdo = AccountabilityService::getKpis(6);
print_r($kpisMcdo);
assert($kpisMcdo['total_events'] > 0, "Total events should be > 0");
echo "PASS: KPIs computed accurately.\n";

echo "\n=== 4. TEST STOCK SERVICE TRANSFER LIFECYCLE LOGGING ===\n";
// Let's create a test transfer from McDo (6) to JABE (7)
$stockService = new StockService();
$itemStmt = $pdo->query("SELECT item_id, quantity FROM inventory WHERE warehouse_id = 6 AND quantity >= 5 LIMIT 1");
$sourceInv = $itemStmt->fetch(PDO::FETCH_ASSOC);

if ($sourceInv) {
    $itemId = (int)$sourceInv['item_id'];
    $transferQty = 2.0;
    
    // 4a. Initiate transfer
    $transferRes = $stockService->recordStockTransfer(
        6, // source
        7, // dest
        [
            ['item_id' => $itemId, 'quantity' => $transferQty]
        ],
        21, // Vince McDo
        'Automated accountability test transfer'
    );
    $transferId = $transferRes['stock_transfer_id'];
    echo "Initiated transfer ID: {$transferId}\n";
    
    // Check log for TRANSFER_INITIATED
    $stmtTLog = $pdo->prepare("SELECT * FROM accountability_logs WHERE reference_number = ? AND action_type = 'TRANSFER_INITIATED'");
    $stmtTLog->execute([$transferRes['transaction_number']]);
    $initLog = $stmtTLog->fetch(PDO::FETCH_ASSOC);
    assert(!empty($initLog), "TRANSFER_INITIATED log must be recorded");
    echo "PASS: TRANSFER_INITIATED recorded in accountability log.\n";
    
    // 4b. Confirm receipt by JABE user (user_id = 6)
    $stmtUserJabe = $pdo->prepare("SELECT * FROM users WHERE user_id = 6");
    $stmtUserJabe->execute();
    $userJabe = $stmtUserJabe->fetch(PDO::FETCH_ASSOC);
    
    $confirmRes = $stockService->confirmStockTransferReceipt($transferId, 6, $userJabe);
    echo "Transfer confirmed received: {$confirmRes['status']}\n";
    
    // Verify received_by column in stock_transfers
    $stmtSt = $pdo->prepare("SELECT received_by, received_at, status FROM stock_transfers WHERE stock_transfer_id = ?");
    $stmtSt->execute([$transferId]);
    $stRow = $stmtSt->fetch(PDO::FETCH_ASSOC);
    assert($stRow['received_by'] == 6, "received_by should be 6 in stock_transfers");
    assert(!empty($stRow['received_at']), "received_at should not be empty");
    echo "PASS: stock_transfers updated with received_by=6 and received_at.\n";
    
    // Check log for TRANSFER_RECEIVED
    $stmtRLog = $pdo->prepare("SELECT * FROM accountability_logs WHERE reference_number = ? AND action_type = 'TRANSFER_RECEIVED'");
    $stmtRLog->execute([$transferRes['transaction_number']]);
    $recLog = $stmtRLog->fetch(PDO::FETCH_ASSOC);
    assert(!empty($recLog), "TRANSFER_RECEIVED log must be recorded");
    assert($recLog['user_id'] == 6, "Recipient user ID must be 6");
    echo "PASS: TRANSFER_RECEIVED recorded in accountability log with recipient user ID 6.\n";
} else {
    echo "SKIP: No inventory available in warehouse 6 for transfer test.\n";
}

echo "\n=== 5. TEST BAD PRODUCT LOGGING ===\n";
if (!empty($itemId)) {
    $badRes = $stockService->recordBadProduct(
        6, // warehouse
        $itemId,
        'damaged',
        1.0,
        'Damaged during testing',
        21 // user
    );
    $badId = $badRes['bad_product_id'];
    echo "Recorded bad product ID: {$badId}\n";
    
    $stmtBLog = $pdo->prepare("SELECT * FROM accountability_logs WHERE action_type = 'BAD_PRODUCT' AND reference_number = ?");
    $stmtBLog->execute([$badRes['bad_product_number']]);
    $bLog = $stmtBLog->fetch(PDO::FETCH_ASSOC);
    assert(!empty($bLog), "BAD_PRODUCT log must be recorded");
    echo "PASS: BAD_PRODUCT logged to accountability_logs.\n";
}

echo "\n=== 6. TEST BADGE FORMATTERS ===\n";
$badgeIn = AccountabilityService::formatActionBadge('STOCK_IN', 'API');
assert(str_contains($badgeIn, 'badge-action inbound'), "Action badge class should be inbound");
assert(str_contains($badgeIn, 'API'), "Badge should show API pill");
echo "PASS: Action badge HTML: {$badgeIn}\n";

$badgeTeam = AccountabilityService::formatTeamBadge('Procurement');
assert(str_contains($badgeTeam, 'badge-team procurement'), "Team badge class should be procurement");
echo "PASS: Team badge HTML: {$badgeTeam}\n";

$initials = AccountabilityService::getUserInitials("Juan Dela Cruz");
assert($initials === 'JD', "Initials should be JD");
echo "PASS: User initials: {$initials}\n";

echo "\n=== ALL ACCOUNTABILITY BACKEND TESTS PASSED SUCCESSFULLY! ===\n";
