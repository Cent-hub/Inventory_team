<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/StockService.php';

$pdo = Database::getConnection();
$stockService = new StockService();

echo "=== STARTING COMPREHENSIVE RECEIVING FLOW VERIFICATION ===\n\n";

$whJabe = 7; // PEREZ
$whMcdo = 6; // BENSENT
$userJabe = ['user_id' => 6, 'name' => 'Perez', 'role' => 'admin', 'warehouse_id' => $whJabe];
$userMcdo = ['user_id' => 21, 'name' => 'Cent', 'role' => 'admin', 'warehouse_id' => $whMcdo];
$userThird = ['user_id' => 99, 'name' => 'Intruder', 'role' => 'admin', 'warehouse_id' => 999];

// 1. Pick a test item and ensure sufficient balance in JABE
$itemRow = $pdo->query("SELECT item_id, item_code, item_name, unit FROM items WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$itemId = (int)$itemRow['item_id'];
$pdo->exec("INSERT INTO inventory (item_id, warehouse_id, quantity, reorder_level) VALUES ($itemId, $whJabe, 100, 10) ON DUPLICATE KEY UPDATE quantity = quantity + 100");

// Record balances before transfer
$stmtBal = $pdo->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE item_id = ? AND warehouse_id = ?");
$stmtBal->execute([$itemId, $whJabe]);
$jabeBalBefore = (float)$stmtBal->fetchColumn();

$stmtBal->execute([$itemId, $whMcdo]);
$mcdoBalBefore = (float)$stmtBal->fetchColumn();

echo "Initial balances for {$itemRow['item_code']}:\n";
echo "  JABE (WH $whJabe): $jabeBalBefore\n";
echo "  McDo (WH $whMcdo): $mcdoBalBefore\n\n";

// 2. TEST TRANSFER INITIATION (Source Warehouse)
echo "--- TEST 1: Source Warehouse Initiates Transfer ---\n";
$qtyToTransfer = 5.0;
$initRes = $stockService->recordStockTransfer(
    $whJabe,
    $whMcdo,
    [['item_id' => $itemId, 'quantity' => $qtyToTransfer]],
    $userJabe['user_id'],
    "Transfer test for receiving confirmation"
);

$transferId = (int)$initRes['stock_transfer_id'];
$txnNumber  = $initRes['transaction_number'];
$initStatus = $initRes['status'];

echo "Transfer created: $txnNumber (ID: $transferId)\n";
echo "Initial Status: $initStatus (Expected: 'pending')\n";

if ($initStatus !== 'pending') {
    echo "FAILED: Expected status to be 'pending'!\n";
    exit(1);
}

// Verify balances immediately after initiation
$stmtBal->execute([$itemId, $whJabe]);
$jabeBalAfterInit = (float)$stmtBal->fetchColumn();

$stmtBal->execute([$itemId, $whMcdo]);
$mcdoBalAfterInit = (float)$stmtBal->fetchColumn();

echo "Balances after initiation:\n";
echo "  JABE: $jabeBalAfterInit (Expected: " . ($jabeBalBefore - $qtyToTransfer) . " -> deducted by $qtyToTransfer)\n";
echo "  McDo: $mcdoBalAfterInit (Expected: $mcdoBalBefore -> UNCHANGED, in transit)\n";

if ($jabeBalAfterInit !== ($jabeBalBefore - $qtyToTransfer) || $mcdoBalAfterInit !== $mcdoBalBefore) {
    echo "FAILED: Inventory deduction/in-transit balance check failed!\n";
    exit(1);
}
echo "-> SUCCESS: Source deducted, Destination untouched while in transit!\n\n";

// 3. TEST UNAUTHORIZED CONFIRMATION ATTEMPTS
echo "--- TEST 2: Unauthorized Confirmation Defense ---\n";
// Attempt 2a: Source warehouse user tries to confirm receipt
try {
    $stockService->confirmStockTransferReceipt($transferId, $userJabe['user_id'], $userJabe);
    echo "FAILED: Source warehouse user was able to confirm receipt!\n";
    exit(1);
} catch (DomainException $e) {
    echo "Source confirmation blocked: " . $e->getMessage() . "\n";
}

// Attempt 2b: Third party warehouse user tries to confirm receipt
try {
    $stockService->confirmStockTransferReceipt($transferId, $userThird['user_id'], $userThird);
    echo "FAILED: Third party was able to confirm receipt!\n";
    exit(1);
} catch (DomainException $e) {
    echo "Third party confirmation blocked: " . $e->getMessage() . "\n";
}
echo "-> SUCCESS: Only destination warehouse can confirm receipt!\n\n";

// 4. TEST LEGITIMATE RECEIVING CONFIRMATION (Destination Warehouse)
echo "--- TEST 3: Destination Warehouse Confirms Receipt ---\n";
$confirmRes = $stockService->confirmStockTransferReceipt($transferId, $userMcdo['user_id'], $userMcdo);
echo "Receipt confirmed by: " . $confirmRes['receiver_name'] . "\n";
echo "New Status: " . $confirmRes['status'] . " (Expected: 'completed')\n";

$stmtBal->execute([$itemId, $whJabe]);
$jabeBalAfterConfirm = (float)$stmtBal->fetchColumn();

$stmtBal->execute([$itemId, $whMcdo]);
$mcdoBalAfterConfirm = (float)$stmtBal->fetchColumn();

echo "Balances after confirmation:\n";
echo "  JABE: $jabeBalAfterConfirm (Expected: " . ($jabeBalBefore - $qtyToTransfer) . ")\n";
echo "  McDo: $mcdoBalAfterConfirm (Expected: " . ($mcdoBalBefore + $qtyToTransfer) . " -> credited by $qtyToTransfer)\n";

if ($mcdoBalAfterConfirm !== ($mcdoBalBefore + $qtyToTransfer)) {
    echo "FAILED: Destination was not properly credited after confirmation!\n";
    exit(1);
}

// Verify remarks contain audit confirmation
$stmtTrf = $pdo->prepare("SELECT remarks, status FROM stock_transfers WHERE stock_transfer_id = ?");
$stmtTrf->execute([$transferId]);
$trfRow = $stmtTrf->fetch(PDO::FETCH_ASSOC);
echo "Transfer DB status: {$trfRow['status']}\n";
echo "Transfer DB remarks:\n{$trfRow['remarks']}\n";

if (!str_contains($trfRow['remarks'], 'Received by Cent')) {
    echo "FAILED: Audit receipt trail missing in remarks!\n";
    exit(1);
}
echo "-> SUCCESS: Destination credited and audit receipt recorded!\n\n";

// 5. TEST DOUBLE CONFIRMATION PREVENTION
echo "--- TEST 4: Double Confirmation Prevention ---\n";
try {
    $stockService->confirmStockTransferReceipt($transferId, $userMcdo['user_id'], $userMcdo);
    echo "FAILED: Allowed confirming already completed transfer!\n";
    exit(1);
} catch (DomainException $e) {
    echo "Double confirmation blocked: " . $e->getMessage() . "\n";
}

$stmtBal->execute([$itemId, $whMcdo]);
$mcdoBalAfterDoubleAttempt = (float)$stmtBal->fetchColumn();
if ($mcdoBalAfterDoubleAttempt !== $mcdoBalAfterConfirm) {
    echo "FAILED: Destination inventory changed on duplicate confirmation attempt!\n";
    exit(1);
}
echo "-> SUCCESS: Double confirmation strictly prevented with zero double-counting!\n\n";

// 6. TEST IN-TRANSIT CANCELLATION
echo "--- TEST 5: Cancelling In-Transit (Pending) Transfer ---\n";
$initRes2 = $stockService->recordStockTransfer(
    $whJabe,
    $whMcdo,
    [['item_id' => $itemId, 'quantity' => 3.0]],
    $userJabe['user_id'],
    "Transfer to cancel while in transit"
);
$trf2Id = (int)$initRes2['stock_transfer_id'];

// Check source deducted by 3
$stmtBal->execute([$itemId, $whJabe]);
$jabeBeforeCancel = (float)$stmtBal->fetchColumn();

// Cancel while pending
$cancelRes = $stockService->cancelStockTransfer($trf2Id, "Truck broke down", $userJabe['user_id'], $userJabe);
echo "Cancelled pending transfer #$trf2Id. Reason: Truck broke down\n";

// Check source restored by 3
$stmtBal->execute([$itemId, $whJabe]);
$jabeAfterCancel = (float)$stmtBal->fetchColumn();
echo "JABE balance before cancel: $jabeBeforeCancel\n";
echo "JABE balance after cancel:  $jabeAfterCancel (Restored: " . ($jabeAfterCancel - $jabeBeforeCancel) . ")\n";

if ($jabeAfterCancel !== ($jabeBeforeCancel + 3.0)) {
    echo "FAILED: In-transit cancellation did not restore source stock!\n";
    exit(1);
}
echo "-> SUCCESS: In-transit cancellation restored source stock safely!\n\n";

echo "=== ALL TESTS PASSED WITH 100% INTEGRITY! ===\n";
