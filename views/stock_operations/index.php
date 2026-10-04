<?php
/**
 * View: Stock Operations (Unified Transaction Hub)
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Unifies three core inventory transaction functions into a single interface:
 * 1. Stock Transfer (Inter-facility warehouse transfer ledger)
 * 2. Stock Adjustment (Discrepancy reconciliation & defect write-offs)
 * 3. Stock Card (Item-level chronological debit/credit ledger)
 */

$pageTitle   = 'Stock Operations — InventoryTeam';
$activePage  = 'stock_operations';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

$successMessage = null;
$errorMessage   = null;

// Determine Initial Active Tab
$activeTab = 'transfer';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['transfer', 'adjustment', 'stock_card'], true)) {
    $activeTab = $_GET['tab'];
} elseif (isset($_POST['active_tab']) && in_array($_POST['active_tab'], ['transfer', 'adjustment', 'stock_card'], true)) {
    $activeTab = $_POST['active_tab'];
} elseif (isset($_GET['item_id']) || isset($_GET['movement_type']) || isset($_GET['start_date']) || isset($_GET['end_date'])) {
    $activeTab = 'stock_card';
}

// Fetch available destination warehouses (other active facilities only)
$destStmt = $pdo->prepare("
    SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name, location 
    FROM warehouses 
    WHERE warehouse_id != :wid AND status = 'active' 
    ORDER BY name ASC
");
$destStmt->execute([':wid' => $currentWarehouseId]);
$destinationWarehouses = $destStmt->fetchAll(PDO::FETCH_ASSOC);

$canInitiateTransfer = !empty($destinationWarehouses);

// =============================================================================
// POST ACTION DISPATCHER
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $stockService = new StockService();
        $userId       = (int)($currentUser['id'] ?? 1);

        try {
            switch ($action) {
                // -------------------------------------------------------------
                // 1. Stock Transfer Actions
                // -------------------------------------------------------------
                case 'create_transfer':
                    $activeTab = 'transfer';
                    if (!$canInitiateTransfer) {
                        throw new RuntimeException("Stock transfer is disabled because only one active warehouse exists in the system.");
                    }
                    $sourceWhId  = (int)$currentWarehouseId;
                    $destWhId    = (int)($_POST['destination_warehouse_id'] ?? 0);
                    $itemId      = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity = $_POST['quantity'] ?? null;
                    $remarks     = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;

                    if ($destWhId <= 0) {
                        throw new InvalidArgumentException("Please select a valid destination warehouse.");
                    } elseif ($destWhId === $sourceWhId) {
                        throw new InvalidArgumentException("Destination warehouse cannot be the same as your source warehouse.");
                    } elseif ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select a valid item to transfer.");
                    }
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'transfer quantity');

                    $result = $stockService->recordStockTransfer(
                        $sourceWhId,
                        $destWhId,
                        [['item_id' => $itemId, 'quantity' => $quantity]],
                        $userId,
                        $remarks ?: null
                    );
                    $successMessage = "Transfer initiated successfully! Transaction reference: " . htmlspecialchars($result['transaction_number']);
                    break;

                case 'confirm_receipt':
                    $activeTab  = 'transfer';
                    $transferId = (int)($_POST['stock_transfer_id'] ?? 0);
                    if ($transferId <= 0) {
                        throw new InvalidArgumentException("Invalid stock transfer reference ID.");
                    }
                    $result = $stockService->confirmStockTransferReceipt($transferId, $userId, $currentUser);
                    $successMessage = "Stock transfer " . htmlspecialchars($result['transaction_number']) . " confirmed and received successfully into your warehouse inventory!";
                    break;

                case 'cancel_transfer':
                    $activeTab  = 'transfer';
                    $transferId = (int)($_POST['stock_transfer_id'] ?? 0);
                    $reason     = trim($_POST['cancellation_reason'] ?? '');
                    if ($transferId <= 0) {
                        throw new InvalidArgumentException("Invalid stock transfer reference ID.");
                    }
                    $result = $stockService->cancelStockTransfer($transferId, $reason, $userId, $currentUser);
                    $successMessage = "Stock transfer " . htmlspecialchars($result['transaction_number']) . " has been cancelled and stock restored to the source warehouse.";
                    break;

                // -------------------------------------------------------------
                // 2. Stock Adjustment Actions
                // -------------------------------------------------------------
                case 'create_adjustment':
                    $activeTab       = 'adjustment';
                    $itemId          = (int)($_POST['item_id'] ?? 0);
                    $rawAdjustedQty  = $_POST['adjusted_quantity'] ?? null;
                    $reason          = trim($_POST['reason'] ?? '');
                    $adjDate         = !empty($_POST['adjustment_date']) ? trim($_POST['adjustment_date']) : date('Y-m-d');

                    if ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select a valid item to adjust.");
                    }
                    $adjustedQty = StockService::validateNonNegativeQuantity($rawAdjustedQty, null, 'adjusted_quantity');
                    if (empty($reason)) {
                        throw new InvalidArgumentException("A reconciliation note or reason is required.");
                    }
                    if (mb_strlen($reason) > 255) {
                        throw new InvalidArgumentException("Adjustment reason cannot exceed 255 characters.");
                    }
                    $adjDate = StockService::validateDateNotFuture($adjDate, 'adjustment_date');

                    $result = $stockService->recordStockAdjustment(
                        $currentWarehouseId,
                        $adjDate,
                        $reason,
                        [['item_id' => $itemId, 'adjusted_quantity' => $adjustedQty]],
                        $userId,
                        $currentUser
                    );
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " created successfully and marked as Pending approval.";
                    break;

                case 'approve_adjustment':
                    $activeTab = 'adjustment';
                    $adjId     = (int)($_POST['stock_adjustment_id'] ?? 0);
                    if ($adjId <= 0) {
                        throw new InvalidArgumentException("Invalid adjustment reference ID.");
                    }
                    $result = $stockService->approveStockAdjustment($adjId, $userId, $currentUser);
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " approved! Stock ledger movement posted and warehouse inventory updated.";
                    break;

                case 'reject_adjustment':
                    $activeTab = 'adjustment';
                    $adjId     = (int)($_POST['stock_adjustment_id'] ?? 0);
                    $reason    = trim($_POST['rejection_reason'] ?? 'Discrepancy count rejected by administrator');
                    if ($adjId <= 0) {
                        throw new InvalidArgumentException("Invalid adjustment reference ID.");
                    }
                    $result = $stockService->rejectStockAdjustment($adjId, $userId, $reason, $currentUser);
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " has been rejected. No inventory changes were made.";
                    break;

                case 'cancel_adjustment':
                    $activeTab = 'adjustment';
                    $adjId     = (int)($_POST['stock_adjustment_id'] ?? 0);
                    $reason    = trim($_POST['cancellation_reason'] ?? 'Administrative cancellation');
                    if ($adjId <= 0) {
                        throw new InvalidArgumentException("Invalid adjustment reference ID.");
                    }
                    $result = $stockService->cancelStockAdjustment($adjId, $reason, $userId, $currentUser);
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " has been cancelled and reversed.";
                    break;

                case 'create_bad_product':
                    $activeTab     = 'adjustment';
                    $itemId        = (int)($_POST['item_id'] ?? 0);
                    $conditionType = trim($_POST['condition_type'] ?? 'damaged');
                    $rawQuantity   = $_POST['quantity'] ?? null;
                    $reason        = trim($_POST['reason'] ?? '');

                    if ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select a valid item.");
                    }
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity');
                    if (empty($reason)) {
                        throw new InvalidArgumentException("Please provide details or a reason for the damage/defect.");
                    }
                    if (mb_strlen($reason) > 255) {
                        throw new InvalidArgumentException("Defect reason cannot exceed 255 characters.");
                    }

                    $result = $stockService->recordBadProduct(
                        $currentWarehouseId,
                        $itemId,
                        $conditionType,
                        $quantity,
                        $reason,
                        $userId,
                        $currentUser
                    );
                    $successMessage = "Damaged product write-off recorded (" . htmlspecialchars($result['bad_product_number']) . "). Stock has been deducted from your warehouse inventory.";
                    break;

                case 'cancel_bad_product':
                    $activeTab = 'adjustment';
                    $bpId      = (int)($_POST['bad_product_id'] ?? 0);
                    $reason    = trim($_POST['cancellation_reason'] ?? 'Logged in error / Stock recovered');
                    if ($bpId <= 0) {
                        throw new InvalidArgumentException("Invalid defect record ID.");
                    }
                    $result = $stockService->cancelBadProduct($bpId, $reason, $userId, $currentUser);
                    $successMessage = "Damaged product report " . htmlspecialchars($result['bad_product_number']) . " cancelled. Deducted stock has been refunded back into your warehouse inventory.";
                    break;
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

// =============================================================================
// DATA QUERIES: SECTION 1 (STOCK TRANSFER)
// =============================================================================

// Consolidated query: Fetch all active items with warehouse inventory balance
$allWhItemsStmt = $pdo->prepare("
    SELECT 
        i.item_id, 
        i.code AS item_code, 
        i.name AS item_name, 
        i.type AS item_type, 
        i.unit, 
        i.reorder_level AS default_reorder_level,
        COALESCE(s.qty_on_hand, 0.000) AS current_stock
    FROM items i
    LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = :wid
    WHERE i.status = 'active'
    ORDER BY i.type ASC, i.name ASC
");
$allWhItemsStmt->execute([':wid' => $currentWarehouseId]);
$allWarehouseItems = $allWhItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Items available in source warehouse with positive stock for transfer
$transferAvailableItems = array_values(array_filter($allWarehouseItems, fn($it) => (float)$it['current_stock'] > 0));

// Inbound Transfers (Destination = Current Warehouse)
$stmtRecv = $pdo->prepare("
    SELECT 
        st.transfer_id AS stock_transfer_id,
        CONCAT('TRF-', LPAD(st.transfer_id, 6, '0')) AS transaction_number,
        st.source_warehouse_id,
        sw.code AS src_code,
        sw.name AS src_name,
        st.destination_warehouse_id,
        dw.code AS dest_code,
        dw.name AS dest_name,
        DATE(st.requested_at) AS transaction_date,
        st.status,
        '' AS remarks,
        NULL AS cancellation_reason,
        NULL AS cancelled_at,
        st.requested_at AS created_at,
        COALESCE(u.name, 'Warehouse Operator') AS requested_by,
        1 AS total_items,
        st.quantity AS total_quantity,
        i.name AS first_item_name,
        i.code AS first_item_code,
        i.type AS first_item_type,
        i.unit AS first_unit,
        CONCAT(i.name, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ROUND(st.quantity, 2))), ' ', i.unit, ')') AS items_summary,
        st.item_id
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    LEFT JOIN users u ON st.requested_by = u.user_id
    JOIN items i ON st.item_id = i.item_id
    WHERE st.destination_warehouse_id = :wid
    ORDER BY st.requested_at DESC, st.transfer_id DESC
");
$stmtRecv->execute([':wid' => $currentWarehouseId]);
$receivedTransfers = $stmtRecv->fetchAll(PDO::FETCH_ASSOC);

