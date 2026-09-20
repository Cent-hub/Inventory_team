<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/StockService.php';

$pdo = Database::getConnection();

echo "=== 1. CHECKING SYNTAX OF REFACTORED FILES ===\n";
$files = [
    'helpers/StockService.php',
    'views/layouts/header.php',
    'views/stock_transfer/index.php'
];
foreach ($files as $f) {
    $fullPath = __DIR__ . '/../' . $f;
    $output = [];
    $ret = 0;
    exec("C:\\xampp\\php\\php.exe -l " . escapeshellarg($fullPath), $output, $ret);
    echo "$f: " . implode(' ', $output) . "\n";
    if ($ret !== 0) {
        exit(1);
    }
}

echo "\n=== 2. VERIFYING TWO-SIDED TRANSFER LEDGER QUERIES ===\n";
// Warehouses: 6 = BENSENT (McDo), 7 = PEREZ (JABE)
$whJabe = 7;
$whMcdo = 6;

// Query function simulating views/stock_transfer/index.php
function getLedgerData(PDO $pdo, int $whId) {
    $stmtRecv = $pdo->prepare("
        SELECT 
            st.stock_transfer_id,
            st.transaction_number,
            st.source_warehouse_id,
            sw.warehouse_code AS src_code,
            sw.warehouse_name AS src_name,
            st.destination_warehouse_id,
            dw.warehouse_code AS dest_code,
            dw.warehouse_name AS dest_name,
            st.transaction_date,
            st.status,
            COALESCE(SUM(sti.quantity), 0) AS total_quantity
        FROM stock_transfers st
        JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
        JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
        LEFT JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
        WHERE st.destination_warehouse_id = :wid
        GROUP BY st.stock_transfer_id
        ORDER BY st.stock_transfer_id DESC
    ");
    $stmtRecv->execute([':wid' => $whId]);
    $received = $stmtRecv->fetchAll(PDO::FETCH_ASSOC);

    $stmtSent = $pdo->prepare("
        SELECT 
            st.stock_transfer_id,
            st.transaction_number,
            st.source_warehouse_id,
            sw.warehouse_code AS src_code,
            sw.warehouse_name AS src_name,
            st.destination_warehouse_id,
            dw.warehouse_code AS dest_code,
            dw.warehouse_name AS dest_name,
            st.transaction_date,
            st.status,
            COALESCE(SUM(sti.quantity), 0) AS total_quantity
        FROM stock_transfers st
        JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
        JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
        LEFT JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
        WHERE st.source_warehouse_id = :wid
        GROUP BY st.stock_transfer_id
        ORDER BY st.stock_transfer_id DESC
    ");
    $stmtSent->execute([':wid' => $whId]);
    $sent = $stmtSent->fetchAll(PDO::FETCH_ASSOC);

    return ['received' => $received, 'transferred' => $sent];
}

$jabeData = getLedgerData($pdo, $whJabe);
$mcdoData = getLedgerData($pdo, $whMcdo);

echo "JABE Warehouse (ID $whJabe):\n";
echo "  Received count: " . count($jabeData['received']) . "\n";
echo "  Transferred count: " . count($jabeData['transferred']) . "\n";

echo "McDo Warehouse (ID $whMcdo):\n";
echo "  Received count: " . count($mcdoData['received']) . "\n";
echo "  Transferred count: " . count($mcdoData['transferred']) . "\n";

// Check mathematical zero duplication
$jabeRecvIds = array_column($jabeData['received'], 'stock_transfer_id');
$jabeSentIds = array_column($jabeData['transferred'], 'stock_transfer_id');
$jabeOverlap = array_intersect($jabeRecvIds, $jabeSentIds);
echo "JABE overlap count between Received and Transferred: " . count($jabeOverlap) . " (Expected: 0)\n";

$mcdoRecvIds = array_column($mcdoData['received'], 'stock_transfer_id');
$mcdoSentIds = array_column($mcdoData['transferred'], 'stock_transfer_id');
$mcdoOverlap = array_intersect($mcdoRecvIds, $mcdoSentIds);
echo "McDo overlap count between Received and Transferred: " . count($mcdoOverlap) . " (Expected: 0)\n";

if (count($jabeOverlap) === 0 && count($mcdoOverlap) === 0) {
    echo "  -> SUCCESS: Zero duplication confirmed across two-sided ledgers!\n";
} else {
    echo "  -> ERROR: Overlap found!\n";
    exit(1);
}

echo "\n=== 3. VERIFYING DUAL PERSPECTIVE ON A REAL TRANSFER ===\n";
// Let's create a test transfer: JABE (7) -> McDo (6)
$stockService = new StockService();
// Find an active item with stock in JABE (7)
$stmtStock = $pdo->query("
    SELECT inv.item_id, inv.quantity, i.item_code, i.item_name 
    FROM inventory inv 
    JOIN items i ON inv.item_id = i.item_id 
    WHERE inv.warehouse_id = $whJabe AND inv.quantity >= 5 AND i.status = 'active' 
    LIMIT 1
");
$testItem = $stmtStock->fetch(PDO::FETCH_ASSOC);

if (!$testItem) {
    // Top up an item in JABE for testing
    $itemRow = $pdo->query("SELECT item_id, item_code, item_name FROM items WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $itemId = (int)$itemRow['item_id'];
    $pdo->exec("INSERT INTO inventory (item_id, warehouse_id, quantity, reorder_level) VALUES ($itemId, $whJabe, 100, 10) ON DUPLICATE KEY UPDATE quantity = quantity + 100");
    $testItem = ['item_id' => $itemId, 'quantity' => 100, 'item_code' => $itemRow['item_code'], 'item_name' => $itemRow['item_name']];
}

$transferQty = 2.0;
$userJabeId = 6; // JABE admin user
$res = $stockService->recordStockTransfer(
    $whJabe,
    $whMcdo,
    [['item_id' => (int)$testItem['item_id'], 'quantity' => $transferQty]],
    $userJabeId,
    "Test two-sided ledger transfer " . date('Y-m-d H:i:s')
);

$newTxnId = (int)$res['stock_transfer_id'];
$newTxnNo = $res['transaction_number'];
echo "Initiated transfer: $newTxnNo (ID: $newTxnId) of {$transferQty} units of {$testItem['item_code']}.\n";

// Now query both perspectives:
$jabeDataAfter = getLedgerData($pdo, $whJabe);
$mcdoDataAfter = getLedgerData($pdo, $whMcdo);

$foundInJabeSent = array_filter($jabeDataAfter['transferred'], fn($t) => (int)$t['stock_transfer_id'] === $newTxnId);
$foundInJabeRecv = array_filter($jabeDataAfter['received'], fn($t) => (int)$t['stock_transfer_id'] === $newTxnId);

$foundInMcdoSent = array_filter($mcdoDataAfter['transferred'], fn($t) => (int)$t['stock_transfer_id'] === $newTxnId);
$foundInMcdoRecv = array_filter($mcdoDataAfter['received'], fn($t) => (int)$t['stock_transfer_id'] === $newTxnId);

echo "Checking JABE Perspective:\n";
echo "  Present in Transferred: " . (!empty($foundInJabeSent) ? 'YES' : 'NO') . " (Expected: YES)\n";
echo "  Present in Received:    " . (!empty($foundInJabeRecv) ? 'YES' : 'NO') . " (Expected: NO)\n";

echo "Checking McDo Perspective:\n";
echo "  Present in Transferred: " . (!empty($foundInMcdoSent) ? 'YES' : 'NO') . " (Expected: NO)\n";
echo "  Present in Received:    " . (!empty($foundInMcdoRecv) ? 'YES' : 'NO') . " (Expected: YES)\n";

if (!empty($foundInJabeSent) && empty($foundInJabeRecv) && empty($foundInMcdoSent) && !empty($foundInMcdoRecv)) {
    echo "  -> SUCCESS: Exact two-sided perspective verified!\n";
} else {
    echo "  -> ERROR: Transfer appearance failed perspective test!\n";
    exit(1);
}

echo "\n=== 4. VERIFYING DETAILS MODAL ACCESS FOR DESTINATION WAREHOUSE ===\n";
// McDo admin (user_id = 21, warehouse_id = 6) attempts to inspect the transfer details:
$mcdoAuthUser = [
    'user_id'      => 21,
    'name'         => 'Cent',
    'role'         => 'admin',
    'warehouse_id' => 6
];
try {
    $details = $stockService->getStockTransferDetails($newTxnId, $mcdoAuthUser);
    echo "Destination warehouse inspection: SUCCESS! Fetched " . count($details['items']) . " item(s).\n";
} catch (Exception $e) {
    echo "Destination warehouse inspection: FAILED! " . $e->getMessage() . "\n";
    exit(1);
}

// Unauthorized warehouse admin attempts to inspect:
$thirdPartyAuthUser = [
    'user_id'      => 999,
    'name'         => 'Third Party Admin',
    'role'         => 'admin',
    'warehouse_id' => 999
];
try {
    $stockService->getStockTransferDetails($newTxnId, $thirdPartyAuthUser);
    echo "Third party inspection: FAILED! (Should have been blocked!)\n";
    exit(1);
} catch (DomainException $e) {
    echo "Third party inspection: BLOCKED successfully! (" . $e->getMessage() . ")\n";
}

echo "\n=== 5. VERIFYING ZERO POLLUTION OF STOCK_INS / STOCK_OUTS ===\n";
$stmtIn = $pdo->prepare("SELECT COUNT(*) FROM stock_ins WHERE transaction_number = ? OR source_reference_no = ?");
$stmtIn->execute([$newTxnNo, $newTxnNo]);
$inCount = (int)$stmtIn->fetchColumn();

$stmtOut = $pdo->prepare("SELECT COUNT(*) FROM stock_outs WHERE transaction_number = ? OR source_reference_no = ?");
$stmtOut->execute([$newTxnNo, $newTxnNo]);
$outCount = (int)$stmtOut->fetchColumn();

echo "Stock In records created for transfer: $inCount (Expected: 0)\n";
echo "Stock Out records created for transfer: $outCount (Expected: 0)\n";

if ($inCount === 0 && $outCount === 0) {
    echo "  -> SUCCESS: Normal Inbound/Outbound tables remain 100% clean and unpolluted!\n";
} else {
    echo "  -> ERROR: Stock In / Out records were created!\n";
    exit(1);
}

echo "\n=== ALL VERIFICATION TESTS PASSED PERFECTLY! ===\n";
