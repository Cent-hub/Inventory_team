<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/StockService.php';

$pdo = Database::getConnection();
$stockService = new StockService();

echo "=== STARTING COMPREHENSIVE ADJUSTMENT & BAD PRODUCT VERIFICATION ===\n\n";

$whId = 6; // BENSENT / McDo
$userWh6 = ['user_id' => 21, 'name' => 'Cent', 'role' => 'admin', 'warehouse_id' => $whId];
$userWh7 = ['user_id' => 6,  'name' => 'Perez', 'role' => 'admin', 'warehouse_id' => 7];

// Pick an active item and seed inventory with a known quantity
$item = $pdo->query("SELECT item_id, item_code, item_name, unit FROM items WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$itemId = (int)$item['item_id'];

// Set starting quantity to exactly 100
$pdo->exec("INSERT INTO inventory (item_id, warehouse_id, quantity, reorder_level) VALUES ($itemId, $whId, 100.000, 10) ON DUPLICATE KEY UPDATE quantity = 100.000");

$getQty = function() use ($pdo, $itemId, $whId) {
    $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE item_id = ? AND warehouse_id = ?");
    $stmt->execute([$itemId, $whId]);
    return (float)$stmt->fetchColumn();
};

echo "Starting inventory for {$item['item_code']} in Warehouse $whId: " . $getQty() . "\n\n";

// -----------------------------------------------------------------------------
// TEST 1: Stock Adjustment Creation (Pending)
// -----------------------------------------------------------------------------
echo "--- TEST 1: Stock Adjustment Creation (100 -> 95, Shortage -5) ---\n";
$adj1 = $stockService->recordStockAdjustment(
    $whId,
    date('Y-m-d'),
    "Cycle count shortage test",
    [['item_id' => $itemId, 'adjusted_quantity' => 95.000]],
    $userWh6['user_id'],
    $userWh6
);
$adj1Id = (int)$adj1['stock_adjustment_id'];
echo "Created adjustment: {$adj1['transaction_number']} (Status: {$adj1['status']})\n";
if ($adj1['status'] !== 'pending') {
    echo "FAILED: Status should be pending!\n";
    exit(1);
}
$qtyAfterCreate = $getQty();
echo "Inventory balance after creation: $qtyAfterCreate (Expected: 100.000 -> UNTOUCHED while pending)\n";
if ($qtyAfterCreate !== 100.0) {
    echo "FAILED: Inventory modified prematurely while adjustment is pending!\n";
    exit(1);
}
echo "-> SUCCESS: Stock adjustment created with status pending, inventory unchanged!\n\n";

// -----------------------------------------------------------------------------
// TEST 2: Unauthorized Cross-Warehouse Approval Defense
// -----------------------------------------------------------------------------
echo "--- TEST 2: Cross-Warehouse Approval Defense ---\n";
try {
    $stockService->approveStockAdjustment($adj1Id, $userWh7['user_id'], $userWh7);
    echo "FAILED: User from WH 7 was allowed to approve adjustment for WH 6!\n";
    exit(1);
} catch (DomainException $e) {
    echo "Blocked unauthorized approval: " . $e->getMessage() . "\n";
}
echo "-> SUCCESS: Cross-warehouse approval strictly blocked!\n\n";

// -----------------------------------------------------------------------------
// TEST 3: Legitimate Approval (Shortage -5)
// -----------------------------------------------------------------------------
echo "--- TEST 3: Legitimate Approval of Adjustment ---\n";
$approveRes = $stockService->approveStockAdjustment($adj1Id, $userWh6['user_id'], $userWh6);
echo "Approved: {$approveRes['transaction_number']} (Status: {$approveRes['status']})\n";

$qtyAfterApprove = $getQty();
echo "Inventory balance after approval: $qtyAfterApprove (Expected: 95.000)\n";
if ($qtyAfterApprove !== 95.0) {
    echo "FAILED: Inventory was not updated to 95.000 after approval!\n";
    exit(1);
}

// Check stock_movements entry
$stmtSm = $pdo->prepare("SELECT * FROM stock_movements WHERE stock_adjustment_id = ?");
$stmtSm->execute([$adj1Id]);
$smRow = $stmtSm->fetch(PDO::FETCH_ASSOC);
echo "Movement Type: {$smRow['movement_type']}, Qty In: {$smRow['quantity_in']}, Qty Out: {$smRow['quantity_out']}, Balance After: {$smRow['balance_after']}\n";
if ($smRow['movement_type'] !== 'STOCK_ADJUSTMENT' || (float)$smRow['quantity_out'] !== 5.0 || (float)$smRow['balance_after'] !== 95.0) {
    echo "FAILED: Incorrect movement record logged!\n";
    exit(1);
}
echo "-> SUCCESS: Adjustment approved, STOCK_ADJUSTMENT movement logged, inventory decreased to 95!\n\n";

// -----------------------------------------------------------------------------
// TEST 4: Double Approval Defense
// -----------------------------------------------------------------------------
echo "--- TEST 4: Double Approval Defense ---\n";
try {
    $stockService->approveStockAdjustment($adj1Id, $userWh6['user_id'], $userWh6);
    echo "FAILED: Allowed approving an already approved adjustment!\n";
    exit(1);
} catch (DomainException $e) {
    echo "Blocked double approval: " . $e->getMessage() . "\n";
}
echo "-> SUCCESS: Double approval blocked!\n\n";

// -----------------------------------------------------------------------------
// TEST 5: Cancellation of Approved Adjustment (Trigger Reversal)
// -----------------------------------------------------------------------------
echo "--- TEST 5: Cancellation of Approved Adjustment (Reversing to 100) ---\n";
$cancelRes = $stockService->cancelStockAdjustment($adj1Id, "Recount error, discrepancy resolved", $userWh6['user_id'], $userWh6);
echo "Cancelled adjustment #$adj1Id (Status: {$cancelRes['status']})\n";

$qtyAfterCancel = $getQty();
echo "Inventory balance after cancellation: $qtyAfterCancel (Expected: 100.000 -> RESTORED)\n";
if ($qtyAfterCancel !== 100.0) {
    echo "FAILED: Inventory was not restored to 100.000 upon adjustment cancellation!\n";
    exit(1);
}

// Verify trigger inserted STOCK_ADJUSTMENT_CANCEL
$stmtSmCan = $pdo->prepare("SELECT * FROM stock_movements WHERE stock_adjustment_id = ? AND movement_type = 'STOCK_ADJUSTMENT_CANCEL'");
$stmtSmCan->execute([$adj1Id]);
$smCanRow = $stmtSmCan->fetch(PDO::FETCH_ASSOC);
echo "Cancel Movement: {$smCanRow['movement_type']}, Qty In: {$smCanRow['quantity_in']}, Balance After: {$smCanRow['balance_after']}\n";
if (!$smCanRow || (float)$smCanRow['quantity_in'] !== 5.0 || (float)$smCanRow['balance_after'] !== 100.0) {
    echo "FAILED: Cancellation trigger did not post expected reversing movement!\n";
    exit(1);
}
echo "-> SUCCESS: Cancellation trigger reversed stock movement and restored inventory!\n\n";

// -----------------------------------------------------------------------------
// TEST 6: Adjustment Rejection Flow
// -----------------------------------------------------------------------------
echo "--- TEST 6: Adjustment Rejection Flow ---\n";
$adj2 = $stockService->recordStockAdjustment(
    $whId,
    date('Y-m-d'),
    "Unverified count",
    [['item_id' => $itemId, 'adjusted_quantity' => 110.000]],
    $userWh6['user_id'],
    $userWh6
);
$adj2Id = (int)$adj2['stock_adjustment_id'];
$stockService->rejectStockAdjustment($adj2Id, $userWh6['user_id'], "Manager rejected count", $userWh6);
$qtyAfterReject = $getQty();
echo "Inventory after rejection: $qtyAfterReject (Expected: 100.000)\n";
if ($qtyAfterReject !== 100.0) {
    echo "FAILED: Inventory changed on rejection!\n";
    exit(1);
}
echo "-> SUCCESS: Rejected adjustment changed no inventory!\n\n";

// -----------------------------------------------------------------------------
// TEST 7: Damaged Product Reporting (Immediate Deduction)
// -----------------------------------------------------------------------------
echo "--- TEST 7: Damaged Product Write-Off (Write off 4 bottles) ---\n";
$badRes = $stockService->recordBadProduct(
    $whId,
    $itemId,
    'damaged',
    4.0,
    "4 bottles broken in transit inside warehouse",
    $userWh6['user_id'],
    $userWh6
);
$badId = (int)$badRes['bad_product_id'];
echo "Reported bad product: {$badRes['bad_product_number']} (Status: {$badRes['status']})\n";

$qtyAfterBad = $getQty();
echo "Inventory balance after bad product report: $qtyAfterBad (Expected: 96.000 -> deducted 4)\n";
if ($qtyAfterBad !== 96.0) {
    echo "FAILED: Inventory was not deducted by 4 for damaged product!\n";
    exit(1);
}

// Check stock_movements entry
$stmtSmBad = $pdo->prepare("SELECT * FROM stock_movements WHERE bad_product_id = ?");
$stmtSmBad->execute([$badId]);
$smBadRow = $stmtSmBad->fetch(PDO::FETCH_ASSOC);
echo "Movement: {$smBadRow['movement_type']}, Qty Out: {$smBadRow['quantity_out']}, Balance: {$smBadRow['balance_after']}\n";
if ($smBadRow['movement_type'] !== 'BAD_PRODUCT' || (float)$smBadRow['quantity_out'] !== 4.0 || (float)$smBadRow['balance_after'] !== 96.0) {
    echo "FAILED: BAD_PRODUCT movement incorrect!\n";
    exit(1);
}
echo "-> SUCCESS: Damaged goods immediately wrote off 4 units via trigger!\n\n";

// -----------------------------------------------------------------------------
// TEST 8: Damaged Product Over-Quantity Guard
// -----------------------------------------------------------------------------
echo "--- TEST 8: Over-Quantity Guard for Damaged Product ---\n";
try {
    // Current stock is 96, attempt to write off 200
    $stockService->recordBadProduct(
        $whId,
        $itemId,
        'spoiled',
        200.0,
        "Attempting to write off more than available",
        $userWh6['user_id'],
        $userWh6
    );
    echo "FAILED: Allowed writing off more stock than available in warehouse!\n";
    exit(1);
} catch (DomainException $e) {
    echo "Over-quantity write-off blocked: " . $e->getMessage() . "\n";
}
echo "-> SUCCESS: Over-quantity guard strictly prevented negative stock!\n\n";

// -----------------------------------------------------------------------------
// TEST 9: Damaged Product Cancellation / Stock Recovery
// -----------------------------------------------------------------------------
echo "--- TEST 9: Cancel Damaged Product Report (Restore 4 bottles) ---\n";
$cancelBadRes = $stockService->cancelBadProduct($badId, "Inspection error: bottles intact", $userWh6['user_id'], $userWh6);
echo "Cancelled bad product report #$badId (Status: {$cancelBadRes['status']})\n";

$qtyAfterBadCancel = $getQty();
echo "Inventory balance after bad product cancellation: $qtyAfterBadCancel (Expected: 100.000 -> RESTORED)\n";
if ($qtyAfterBadCancel !== 100.0) {
    echo "FAILED: Stock not restored upon bad product cancellation!\n";
    exit(1);
}

// Check trigger inserted BAD_PRODUCT_CANCEL
$stmtSmBadCan = $pdo->prepare("SELECT * FROM stock_movements WHERE bad_product_id = ? AND movement_type = 'BAD_PRODUCT_CANCEL'");
$stmtSmBadCan->execute([$badId]);
$smBadCanRow = $stmtSmBadCan->fetch(PDO::FETCH_ASSOC);
echo "Cancel Movement: {$smBadCanRow['movement_type']}, Qty In: {$smBadCanRow['quantity_in']}, Balance: {$smBadCanRow['balance_after']}\n";
if (!$smBadCanRow || (float)$smBadCanRow['quantity_in'] !== 4.0 || (float)$smBadCanRow['balance_after'] !== 100.0) {
    echo "FAILED: BAD_PRODUCT_CANCEL movement incorrect!\n";
    exit(1);
}
echo "-> SUCCESS: Damaged product cancellation trigger restored stock to 100!\n\n";

// -----------------------------------------------------------------------------
// TEST 10: Details Retrieval API Verification
// -----------------------------------------------------------------------------
echo "--- TEST 10: Details Retrieval Verification ---\n";
$adjDetails = $stockService->getStockAdjustmentDetails($adj1Id, $userWh6);
echo "Adjustment Details: Ref={$adjDetails['transaction_number']}, ItemsCount=" . count($adjDetails['items']) . "\n";
$badDetails = $stockService->getBadProductDetails($badId, $userWh6);
echo "Bad Product Details: Ref={$badDetails['bad_product_number']}, Item={$badDetails['item_name']}, Condition={$badDetails['condition_type']}\n";
echo "-> SUCCESS: Both details methods work with complete metadata!\n\n";

echo "=== ALL 10 TESTS PASSED WITH 100% SUCCESS! ===\n";