// Outbound Transfers (Source = Current Warehouse)
$stmtSent = $pdo->prepare("
    SELECT 
        st.transfer_id AS stock_transfer_id,
        CONCAT('TRF-', LPAD(st.transfer_id, 6, '0')) AS transaction_number,
        st.source_warehouse_id,
        sw.code AS src_code,
        sw.name AS src_name,
        st.destination_warehouse_id,
        dw.code AS dest_code,
        dw.name AS dest_name,
        DATE(st.requested_at) AS transaction_date,
        st.status,
        '' AS remarks,
        NULL AS cancellation_reason,
        NULL AS cancelled_at,
        st.requested_at AS created_at,
        COALESCE(u.name, 'Warehouse Operator') AS requested_by,
        1 AS total_items,
        st.quantity AS total_quantity,
        i.name AS first_item_name,
        i.code AS first_item_code,
        i.type AS first_item_type,
        i.unit AS first_unit,
        CONCAT(i.name, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ROUND(st.quantity, 2))), ' ', i.unit, ')') AS items_summary,
        st.item_id
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    LEFT JOIN users u ON st.requested_by = u.user_id
    JOIN items i ON st.item_id = i.item_id
    WHERE st.source_warehouse_id = :wid
    ORDER BY st.requested_at DESC, st.transfer_id DESC
");
$stmtSent->execute([':wid' => $currentWarehouseId]);
$transferredTransfers = $stmtSent->fetchAll(PDO::FETCH_ASSOC);

// Line items for modal inspection
$linesByTransfer = [];
foreach (array_merge($receivedTransfers, $transferredTransfers) as $row) {
    $linesByTransfer[(int)$row['stock_transfer_id']][] = [
        'stock_transfer_id' => $row['stock_transfer_id'],
        'item_code'         => $row['first_item_code'],
        'item_name'         => $row['first_item_name'],
        'item_type'         => $row['first_item_type'],
        'unit'              => $row['first_unit'],
        'quantity'          => $row['total_quantity']
    ];
}

$totalReceived       = count(array_filter($receivedTransfers, fn($t) => ($t['status'] ?? '') !== 'cancelled'));
$totalTransferred    = count(array_filter($transferredTransfers, fn($t) => ($t['status'] ?? '') !== 'cancelled'));
$totalTransfersCount = $totalReceived + $totalTransferred;
$allInvolved         = array_merge($receivedTransfers, $transferredTransfers);
$completedCount      = count(array_filter($allInvolved, fn($t) => in_array(($t['status'] ?? ''), ['received', 'completed'], true)));

// =============================================================================
// DATA QUERIES: SECTION 2 (STOCK ADJUSTMENT)
// =============================================================================

// Items for adjustment dropdown (reusing consolidated item query)
$adjAvailableItems = $allWarehouseItems;

// Stock Adjustments for assigned warehouse
$stmtAdj = $pdo->prepare("
    SELECT 
        sa.adjustment_id AS stock_adjustment_id,
        CONCAT('ADJ-', LPAD(sa.adjustment_id, 6, '0')) AS transaction_number,
        DATE(sa.requested_at) AS adjustment_date,
        sa.reason,
        NULL AS cancellation_reason,
        NULL AS cancelled_at,
        sa.status,
        sa.requested_at AS created_at,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(u.name, 'Warehouse Operator') AS logged_by,
        ua.name AS approved_by_name,
        NULL AS cancelled_by_name,
        sa.previous_quantity,
        sa.adjusted_quantity,
        sa.difference,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit
    FROM stock_adjustments sa
    JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sa.requested_by = u.user_id
    LEFT JOIN users ua ON sa.approved_by = ua.user_id
    JOIN items i ON sa.item_id = i.item_id
    WHERE sa.warehouse_id = :wid
    ORDER BY sa.adjustment_id DESC
");
$stmtAdj->execute([':wid' => $currentWarehouseId]);
$adjustments = $stmtAdj->fetchAll(PDO::FETCH_ASSOC);

// Bad Product Defect Records
$stmtBad = $pdo->prepare("
    SELECT 
        bp.bad_product_id,
        CONCAT('BP-', LPAD(bp.bad_product_id, 6, '0')) AS bad_product_number,
        'damaged' AS condition_type,
        bp.quantity,
        bp.reason,
        NULL AS cancellation_reason,
        NULL AS cancelled_at,
        bp.status,
        bp.reported_at AS created_at,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        COALESCE(u.name, 'Warehouse Operator') AS reported_by_name,
        NULL AS cancelled_by_name
    FROM bad_products bp
    JOIN warehouses w ON bp.warehouse_id = w.warehouse_id
    JOIN items i ON bp.item_id = i.item_id
    LEFT JOIN users u ON bp.reported_by = u.user_id
    WHERE bp.warehouse_id = :wid
    ORDER BY bp.bad_product_id DESC
");
$stmtBad->execute([':wid' => $currentWarehouseId]);
$badProducts = $stmtBad->fetchAll(PDO::FETCH_ASSOC);

$totalAdjustments = count(array_filter($adjustments, fn($a) => !in_array($a['status'] ?? '', ['cancelled', 'rejected'], true)));
$totalBadProducts = count(array_filter($badProducts, fn($b) => ($b['status'] ?? '') !== 'cancelled'));
$netVariance      = 0.0;
foreach ($adjustments as $a) {
    if (($a['status'] ?? '') === 'approved') {
        $netVariance += (float)($a['difference'] ?? 0);
    }
}

// =============================================================================
// DATA QUERIES: SECTION 3 (STOCK CARD)
// =============================================================================

// Distinct items in assigned warehouse inventory for Stock Card selector (reusing consolidated item query)
$cardItems = $allWarehouseItems;

// Selected filters
$selectedItemId = (isset($_GET['item_id']) && is_numeric($_GET['item_id']) && (int)$_GET['item_id'] > 0)
    ? (int)$_GET['item_id']
    : (int)($cardItems[0]['item_id'] ?? 0);
$movementType   = isset($_GET['movement_type']) ? trim($_GET['movement_type']) : '';
$rawStartDate   = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$rawEndDate     = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$dtStart        = DateTime::createFromFormat('Y-m-d', $rawStartDate);
$dtEnd          = DateTime::createFromFormat('Y-m-d', $rawEndDate);
$startDate      = ($dtStart && $dtStart->format('Y-m-d') === $rawStartDate) ? $rawStartDate : '';
$endDate        = ($dtEnd && $dtEnd->format('Y-m-d') === $rawEndDate) ? $rawEndDate : '';
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

// Retrieve selected item profile
$selectedItem = null;
foreach ($cardItems as $it) {
    if ((int)$it['item_id'] === $selectedItemId) {
        $selectedItem = $it;
        break;
    }
}

// Current stock snapshot
$cardCurrentStock = 0.0;
if ($selectedItem) {
    $stockStmt = $pdo->prepare("SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock WHERE item_id = ? AND warehouse_id = ?");
    $stockStmt->execute([$selectedItemId, $currentWarehouseId]);
    $cardCurrentStock = (float)$stockStmt->fetchColumn();
}

// Movements ledger strictly for assigned warehouse
$movements = [];
if ($selectedItemId > 0) {
    $sql = "
        SELECT 
            sm.movement_id,
            sm.movement_type,
            COALESCE(sm.remarks, CONCAT('MOV-', sm.movement_id)) AS reference_number,
            CASE WHEN sm.movement_type IN ('STOCK_IN', 'STOCK_TRANSFER_IN') THEN sm.quantity ELSE 0 END AS quantity_in,
            CASE WHEN sm.movement_type IN ('STOCK_OUT', 'STOCK_TRANSFER_OUT') THEN sm.quantity ELSE 0 END AS quantity_out,
            0.00 AS balance_after,
            sm.created_at,
            w.code AS warehouse_code,
            w.name AS warehouse_name,
            COALESCE(sm.remarks, '') AS notes
        FROM stock_movements sm
        JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        WHERE sm.item_id = ? AND sm.warehouse_id = ?
    ";
    $params = [$selectedItemId, $currentWarehouseId];

    if (!empty($movementType)) {
        $sql .= " AND sm.movement_type = ?";
        $params[] = $movementType;
    }

    if (!empty($startDate)) {
        $sql .= " AND DATE(sm.created_at) >= ?";
        $params[] = $startDate;
    }

    if (!empty($endDate)) {
        $sql .= " AND DATE(sm.created_at) <= ?";
        $params[] = $endDate;
    }

    $sql .= " ORDER BY sm.created_at ASC, sm.movement_id ASC";

    $stmtMovements = $pdo->prepare($sql);
    $stmtMovements->execute($params);
    $movements = $stmtMovements->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Main Page Header -->
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Stock Operations</h1>
        <p class="page-subtitle">Unified transaction hub for Transfers, Physical Count Adjustments, and Item Stock Cards</p>
    </div>
</div>

<!-- ========================================================================= -->
<!-- NAVIGATION SWITCHER: INVENTORY OPERATIONS                                 -->
<!-- ========================================================================= -->
<div class="stock-ops-nav-wrapper">
    <div class="stock-ops-nav-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect width="7" height="7" x="3" y="3" rx="1"/>
            <rect width="7" height="7" x="14" y="3" rx="1"/>
            <rect width="7" height="7" x="14" y="14" rx="1"/>
            <rect width="7" height="7" x="3" y="14" rx="1"/>
        </svg>
        <span>Inventory Operations</span>
    </div>
    <div class="stock-ops-tabs">
        <button type="button" id="tab-btn-transfer" class="stock-tab-btn <?= $activeTab === 'transfer' ? 'active' : '' ?>" onclick="switchStockTab('transfer')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m16 3 4 4-4 4"/>
                <path d="M20 7H4"/>
                <path d="m8 21-4-4 4-4"/>
                <path d="M4 17h16"/>
            </svg>
            <span>Stock Transfer</span>
            <span class="tab-badge-count"><?= $totalTransfersCount ?></span>
        </button>

        <button type="button" id="tab-btn-adjustment" class="stock-tab-btn <?= $activeTab === 'adjustment' ? 'active' : '' ?>" onclick="switchStockTab('adjustment')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
            </svg>
            <span>Stock Adjustment</span>
            <span class="tab-badge-count"><?= $totalAdjustments ?></span>
        </button>

        <button type="button" id="tab-btn-stock_card" class="stock-tab-btn <?= $activeTab === 'stock_card' ? 'active' : '' ?>" onclick="switchStockTab('stock_card')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="18" x="3" y="3" rx="2"/>
                <path d="M3 9h18"/>
                <path d="M9 21V9"/>
            </svg>
            <span>Stock Card</span>
        </button>
    </div>
</div>

<!-- Flash Alerts -->
<?php if ($successMessage): ?>
    <div style="background: var(--success-light); border: 1px solid var(--success-border); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?= htmlspecialchars($successMessage) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: var(--success); font-size: 16px;">&times;</button>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div style="background: var(--error-light); border: 1px solid var(--error-border); color: var(--error); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errorMessage) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: var(--error); font-size: 16px;">&times;</button>
    </div>
<?php endif; ?>


<!-- ######################################################################### -->
<!-- PANE 1: STOCK TRANSFER SECTION                                            -->
<!-- ######################################################################### -->
<div id="pane-transfer" class="stock-op-pane" style="display: <?= $activeTab === 'transfer' ? 'block' : 'none' ?>;">

    <!-- Section Header Toolbar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="font-size: 18px; font-weight: 700; color: var(--panel-ink); margin-bottom: 4px;">Stock Transfer Ledger</h2>
            <p style="font-size: 13px; color: var(--gray); margin: 0;">Two-sided warehouse movement tracking and dispatch control</p>
        </div>
        <div>
            <?php if ($canInitiateTransfer): ?>
                <button type="button" class="btn btn-primary" onclick="openNewTransferModal()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>Initiate Transfer</span>
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-primary" disabled style="opacity: 0.55; cursor: not-allowed;" title="Inter-warehouse transfers require at least two active warehouses.">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>Initiate Transfer (Disabled)</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$canInitiateTransfer): ?>
        <div style="background: #FEF3C7; border: 1px solid #FDE68A; color: #92400E; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-weight: 500;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
                <strong>Single Warehouse Notice:</strong> There is only 1 active warehouse registered in the system. Inter-warehouse transfers are disabled until additional facilities are active.
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI Summary Cards -->
    <div class="stats-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Received from Other Facilities</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <polyline points="19 12 12 19 5 12"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value" style="color: #15803D;"><?= $totalReceived ?></div>
            <div class="stat-meta">Inbound transfers to <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Transferred to Other Facilities</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="19" x2="12" y2="5"/>
                        <polyline points="5 12 12 5 19 12"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value" style="color: #1D4ED8;"><?= $totalTransferred ?></div>
            <div class="stat-meta">Outbound transfers from <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Completed Transfers</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #047857; background: #D1FAE5;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $completedCount ?></div>
            <div class="stat-meta">Posted across dual ledgers</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Partner Warehouses</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #7C3AED; background: #F5F3FF;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                        <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                        <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= count($destinationWarehouses) ?></div>
            <div class="stat-meta">Active facilities ready for exchange</div>
        </div>
    </div>

    <!-- Section: Received from Other Warehouse (Inbound) -->
    <div class="card mb-6" style="margin-bottom: 28px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <span class="section-badge-recv">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                        Inbound
                    </span>
                    <h3 class="card-title" style="margin: 0; font-size: 16px;">Received from Other Warehouse</h3>
                    <span class="badge" style="background: #F1F5F9; color: #475569; font-weight: 700;"><?= $totalReceived ?></span>
                </div>
                <p class="card-desc" style="margin: 0;">Transfers where <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></strong> is the destination receiving facility</p>
            </div>
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="receivedSearch" class="search-box" aria-label="Filter received transfers" placeholder="Filter received transfers..." oninput="filterTable('receivedSearch', 'receivedTable')">
            </div>
        </div>

        <div class="table-responsive">
            <table id="receivedTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Transfer Reference</th>
                        <th>Item</th>
                        <th>Item Type</th>
                        <th>Quantity</th>
                        <th>From Warehouse</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($receivedTransfers)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">
                                No incoming transfers received from other warehouses yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($receivedTransfers as $row): 
                            $hasMultiple = ((int)$row['total_items'] > 1);
                            $itemType = $row['first_item_type'] ?? 'finished_good';
                        ?>
                            <tr>
                                <td style="font-size: 12.5px; white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['created_at'] ?: $row['transaction_date'])) ?>
                                    <small style="display: block; color: var(--gray); font-size: 11px;">
                                        <?= date('h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($row['transaction_number']) ?>
                                </td>
                                <td style="max-width: 240px;">
                                    <?php if (!$hasMultiple && !empty($row['first_item_name'])): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['first_item_name']) ?></div>
                                        <small style="font-family: monospace; color: var(--gray); font-size: 11.5px;"><?= htmlspecialchars($row['first_item_code']) ?></small>
                                    <?php elseif ($hasMultiple): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= (int)$row['total_items'] ?> items received</div>
                                        <small style="color: var(--gray); font-size: 11.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['items_summary'] ?: '') ?>">
                                            <?= htmlspecialchars($row['items_summary'] ?: 'Multiple items') ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">No items specified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$hasMultiple): ?>
                                        <?php if ($itemType === 'finished_good'): ?>
                                            <span class="badge-type type-fg">Finished Good</span>
                                        <?php else: ?>
                                            <span class="badge-type type-raw">Raw Material</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge-type type-raw">Mixed (<?= (int)$row['total_items'] ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 700; color: #15803D; white-space: nowrap;">
                                    +<?= formatQty((float)$row['total_quantity']) ?>
                                    <?php if (!$hasMultiple && !empty($row['first_unit'])): ?>
                                        <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($row['first_unit']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-wh <?= getWarehouseBadgeClass($row['src_code']) ?>"><?= htmlspecialchars($row['src_code']) ?></span>
                                    <span style="font-size: 12px; color: var(--panel-ink); margin-left: 4px; font-weight: 500;"><?= htmlspecialchars($row['src_name']) ?></span>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'received' || $row['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Received</span>
                                    <?php elseif ($row['status'] === 'in_transit' || $row['status'] === 'pending'): ?>
                                        <span class="badge status-pending">In Transit</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <?php if ($row['status'] === 'in_transit' || $row['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-primary" style="height: 30px; padding: 0 11px; font-size: 11.5px; background: #15803D; border-color: #15803D; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;" onclick='openConfirmReceiptModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Received Stock</span>
                                            </button>
                                        <?php elseif ($row['status'] === 'received' || $row['status'] === 'completed'): ?>
                                            <span style="color: #15803D; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 3px; margin-right: 4px;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                Received
                                            </span>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" onclick='openTransferDetailModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "inbound")'>
                                            View Details
                                        </button>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section: Transferred to Other Warehouse (Outbound) -->
    <div class="card mb-6">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <span class="section-badge-send">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                        Outbound
                    </span>
                    <h3 class="card-title" style="margin: 0; font-size: 16px;">Transferred to Other Warehouse</h3>
                    <span class="badge" style="background: #F1F5F9; color: #475569; font-weight: 700;"><?= $totalTransferred ?></span>
                </div>
                <p class="card-desc" style="margin: 0;">Transfers originating from <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></strong> dispatched to other facilities</p>
            </div>
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="transferredSearch" class="search-box" aria-label="Filter transferred records" placeholder="Filter transferred records..." oninput="filterTable('transferredSearch', 'transferredTable')">
            </div>
        </div>

        <div class="table-responsive">
            <table id="transferredTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Transfer Reference</th>
                        <th>Item</th>
                        <th>Item Type</th>
                        <th>Quantity</th>
                        <th>To Warehouse</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transferredTransfers)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">
                                No outgoing transfers dispatched to other warehouses yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transferredTransfers as $row): 
                            $hasMultiple = ((int)$row['total_items'] > 1);
                            $itemType = $row['first_item_type'] ?? 'finished_good';
                        ?>
                            <tr>
                                <td style="font-size: 12.5px; white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['created_at'] ?: $row['transaction_date'])) ?>
                                    <small style="display: block; color: var(--gray); font-size: 11px;">
                                        <?= date('h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($row['transaction_number']) ?>
                                </td>
                                <td style="max-width: 240px;">
                                    <?php if (!$hasMultiple && !empty($row['first_item_name'])): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['first_item_name']) ?></div>
                                        <small style="font-family: monospace; color: var(--gray); font-size: 11.5px;"><?= htmlspecialchars($row['first_item_code']) ?></small>
                                    <?php elseif ($hasMultiple): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= (int)$row['total_items'] ?> items transferred</div>
                                        <small style="color: var(--gray); font-size: 11.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['items_summary'] ?: '') ?>">
                                            <?= htmlspecialchars($row['items_summary'] ?: 'Multiple items') ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">No items specified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$hasMultiple): ?>
                                        <?php if ($itemType === 'finished_good'): ?>
                                            <span class="badge-type type-fg">Finished Good</span>
                                        <?php else: ?>
                                            <span class="badge-type type-raw">Raw Material</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge-type type-raw">Mixed (<?= (int)$row['total_items'] ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 700; color: #1D4ED8; white-space: nowrap;">
                                    -<?= formatQty((float)$row['total_quantity']) ?>
                                    <?php if (!$hasMultiple && !empty($row['first_unit'])): ?>
                                        <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($row['first_unit']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-wh <?= getWarehouseBadgeClass($row['dest_code']) ?>"><?= htmlspecialchars($row['dest_code']) ?></span>
                                    <span style="font-size: 12px; color: var(--panel-ink); margin-left: 4px; font-weight: 500;"><?= htmlspecialchars($row['dest_name']) ?></span>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'received' || $row['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Received</span>
                                    <?php elseif ($row['status'] === 'in_transit' || $row['status'] === 'pending'): ?>
                                        <span class="badge status-pending">In Transit</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <?php if ($row['status'] === 'in_transit' || $row['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px; color: #B91C1C; border-color: #FCA5A5;" onclick='openCancelTransferModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                Cancel
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" onclick='openTransferDetailModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "outbound")'>
                                            View Details
                                        </button>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ######################################################################### -->
<!-- PANE 2: STOCK ADJUSTMENT SECTION                                          -->
<!-- ######################################################################### -->
<div id="pane-adjustment" class="stock-op-pane" style="display: <?= $activeTab === 'adjustment' ? 'block' : 'none' ?>;">

    <!-- Section Header Toolbar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h2 style="font-size: 18px; font-weight: 700; color: var(--panel-ink); margin-bottom: 4px;">Stock Adjustments &amp; Discrepancy Audits</h2>
            <p style="font-size: 13px; color: var(--gray); margin: 0;">Audited inventory corrections and damaged goods write-offs</p>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" onclick="openReportBadProductModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>Report Damaged Goods</span>
            </button>
            <button type="button" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" onclick="openNewAdjustmentModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>New Stock Adjustment</span>
            </button>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="stats-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Total Adjustments</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #B45309; background: #FEF3C7;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 20h9"/>
                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $totalAdjustments ?></div>
            <div class="stat-meta">Discrepancy count records logged</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Damaged / Defect Records</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $totalBadProducts ?></div>
            <div class="stat-meta">Damaged / spoiled / broken units</div>
        </div>
    </div>

    <!-- Section 1: Stock Adjustments (Physical Count Reconciliation) -->
    <div class="card mb-6" style="margin-bottom: 28px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 class="card-title" style="margin: 0; font-size: 16px;">Stock Adjustment Reconciliation Records</h3>
                <p class="card-desc" style="margin: 0;">Audited inventory corrections between recorded balance and physical shelf counts</p>
            </div>
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="adjSearch" class="search-box" aria-label="Filter stock adjustments" placeholder="Filter adjustment ref, item..." oninput="filterTable('adjSearch', 'adjustmentsTable')">
            </div>
        </div>

        <div class="table-responsive">
            <table id="adjustmentsTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Adjustment Ref</th>
                        <th>Item</th>
                        <th>Previous System Qty</th>
                        <th>Adjusted Physical Qty</th>
                        <th>Difference</th>
                        <th>Stated Reason</th>
                        <th>Date</th>
                        <th>Logged By</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adjustments)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">No stock adjustments recorded for this warehouse yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($adjustments as $row): ?>
                            <?php $diff = (float)($row['difference'] ?? 0); ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($row['transaction_number']) ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['item_name'])): ?>
                                        <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                        <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--gray);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= formatQty($row['previous_quantity'] ?? 0) ?> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit'] ?? '') ?></small>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);"><?= formatQty($row['adjusted_quantity'] ?? 0) ?></strong> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit'] ?? '') ?></small>
                                </td>
                                <td style="font-weight: 700; color: <?= $diff >= 0 ? '#15803D' : '#B91C1C' ?>;">
                                    <?= ($diff >= 0 ? '+' : '') . formatQty($diff) ?>
                                </td>
                                <td style="max-width: 200px; font-size: 12px; color: var(--gray); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['reason']) ?>">
                                    <?= htmlspecialchars($row['reason']) ?>
                                </td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['adjustment_date'])) ?>
                                </td>
                                <td style="font-size: 12px;">
                                    <?= htmlspecialchars($row['logged_by']) ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'approved'): ?>
                                        <span class="badge status-completed">Approved</span>
                                    <?php elseif ($row['status'] === 'pending'): ?>
                                        <span class="badge status-pending">Pending</span>
                                    <?php elseif ($row['status'] === 'rejected'): ?>
                                        <span class="badge status-cancelled">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <?php if ($row['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-primary" style="height: 28px; padding: 0 11px; font-size: 11.5px;" onclick='openAdjDetailModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                Review &amp; Details
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11.5px;" onclick='openAdjDetailModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                Details
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 2: Damaged & Defective Goods (Write-offs) -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 class="card-title" style="margin: 0; font-size: 16px;">Damaged &amp; Defective Liquor Goods</h3>
                <p class="card-desc" style="margin: 0;">Losses from bottle breakage, cork defects, barrel leakage, or expired batches written off from active inventory</p>
            </div>
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="badSearch" class="search-box" aria-label="Filter damaged and defective goods" placeholder="Filter report ref, item..." oninput="filterTable('badSearch', 'badProductsTable')">
            </div>
        </div>
        <div class="table-responsive">
            <table id="badProductsTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Report Ref #</th>
                        <th>Item</th>
                        <th>Condition / Defect</th>
                        <th>Quantity Written Off</th>
                        <th>Reason / Details</th>
                        <th>Reported By</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($badProducts)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No damaged or defective products reported for this warehouse yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($badProducts as $bp): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($bp['bad_product_number']) ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($bp['item_name']) ?></strong>
                                    <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($bp['item_code']) ?></div>
                                </td>
                                <td>
                                    <span class="badge status-alert" style="text-transform: capitalize;">
                                        <?= htmlspecialchars($bp['condition_type']) ?>
                                    </span>
                                </td>
                                <td style="font-weight: 700; color: #B91C1C; white-space: nowrap;">
                                    -<?= formatQty($bp['quantity']) ?> <small style="color: var(--gray);"><?= htmlspecialchars($bp['unit']) ?></small>
                                </td>
                                <td style="font-size: 12px; color: var(--gray); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($bp['reason'] . (!empty($bp['cancellation_reason']) ? ' | Cancelled: ' . $bp['cancellation_reason'] : '')) ?>">
                                    <?= htmlspecialchars($bp['reason']) ?>
                                    <?php if (!empty($bp['cancellation_reason'])): ?>
                                        <div style="font-size: 11px; color: #B91C1C; font-weight: 600;">Cancelled: <?= htmlspecialchars($bp['cancellation_reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px;">
                                    <?= htmlspecialchars($bp['reported_by_name']) ?>
                                </td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($bp['created_at'])) ?>
                                </td>
                                <td>
                                    <?php if ($bp['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Written Off</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($bp['status'] === 'completed'): ?>
                                        <button type="button" class="btn btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11.5px; color: #B91C1C; border-color: #FCA5A5;" onclick='openCancelBadModal(<?= json_encode($bp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            Cancel / Restore
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size: 11.5px; color: var(--gray); font-style: italic;">Restored</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ######################################################################### -->
<!-- PANE 3: STOCK CARD SECTION                                                -->
<!-- ######################################################################### -->
<div id="pane-stock_card" class="stock-op-pane" style="display: <?= $activeTab === 'stock_card' ? 'block' : 'none' ?>;">

    <!-- Section Header Toolbar -->
    <div style="margin-bottom: 20px;">
        <h2 style="font-size: 18px; font-weight: 700; color: var(--panel-ink); margin-bottom: 4px;">Item Stock Card Ledger</h2>
        <p style="font-size: 13px; color: var(--gray); margin: 0;">Chronological debit, credit, and running balance audit ledger for each item</p>
    </div>

    <!-- Filter Toolbar Card -->
    <div class="card" style="padding: 18px 22px; margin-bottom: 24px;">
        <form method="GET" action="index.php" id="stockCardForm" style="display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;">
            <input type="hidden" name="tab" value="stock_card">
            
            <!-- Searchable Item Autocomplete Selector -->
            <div style="display: flex; flex-direction: column; gap: 4px; min-width: 280px; flex: 1; position: relative;">
                <label for="itemSearchInput" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">Select Inventory Item:</label>
                <input type="hidden" name="item_id" id="selectedItemId" value="<?= (int)$selectedItemId ?>">
                
                <div id="itemSearchWrapper" class="searchable-select-wrap">
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="text" 
                               id="itemSearchInput" 
                               class="select-filter" 
                               role="combobox"
                               aria-expanded="false"
                               aria-controls="itemDropdownList"
                               aria-autocomplete="list"
                               style="width: 100%; font-weight: 600; padding-right: 32px; height: 38px; cursor: text;" 
                               placeholder="Type item name or ID... 🔍" 
                               value="<?= $selectedItem ? htmlspecialchars($selectedItem['item_name'] . ' — ' . $selectedItem['item_code']) : '' ?>" 
                               autocomplete="off"
                               onfocus="openItemDropdown()"
                               oninput="filterItemDropdown(this.value)"
                               onkeydown="handleItemDropdownKeydown(event)">
                        <button type="button" id="clearItemSearchBtn" aria-label="Clear item search" onclick="clearItemSearch()" style="position: absolute; right: 8px; background: none; border: none; cursor: pointer; color: var(--gray); font-size: 16px; display: <?= $selectedItem ? 'inline-block' : 'none' ?>; line-height: 1; padding: 2px;" title="Clear search">&times;</button>
                    </div>

                    <!-- Dropdown Search Results Container -->
                    <div id="itemDropdownList" class="searchable-dropdown-list" role="listbox" aria-label="Inventory items"></div>
                </div>
            </div>

            <!-- Movement Type Filter -->
            <div style="display: flex; flex-direction: column; gap: 4px; min-width: 160px;">
                <label for="movement_type" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">Movement Type:</label>
                <select name="movement_type" id="movement_type" class="select-filter" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="STOCK_IN" <?= $movementType === 'STOCK_IN' ? 'selected' : '' ?>>Stock In</option>
                    <option value="STOCK_OUT" <?= $movementType === 'STOCK_OUT' ? 'selected' : '' ?>>Stock Out</option>
                    <option value="STOCK_TRANSFER_IN" <?= $movementType === 'STOCK_TRANSFER_IN' ? 'selected' : '' ?>>Transfer In</option>
                    <option value="STOCK_TRANSFER_OUT" <?= $movementType === 'STOCK_TRANSFER_OUT' ? 'selected' : '' ?>>Transfer Out</option>
                    <option value="STOCK_ADJUSTMENT" <?= $movementType === 'STOCK_ADJUSTMENT' ? 'selected' : '' ?>>Stock Adjustment</option>
                    <option value="BAD_PRODUCT" <?= $movementType === 'BAD_PRODUCT' ? 'selected' : '' ?>>Damaged / Defective</option>
                </select>
            </div>

            <!-- Start Date -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <label for="start_date" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">From:</label>
                <input type="date" id="start_date" name="start_date" class="select-filter" value="<?= htmlspecialchars($startDate) ?>" onchange="this.form.submit()">
            </div>

            <!-- End Date -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <label for="end_date" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">To:</label>
                <input type="date" id="end_date" name="end_date" class="select-filter" value="<?= htmlspecialchars($endDate) ?>" onchange="this.form.submit()">
            </div>

            <a href="index.php?tab=stock_card&item_id=<?= $selectedItemId ?>" class="btn btn-secondary" style="height: 38px;">Reset</a>
        </form>
    </div>

    <!-- Selected Item Profile Banner -->
    <?php if ($selectedItem): ?>
    <div class="card" style="background: linear-gradient(135deg, #14213D 0%, #1c2e54 100%); color: #ffffff; border: none; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <span class="badge-type <?= $selectedItem['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                        <?= $selectedItem['item_type'] === 'finished_good' ? 'Finished Product' : 'Raw Material' ?>
                    </span>
                    <span style="font-family: monospace; font-size: 13px; color: var(--gold); font-weight: 700;">
                        <?= htmlspecialchars($selectedItem['item_code']) ?>
                    </span>
                </div>
                <h3 style="font-family: var(--font-display); font-size: 22px; font-weight: 800; color: #ffffff; margin-bottom: 4px;">
                    <?= htmlspecialchars($selectedItem['item_name']) ?>
                </h3>
                <p style="font-size: 13px; color: #94A3B8; margin: 0;">
                    Standard Inventory Unit: <strong><?= htmlspecialchars($selectedItem['unit']) ?></strong> &middot; Reorder Threshold: <?= formatQty($selectedItem['default_reorder_level']) ?> <?= htmlspecialchars($selectedItem['unit']) ?>
                </p>
            </div>

            <!-- Current Stock Big Badge -->
            <div style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: var(--radius-lg); padding: 14px 22px; text-align: right;">
                <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #5EEAD4;">
                    Current Balance on Hand
                </div>
                <div style="font-family: var(--font-display); font-size: 28px; font-weight: 800; color: #ffffff;">
                    <?= formatQty($cardCurrentStock) ?> <small style="font-size: 14px; font-weight: 500; color: #E2E8F0;"><?= htmlspecialchars($selectedItem['unit']) ?></small>
                </div>
                <div style="font-size: 11.5px; color: #CBD5E1; margin-top: 2px;">
                    <?= count($movements) ?> ledger transactions recorded
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stock Card Ledger Table -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Chronological Stock Card Entries</h3>
                <p class="card-desc">Every transaction debit, credit, and resulting running stock balance</p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="stockCardTable">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Reference / Document #</th>
                        <th>Movement Type</th>
                        <th style="text-align: right;">Stock In (+)</th>
                        <th style="text-align: right;">Stock Out (-)</th>
                        <th style="text-align: right;">Balance After</th>
                        <th>Notes / Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--gray); padding: 36px;"><?= empty($cardItems) ? 'No inventory items registered in system. Add items to view stock card ledger.' : 'No transactions recorded for this item under the selected filter criteria.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                            <?php 
                                $qtyIn  = (float)$m['quantity_in'];
                                $qtyOut = (float)$m['quantity_out'];
                                $isTransfer = strpos($m['movement_type'], 'TRANSFER') !== false;
                                $isAdj = strpos($m['movement_type'], 'ADJUSTMENT') !== false;
                                $pillClass = $isTransfer ? 'mov-transfer' : ($isAdj ? 'mov-adj' : ($qtyIn > 0 ? 'mov-in' : 'mov-out'));
                            ?>
                            <tr>
                                <td style="font-size: 12.5px; white-space: nowrap; color: var(--gray);">
                                    <?= date('M d, Y H:i', strtotime($m['created_at'])) ?>
                                </td>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($m['reference_number']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $pillClass ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $m['movement_type'])) ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #15803D;">
                                    <?= $qtyIn > 0 ? ('+' . formatQty($qtyIn)) : '—' ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #B91C1C;">
                                    <?= $qtyOut > 0 ? ('-' . formatQty($qtyOut)) : '—' ?>
                                </td>
                                <td style="text-align: right; font-weight: 800; font-size: 14px; color: var(--panel-ink); background: #F8FAFC;">
                                    <?= formatQty($m['balance_after'] ?? 0) ?>
                                </td>
                                <td style="font-size: 12px; color: var(--gray); max-width: 220px;" title="<?= htmlspecialchars($m['notes']) ?>">
                                    <?= htmlspecialchars($m['notes'] ?: 'Standard movement') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ========================================================================= -->
<!-- MODALS SECTION                                                            -->
<!-- ========================================================================= -->

<!-- 1. Transfer Details Modal -->
<div id="transferModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalTrfTitle" onclick="if(event.target === this) closeTransferDetailModal()">
    <div class="modal-card" style="max-width: 620px;">
        <div class="modal-header">
            <div>
                <h3 id="modalTrfTitle" class="card-title">Transfer Details</h3>
                <p id="modalTrfSub" class="card-desc">Warehouse-to-warehouse movement record</p>
            </div>
            <button type="button" class="modal-close" aria-label="Close modal" onclick="closeTransferDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-meta-grid">
                <div class="modal-meta-item">
                    <div class="modal-meta-label">Transfer Reference</div>
                    <div id="modalTrfRef" class="modal-meta-val" style="font-family: monospace;">—</div>
                </div>
                <div class="modal-meta-item">
                    <div class="modal-meta-label">Movement Status</div>
                    <div id="modalTrfStatus" class="modal-meta-val">—</div>
                </div>
                <div class="modal-meta-item">
                    <div class="modal-meta-label">Source Warehouse</div>
                    <div id="modalTrfSrc" class="modal-meta-val">—</div>
                </div>
                <div class="modal-meta-item">
                    <div class="modal-meta-label">Destination Warehouse</div>
                    <div id="modalTrfDest" class="modal-meta-val">—</div>
                </div>
                <div class="modal-meta-item">
                    <div class="modal-meta-label">Date &amp; Time</div>
                    <div id="modalTrfDate" class="modal-meta-val">—</div>
                </div>
                <div class="modal-meta-item">
                    <div class="modal-meta-label">Initiated By</div>
                    <div id="modalTrfUser" class="modal-meta-val">—</div>
                </div>
            </div>

            <div id="modalTrfRemarksContainer" style="margin-bottom: 12px; background: #F1F5F9; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; display: none;">
                <strong style="color: #475569; display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 2px;">Transfer Notes:</strong>
                <span id="modalTrfRemarks" style="color: var(--panel-ink);"></span>
            </div>

            <div id="modalTrfCancelContainer" style="margin-bottom: 16px; background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; display: none;">
                <strong style="color: #B91C1C; display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 2px;">Cancellation Reason:</strong>
                <span id="modalTrfCancelReason" style="color: #991B1B; font-weight: 600;"></span>
            </div>

            <div style="font-size: 13px; font-weight: 700; color: var(--panel-ink); margin-bottom: 8px;">
                Transferred Items
            </div>
            <div class="table-responsive">
                <table class="no-paginate">
                    <thead>
                        <tr>
                            <th>SKU / Code</th>
                            <th>Item Name</th>
                            <th>Classification</th>
                            <th style="text-align: right;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody id="modalTrfTableBody"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeTransferDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- 2. Initiate Transfer Modal -->
<div id="newTransferModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="newTransferModalTitle" onclick="handleOpsBackdropClose(event, this, closeNewTransferModal)">
    <div class="modal-card" style="max-width: 540px;">
        <form method="POST" action="index.php?tab=transfer">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_transfer">
            <input type="hidden" name="active_tab" value="transfer">
            
            <div class="modal-header">
                <div>
                    <h3 id="newTransferModalTitle" class="card-title">Initiate Stock Transfer</h3>
                    <p class="card-desc">Transfer inventory from your assigned warehouse to another facility</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeNewTransferModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        From Warehouse (Source) <span style="font-size: 11px; font-weight: normal; color: var(--gray);">(Your Assigned Facility &mdash; Locked)</span>
                    </label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 600; color: var(--panel-ink); font-size: 13px;">
                            <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?>
                        </span>
                        <span class="badge" style="background: #E2E8F0; color: #475569; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Source Locked</span>
                    </div>
                    <input type="hidden" name="source_warehouse_id" value="<?= $currentWarehouseId ?>">
                </div>

                <div>
                    <label for="destinationWarehouseSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        To Warehouse (Destination) <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="destination_warehouse_id" id="destinationWarehouseSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required>
                        <option value="">-- Select Destination Facility --</option>
                        <?php foreach ($destinationWarehouses as $dw): ?>
                            <option value="<?= (int)$dw['warehouse_id'] ?>">
                                <?= htmlspecialchars($dw['warehouse_code']) ?> &mdash; <?= htmlspecialchars($dw['warehouse_name']) ?> (<?= htmlspecialchars($dw['location'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="transferItemSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Item to Transfer <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="transferItemSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="handleTransferItemChange(this)">
                        <option value="">-- Select Item with Available Stock --</option>
                        <?php foreach ($transferAvailableItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [Stock: <?= formatQty((float)$item['current_stock']) ?> <?= htmlspecialchars($item['unit']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($transferAvailableItems)): ?>
                        <div style="font-size: 11.5px; color: #DC2626; margin-top: 4px;">No items currently available with positive stock in this warehouse.</div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="transferQuantity" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Transfer Quantity <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.01" min="0.01" name="quantity" id="transferQuantity" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0" required>
                        <span id="unitIndicator" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                    <div id="availStockHint" style="font-size: 11.5px; color: var(--gray); margin-top: 4px;">Select an item to view maximum transferable balance.</div>
                </div>

                <div>
                    <label for="transferRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Transfer Notes / Remarks
                    </label>
                    <textarea name="remarks" id="transferRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 64px; padding: 8px 12px; resize: vertical;" placeholder="Optional dispatch notes or batch reference..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= empty($transferAvailableItems) ? 'disabled' : '' ?>>
                    <span>Confirm &amp; Dispatch</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Confirm Received Stock Modal -->
<div id="confirmReceiptModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmReceiptModalTitle" onclick="if(event.target === this) closeConfirmReceiptModal()">
    <div class="modal-card" style="max-width: 480px;">
        <form method="POST" action="index.php?tab=transfer">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="confirm_receipt">
            <input type="hidden" name="active_tab" value="transfer">
            <input type="hidden" name="stock_transfer_id" id="confirmReceiptId" value="">
            
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: #DCFCE7; color: #15803D; display: flex; align-items: center; justify-content: center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <h3 id="confirmReceiptModalTitle" class="card-title" style="margin: 0;">Confirm Received Stock</h3>
                        <p class="card-desc" style="margin: 0;">Verify physical delivery of items into your warehouse</p>
                    </div>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeConfirmReceiptModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <p style="font-size: 13.5px; color: var(--panel-ink); margin: 0; line-height: 1.5;">
                    Are you sure this stock has arrived and been verified at <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></strong>?
                </p>

                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 14px 16px;">
                    <div style="display: grid; grid-template-columns: 120px 1fr; gap: 8px; font-size: 13px;">
                        <span style="color: var(--gray); font-weight: 600;">Transfer Ref:</span>
                        <strong id="confirmTrfRef" style="font-family: monospace;">—</strong>

                        <span style="color: var(--gray); font-weight: 600;">From Facility:</span>
                        <span id="confirmTrfFrom" style="font-weight: 600; color: var(--panel-ink);">—</span>

                        <span style="color: var(--gray); font-weight: 600;">Item(s):</span>
                        <span id="confirmTrfItem" style="font-weight: 600; color: var(--panel-ink);">—</span>

                        <span style="color: var(--gray); font-weight: 600;">Quantity:</span>
                        <span id="confirmTrfQty" style="font-weight: 700; color: #15803D;">—</span>
                    </div>
                </div>

                <div style="font-size: 12px; color: #475569; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 12px;">
                    <strong style="color: #0F172A;">Note:</strong> Confirming this receipt will immediately post the stock into your warehouse inventory and mark the transfer as Completed.
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeConfirmReceiptModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #15803D; border-color: #15803D; display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Confirm Received Stock</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 4. New Stock Adjustment Modal -->
<div id="newAdjustmentModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="newAdjustmentModalTitle" onclick="handleOpsBackdropClose(event, this, closeNewAdjustmentModal)">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php?tab=adjustment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_adjustment">
            <input type="hidden" name="active_tab" value="adjustment">
            
            <div class="modal-header">
                <div>
                    <h3 id="newAdjustmentModalTitle" class="card-title" style="margin: 0;">New Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Record discrepancy between system records and physical shelf count</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeNewAdjustmentModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Facility</label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; font-size: 13px; font-weight: 600; color: var(--panel-ink);">
                        <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?>
                    </div>
                </div>

                <div>
                    <label for="adjItemSelect" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Item <span style="color: #DC2626;">*</span></label>
                    <select name="item_id" id="adjItemSelect" class="select-filter" style="width: 100%; height: 38px; border-radius: 8px;" required onchange="handleAdjItemChange(this)">
                        <option value="">-- Select Item to Adjust --</option>
                        <?php foreach ($adjAvailableItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [System Stock: <?= formatQty((float)$item['current_stock']) ?> <?= htmlspecialchars($item['unit']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="adjEntryMode" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Adjustment Entry Mode</label>
                    <select id="adjEntryMode" class="select-filter" style="width: 100%; height: 38px; border-radius: 8px;" onchange="handleAdjModeChange()">
                        <option value="physical">Enter Actual Physical Shelf Count</option>
                        <option value="add">Add Quantity (+ Surplus)</option>
                        <option value="deduct">Deduct Quantity (- Shortage)</option>
                    </select>
                </div>

                <div class="form-grid-2">
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: var(--gray); margin-bottom: 4px; display: block;">Previous System Stock</label>
                        <div id="adjPrevStock" style="background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 8px; height: 38px; display: flex; align-items: center; padding: 0 12px; font-weight: 700; color: var(--panel-ink);">
                            —
                        </div>
                    </div>
                    <div>
                        <label id="adjCountLabel" for="adjInputQty" style="font-size: 12px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Actual Physical Count <span style="color: #DC2626;">*</span></label>
                        <input type="number" step="0.01" min="0" id="adjInputQty" class="search-box" style="width: 100%; height: 38px; border-radius: 8px;" placeholder="0" required oninput="calcAdjDiff()">
                        <input type="hidden" name="adjusted_quantity" id="adjPhysicalCount" value="0">
                    </div>
                </div>

                <div id="adjDiffContainer" style="display: none; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 14px; font-size: 13px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--gray); font-weight: 600;">Calculated Variance:</span>
                        <strong id="adjDiffValue" style="font-size: 15px;">—</strong>
                    </div>
                    <small id="adjDiffDesc" style="display: block; color: var(--gray); font-size: 11.5px; margin-top: 3px;"></small>
                </div>

                <div>
                    <label for="adjDate" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Count Date</label>
                    <input type="date" name="adjustment_date" id="adjDate" class="search-box" style="width: 100%; height: 38px; border-radius: 8px;" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div>
                    <label for="adjReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Reconciliation Notes / Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="reason" id="adjReason" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px; resize: vertical;" placeholder="e.g. Discrepancy discovered during monthly physical cycle count..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewAdjustmentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- 5. Report Damaged Goods Modal -->
<div id="reportBadProductModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="reportBadProductModalTitle" onclick="handleOpsBackdropClose(event, this, closeReportBadProductModal)">
    <div class="modal-card" style="max-width: 500px;">
        <form method="POST" action="index.php?tab=adjustment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_bad_product">
            <input type="hidden" name="active_tab" value="adjustment">
            
            <div class="modal-header">
                <div>
                    <h3 id="reportBadProductModalTitle" class="card-title" style="margin: 0;">Report Damaged / Defective Stock</h3>
                    <p class="card-desc" style="margin: 0;">Immediately write off spoiled, broken, or defective goods</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeReportBadProductModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div>
                    <label for="badItemSelect" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Item <span style="color: #DC2626;">*</span></label>
                    <select name="item_id" id="badItemSelect" class="select-filter" style="width: 100%; height: 38px; border-radius: 8px;" required onchange="handleBadItemChange(this)">
                        <option value="">-- Select Damaged Item --</option>
                        <?php foreach ($adjAvailableItems as $item): ?>
                            <?php if ((float)$item['current_stock'] > 0): ?>
                                <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                    <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [Available: <?= formatQty((float)$item['current_stock']) ?> <?= htmlspecialchars($item['unit']) ?>]
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="badConditionSelect" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Condition / Defect Type <span style="color: #DC2626;">*</span></label>
                    <select name="condition_type" id="badConditionSelect" class="select-filter" style="width: 100%; height: 38px; border-radius: 8px;" required>
                        <option value="damaged">Damaged (e.g. Broken bottle, cracked crate)</option>
                        <option value="defective">Defective (e.g. Bad seal, cork taint, cloudy liquid)</option>
                        <option value="expired">Expired (e.g. Passed shelf life / best before)</option>
                        <option value="spoiled">Spoiled / Sourced</option>
                        <option value="unusable">Unusable Raw Material</option>
                        <option value="other">Other Incident</option>
                    </select>
                </div>

                <div>
                    <label for="badQuantity" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Quantity to Write Off <span style="color: #DC2626;">*</span></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.01" min="0.01" name="quantity" id="badQuantity" class="search-box" style="flex: 1; height: 38px; border-radius: 8px;" placeholder="0" required>
                        <span id="badUnitIndicator" style="font-size: 12.5px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                    <small id="badStockHint" style="color: var(--gray); font-size: 11.5px; display: block; margin-top: 3px;">Select an item to view maximum write-off balance.</small>
                </div>

                <div>
                    <label for="badReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Incident Details / Cause <span style="color: #DC2626;">*</span></label>
                    <textarea name="reason" id="badReason" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px; resize: vertical;" placeholder="e.g. Pallet slipped during restack causing bottle breakage..." required></textarea>
                </div>

                <div style="font-size: 11.5px; color: #991B1B; background: #FEE2E2; border: 1px solid #FCA5A5; border-radius: 8px; padding: 8px 12px;">
                    <strong>Note:</strong> Submitting this report will immediately deduct the written-off quantity from active inventory.
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeReportBadProductModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Record Write-Off</button>
            </div>
        </form>
    </div>
</div>

<!-- 6. Approve Adjustment Modal -->
<div id="confirmApproveModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmApproveModalTitle" onclick="if(event.target === this) closeApproveModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=adjustment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve_adjustment">
            <input type="hidden" name="active_tab" value="adjustment">
            <input type="hidden" name="stock_adjustment_id" id="approveAdjId" value="">
            
            <div class="modal-header">
                <div>
                    <h3 id="confirmApproveModalTitle" class="card-title" style="margin: 0;">Approve Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Confirm variance and update warehouse inventory</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeApproveModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Are you sure you want to approve adjustment <strong id="approveTrfRef" style="font-family: monospace;">—</strong>?
                </p>
                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px; font-size: 13px;">
                    <div><strong>Item:</strong> <span id="approveItemName">—</span></div>
                    <div style="margin-top: 4px;"><strong>Variance:</strong> <span id="approveDiff">—</span></div>
                </div>
                <div style="font-size: 12px; color: #475569; background: #F1F5F9; border-radius: 8px; padding: 8px 12px;">
                    Approving this adjustment will post an immutable stock movement and update the warehouse balance.
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #15803D; border-color: #15803D;">Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- 7. Reject Adjustment Modal -->
<div id="confirmRejectModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmRejectModalTitle" onclick="if(event.target === this) closeRejectModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=adjustment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject_adjustment">
            <input type="hidden" name="active_tab" value="adjustment">
            <input type="hidden" name="stock_adjustment_id" id="rejectAdjId" value="">
            
            <div class="modal-header">
                <div>
                    <h3 id="confirmRejectModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Reject Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Decline count discrepancy correction</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeRejectModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Rejecting adjustment <strong id="rejectTrfRef" style="font-family: monospace;">—</strong> will close this record without altering any stock balances.
                </p>
                <div>
                    <label for="rejectReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Rejection Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="rejection_reason" id="rejectReason" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px;" placeholder="Reason for rejecting adjustment..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- 8. Cancel Approved Adjustment Modal -->
<div id="confirmCancelAdjModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmCancelAdjModalTitle" onclick="if(event.target === this) closeCancelAdjModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=adjustment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_adjustment">
            <input type="hidden" name="active_tab" value="adjustment">
            <input type="hidden" name="stock_adjustment_id" id="cancelAdjId" value="">
            
            <div class="modal-header">
                <div>
                    <h3 id="confirmCancelAdjModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Cancel Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Reverse approved adjustment and restore previous balance</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelAdjModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Cancelling adjustment <strong id="cancelAdjRef" style="font-family: monospace;">—</strong> will generate a reversing entry (`STOCK_ADJUSTMENT_CANCEL`) and restore the previous stock level.
                </p>
                <div>
                    <label for="cancelAdjReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelAdjReason" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px;" placeholder="Reason for cancelling approved adjustment..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelAdjModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- 9. Restore Damaged Product Modal -->
<div id="confirmCancelBadModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmCancelBadModalTitle" onclick="if(event.target === this) closeCancelBadModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=adjustment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_bad_product">
            <input type="hidden" name="active_tab" value="adjustment">
            <input type="hidden" name="bad_product_id" id="cancelBadId" value="">
            
            <div class="modal-header">
                <div>
                    <h3 id="confirmCancelBadModalTitle" class="card-title" style="margin: 0; color: #15803D;">Restore Damaged Product Stock</h3>
                    <p class="card-desc" style="margin: 0;">Cancel write-off report and return items to inventory</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelBadModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Cancel write-off <strong id="cancelBadRef" style="font-family: monospace;">—</strong> and restore stock to active inventory?
                </p>
                <div>
                    <label for="cancelBadReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation / Recovery Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelBadReason" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px;" placeholder="e.g. Logged in error; items passed secondary QC inspection..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelBadModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #15803D; border-color: #15803D;">Confirm &amp; Restore Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- 10. Adjustment Details Modal -->
<div id="adjustmentDetailModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalAdjTitle" onclick="if(event.target === this) closeAdjDetailModal()">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <div>
                <h3 id="modalAdjTitle" class="card-title" style="margin: 0;">Adjustment Details</h3>
                <p class="card-desc" style="margin: 0;">Physical count discrepancy audit trail</p>
            </div>
            <button type="button" class="modal-close" aria-label="Close modal" onclick="closeAdjDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
            <div class="form-grid-2" style="gap: 10px; font-size: 13px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px;">
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Reference</span>
                    <strong id="modalAdjRef" style="font-family: monospace;">—</strong>
                </div>
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Status</span>
                    <span id="modalAdjStatus">—</span>
                </div>
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Logged By</span>
                    <span id="modalAdjUser">—</span>
                </div>
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Date</span>
                    <span id="modalAdjDate">—</span>
                </div>
            </div>

            <div style="background: #F1F5F9; border-radius: 8px; padding: 10px 12px; font-size: 12.5px;">
                <strong style="color: #475569; display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 2px;">Item &amp; Discrepancy:</strong>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                    <div>
                        <strong id="modalAdjItemName">—</strong>
                        <div id="modalAdjItemCode" style="font-family: monospace; font-size: 11px; color: var(--gray);">—</div>
                    </div>
                    <div style="text-align: right;">
                        <span id="modalAdjDiff" style="font-size: 14px; font-weight: 700;">—</span>
                    </div>
                </div>
                <div style="margin-top: 8px; font-size: 12px; color: #475569;">
                    Previous System: <span id="modalAdjPrev">—</span> &rarr; Adjusted Physical: <span id="modalAdjCount">—</span>
                </div>
            </div>

            <div style="font-size: 12.5px;">
                <strong style="color: var(--panel-ink); display: block; margin-bottom: 2px;">Reason / Notes:</strong>
                <div id="modalAdjReason" style="color: var(--gray); background: #FAF5FF; border: 1px solid #E9D5FF; border-radius: 8px; padding: 8px 12px;">—</div>
            </div>

            <div id="modalAdjCancelContainer" style="font-size: 12.5px; display: none;">
                <strong id="modalAdjCancelLabel" style="color: #B91C1C; display: block; margin-bottom: 2px;">Cancellation / Rejection Reason:</strong>
                <div id="modalAdjCancelReason" style="color: #991B1B; font-weight: 600; background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 8px 12px;">—</div>
            </div>

            <div id="modalAdjAuditRow" style="font-size: 11.5px; color: var(--gray);"></div>
        </div>
        <div id="modalAdjFooter" class="modal-footer" style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div id="modalAdjActionBtns" style="display: flex; align-items: center; gap: 8px;"></div>
            <button type="button" class="btn btn-secondary" onclick="closeAdjDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- 11. Cancel Stock Transfer Modal -->
<div id="confirmCancelTransferModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirmCancelTransferModalTitle" onclick="if(event.target === this) closeCancelTransferModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=transfer">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_transfer">
            <input type="hidden" name="active_tab" value="transfer">
            <input type="hidden" name="stock_transfer_id" id="cancelTransferId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="confirmCancelTransferModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Cancel Stock Transfer</h3>
                    <p class="card-desc" style="margin: 0;">Reverse transfer and restore stock to source warehouse</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelTransferModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Are you sure you want to cancel transfer <strong id="cancelTransferRef" style="font-family: monospace;">—</strong>?
                </p>
                <div>
                    <label for="cancelTransferReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelTransferReason" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px;" placeholder="Reason for cancelling this stock transfer..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelTransferModal()">Close</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT LOGIC                                                          -->
<!-- ========================================================================= -->
<script>
// Protect creation modals from accidental backdrop-click dismissal when forms contain unsaved input
function handleOpsBackdropClose(event, modalEl, closeFn) {
    if (event.target !== modalEl) return;
    const form = modalEl.querySelector('form');
    if (form) {
        const hasInput = Array.from(form.querySelectorAll('input[type="number"], textarea, select')).some(el => {
            if (el.id === 'adjEntryMode' || el.id === 'badConditionSelect') return false;
            return el.value && el.value.trim() !== '';
        });
        if (hasInput && !confirm('Discard unsaved entries and close this modal?')) {
            return;
        }
    }
    closeFn();
}

// Tab Switching Controller
function switchStockTab(tabName) {
    const validTabs = ['transfer', 'adjustment', 'stock_card'];
    if (!validTabs.includes(tabName)) tabName = 'transfer';

    // Update Tab Buttons
    validTabs.forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        if (btn) {
            if (t === tabName) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
    });

    // Update Tab Panes
    validTabs.forEach(t => {
        const pane = document.getElementById('pane-' + t);
        if (pane) {
            pane.style.display = (t === tabName) ? 'block' : 'none';
        }
    });

    // Sync URL without reloading
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url.toString());
}

// -----------------------------------------------------------------------------
// Stock Transfer JS
// -----------------------------------------------------------------------------
const trfLinesData = <?= json_encode($linesByTransfer) ?>;

function openCancelTransferModal(trf) {
    if (!trf) return;
    document.getElementById('cancelTransferId').value = trf.stock_transfer_id;
    document.getElementById('cancelTransferRef').textContent = trf.transaction_number;
    document.getElementById('cancelTransferReason').value = '';
    document.getElementById('confirmCancelTransferModal').style.display = 'flex';
}

function closeCancelTransferModal() {
    document.getElementById('confirmCancelTransferModal').style.display = 'none';
}

function openConfirmReceiptModal(trf) {
    if (!trf) return;
    document.getElementById('confirmReceiptId').value = trf.stock_transfer_id;
    document.getElementById('confirmTrfRef').textContent = trf.transaction_number;
    document.getElementById('confirmTrfFrom').textContent = (trf.src_code || '') + ' - ' + (trf.src_name || '');
    
    const itemName = ((parseInt(trf.total_items) || 0) > 1) 
        ? (trf.total_items + ' items (' + (trf.items_summary || '') + ')') 
        : (trf.first_item_name || 'Item');
    document.getElementById('confirmTrfItem').textContent = itemName;
    
    const unit = trf.first_unit || '';
    document.getElementById('confirmTrfQty').textContent = '+' + Number(parseFloat(trf.total_quantity).toFixed(2)) + ' ' + unit;

    document.getElementById('confirmReceiptModal').style.display = 'flex';
}

function closeConfirmReceiptModal() {
    document.getElementById('confirmReceiptModal').style.display = 'none';
}

function openTransferDetailModal(trf, direction) {
    if (!trf) return;

    document.getElementById('modalTrfTitle').textContent = 'Transfer ' + trf.transaction_number;
    document.getElementById('modalTrfRef').textContent = trf.transaction_number;
    
    let statusBadge = '<span class="badge status-completed">Received</span>';
    if (trf.status === 'in_transit' || trf.status === 'pending') {
        statusBadge = '<span class="badge status-pending">In Transit</span>';
    } else if (trf.status === 'cancelled') {
        statusBadge = '<span class="badge status-cancelled">Cancelled</span>';
    }

    document.getElementById('modalTrfStatus').innerHTML = statusBadge;

    document.getElementById('modalTrfSrc').textContent = (trf.src_code || '') + ' - ' + (trf.src_name || '');
    document.getElementById('modalTrfDest').textContent = (trf.dest_code || '') + ' - ' + (trf.dest_name || '');
    
    const dateFormatted = trf.created_at ? trf.created_at : (trf.transaction_date || '—');
    document.getElementById('modalTrfDate').textContent = dateFormatted;
    document.getElementById('modalTrfUser').textContent = trf.requested_by || '—';

    const remarksElem = document.getElementById('modalTrfRemarks');
    const remarksCont = document.getElementById('modalTrfRemarksContainer');
    if (trf.remarks && trf.remarks.trim() !== '') {
        remarksElem.textContent = trf.remarks;
        remarksCont.style.display = 'block';
    } else {
        remarksCont.style.display = 'none';
    }

    const cancelElem = document.getElementById('modalTrfCancelReason');
    const cancelCont = document.getElementById('modalTrfCancelContainer');
    if (cancelElem && cancelCont) {
        if (trf.cancellation_reason && trf.cancellation_reason.trim() !== '') {
            cancelElem.textContent = trf.cancellation_reason;
            cancelCont.style.display = 'block';
        } else {
            cancelCont.style.display = 'none';
        }
    }

    const tbody = document.getElementById('modalTrfTableBody');
    tbody.innerHTML = '';

    const lines = trfLinesData[trf.stock_transfer_id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--gray); padding: 16px;">No line items recorded for this transfer.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            const qtySign = direction === 'inbound' ? '+' : '-';
            const qtyColor = direction === 'inbound' ? '#15803D' : '#1D4ED8';
            tr.innerHTML = `
                <td style="font-family: monospace; font-weight: 700;">${escapeHtml(l.item_code)}</td>
                <td><strong>${escapeHtml(l.item_name)}</strong></td>
                <td><span class="badge-type ${l.item_type === 'finished_good' ? 'type-fg' : 'type-raw'}">${escapeHtml(l.item_type.replace('_', ' '))}</span></td>
                <td style="font-weight: 700; color: ${qtyColor}; text-align: right;">${qtySign}${formatQty(l.quantity)} <small style="color: var(--gray); font-weight: normal;">${escapeHtml(l.unit)}</small></td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('transferModal').style.display = 'flex';
}

function closeTransferDetailModal() {
    document.getElementById('transferModal').style.display = 'none';
}

function openNewTransferModal() {
    document.getElementById('newTransferModal').style.display = 'flex';
}

function closeNewTransferModal() {
    document.getElementById('newTransferModal').style.display = 'none';
}

function handleTransferItemChange(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const unitSpan = document.getElementById('unitIndicator');
    const hintDiv = document.getElementById('availStockHint');
    const qtyInput = document.getElementById('transferQuantity');

    if (selectedOption && selectedOption.dataset.stock) {
        const maxStock = parseFloat(selectedOption.dataset.stock);
        const unit = selectedOption.dataset.unit || '';
        unitSpan.textContent = unit;
        hintDiv.innerHTML = `Available in <strong><?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?></strong>: <strong>${Number(maxStock.toFixed(2))} ${unit}</strong>`;
        qtyInput.max = maxStock;
        qtyInput.placeholder = `Max ${Number(maxStock.toFixed(2))}`;
    } else {
        unitSpan.textContent = '—';
        hintDiv.textContent = 'Select an item to view maximum transferable balance.';
        qtyInput.removeAttribute('max');
        qtyInput.placeholder = '0';
    }
}

// -----------------------------------------------------------------------------
// Stock Adjustment JS
// -----------------------------------------------------------------------------
let currentSelectedStock = 0;
let currentSelectedUnit  = '';

function openNewAdjustmentModal() {
    document.getElementById('newAdjustmentModal').style.display = 'flex';
}
function closeNewAdjustmentModal() {
    document.getElementById('newAdjustmentModal').style.display = 'none';
}

function handleAdjModeChange() {
    const modeSelect = document.getElementById('adjEntryMode');
    const label = document.getElementById('adjCountLabel');
    const input = document.getElementById('adjInputQty');
    if (!modeSelect || !label || !input) return;

    const mode = modeSelect.value;
    if (mode === 'add') {
        label.innerHTML = 'Quantity to Add (+) <span style="color: #DC2626;">*</span>';
        input.min = '0.01';
        input.placeholder = 'Enter surplus quantity';
    } else if (mode === 'deduct') {
        label.innerHTML = 'Quantity to Deduct (-) <span style="color: #DC2626;">*</span>';
        input.min = '0.01';
        input.placeholder = 'Enter shortage quantity';
    } else {
        label.innerHTML = 'Actual Physical Count <span style="color: #DC2626;">*</span>';
        input.min = '0';
        input.placeholder = '0';
    }
    calcAdjDiff();
}

function handleAdjItemChange(select) {
    const option = select.options[select.selectedIndex];
    if (!option || !option.value) {
        currentSelectedStock = 0;
        currentSelectedUnit  = '';
        document.getElementById('adjPrevStock').textContent = '—';
        document.getElementById('adjDiffContainer').style.display = 'none';
        return;
    }
    currentSelectedStock = parseFloat(option.getAttribute('data-stock')) || 0;
    currentSelectedUnit  = option.getAttribute('data-unit') || '';

    document.getElementById('adjPrevStock').textContent = Number(currentSelectedStock.toFixed(2)) + ' ' + currentSelectedUnit;
    calcAdjDiff();
}

function calcAdjDiff() {
    const inputEl = document.getElementById('adjInputQty');
    const hiddenPhysicalEl = document.getElementById('adjPhysicalCount');
    const modeSelect = document.getElementById('adjEntryMode');
    const container = document.getElementById('adjDiffContainer');
    const valElem = document.getElementById('adjDiffValue');
    const descElem = document.getElementById('adjDiffDesc');

    const inputVal = inputEl ? inputEl.value : '';
    if (inputVal === '' || isNaN(inputVal)) {
        container.style.display = 'none';
        return;
    }

    const rawQty = parseFloat(inputVal);
    const mode = modeSelect ? modeSelect.value : 'physical';
    let physical = rawQty;
    if (mode === 'add') {
        physical = currentSelectedStock + rawQty;
    } else if (mode === 'deduct') {
        physical = Math.max(0, currentSelectedStock - rawQty);
    }

    if (hiddenPhysicalEl) {
        hiddenPhysicalEl.value = Number(physical.toFixed(2));
    }

    const diff = physical - currentSelectedStock;
    container.style.display = 'block';

    if (diff > 0) {
        valElem.textContent = '+' + Number(diff.toFixed(2)) + ' ' + currentSelectedUnit;
        valElem.style.color = '#15803D';
        descElem.textContent = 'Surplus: New balance will be ' + Number(physical.toFixed(2)) + ' ' + currentSelectedUnit + ' upon approval.';
    } else if (diff < 0) {
        valElem.textContent = Number(diff.toFixed(2)) + ' ' + currentSelectedUnit;
        valElem.style.color = '#B91C1C';
        descElem.textContent = 'Shortage / Loss: New balance will be ' + Number(physical.toFixed(2)) + ' ' + currentSelectedUnit + ' upon approval.';
    } else {
        valElem.textContent = '0.00 ' + currentSelectedUnit;
        valElem.style.color = '#475569';
        descElem.textContent = 'No variance: Physical count exactly matches recorded inventory balance.';
    }
}

function openReportBadProductModal() {
    document.getElementById('reportBadProductModal').style.display = 'flex';
}
function closeReportBadProductModal() {
    document.getElementById('reportBadProductModal').style.display = 'none';
}

function handleBadItemChange(select) {
    const option = select.options[select.selectedIndex];
    const qtyInput = document.getElementById('badQuantity');
    const unitInd = document.getElementById('badUnitIndicator');
    const hint = document.getElementById('badStockHint');

    if (!option || !option.value) {
        unitInd.textContent = '—';
        hint.textContent = 'Select an item to view maximum write-off balance.';
        qtyInput.removeAttribute('max');
        return;
    }

    const stock = parseFloat(option.getAttribute('data-stock')) || 0;
    const unit = option.getAttribute('data-unit') || '';
    unitInd.textContent = unit;
    hint.textContent = 'Maximum transferable/deductible stock: ' + Number(stock.toFixed(2)) + ' ' + unit;
    qtyInput.max = stock;
}

function openApproveModal(row) {
    if (!row) return;
    document.getElementById('approveAdjId').value = row.stock_adjustment_id;
    document.getElementById('approveTrfRef').textContent = row.transaction_number;
    document.getElementById('approveItemName').textContent = row.item_name || 'Item';
    
    const diff = parseFloat(row.difference) || 0;
    const unit = row.unit || '';
    document.getElementById('approveDiff').textContent = (diff >= 0 ? '+' : '') + Number(diff.toFixed(2)) + ' ' + unit;
    document.getElementById('approveDiff').style.color = (diff >= 0) ? '#15803D' : '#B91C1C';

    document.getElementById('confirmApproveModal').style.display = 'flex';
}
function closeApproveModal() {
    document.getElementById('confirmApproveModal').style.display = 'none';
}

function openRejectModal(row) {
    if (!row) return;
    document.getElementById('rejectAdjId').value = row.stock_adjustment_id;
    document.getElementById('rejectTrfRef').textContent = row.transaction_number;
    document.getElementById('confirmRejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('confirmRejectModal').style.display = 'none';
}

function openCancelAdjModal(row) {
    if (!row) return;
    document.getElementById('cancelAdjId').value = row.stock_adjustment_id;
    document.getElementById('cancelAdjRef').textContent = row.transaction_number;
    document.getElementById('confirmCancelAdjModal').style.display = 'flex';
}
function closeCancelAdjModal() {
    document.getElementById('confirmCancelAdjModal').style.display = 'none';
}

function openCancelBadModal(row) {
    if (!row) return;
    document.getElementById('cancelBadId').value = row.bad_product_id;
    document.getElementById('cancelBadRef').textContent = row.bad_product_number;
    document.getElementById('confirmCancelBadModal').style.display = 'flex';
}
function closeCancelBadModal() {
    document.getElementById('confirmCancelBadModal').style.display = 'none';
}

let currentDetailAdjRow = null;

function openAdjDetailModal(row) {
    if (!row) return;
    currentDetailAdjRow = row;
    document.getElementById('modalAdjTitle').textContent = 'Adjustment ' + row.transaction_number;
    document.getElementById('modalAdjRef').textContent = row.transaction_number;
    
    let badge = '<span class="badge status-pending">Pending</span>';
    if (row.status === 'approved') badge = '<span class="badge status-completed">Approved</span>';
    else if (row.status === 'rejected') badge = '<span class="badge status-cancelled">Rejected</span>';
    else if (row.status === 'cancelled') badge = '<span class="badge status-cancelled">Cancelled</span>';
    document.getElementById('modalAdjStatus').innerHTML = badge;

    document.getElementById('modalAdjUser').textContent = row.logged_by || '—';
    document.getElementById('modalAdjDate').textContent = row.adjustment_date || '—';

    document.getElementById('modalAdjItemName').textContent = row.item_name || '—';
    document.getElementById('modalAdjItemCode').textContent = row.item_code || '';

    const diff = parseFloat(row.difference) || 0;
    const unit = row.unit || '';
    document.getElementById('modalAdjDiff').textContent = (diff >= 0 ? '+' : '') + Number(diff.toFixed(2)) + ' ' + unit;
    document.getElementById('modalAdjDiff').style.color = (diff >= 0) ? '#15803D' : '#B91C1C';

    document.getElementById('modalAdjPrev').textContent = Number((parseFloat(row.previous_quantity) || 0).toFixed(2)) + ' ' + unit;
    document.getElementById('modalAdjCount').textContent = Number((parseFloat(row.adjusted_quantity) || 0).toFixed(2)) + ' ' + unit;

    document.getElementById('modalAdjReason').textContent = row.reason || 'No notes provided';

    const cancelCont = document.getElementById('modalAdjCancelContainer');
    const cancelLabel = document.getElementById('modalAdjCancelLabel');
    const cancelReasonEl = document.getElementById('modalAdjCancelReason');
    if (cancelCont && cancelReasonEl) {
        if (row.cancellation_reason && row.cancellation_reason.trim() !== '') {
            if (cancelLabel) {
                cancelLabel.textContent = (row.status === 'rejected') ? 'Rejection Reason:' : 'Cancellation Reason:';
            }
            cancelReasonEl.textContent = row.cancellation_reason;
            cancelCont.style.display = 'block';
        } else {
            cancelCont.style.display = 'none';
        }
    }

    let audit = '';
    if (row.approved_by_name) audit += 'Approved by: ' + row.approved_by_name;
    if (row.cancelled_by_name) {
        const actionWord = (row.status === 'rejected') ? 'Rejected by: ' : 'Cancelled by: ';
        audit += (audit ? ' &middot; ' : '') + actionWord + row.cancelled_by_name;
    }
    document.getElementById('modalAdjAuditRow').innerHTML = audit;

    const actionBtns = document.getElementById('modalAdjActionBtns');
    const footerEl = document.getElementById('modalAdjFooter');
    if (actionBtns) {
        if (row.status === 'pending') {
            actionBtns.innerHTML = `
                <button type="button" class="btn btn-primary" style="background: #15803D; border-color: #15803D;" onclick="closeAdjDetailModal(); openApproveModal(currentDetailAdjRow);">
                    Approve
                </button>
                <button type="button" class="btn btn-secondary" style="color: #B91C1C; border-color: #FCA5A5;" onclick="closeAdjDetailModal(); openRejectModal(currentDetailAdjRow);">
                    Reject
                </button>
            `;
        } else if (row.status === 'approved') {
            actionBtns.innerHTML = `
                <button type="button" class="btn btn-secondary" style="color: #B91C1C; border-color: #FCA5A5;" onclick="closeAdjDetailModal(); openCancelAdjModal(currentDetailAdjRow);">
                    Cancel Adjustment
                </button>
            `;
        } else {
            actionBtns.innerHTML = '';
        }
        if (footerEl) footerEl.style.display = 'flex';
    }

    document.getElementById('adjustmentDetailModal').style.display = 'flex';
}
function closeAdjDetailModal() {
    document.getElementById('adjustmentDetailModal').style.display = 'none';
}

// -----------------------------------------------------------------------------
// Stock Card JS
// -----------------------------------------------------------------------------
const allInventoryItems = <?= json_encode(array_map(function($it) {
    return [
        'id'   => (int)$it['item_id'],
        'code' => (string)$it['item_code'],
        'name' => (string)$it['item_name'],
        'type' => (string)$it['item_type'],
        'unit' => (string)($it['unit'] ?? '')
    ];
}, $cardItems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let currentHighlightedIndex = -1;
let currentFilteredItems = [];

function highlightMatches(text, query) {
    if (!query || !text) return escapeHtml(text || '');
    const escapedText = escapeHtml(text);
    const escapedQuery = escapeHtml(query);
    const regex = new RegExp('(' + escapedQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return escapedText.replace(regex, '<span class="search-match-highlight">$1</span>');
}

function renderItemDropdown(matches, query) {
    const list = document.getElementById('itemDropdownList');
    if (!list) return;
    currentFilteredItems = matches;
    currentHighlightedIndex = -1;

    if (!matches || matches.length === 0) {
        list.innerHTML = '<div style="padding: 14px 16px; text-align: center; color: var(--gray); font-size: 13px; font-weight: 600;">No inventory items found.</div>';
        list.style.display = 'block';
        return;
    }

    const selectedId = parseInt(document.getElementById('selectedItemId').value, 10);

    let html = '';
    matches.forEach((item, index) => {
        const isSelected = (item.id === selectedId);
        const typeBadge = item.type === 'finished_good' 
            ? '<span class="badge-type type-fg" style="font-size: 10px; padding: 2px 6px;">Finished</span>'
            : '<span class="badge-type type-raw" style="font-size: 10px; padding: 2px 6px;">Raw</span>';

        html += `
            <div class="item-result-row ${isSelected ? 'selected' : ''}" 
                 id="item-opt-${index}"
                 role="option"
                 aria-selected="${isSelected ? 'true' : 'false'}"
                 data-index="${index}"
                 onclick="selectInventoryItem(${item.id})">
                <div style="display: flex; flex-direction: column;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-weight: 700; color: var(--panel-ink); font-size: 13px;">${highlightMatches(item.name, query)}</span>
                        <span style="color: var(--gray); font-size: 12px;">—</span>
                        <span style="font-family: monospace; font-size: 12px; font-weight: 600; color: #475569;">${highlightMatches(item.code, query)}</span>
                    </div>
                    <small style="color: var(--gray); font-size: 11px;">Item ID: ${item.id} ${item.unit ? '&middot; Unit: ' + escapeHtml(item.unit) : ''}</small>
                </div>
                ${typeBadge}
            </div>
        `;
    });

    list.innerHTML = html;
    list.style.display = 'block';
}

function filterItemDropdown(query) {
    const q = (query || '').trim().toLowerCase();
    const clearBtn = document.getElementById('clearItemSearchBtn');
    if (clearBtn) {
        clearBtn.style.display = (query && query.length > 0) ? 'inline-block' : 'none';
    }

    if (!q) {
        renderItemDropdown(allInventoryItems, '');
        return;
    }

    const matches = allInventoryItems.filter(item => {
        const nameMatch = (item.name || '').toLowerCase().includes(q);
        const codeMatch = (item.code || '').toLowerCase().includes(q);
        const idMatch   = String(item.id).includes(q) || ('rm-' + item.id).includes(q) || ('fg-' + item.id).includes(q);
        return nameMatch || codeMatch || idMatch;
    });

    renderItemDropdown(matches, query);
}

function openItemDropdown() {
    const input = document.getElementById('itemSearchInput');
    if (!input) return;
    input.setAttribute('aria-expanded', 'true');
    input.select();
    filterItemDropdown('');
}

function closeItemDropdown() {
    const list = document.getElementById('itemDropdownList');
    if (list) {
        list.style.display = 'none';
    }
    const input = document.getElementById('itemSearchInput');
    if (input) {
        input.setAttribute('aria-expanded', 'false');
    }
    currentHighlightedIndex = -1;
}

function selectInventoryItem(itemId) {
    const item = allInventoryItems.find(it => it.id === itemId);
    if (!item) return;

    document.getElementById('selectedItemId').value = item.id;
    document.getElementById('itemSearchInput').value = item.name + ' — ' + item.code;
    const clearBtn = document.getElementById('clearItemSearchBtn');
    if (clearBtn) clearBtn.style.display = 'inline-block';
    closeItemDropdown();

    document.getElementById('stockCardForm').submit();
}

function clearItemSearch() {
    const input = document.getElementById('itemSearchInput');
    if (!input) return;
    input.value = '';
    const clearBtn = document.getElementById('clearItemSearchBtn');
    if (clearBtn) clearBtn.style.display = 'none';
    input.focus();
    filterItemDropdown('');
}

function restoreSelectedItemDisplay() {
    const selectedId = parseInt(document.getElementById('selectedItemId').value, 10);
    const item = allInventoryItems.find(it => it.id === selectedId);
    if (item) {
        document.getElementById('itemSearchInput').value = item.name + ' — ' + item.code;
        const clearBtn = document.getElementById('clearItemSearchBtn');
        if (clearBtn) clearBtn.style.display = 'inline-block';
    }
}

function handleItemDropdownKeydown(e) {
    const list = document.getElementById('itemDropdownList');
    const isOpen = (list && list.style.display === 'block');

    if (!isOpen) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            openItemDropdown();
            e.preventDefault();
        }
        return;
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (currentFilteredItems.length === 0) return;
        currentHighlightedIndex = (currentHighlightedIndex + 1) % currentFilteredItems.length;
        updateHighlightedRow();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (currentFilteredItems.length === 0) return;
        currentHighlightedIndex = (currentHighlightedIndex - 1 + currentFilteredItems.length) % currentFilteredItems.length;
        updateHighlightedRow();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (currentHighlightedIndex >= 0 && currentHighlightedIndex < currentFilteredItems.length) {
            selectInventoryItem(currentFilteredItems[currentHighlightedIndex].id);
        } else if (currentFilteredItems.length === 1) {
            selectInventoryItem(currentFilteredItems[0].id);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        closeItemDropdown();
        restoreSelectedItemDisplay();
    }
}

function updateHighlightedRow() {
    document.querySelectorAll('.searchable-dropdown-list .item-result-row').forEach((row, idx) => {
        if (idx === currentHighlightedIndex) {
            row.classList.add('highlighted');
            row.scrollIntoView({ block: 'nearest' });
        } else {
            row.classList.remove('highlighted');
        }
    });
}

// Click outside listener for item search dropdown
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('itemSearchWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        const list = document.getElementById('itemDropdownList');
        if (list && list.style.display === 'block') {
            closeItemDropdown();
            restoreSelectedItemDisplay();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
