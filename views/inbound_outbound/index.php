<?php
/*
 * Inbound and outbound stock page. Lets staff record incoming stock receipts,
 * dispatch outgoing stock, and manage inbound and outbound orders.
 */

$pageTitle   = 'Inventory Inbound & Outbound — InventoryTeam';
$activePage  = 'inbound_outbound';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';
require_once __DIR__ . '/../../helpers/OrderService.php';

$successMessage = null;
$errorMessage   = null;

$activeTab = 'inbound';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['inbound', 'outbound'], true)) {
    $activeTab = $_GET['tab'];
} elseif (isset($_POST['active_tab']) && in_array($_POST['active_tab'], ['inbound', 'outbound'], true)) {
    $activeTab = $_POST['active_tab'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken()) {
        $errorMessage = "Your session expired. Please refresh the page and try again.";
    } else {
        $stockService = new StockService();
        $userId       = (int)($currentUser['id'] ?? 1);

        try {
            switch ($action) {

                case 'cancel_inbound_order':
                    $activeTab = 'inbound';
                    $orderId = (int)($_POST['inbound_order_id'] ?? 0);
                    $reason  = trim($_POST['cancellation_reason'] ?? '');
                    if ($orderId <= 0) {
                        throw new InvalidArgumentException("Invalid Inbound Order ID.");
                    }
                    if (empty($reason)) {
                        throw new InvalidArgumentException("Rejection reason is required.");
                    }
                    $orderService = new OrderService($pdo);
                    $res = $orderService->cancelInboundOrder($orderId, $userId, $reason, $currentUser);
                    $successMessage = "Incoming order " . htmlspecialchars($res['order_number']) . " has been rejected.";
                    break;

                case 'cancel_outbound_order':
                    $activeTab = 'outbound';
                    $orderId = (int)($_POST['outbound_order_id'] ?? 0);
                    $reason  = trim($_POST['cancellation_reason'] ?? '');
                    if ($orderId <= 0) {
                        throw new InvalidArgumentException("Invalid Outbound Order ID.");
                    }
                    if (empty($reason)) {
                        throw new InvalidArgumentException("Cancellation reason is required.");
                    }
                    $orderService = new OrderService($pdo);
                    $res = $orderService->cancelOutboundOrder($orderId, $userId, $reason, $currentUser);
                    $successMessage = "Order " . htmlspecialchars($res['order_number']) . " has been cancelled.";
                    break;

                case 'cancel_stock_out':
                    $activeTab  = 'outbound';
                    $stockOutId = (int)($_POST['stock_out_id'] ?? 0);
                    $reason     = trim($_POST['cancellation_reason'] ?? '');
                    if ($stockOutId <= 0) {
                        throw new InvalidArgumentException("Invalid Stock Out transaction ID.");
                    }
                    $res = $stockService->cancelStockOut($stockOutId, $reason, $userId, $currentUser);
                    $successMessage = "Order dispatch cancelled and stock returned to inventory.";
                    break;

                case 'receive_inbound_order':
                    $activeTab = 'inbound';
                    $orderId = (int)($_POST['inbound_order_id'] ?? 0);
                    $receivedQtys = $_POST['received_quantity'] ?? [];
                    $damagedQtys  = $_POST['damaged_quantity'] ?? [];
                    $condition = trim($_POST['item_condition'] ?? 'salable');
                    $remarks = trim($_POST['remarks'] ?? '');
                    if ($orderId <= 0) {
                        throw new InvalidArgumentException("Invalid inbound order ID.");
                    }
                    if ($condition === 'mixed') {
                        $stmtRem = $pdo->prepare("SELECT item_id, (expected_quantity - received_quantity) AS remaining FROM inbound_order_items WHERE inbound_order_id = ?");
                        $stmtRem->execute([$orderId]);
                        $remainingMap = [];
                        foreach ($stmtRem->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                            $remainingMap[(int)$rRow['item_id']] = (float)$rRow['remaining'];
                        }

                        $salableToPost = [];
                        $damagedToPost = [];
                        $hasSalable = false;
                        $hasDamaged = false;

                        foreach ($remainingMap as $itemId => $remQty) {
                            $sQty = max(0.0, (float)($receivedQtys[$itemId] ?? 0));
                            $dQty = max(0.0, (float)($damagedQtys[$itemId] ?? 0));
                            if (($sQty + $dQty) > ($remQty + 0.0001)) {
                                throw new InvalidArgumentException("Combined salable ({$sQty}) and damaged ({$dQty}) quantity exceeds remaining expected quantity ({$remQty}) for item ID {$itemId}.");
                            }
                            if ($sQty > 0) {
                                $salableToPost[$itemId] = $sQty;
                                $hasSalable = true;
                            }
                            if ($dQty > 0) {
                                $damagedToPost[$itemId] = $dQty;
                                $hasDamaged = true;
                            }
                        }

                        if (!$hasSalable && !$hasDamaged) {
                            throw new InvalidArgumentException("Please enter at least one salable or damaged quantity to receive.");
                        }

                        $parts = [];
                        $orderNumber = '';
                        if ($hasSalable) {
                            $resSalable = $stockService->receiveInboundOrder($orderId, $salableToPost, $userId, $remarks ?: null, $currentUser, 'salable');
                            $orderNumber = $resSalable['order_number'];
                            $parts[] = "good items added to stock";
                        }
                        if ($hasDamaged) {
                            $resDamaged = $stockService->receiveInboundOrder($orderId, $damagedToPost, $userId, $remarks ?: null, $currentUser, 'damaged');
                            $orderNumber = $resDamaged['order_number'];
                            $parts[] = "damaged items recorded in Bad Products";
                        }
                        $successMessage = "Order " . htmlspecialchars($orderNumber) . " received: " . implode(' and ', $parts) . ".";
                    } else {
                        $res = $stockService->receiveInboundOrder($orderId, $receivedQtys, $userId, $remarks ?: null, $currentUser, $condition);
                        $successMessage = "Order " . htmlspecialchars($res['order_number']) . " received! " . 
                            ($res['condition'] === 'damaged' ? "Damaged items were added to Bad Products." : "Items added to stock.");
                    }
                    break;

                case 'dispatch_outbound_order':
                    $activeTab = 'outbound';
                    $orderId = (int)($_POST['outbound_order_id'] ?? 0);
                    $dispatchedQtys = $_POST['dispatched_quantity'] ?? [];
                    $remarks = trim($_POST['remarks'] ?? '');
                    if ($orderId <= 0) {
                        throw new InvalidArgumentException("Invalid outbound order ID.");
                    }
                    $res = $stockService->dispatchOutboundOrder($orderId, $dispatchedQtys, $userId, $remarks ?: null, $currentUser);
                    $successMessage = "Order " . htmlspecialchars($res['order_number']) . " has been approved and dispatched!";
                    break;

                default:
                    break;
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$allItemsStmt = $pdo->prepare("
    SELECT i.item_id, i.code AS item_code, i.name AS item_name, i.type AS item_type, i.unit, COALESCE(s.qty_on_hand, 0.000) AS current_stock
    FROM items i 
    LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = :wid
    WHERE i.status = 'active' 
    ORDER BY i.type ASC, i.name ASC
");
$allItemsStmt->execute([':wid' => $currentWarehouseId]);
$allItems = $allItemsStmt->fetchAll(PDO::FETCH_ASSOC);

$stmtIn = $pdo->prepare("
    SELECT 
        sm.movement_id AS stock_in_id,
        CONCAT('IN-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        COALESCE(
            NULLIF(sm.source_type, ''),
            CASE 
                WHEN sm.remarks LIKE '%CUSTOMER_RETURN%' OR sm.remarks LIKE '%RMA%' OR sm.reference_no LIKE 'RMA-%' OR sm.reference_number LIKE 'RMA-%' THEN 'CUSTOMER_RETURN'
                WHEN i.type IN ('finished_good', 'Finished Good') OR sm.reference_type = 'PROD_RECEIPT' OR sm.remarks LIKE '%Finished Goods%' THEN 'PRODUCTION_RECEIPT'
                WHEN sm.remarks LIKE '%PO%' OR sm.remarks LIKE '%PURCHASE%' THEN 'PURCHASE_ORDER'
                ELSE 'PURCHASE_ORDER'
            END
        ) AS source_type,
        COALESCE(
            NULLIF(sm.reference_no, ''),
            NULLIF(sm.reference_number, ''),
            NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(sm.remarks, ']', 1), '[', -1), ''),
            CONCAT('REF-', sm.movement_id)
        ) AS source_reference_no,
        DATE(sm.created_at) AS transaction_date,
        CASE WHEN rev.movement_id IS NOT NULL THEN 'cancelled' ELSE 'completed' END AS status,
        sm.remarks,
        CASE 
            WHEN rev.movement_id IS NOT NULL THEN 
                COALESCE(NULLIF(SUBSTRING(rev.remarks, LOCATE(': ', rev.remarks) + 2), ''), rev.remarks)
            ELSE NULL 
        END AS cancellation_reason,
        rev.created_at AS cancelled_at,
        sm.created_at,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(u.name, 'Warehouse Operator') AS operator_name,
        1 AS total_item_count,
        sm.quantity AS total_quantity,
        CONCAT(i.name, ' (', ROUND(sm.quantity, 0), ' ', i.unit, ')') AS item_breakdown,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    LEFT JOIN stock_movements rev ON rev.reference_id = sm.movement_id 
        AND (rev.movement_type = 'CANCELLED_INBOUND' OR (rev.movement_type = 'STOCK_OUT' AND rev.remarks LIKE 'Cancelled Stock IN %'))
    WHERE sm.warehouse_id = :wid 
      AND sm.movement_type IN ('STOCK_IN', 'IN')
      AND (sm.remarks IS NULL OR (
          sm.remarks NOT LIKE 'Cancelled Stock OUT %'
          AND sm.remarks NOT LIKE '[INITIAL_BALANCE]%'
          AND sm.remarks NOT LIKE 'Customer RMA Inbound (%'
      ))
    ORDER BY sm.movement_id DESC
");
$stmtIn->execute([':wid' => $currentWarehouseId]);
$stockIns = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

$linesByStockIn = [];
foreach ($stockIns as $row) {
    $linesByStockIn[(int)$row['stock_in_id']][] = [
        'stock_in_id' => $row['stock_in_id'],
        'item_code'   => $row['item_code'],
        'item_name'   => $row['item_name'],
        'item_type'   => $row['item_type'],
        'unit'        => $row['unit'],
        'quantity'    => $row['total_quantity']
    ];
}

$totalStockInTxns    = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed'));
$procurementInbounds = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'PURCHASE_ORDER'));
$productionInbounds  = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed' && in_array($r['source_type'] ?? '', ['PRODUCTION_RECEIPT', 'PRODUCTION_RETURN'], true)));

$availableItems = array_values(array_filter($allItems, fn($it) => (float)$it['current_stock'] > 0));

$stmtOut = $pdo->prepare("
    SELECT 
        sm.movement_id AS stock_out_id,
        CONCAT('OUT-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        COALESCE(
            NULLIF(sm.source_type, ''),
            CASE 
                WHEN i.type IN ('raw_material', 'Raw Material') OR sm.reference_type = 'PROD_ISSUE' OR sm.remarks LIKE '%Production%' THEN 'MATERIAL_REQUEST'
                ELSE 'SALES_DELIVERY'
            END
        ) AS source_type,
        COALESCE(
            NULLIF(sm.reference_no, ''),
            NULLIF(sm.reference_number, ''),
            NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(sm.remarks, ']', 1), '[', -1), ''),
            CONCAT('REF-', sm.movement_id)
        ) AS source_reference_no,
        DATE(sm.created_at) AS transaction_date,
        CASE WHEN rev.movement_id IS NOT NULL THEN 'cancelled' ELSE 'completed' END AS status,
        sm.remarks,
        CASE 
            WHEN rev.movement_id IS NOT NULL THEN 
                COALESCE(NULLIF(SUBSTRING(rev.remarks, LOCATE(': ', rev.remarks) + 2), ''), rev.remarks)
            ELSE NULL 
        END AS cancellation_reason,
        rev.created_at AS cancelled_at,
        sm.created_at,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(u.name, 'Warehouse Operator') AS operator_name,
        1 AS total_item_count,
        sm.quantity AS total_quantity,
        CONCAT(i.name, ' (', ROUND(sm.quantity, 0), ' ', i.unit, ')') AS item_breakdown,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    LEFT JOIN stock_movements rev ON rev.reference_id = sm.movement_id 
        AND (rev.movement_type = 'CANCELLED_OUTBOUND' OR (rev.movement_type = 'STOCK_IN' AND rev.remarks LIKE 'Cancelled Stock OUT %'))
    WHERE sm.warehouse_id = :wid 
      AND sm.movement_type IN ('STOCK_OUT', 'OUT')
      AND (sm.remarks IS NULL OR sm.remarks NOT LIKE 'Cancelled Stock IN %')
    ORDER BY sm.movement_id DESC
");
$stmtOut->execute([':wid' => $currentWarehouseId]);
$stockOuts = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

$linesByStockOut = [];
foreach ($stockOuts as $row) {
    $linesByStockOut[(int)$row['stock_out_id']][] = [
        'stock_out_id' => $row['stock_out_id'],
        'item_code'    => $row['item_code'],
        'item_name'    => $row['item_name'],
        'item_type'    => $row['item_type'],
        'unit'         => $row['unit'],
        'quantity'     => $row['total_quantity']
    ];
}

$totalStockOutTxns = count(array_filter($stockOuts, fn($r) => ($r['status'] ?? '') === 'completed'));
$salesDispatches   = count(array_filter($stockOuts, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'SALES_DELIVERY'));
$materialRequests  = count(array_filter($stockOuts, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'MATERIAL_REQUEST'));

$stmtPendingIn = $pdo->prepare("
    SELECT 
        io.inbound_order_id,
        io.order_number,
        io.source_type,
        io.entity_name,
        io.expected_date,
        io.status,
        COALESCE(io.notes, '') AS notes,
        COUNT(ioi.item_entry_id) AS total_items,
        COALESCE(SUM(ioi.expected_quantity), 0) AS total_expected_qty,
        COALESCE(SUM(ioi.received_quantity), 0) AS total_received_qty
    FROM inbound_orders io
    LEFT JOIN inbound_order_items ioi ON io.inbound_order_id = ioi.inbound_order_id
    WHERE io.warehouse_id = :wid AND io.status IN ('pending', 'partially_received')
    GROUP BY io.inbound_order_id
    ORDER BY io.expected_date ASC, io.inbound_order_id ASC
");
$stmtPendingIn->execute([':wid' => $currentWarehouseId]);
$pendingInboundOrders = $stmtPendingIn->fetchAll(PDO::FETCH_ASSOC);

$inboundOrderLines = [];
$stmtInLines = $pdo->prepare("
    SELECT 
        ioi.item_entry_id,
        ioi.inbound_order_id,
        ioi.item_id,
        ioi.expected_quantity,
        ioi.received_quantity,
        GREATEST(0, ioi.expected_quantity - ioi.received_quantity) AS remaining_quantity,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit
    FROM inbound_order_items ioi
    JOIN items i ON ioi.item_id = i.item_id
    JOIN inbound_orders io ON ioi.inbound_order_id = io.inbound_order_id
    WHERE io.warehouse_id = :wid AND io.status IN ('pending', 'partially_received')
    ORDER BY ioi.item_entry_id ASC
");
$stmtInLines->execute([':wid' => $currentWarehouseId]);
foreach ($stmtInLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
    $inboundOrderLines[(int)$line['inbound_order_id']][] = $line;
}

$stmtPendingOut = $pdo->prepare("
    SELECT 
        oo.outbound_order_id,
        oo.order_number,
        oo.source_type,
        oo.entity_name,
        oo.required_date,
        oo.status,
        COALESCE(oo.notes, '') AS notes,
        COUNT(ooi.item_entry_id) AS total_items,
        COALESCE(SUM(ooi.requested_quantity), 0) AS total_requested_qty,
        COALESCE(SUM(ooi.dispatched_quantity), 0) AS total_dispatched_qty
    FROM outbound_orders oo
    LEFT JOIN outbound_order_items ooi ON oo.outbound_order_id = ooi.outbound_order_id
    WHERE oo.warehouse_id = :wid AND oo.status IN ('pending', 'partially_dispatched')
    GROUP BY oo.outbound_order_id
    ORDER BY oo.required_date ASC, oo.outbound_order_id ASC
");
$stmtPendingOut->execute([':wid' => $currentWarehouseId]);
$pendingOutboundOrders = $stmtPendingOut->fetchAll(PDO::FETCH_ASSOC);

$outboundOrderLines = [];
$stmtOutLines = $pdo->prepare("
    SELECT 
        ooi.item_entry_id,
        ooi.outbound_order_id,
        ooi.item_id,
        ooi.requested_quantity,
        ooi.dispatched_quantity,
        GREATEST(0, ooi.requested_quantity - ooi.dispatched_quantity) AS remaining_quantity,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        GREATEST(0.00, COALESCE(s.qty_on_hand, 0.00) - COALESCE(s.qty_reserved, 0.00)) AS available_stock
    FROM outbound_order_items ooi
    JOIN items i ON ooi.item_id = i.item_id
    JOIN outbound_orders oo ON ooi.outbound_order_id = oo.outbound_order_id
    LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = oo.warehouse_id
    WHERE oo.warehouse_id = :wid AND oo.status IN ('pending', 'partially_dispatched')
    ORDER BY ooi.item_entry_id ASC
");
$stmtOutLines->execute([':wid' => $currentWarehouseId]);
foreach ($stmtOutLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
    $outboundOrderLines[(int)$line['outbound_order_id']][] = $line;
}

$pendingInboundCount  = count($pendingInboundOrders);
$pendingOutboundCount = count($pendingOutboundOrders);
$stmtDamagedRma = $pdo->prepare("
    SELECT COUNT(*) FROM bad_products 
    WHERE warehouse_id = :wid 
      AND status != 'cancelled' 
      AND (reason LIKE '%Customer Return%' OR reason LIKE '%RMA%')
");
$stmtDamagedRma->execute([':wid' => $currentWarehouseId]);
$damagedRmaCount      = (int)$stmtDamagedRma->fetchColumn();
$salableReturnsCount  = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'CUSTOMER_RETURN'));
$customerReturnsCount = $salableReturnsCount + $damagedRmaCount;
?>

<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Inventory Inbound &amp; Outbound</h1>
        <p class="page-subtitle">Unified transaction ledger for receipts and dispatches</p>
    </div>
    <div class="header-actions">
        <?php require __DIR__ . '/../layouts/notification_bell.php'; ?>
    </div>
</div>

<div class="stock-ops-nav-wrapper">
    <div class="stock-ops-nav-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m16 3 4 4-4 4"/>
            <path d="M20 7H4"/>
            <path d="m8 21-4-4 4-4"/>
            <path d="M4 17h16"/>
        </svg>
        <span>Inventory Inbound &amp; Outbound</span>
    </div>
    <div class="stock-ops-tabs">
        <button type="button" id="tab-btn-inbound" class="stock-tab-btn <?= $activeTab === 'inbound' ? 'active' : '' ?>" onclick="switchStockTab('inbound')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="17" y1="7" x2="7" y2="17"/>
                <polyline points="17 17 7 17 7 7"/>
            </svg>
            <span>Inbound</span>
            <span class="tab-badge-count"><?= $totalStockInTxns ?></span>
        </button>

        <button type="button" id="tab-btn-outbound" class="stock-tab-btn <?= $activeTab === 'outbound' ? 'active' : '' ?>" onclick="switchStockTab('outbound')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="7" y1="17" x2="17" y2="7"/>
                <polyline points="7 7 17 7 17 17"/>
            </svg>
            <span>Outbound</span>
            <span class="tab-badge-count"><?= $totalStockOutTxns ?></span>
        </button>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="flash-alert flash-alert-success" style="background: var(--success-light); border: 1px solid var(--success-border); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?= htmlspecialchars($successMessage) ?></span>
        </div>
        <button type="button" class="flash-alert-close" onclick="dismissFlashAlert(this.closest('.flash-alert') || this.parentElement)" style="background: none; border: none; cursor: pointer; color: var(--success); font-size: 18px; line-height: 1; padding: 0 4px;" aria-label="Dismiss">&times;</button>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="flash-alert flash-alert-error" style="background: var(--error-light); border: 1px solid var(--error-border); color: var(--error); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errorMessage) ?></span>
        </div>
        <button type="button" class="flash-alert-close" onclick="dismissFlashAlert(this.closest('.flash-alert') || this.parentElement)" style="background: none; border: none; cursor: pointer; color: var(--error); font-size: 18px; line-height: 1; padding: 0 4px;" aria-label="Dismiss">&times;</button>
    </div>
<?php endif; ?>

<div id="pane-inbound" class="stock-op-pane" style="display: <?= $activeTab === 'inbound' ? 'block' : 'none' ?>;">

    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Waiting to Receive</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #D97706; background: #FEF3C7;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $pendingInboundCount ?></div>
            <div class="stat-meta">Deliveries not yet received</div>
        </div>

        <div class="stat-card stat-gold">
            <div class="stat-header">
                <span class="stat-label">From Suppliers</span>
                <div class="stat-icon-wrap" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                        <polyline points="2 17 12 22 22 17"/>
                        <polyline points="2 12 12 17 22 12"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $procurementInbounds ?></div>
            <div class="stat-meta">Raw materials delivered</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">From Production</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--accent); background: var(--accent-light);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m16 16 2 2 4-4"/>
                        <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $productionInbounds ?></div>
            <div class="stat-meta">Finished goods made</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Customer Returns</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #7C3AED; background: #F3E8FF;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 14 4 9 9 4"/>
                        <path d="M20 20v-7a4 4 0 0 0-4-4H4"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $customerReturnsCount ?></div>
            <div class="stat-meta"><?= $salableReturnsCount ?> restocked &middot; <?= $damagedRmaCount ?> damaged</div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 class="card-title" style="margin: 0;">Incoming Shipments to Receive</h2>
                </div>
                <p class="card-desc" style="margin: 4px 0 0 0;">
                    Select a pending Purchase Order, Production Batch, or Return below to check and add items to stock.
                </p>
            </div>
            <div class="filter-group">
                <div class="search-wrap">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="pendingInboundSearch" class="search-box" aria-label="Filter pending inbound shipments" placeholder="Filter order #, supplier, item..." oninput="filterPendingInboundTable()">
                </div>

                <select id="pendingInboundFilter" class="select-filter" aria-label="Filter pending inbound by team and action" onchange="filterPendingInboundTable()">
                    <option value="">All Incoming</option>
                    <option value="PURCHASE_ORDER">Receiving Raw Materials (Procurement)</option>
                    <option value="PRODUCTION_RECEIPT">Receiving Finished Goods (Production)</option>
                    <option value="PRODUCTION_RETURN">Receiving Raw Material Returns (Production)</option>
                    <option value="CUSTOMER_RETURN">Receiving Returns (Sales)</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table id="pendingInboundTable">
                <thead>
                    <tr>
                        <th>Order / Tracking #</th>
                        <th>Document Type</th>
                        <th>Supplier / Customer / Origin</th>
                        <th>Expected Items &amp; Quantity</th>
                        <th>Expected Date</th>
                        <th>Queue Status</th>
                        <th style="text-align: right;">Receiving Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingInboundOrders)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--gray); padding: 32px;">
                                No pending inbound shipments in queue for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?>. All incoming orders are fully received!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingInboundOrders as $pIn): 
                            $lines = $inboundOrderLines[(int)$pIn['inbound_order_id']] ?? [];
                        ?>
                            <tr data-source="<?= htmlspecialchars($pIn['source_type']) ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($pIn['order_number']) ?>
                                </td>
                                <td>
                                    <?php if ($pIn['source_type'] === 'PURCHASE_ORDER'): ?>
                                        <span class="badge-source-procurement">Purchase Order</span>
                                    <?php elseif ($pIn['source_type'] === 'PRODUCTION_RECEIPT'): ?>
                                        <span class="badge-source-production">Production Batch</span>
                                    <?php elseif ($pIn['source_type'] === 'PRODUCTION_RETURN'): ?>
                                        <span class="badge" style="background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A;">Production Return (RM)</span>
                                    <?php elseif ($pIn['source_type'] === 'CUSTOMER_RETURN'): ?>
                                        <span class="badge" style="background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE;">Customer Return (Sales)</span>
                                    <?php else: ?>
                                        <span class="badge-source-manual"><?= htmlspecialchars($pIn['source_type']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($pIn['entity_name']) ?></strong>
                                    <?php if (!empty($pIn['notes'])): ?>
                                        <div style="font-size: 11px; color: var(--gray);"><?= htmlspecialchars($pIn['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($lines)): ?>
                                        <div style="font-size: 12.5px; font-weight: 600;">
                                            <?php foreach ($lines as $l): ?>
                                                <div style="margin-bottom: 2px;">
                                                    <?= htmlspecialchars($l['item_name']) ?>:
                                                    <span style="color: #15803D;"><?= formatQty($l['remaining_quantity']) ?> <?= htmlspecialchars($l['unit']) ?></span>
                                                    <?php if ((float)$l['received_quantity'] > 0): ?>
                                                        <small style="color: var(--gray); font-weight: normal;">(<?= formatQty($l['received_quantity']) ?> already received)</small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--gray);"><?= formatQty($pIn['total_expected_qty']) ?> items</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12.5px; color: var(--gray); white-space: nowrap;">
                                    <?= $pIn['expected_date'] ? date('M d, Y', strtotime($pIn['expected_date'])) : '—' ?>
                                </td>
                                <td>
                                    <?php if ($pIn['status'] === 'partially_received'): ?>
                                        <span class="badge status-pending">Partially Received</span>
                                    <?php else: ?>
                                        <span class="badge status-pending" style="background: #E0F2FE; color: #0369A1; border-color: #BAE6FD;">Awaiting Arrival</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php
                                        $receiveBtnLabel = 'Receive Goods &rarr;';
                                        if ($pIn['source_type'] === 'PURCHASE_ORDER') {
                                            $receiveBtnLabel = 'Accept &amp; Receive &rarr;';
                                        } elseif (in_array($pIn['source_type'], ['PRODUCTION_RETURN', 'CUSTOMER_RETURN'], true)) {
                                            $receiveBtnLabel = 'Receive Return &rarr;';
                                        }
                                    ?>
                                    <div style="display: inline-flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openReceiveOrderModal(<?= (int)$pIn['inbound_order_id'] ?>)">
                                            <?= $receiveBtnLabel ?>
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" style="color: #B91C1C; border-color: #FCA5A5; height: 32px; padding: 0 10px; font-size: 12px;" onclick="openCancelInboundOrderModal(<?= (int)$pIn['inbound_order_id'] ?>, '<?= htmlspecialchars($pIn['order_number'], ENT_QUOTES) ?>')">
                                            Reject
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

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Completed Inbound History</h2>
                <p class="card-desc">List of all completed items received into stock.</p>
            </div>
            <div class="filter-group">

                <div class="search-wrap">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="stockInSearch" class="search-box" aria-label="Filter inbound transactions" placeholder="Filter item, ref, PO #..." oninput="filterStockInTable()">
                </div>

                <select id="sourceTypeFilter" class="select-filter" aria-label="Filter by inbound source" onchange="filterStockInTable()">
                    <option value="">All Sources</option>
                    <option value="PURCHASE_ORDER">Receiving Raw Materials (Procurement)</option>
                    <option value="PRODUCTION_RECEIPT">Receiving Finished Goods (Production)</option>
                    <option value="PRODUCTION_RETURN">Receiving Raw Material Returns (Production)</option>
                    <option value="CUSTOMER_RETURN">Receiving Returns (Sales)</option>
                </select>

                <select id="itemTypeFilter" class="select-filter" aria-label="Filter inbound by item classification" onchange="filterStockInTable()">
                    <option value="">All Classifications</option>
                    <option value="raw_material">Raw Materials</option>
                    <option value="finished_good">Finished Goods</option>
                </select>

            </div>
        </div>

        <div class="table-responsive">
            <table id="stockInTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th class="text-center">Quantity</th>
                        <th>Reference / Tracking #</th>
                        <th>Date Received</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockIns)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--gray); padding: 40px;">
                                No inbound Stock In transactions recorded for this warehouse yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockIns as $row): 
                            $lines = $linesByStockIn[(int)$row['stock_in_id']] ?? [];
                            $firstLine = $lines[0] ?? null;
                            $hasMultiple = count($lines) > 1;

                            $itemType = 'raw_material';
                            if ($row['source_type'] === 'PRODUCTION_RECEIPT' || $row['source_type'] === 'CUSTOMER_RETURN') {
                                $itemType = 'finished_good';
                            } elseif (!empty($lines)) {
                                $types = array_unique(array_column($lines, 'item_type'));
                                $itemType = count($types) === 1 ? strtolower(str_replace(' ', '_', $types[0])) : 'mixed';
                            }
                        ?>
                            <tr data-source="<?= htmlspecialchars($row['source_type']) ?>" data-type="<?= htmlspecialchars($itemType) ?>">

                                <td>
                                    <?php if ($row['source_type'] === 'PURCHASE_ORDER'): ?>
                                        <span class="badge-source-procurement">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                                            Procurement
                                        </span>
                                    <?php elseif ($row['source_type'] === 'PRODUCTION_RECEIPT'): ?>
                                        <span class="badge-source-production">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m16 16 2 2 4-4"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/></svg>
                                            Production
                                        </span>
                                    <?php elseif ($row['source_type'] === 'PRODUCTION_RETURN'): ?>
                                        <span class="badge" style="background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A;">
                                            Production Return (RM)
                                        </span>
                                    <?php elseif ($row['source_type'] === 'CUSTOMER_RETURN'): ?>
                                        <span class="badge" style="background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE;">
                                            Customer Return
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-source-manual">
                                            Internal
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td style="max-width: 260px;">
                                    <?php if (!$hasMultiple && $firstLine): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($firstLine['item_name']) ?></div>
                                        <small style="font-family: monospace; color: var(--gray); font-size: 11.5px;"><?= htmlspecialchars($firstLine['item_code']) ?></small>
                                    <?php elseif ($hasMultiple): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= count($lines) ?> items received</div>
                                        <small style="color: var(--gray); font-size: 11.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['item_breakdown'] ?: '') ?>">
                                            <?= htmlspecialchars($row['item_breakdown'] ?: 'Multiple items') ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">No items specified</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($itemType === 'finished_good'): ?>
                                        <span class="badge-type type-fg">Finished Good</span>
                                    <?php elseif ($itemType === 'raw_material'): ?>
                                        <span class="badge-type type-raw">Raw Material</span>
                                    <?php else: ?>
                                        <span class="badge-type type-raw">Mixed (<?= count($lines) ?>)</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center" style="font-weight: 700; color: #15803D; white-space: nowrap;">
                                    <?= formatQty(abs((float)$row['total_quantity'])) ?>
                                    <?php if (!$hasMultiple && $firstLine): ?>
                                        <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($firstLine['unit']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--panel-ink);">
                                        <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                                    </div>
                                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">
                                        <?= htmlspecialchars($row['transaction_number']) ?>
                                    </small>
                                </td>

                                <td style="font-size: 12.5px; white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                                    <small style="display: block; color: var(--gray); font-size: 11px;">
                                        <?= date('h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>

                                <td>
                                    <?php if ($row['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Received</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 11px; font-size: 11.5px;" onclick="openDetailModal(<?= (int)$row['stock_in_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
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

<div id="stockInModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalTitle" onclick="if(event.target === this) closeDetailModal()">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalTitle" class="card-title">Transaction Details</h3>
                <p id="modalSub" class="card-desc">Inbound receipt metadata and line items received into warehouse</p>
            </div>
            <button type="button" class="modal-close" aria-label="Close modal" onclick="closeDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div id="modalInMetaBox" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; background: #F8FAFC; border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; font-size: 12.5px;">
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Reference / PO #</div>
                    <div id="modalInRef" style="font-family: monospace; font-weight: 700; color: var(--panel-ink); margin-top: 2px;">—</div>
                </div>
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Status</div>
                    <div id="modalInStatus" style="margin-top: 2px;">—</div>
                </div>
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Logged By</div>
                    <div id="modalInOperator" style="font-weight: 600; color: var(--panel-ink); margin-top: 2px;">—</div>
                </div>
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Date Received</div>
                    <div id="modalInDate" style="font-weight: 600; color: var(--panel-ink); margin-top: 2px;">—</div>
                </div>
                <div id="modalInRemarksWrap" style="grid-column: 1 / -1; border-top: 1px solid var(--border); padding-top: 10px; margin-top: 2px;">
                    <div id="modalInRemarksLabel" style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Remarks / Notes</div>
                    <div id="modalInRemarks" style="color: var(--panel-ink); margin-top: 4px; line-height: 1.45; white-space: pre-wrap;">—</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="no-paginate">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Classification</th>
                            <th class="text-center">Quantity Received</th>
                        </tr>
                    </thead>
                    <tbody id="modalLineTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">Close</button>
        </div>
    </div>
</div>

<div id="pane-outbound" class="stock-op-pane" style="display: <?= $activeTab === 'outbound' ? 'block' : 'none' ?>;">

    <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Pending Dispatches</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #DC2626; background: #FEE2E2;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $pendingOutboundCount ?></div>
            <div class="stat-meta">Waiting to be packed and shipped</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Sales (Finished Goods)</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="16" height="13" x="1" y="5" rx="2"/>
                        <polygon points="17 8 20 8 23 11 23 18 17 18 17 8"/>
                        <circle cx="5.5" cy="18.5" r="2.5"/>
                        <circle cx="18.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $salesDispatches ?></div>
            <div class="stat-meta">Orders shipped to customers</div>
        </div>

        <div class="stat-card stat-gold">
            <div class="stat-header">
                <span class="stat-label">Production (Raw Materials)</span>
                <div class="stat-icon-wrap" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                        <polyline points="2 17 12 22 22 17"/>
                        <polyline points="2 12 12 17 22 12"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $materialRequests ?></div>
            <div class="stat-meta">Raw materials sent to production</div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 class="card-title" style="margin: 0;">Pending Outbound Orders</h2>
                </div>
                <p class="card-desc" style="margin: 4px 0 0 0;">
                    Select a pending Sales Order or Raw Material Request below to pack and ship.
                </p>
            </div>
            <div class="filter-group">
                <div class="search-wrap">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="pendingOutboundSearch" class="search-box" aria-label="Filter pending outbound orders" placeholder="Filter order #, customer, item..." oninput="filterPendingOutboundTable()">
                </div>

                <select id="pendingOutboundFilter" class="select-filter" aria-label="Filter pending outbound by team and action" onchange="filterPendingOutboundTable()">
                    <option value="">All Pending Outbound</option>
                    <option value="SALES_DELIVERY">Dispatching Finished Goods (Sales)</option>
                    <option value="MATERIAL_REQUEST">Dispatching Raw Materials (Production)</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table id="pendingOutboundTable">
                <thead>
                    <tr>
                        <th>Order / Requisition #</th>
                        <th>Document Type</th>
                        <th>Customer / Recipient</th>
                        <th>Requested Items &amp; Quantity</th>
                        <th>Required Date</th>
                        <th>Queue Status</th>
                        <th style="text-align: right;">Fulfillment Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingOutboundOrders)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--gray); padding: 32px;">
                                No pending outbound orders in queue for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?>. All orders are fully fulfilled and dispatched!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingOutboundOrders as $pOut): 
                            $lines = $outboundOrderLines[(int)$pOut['outbound_order_id']] ?? [];
                        ?>
                            <tr data-source="<?= htmlspecialchars($pOut['source_type']) ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($pOut['order_number']) ?>
                                </td>
                                <td>
                                    <?php if ($pOut['source_type'] === 'SALES_DELIVERY'): ?>
                                        <span class="badge" style="background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE;">Sales Order Delivery</span>
                                    <?php elseif ($pOut['source_type'] === 'MATERIAL_REQUEST'): ?>
                                        <span class="badge-source-production">Raw Material Request</span>
                                    <?php else: ?>
                                        <span class="badge-source-manual"><?= htmlspecialchars($pOut['source_type']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($pOut['entity_name']) ?></strong>
                                    <?php if (!empty($pOut['notes'])): ?>
                                        <div style="font-size: 11px; color: var(--gray);"><?= htmlspecialchars($pOut['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($lines)): ?>
                                        <div style="font-size: 12.5px; font-weight: 600;">
                                            <?php foreach ($lines as $l): ?>
                                                <div style="margin-bottom: 2px;">
                                                    <?= htmlspecialchars($l['item_name']) ?>:
                                                    <span style="color: #DC2626;"><?= formatQty($l['remaining_quantity']) ?> <?= htmlspecialchars($l['unit']) ?></span>
                                                    <small style="color: var(--gray); font-weight: normal;">(Avail: <?= formatQty($l['available_stock']) ?>)</small>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--gray);"><?= formatQty($pOut['total_requested_qty']) ?> items</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12.5px; color: var(--gray); white-space: nowrap;">
                                    <?= $pOut['required_date'] ? date('M d, Y', strtotime($pOut['required_date'])) : '—' ?>
                                </td>
                                <td>
                                    <?php if ($pOut['status'] === 'partially_dispatched'): ?>
                                        <span class="badge status-pending">Partially Dispatched</span>
                                    <?php else: ?>
                                        <span class="badge status-pending" style="background: #FEF3C7; color: #B45309; border-color: #FDE68A;">Ready for Dispatch</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php
                                        $dispatchBtnLabel = ($pOut['source_type'] === 'MATERIAL_REQUEST')
                                            ? 'Approve &amp; Issue &rarr;'
                                            : 'Approve &amp; Dispatch &rarr;';
                                    ?>
                                    <div style="display: inline-flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openDispatchOrderModal(<?= (int)$pOut['outbound_order_id'] ?>)">
                                            <?= $dispatchBtnLabel ?>
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" style="color: #B91C1C; border-color: #FCA5A5; height: 32px; padding: 0 10px; font-size: 12px;" onclick="openCancelOutboundOrderModal(<?= (int)$pOut['outbound_order_id'] ?>, '<?= htmlspecialchars($pOut['order_number'], ENT_QUOTES) ?>')">
                                            Cancel
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

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Completed Outbound History</h2>
                <p class="card-desc">List of all completed items shipped or issued out of stock.</p>
            </div>
            <div class="filter-group">

                <div class="search-wrap">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="stockOutSearch" class="search-box" aria-label="Filter outbound transactions" placeholder="Filter item, ref, SO/MR #..." oninput="filterStockOutTable()">
                </div>

                <select id="stockOutDestFilter" class="select-filter" aria-label="Filter by outbound destination" onchange="filterStockOutTable()">
                    <option value="">All Destinations</option>
                    <option value="SALES_DELIVERY">Dispatching Finished Goods (Sales)</option>
                    <option value="MATERIAL_REQUEST">Dispatching Raw Materials (Production)</option>
                </select>

                <select id="stockOutTypeFilter" class="select-filter" aria-label="Filter outbound by item classification" onchange="filterStockOutTable()">
                    <option value="">All Classifications</option>
                    <option value="finished_good">Finished Goods</option>
                    <option value="raw_material">Raw Materials</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table id="stockOutTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th class="text-center">Quantity</th>
                        <th>Reference / Order #</th>
                        <th>Date Dispatched</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockOuts)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--gray); padding: 40px;">
                                No outbound Stock Out transactions recorded for this warehouse yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockOuts as $row): 
                            $lines = $linesByStockOut[(int)$row['stock_out_id']] ?? [];
                            $firstLine = $lines[0] ?? null;
                            $hasMultiple = count($lines) > 1;

                            $itemType = 'finished_good';
                            if ($row['source_type'] === 'MATERIAL_REQUEST') {
                                $itemType = 'raw_material';
                            } elseif (!empty($lines)) {
                                $types = array_unique(array_column($lines, 'item_type'));
                                $itemType = count($types) === 1 ? $types[0] : 'mixed';
                            }
                        ?>
                            <tr data-destination="<?= htmlspecialchars($row['source_type']) ?>" data-type="<?= htmlspecialchars($itemType) ?>">

                                <td>
                                    <?php if ($row['source_type'] === 'SALES_DELIVERY'): ?>
                                        <span class="badge-dest-sales">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="16" height="13" x="1" y="5" rx="2"/><polygon points="17 8 20 8 23 11 23 18 17 18 17 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                            Sales
                                        </span>
                                    <?php elseif ($row['source_type'] === 'MATERIAL_REQUEST'): ?>
                                        <span class="badge-dest-production">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 2 7 12 12 22 7 12 2"/>    <polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                                            Production
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-dest-manual">
                                            Internal
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td style="max-width: 260px;">
                                    <?php if (!$hasMultiple && $firstLine): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($firstLine['item_name']) ?></div>
                                        <small style="font-family: monospace; color: var(--gray); font-size: 11.5px;"><?= htmlspecialchars($firstLine['item_code']) ?></small>
                                    <?php elseif ($hasMultiple): ?>
                                        <div style="font-weight: 700; color: var(--panel-ink);"><?= count($lines) ?> items dispatched</div>
                                        <small style="color: var(--gray); font-size: 11.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['item_breakdown'] ?: '') ?>">
                                            <?= htmlspecialchars($row['item_breakdown'] ?: 'Multiple items') ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">No items specified</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($itemType === 'finished_good'): ?>
                                        <span class="badge-type type-fg">Finished Good</span>
                                    <?php elseif ($itemType === 'raw_material'): ?>
                                        <span class="badge-type type-raw">Raw Material</span>
                                    <?php else: ?>
                                        <span class="badge-type type-raw">Mixed (<?= count($lines) ?>)</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center" style="font-weight: 700; color: #B91C1C; white-space: nowrap;">
                                    <?= formatQty(abs((float)$row['total_quantity'])) ?>
                                    <?php if (!$hasMultiple && $firstLine): ?>
                                        <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($firstLine['unit']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--panel-ink);">
                                        <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                                    </div>
                                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">
                                        <?= htmlspecialchars($row['transaction_number']) ?>
                                    </small>
                                </td>

                                <td style="font-size: 12.5px; white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                                    <small style="display: block; color: var(--gray); font-size: 11px;">
                                        <?= date('h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>

                                <td>
                                    <?php if ($row['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Dispatched</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 11px; font-size: 11.5px;" onclick="openOutDetailModal(<?= (int)$row['stock_out_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
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

<div id="stockOutModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalOutTitle" onclick="if(event.target === this) closeOutDetailModal()">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalOutTitle" class="card-title">Dispatch Details</h3>
                <p id="modalOutSub" class="card-desc">Outbound dispatch metadata and line items released from inventory</p>
            </div>
            <button type="button" class="modal-close" aria-label="Close modal" onclick="closeOutDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div id="modalOutMetaBox" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; background: #F8FAFC; border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; font-size: 12.5px;">
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Reference / Order #</div>
                    <div id="modalOutRef" style="font-family: monospace; font-weight: 700; color: var(--panel-ink); margin-top: 2px;">—</div>
                </div>
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Status</div>
                    <div id="modalOutStatus" style="margin-top: 2px;">—</div>
                </div>
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Logged By</div>
                    <div id="modalOutOperator" style="font-weight: 600; color: var(--panel-ink); margin-top: 2px;">—</div>
                </div>
                <div>
                    <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Date Dispatched</div>
                    <div id="modalOutDate" style="font-weight: 600; color: var(--panel-ink); margin-top: 2px;">—</div>
                </div>
                <div id="modalOutRemarksWrap" style="grid-column: 1 / -1; border-top: 1px solid var(--border); padding-top: 10px; margin-top: 2px;">
                    <div id="modalOutRemarksLabel" style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Remarks / Notes</div>
                    <div id="modalOutRemarks" style="color: var(--panel-ink); margin-top: 4px; line-height: 1.45; white-space: pre-wrap;">—</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="no-paginate">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Classification</th>
                            <th class="text-center">Quantity Dispatched</th>
                        </tr>
                    </thead>
                    <tbody id="modalOutTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeOutDetailModal()">Close</button>
        </div>
    </div>
</div>

<div id="cancelStockOutModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cancelStockOutModalTitle" onclick="if(event.target === this) closeCancelStockOutModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=outbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_stock_out">
            <input type="hidden" name="active_tab" value="outbound">
            <input type="hidden" name="stock_out_id" id="cancelStockOutId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="cancelStockOutModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Cancel Outbound Dispatch</h3>
                    <p class="card-desc" style="margin: 0;">Reverse Stock Out and restore dispatched quantity to inventory</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelStockOutModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Are you sure you want to cancel outbound dispatch <strong id="cancelStockOutRef" style="font-family: monospace;">—</strong>?
                </p>
                <div>
                    <label for="cancelStockOutReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelStockOutReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px;" placeholder="Reason for cancelling this outbound dispatch..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelStockOutModal()">Close</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<div id="cancelOutboundOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cancelOutboundOrderModalTitle" onclick="if(event.target === this) closeCancelOutboundOrderModal()">
    <div class="modal-card" style="max-width: 480px;">
        <form method="POST" action="index.php?tab=outbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_outbound_order">
            <input type="hidden" name="active_tab" value="outbound">
            <input type="hidden" name="outbound_order_id" id="cancelOutboundOrderId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="cancelOutboundOrderModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Cancel Outbound Order</h3>
                    <p class="card-desc" style="margin: 0;">Release reserved stock and cancel order</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelOutboundOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div style="background: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 8px; padding: 12px 14px; font-size: 13px; color: #991B1B; line-height: 1.5;">
                    Are you sure you want to cancel Outbound Order <strong id="cancelOutboundOrderRef" style="font-family: monospace;">—</strong>?<br>
                    Any stock currently reserved for this order will be released immediately back to available warehouse inventory.
                </div>
                <div>
                    <label for="cancelOutboundOrderReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">Cancellation Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelOutboundOrderReason" class="search-box" style="width: 100%; border-radius: 6px; height: 70px; padding: 8px 12px; resize: vertical;" placeholder="Specify why this outbound order is being cancelled..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelOutboundOrderModal()">Back</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<div id="cancelInboundOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cancelInboundOrderModalTitle" onclick="if(event.target === this) closeCancelInboundOrderModal()">
    <div class="modal-card" style="max-width: 480px;">
        <form method="POST" action="index.php?tab=inbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_inbound_order">
            <input type="hidden" name="active_tab" value="inbound">
            <input type="hidden" name="inbound_order_id" id="cancelInboundOrderId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="cancelInboundOrderModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Reject Incoming Order</h3>
                    <p class="card-desc" style="margin: 0;">Decline delivery or return without adding to stock</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelInboundOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div style="background: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 8px; padding: 12px 14px; font-size: 13px; color: #991B1B; line-height: 1.5;">
                    Are you sure you want to reject <strong id="cancelInboundOrderRef" style="font-family: monospace;">—</strong>?<br>
                    No items from this order will be added to warehouse stock.
                </div>
                <div>
                    <label for="cancelInboundOrderReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">Rejection Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelInboundOrderReason" class="search-box" style="width: 100%; border-radius: 6px; height: 70px; padding: 8px 12px; resize: vertical;" placeholder="Specify why this incoming shipment or return is being rejected..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelInboundOrderModal()">Back</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<div id="receiveOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="receiveOrderModalTitle" onclick="if(event.target === this) closeReceiveOrderModal()">
    <div class="modal-card" style="max-width: 680px;">
        <form method="POST" action="index.php?tab=inbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="receive_inbound_order">
            <input type="hidden" name="active_tab" value="inbound">
            <input type="hidden" name="inbound_order_id" id="receiveInboundOrderId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="receiveOrderModalTitle" class="card-title" style="margin: 0;">Receive Inbound Shipment</h3>
                    <p class="card-desc" style="margin: 0;">Perform quality inspection, confirm quantities, and receive into warehouse</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeReceiveOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; font-size: 12.5px;">
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Order / Tracking #</div>
                        <div id="recOrderNum" style="font-family: monospace; font-weight: 700; color: var(--panel-ink); font-size: 13.5px; margin-top: 2px;">—</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Document Type</div>
                        <div id="recOrderTypeBadge" style="margin-top: 2px;">—</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Supplier / Origin</div>
                        <div id="recOrderEntity" style="font-weight: 600; color: var(--panel-ink); margin-top: 2px;">—</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Expected Date</div>
                        <div id="recOrderDate" style="color: var(--panel-ink); margin-top: 2px;">—</div>
                    </div>
                </div>

                <div id="recConditionGroup" style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 12px 14px;">
                    <label for="receiveCondition" style="font-size: 12.5px; font-weight: 700; color: #166534; display: block; margin-bottom: 6px;">
                        Quality Inspection &amp; Stock Disposition <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_condition" id="receiveCondition" class="select-filter" style="width: 100%; height: 38px; border-radius: 6px; font-size: 13px;" onchange="handleReceiveConditionChange(this)">
                        <option value="salable">Passed Inspection / Salable &mdash; Receive into Available Stock (Stock In)</option>
                        <option value="damaged">Damaged / Defective / Expired &mdash; Route directly to Bad Products Discard</option>
                        <option value="mixed">Mixed Inspection &mdash; Split Salable (Stock In) &amp; Damaged (Bad Products)</option>
                    </select>
                    <small id="recConditionHint" style="display: block; margin-top: 4px; font-size: 11.5px; color: #15803D;">
                        Salable items will increase current inventory balance.
                    </small>
                </div>

                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Line Items to Receive
                    </label>
                    <div class="table-responsive" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
                        <table style="width: 100%; font-size: 12.5px; margin: 0;">
                            <thead>
                                <tr>
                                    <th>Item Code &amp; Name</th>
                                    <th style="text-align: right;">Expected</th>
                                    <th style="text-align: right;">Received</th>
                                    <th style="text-align: right;">Remaining</th>
                                    <th id="receiveQtyHeaderCol" style="width: 130px; text-align: right;">Receive Qty</th>
                                    <th id="receiveDamagedHeaderCol" style="width: 120px; text-align: right; display: none; color: #FCA5A5;">Damaged Qty</th>
                                </tr>
                            </thead>
                            <tbody id="receiveOrderLinesTbody">
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <label for="receiveRemarks" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">
                        Receiving / QA Inspection Notes
                    </label>
                    <textarea name="remarks" id="receiveRemarks" class="search-box" style="width: 100%; border-radius: 6px; height: 50px; padding: 8px 12px; resize: vertical;" placeholder="Optional inspection remarks, batch numbers, or carrier notes..."></textarea>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeReceiveOrderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnConfirmReceive" style="background: #15803D; border-color: #15803D;">Confirm &amp; Receive Shipment</button>
            </div>
        </form>
    </div>
</div>

<div id="dispatchOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="dispatchOrderModalTitle" onclick="if(event.target === this) closeDispatchOrderModal()">
    <div class="modal-card" style="max-width: 680px;">
        <form method="POST" action="index.php?tab=outbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dispatch_outbound_order">
            <input type="hidden" name="active_tab" value="outbound">
            <input type="hidden" name="outbound_order_id" id="dispatchOutboundOrderId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="dispatchOrderModalTitle" class="card-title" style="margin: 0;">Fulfill &amp; Dispatch Outbound Order</h3>
                    <p class="card-desc" style="margin: 0;">Verify available inventory, pack quantities, and dispatch to carrier or department</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeDispatchOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; font-size: 12.5px;">
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Order / Requisition #</div>
                        <div id="dispOrderNum" style="font-family: monospace; font-weight: 700; color: var(--panel-ink); font-size: 13.5px; margin-top: 2px;">—</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Document Type</div>
                        <div id="dispOrderTypeBadge" style="margin-top: 2px;">—</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Customer / Destination</div>
                        <div id="dispOrderEntity" style="font-weight: 600; color: var(--panel-ink); margin-top: 2px;">—</div>
                    </div>
                    <div>
                        <div style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600;">Required Date</div>
                        <div id="dispOrderDate" style="color: var(--panel-ink); margin-top: 2px;">—</div>
                    </div>
                </div>

                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Requested Items &amp; Stock Availability
                    </label>
                    <div class="table-responsive" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
                        <table style="width: 100%; font-size: 12.5px; margin: 0;">
                            <thead>
                                <tr>
                                    <th>Item Code &amp; Name</th>
                                    <th style="text-align: right;">Available Stock</th>
                                    <th style="text-align: right;">Requested</th>
                                    <th style="text-align: right;">Remaining</th>
                                    <th style="width: 140px; text-align: right;">Dispatch Qty</th>
                                </tr>
                            </thead>
                            <tbody id="dispatchOrderLinesTbody">
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <label for="dispatchRemarks" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">
                        Carrier / Dispatch Notes
                    </label>
                    <textarea name="remarks" id="dispatchRemarks" class="search-box" style="width: 100%; border-radius: 6px; height: 50px; padding: 8px 12px; resize: vertical;" placeholder="Optional carrier details, tracking #, or fulfillment notes..."></textarea>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeDispatchOrderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnConfirmDispatch" style="background: #1D4ED8; border-color: #1D4ED8;">Confirm &amp; Dispatch Order</button>
            </div>
        </form>
    </div>
</div>

<script>

function switchStockTab(tabName) {
    const validTabs = ['inbound', 'outbound'];
    if (!validTabs.includes(tabName)) tabName = 'inbound';

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

    validTabs.forEach(t => {
        const pane = document.getElementById('pane-' + t);
        if (pane) {
            pane.style.display = (t === tabName) ? 'block' : 'none';
        }
    });

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({ tab: tabName }, '', url.toString());

    const activePane = document.getElementById('pane-' + tabName);
    if (activePane) {
        activePane.querySelectorAll('table').forEach(tbl => {
            if (typeof tbl.paginationUpdate === 'function') {
                tbl.paginationUpdate(false);
            }
        });
    }
}

window.addEventListener('popstate', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || 'inbound';
    switchStockTab(tab);
});

const linesData = <?= json_encode($linesByStockIn, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const stockInList = <?= json_encode($stockIns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const stockInMetaById = {};
stockInList.forEach(r => { stockInMetaById[r.stock_in_id] = r; });

function openDetailModal(id, txnNo) {
    document.getElementById('modalTitle').textContent = 'Inbound Receipt: ' + txnNo;
    const meta = stockInMetaById[id] || {};
    const isCancelled = (meta.status || '').toLowerCase() === 'cancelled';

    document.getElementById('modalInRef').textContent = meta.source_reference_no || '—';
    document.getElementById('modalInStatus').innerHTML = isCancelled
        ? '<span class="badge status-cancelled">Cancelled</span>'
        : '<span class="badge status-completed">Received</span>';
    document.getElementById('modalInOperator').textContent = meta.operator_name || 'System Operator';
    document.getElementById('modalInDate').textContent = meta.transaction_date
        ? meta.transaction_date + (meta.created_at ? ' (' + meta.created_at.slice(11, 16) + ')' : '')
        : '—';

    const remarksLabel = document.getElementById('modalInRemarksLabel');
    const remarksEl = document.getElementById('modalInRemarks');
    if (isCancelled) {
        remarksLabel.textContent = 'Cancellation Reason';
        remarksLabel.style.color = '#B91C1C';
        remarksEl.style.color = '#991B1B';
        remarksEl.style.fontWeight = '600';
        const cancelReason = (meta.cancellation_reason && meta.cancellation_reason.trim() !== '') ? meta.cancellation_reason.trim() : '';
        const origRemarks = (meta.remarks && meta.remarks.trim() !== '') ? meta.remarks.trim() : '';
        if (cancelReason && origRemarks) {
            remarksEl.textContent = cancelReason + '\n(Original Remarks: ' + origRemarks + ')';
        } else {
            remarksEl.textContent = cancelReason || origRemarks || 'No cancellation reason recorded.';
        }
    } else {
        remarksLabel.textContent = 'Remarks / Notes';
        remarksLabel.style.color = 'var(--gray)';
        remarksEl.style.color = 'var(--panel-ink)';
        remarksEl.style.fontWeight = 'normal';
        remarksEl.textContent = (meta.remarks && meta.remarks.trim() !== '') ? meta.remarks : 'No remarks provided.';
    }

    const tbody = document.getElementById('modalLineTableBody');
    tbody.innerHTML = '';

    const lines = linesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--gray); padding: 16px;">No line items found.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-family: monospace; font-weight: 700;">${escapeHtml(l.item_code)}</td>
                <td><strong>${escapeHtml(l.item_name)}</strong></td>
                <td><span class="badge-type ${l.item_type === 'finished_good' ? 'type-fg' : 'type-raw'}">${escapeHtml(l.item_type.replace('_', ' '))}</span></td>
                <td class="text-center" style="font-weight: 700; color: #15803D;">+${formatQty(l.quantity)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = document.getElementById('stockInModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeDetailModal() {
    const modal = document.getElementById('stockInModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterStockInTable() {
    const term = document.getElementById('stockInSearch').value.toLowerCase().trim();
    const sourceFilter = document.getElementById('sourceTypeFilter').value;
    const typeFilter = document.getElementById('itemTypeFilter').value;
    const table = document.getElementById('stockInTable');
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const rowSource = row.getAttribute('data-source') || '';
        const rowType = row.getAttribute('data-type') || '';

        const matchesText = text.includes(term);
        const matchesSource = !sourceFilter || rowSource === sourceFilter;
        const matchesType = !typeFilter || rowType === typeFilter;

        if (matchesText && matchesSource && matchesType) {
            delete row.dataset.filteredOut;
        } else {
            row.dataset.filteredOut = 'true';
        }
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

function resetStockInFilters() {
    const search = document.getElementById('stockInSearch');
    const source = document.getElementById('sourceTypeFilter');
    const type = document.getElementById('itemTypeFilter');
    if (search) search.value = '';
    if (source) source.value = '';
    if (type) type.value = '';
    filterStockInTable();
}

function filterPendingInboundTable() {
    const term = (document.getElementById('pendingInboundSearch')?.value || '').toLowerCase().trim();
    const sourceFilter = document.getElementById('pendingInboundFilter')?.value || '';
    const table = document.getElementById('pendingInboundTable');
    if (!table) return;
    table.querySelectorAll('tbody tr').forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const rowSource = row.getAttribute('data-source') || '';
        const matches = text.includes(term) && (!sourceFilter || rowSource === sourceFilter);
        row.style.display = matches ? '' : 'none';
    });
}

function resetPendingInboundFilters() {
    const search = document.getElementById('pendingInboundSearch');
    const source = document.getElementById('pendingInboundFilter');
    if (search) search.value = '';
    if (source) source.value = '';
    filterPendingInboundTable();
}

function filterPendingOutboundTable() {
    const term = (document.getElementById('pendingOutboundSearch')?.value || '').toLowerCase().trim();
    const sourceFilter = document.getElementById('pendingOutboundFilter')?.value || '';
    const table = document.getElementById('pendingOutboundTable');
    if (!table) return;
    table.querySelectorAll('tbody tr').forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const rowSource = row.getAttribute('data-source') || '';
        const matches = text.includes(term) && (!sourceFilter || rowSource === sourceFilter);
        row.style.display = matches ? '' : 'none';
    });
}

function resetPendingOutboundFilters() {
    const search = document.getElementById('pendingOutboundSearch');
    const source = document.getElementById('pendingOutboundFilter');
    if (search) search.value = '';
    if (source) source.value = '';
    filterPendingOutboundTable();
}

const outLinesData = <?= json_encode($linesByStockOut, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const stockOutList = <?= json_encode($stockOuts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const stockOutMetaById = {};
stockOutList.forEach(r => { stockOutMetaById[r.stock_out_id] = r; });

function openOutDetailModal(id, txnNo) {
    document.getElementById('modalOutTitle').textContent = 'Dispatch: ' + txnNo;
    const meta = stockOutMetaById[id] || {};
    const isCancelled = (meta.status || '').toLowerCase() === 'cancelled';

    document.getElementById('modalOutRef').textContent = meta.source_reference_no || '—';
    document.getElementById('modalOutStatus').innerHTML = isCancelled
        ? '<span class="badge status-cancelled">Cancelled</span>'
        : '<span class="badge status-completed">Dispatched</span>';
    document.getElementById('modalOutOperator').textContent = meta.operator_name || 'System Operator';
    document.getElementById('modalOutDate').textContent = meta.transaction_date
        ? meta.transaction_date + (meta.created_at ? ' (' + meta.created_at.slice(11, 16) + ')' : '')
        : '—';

    const remarksLabel = document.getElementById('modalOutRemarksLabel');
    const remarksEl = document.getElementById('modalOutRemarks');
    if (isCancelled) {
        remarksLabel.textContent = 'Cancellation Reason';
        remarksLabel.style.color = '#B91C1C';
        remarksEl.style.color = '#991B1B';
        remarksEl.style.fontWeight = '600';
        const cancelReason = (meta.cancellation_reason && meta.cancellation_reason.trim() !== '') ? meta.cancellation_reason.trim() : '';
        const origRemarks = (meta.remarks && meta.remarks.trim() !== '') ? meta.remarks.trim() : '';
        if (cancelReason && origRemarks) {
            remarksEl.textContent = cancelReason + '\n(Original Remarks: ' + origRemarks + ')';
        } else {
            remarksEl.textContent = cancelReason || origRemarks || 'No cancellation reason recorded.';
        }
    } else {
        remarksLabel.textContent = 'Remarks / Notes';
        remarksLabel.style.color = 'var(--gray)';
        remarksEl.style.color = 'var(--panel-ink)';
        remarksEl.style.fontWeight = 'normal';
        remarksEl.textContent = (meta.remarks && meta.remarks.trim() !== '') ? meta.remarks : 'No remarks provided.';
    }

    const tbody = document.getElementById('modalOutTableBody');
    tbody.innerHTML = '';

    const lines = outLinesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--gray); padding: 16px;">No line items found.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-family: monospace; font-weight: 700;">${escapeHtml(l.item_code)}</td>
                <td><strong>${escapeHtml(l.item_name)}</strong></td>
                <td><span class="badge-type ${l.item_type === 'finished_good' ? 'type-fg' : 'type-raw'}">${escapeHtml(l.item_type.replace('_', ' '))}</span></td>
                <td class="text-center" style="font-weight: 700; color: #B91C1C;">-${formatQty(l.quantity)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = document.getElementById('stockOutModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeOutDetailModal() {
    const modal = document.getElementById('stockOutModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterStockOutTable() {
    const term = document.getElementById('stockOutSearch').value.toLowerCase().trim();
    const destFilter = document.getElementById('stockOutDestFilter').value;
    const typeFilter = document.getElementById('stockOutTypeFilter').value;
    const table = document.getElementById('stockOutTable');
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const rowDest = row.getAttribute('data-destination') || '';
        const rowType = row.getAttribute('data-type') || '';

        const matchesText = text.includes(term);
        const matchesDest = !destFilter || rowDest === destFilter;
        const matchesType = !typeFilter || rowType === typeFilter;

        if (matchesText && matchesDest && matchesType) {
            delete row.dataset.filteredOut;
        } else {
            row.dataset.filteredOut = 'true';
        }
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

function resetStockOutFilters() {
    const search = document.getElementById('stockOutSearch');
    const dest = document.getElementById('stockOutDestFilter');
    const type = document.getElementById('stockOutTypeFilter');
    if (search) search.value = '';
    if (dest) dest.value = '';
    if (type) type.value = '';
    filterStockOutTable();
}

function openCancelStockOutModal(id, txnNo) {
    document.getElementById('cancelStockOutId').value = id;
    document.getElementById('cancelStockOutRef').textContent = txnNo;
    document.getElementById('cancelStockOutReason').value = '';
    const modal = document.getElementById('cancelStockOutModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeCancelStockOutModal() {
    const modal = document.getElementById('cancelStockOutModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function openCancelOutboundOrderModal(id, orderNo) {
    document.getElementById('cancelOutboundOrderId').value = id;
    document.getElementById('cancelOutboundOrderRef').textContent = orderNo;
    document.getElementById('cancelOutboundOrderReason').value = '';
    const modal = document.getElementById('cancelOutboundOrderModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeCancelOutboundOrderModal() {
    const modal = document.getElementById('cancelOutboundOrderModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

const pendingInOrdersData   = <?= json_encode($pendingInboundOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const inLinesData           = <?= json_encode($inboundOrderLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const pendingOutOrdersData  = <?= json_encode($pendingOutboundOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const outLinesOrderData     = <?= json_encode($outboundOrderLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

const pendingInMap = {};
pendingInOrdersData.forEach(o => { pendingInMap[o.inbound_order_id] = o; });

const pendingOutMap = {};
pendingOutOrdersData.forEach(o => { pendingOutMap[o.outbound_order_id] = o; });

function openReceiveOrderModal(id) {
    const order = pendingInMap[id];
    if (!order) return;

    document.getElementById('receiveInboundOrderId').value = id;
    document.getElementById('recOrderNum').textContent = order.order_number || '—';

    let badgeHtml = '';
    if (order.source_type === 'PURCHASE_ORDER') {
        badgeHtml = '<span class="badge-source-procurement">Purchase Order</span>';
    } else if (order.source_type === 'PRODUCTION_RECEIPT') {
        badgeHtml = '<span class="badge-source-production">Production Batch</span>';
    } else if (order.source_type === 'PRODUCTION_RETURN') {
        badgeHtml = '<span class="badge" style="background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A;">Production Return (RM)</span>';
    } else if (order.source_type === 'CUSTOMER_RETURN') {
        badgeHtml = '<span class="badge" style="background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE;">Customer Return (Sales)</span>';
    } else {
        badgeHtml = '<span class="badge-source-manual">' + escapeHtml(order.source_type) + '</span>';
    }
    document.getElementById('recOrderTypeBadge').innerHTML = badgeHtml;
    document.getElementById('recOrderEntity').textContent = order.entity_name || '—';
    document.getElementById('recOrderDate').textContent = order.expected_date ? order.expected_date : '—';
    document.getElementById('receiveRemarks').value = '';

    const condSelect = document.getElementById('receiveCondition');
    const condHint   = document.getElementById('recConditionHint');
    const submitBtn  = document.getElementById('btnConfirmReceive');

    if (order.source_type === 'CUSTOMER_RETURN' || order.source_type === 'PRODUCTION_RETURN') {
        condSelect.value = 'salable';
        condHint.textContent = 'Returns: Select whether returned items passed QA for restock or should be discarded.';
        submitBtn.textContent = 'Confirm & Receive Return';
        submitBtn.style.background = '#6D28D9';
        submitBtn.style.borderColor = '#6D28D9';
    } else if (order.source_type === 'PURCHASE_ORDER') {
        condSelect.value = 'salable';
        condHint.textContent = 'Accepted items will be added to current inventory balance.';
        submitBtn.textContent = 'Confirm & Accept Delivery';
        submitBtn.style.background = '#15803D';
        submitBtn.style.borderColor = '#15803D';
    } else {
        condSelect.value = 'salable';
        condHint.textContent = 'Salable items will increase current inventory balance.';
        submitBtn.textContent = 'Confirm & Receive Goods';
        submitBtn.style.background = '#15803D';
        submitBtn.style.borderColor = '#15803D';
    }

    const tbody = document.getElementById('receiveOrderLinesTbody');
    tbody.innerHTML = '';
    const lines = inLinesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--gray); padding: 16px;">No line items pending receipt.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            const rem = Math.round(parseFloat(l.remaining_quantity) || 0);
            tr.innerHTML = `
                <td style="padding: 8px 10px;">
                    <div style="font-weight: 700; color: var(--panel-ink);">${escapeHtml(l.item_name)}</div>
                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">${escapeHtml(l.item_code)}</small>
                </td>
                <td style="text-align: right; padding: 8px 10px; color: var(--gray);">${formatQty(l.expected_quantity)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px; color: var(--gray);">${formatQty(l.received_quantity)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px; font-weight: 700; color: #15803D;">${formatQty(rem)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px;">
                    <input type="number" step="1" min="0" max="${rem}" name="received_quantity[${l.item_id}]" value="${rem}" class="search-box rec-salable-input" data-rem="${rem}" oninput="syncMixedQtyBounds(this, 'salable')" style="width: 100px; height: 34px; padding: 4px 8px; text-align: right; font-weight: 700; border-radius: 6px;" required>
                </td>
                <td class="rec-damaged-col" style="text-align: right; padding: 8px 10px; display: none;">
                    <input type="number" step="1" min="0" max="${rem}" name="damaged_quantity[${l.item_id}]" value="0" class="search-box rec-damaged-input" data-rem="${rem}" oninput="syncMixedQtyBounds(this, 'damaged')" style="width: 100px; height: 34px; padding: 4px 8px; text-align: right; font-weight: 700; border-radius: 6px; border-color: #FCA5A5; color: #B91C1C;">
                </td>
            `;
            tbody.appendChild(tr);
        });
    }
    handleReceiveConditionChange(condSelect);

    const modal = document.getElementById('receiveOrderModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeReceiveOrderModal() {
    const modal = document.getElementById('receiveOrderModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function syncMixedQtyBounds(inputEl, changedType) {
    const cond = document.getElementById('receiveCondition')?.value;
    if (cond !== 'mixed') return;
    const tr = inputEl.closest('tr');
    if (!tr) return;
    const salInput = tr.querySelector('.rec-salable-input');
    const dmgInput = tr.querySelector('.rec-damaged-input');
    const rem = parseInt(inputEl.dataset.rem || '0', 10);
    let val = Math.max(0, Math.min(rem, parseInt(inputEl.value || '0', 10) || 0));
    inputEl.value = val;
    if (changedType === 'damaged' && salInput) {
        salInput.value = Math.max(0, rem - val);
    } else if (changedType === 'salable' && dmgInput) {
        const dmgVal = Math.max(0, parseInt(dmgInput.value || '0', 10) || 0);
        if (val + dmgVal > rem) {
            dmgInput.value = Math.max(0, rem - val);
        }
    }
}

function handleReceiveConditionChange(selectEl) {
    const hint = document.getElementById('recConditionHint');
    const submitBtn = document.getElementById('btnConfirmReceive');
    const qtyHeader = document.getElementById('receiveQtyHeaderCol');
    const dmgHeader = document.getElementById('receiveDamagedHeaderCol');
    const dmgCols   = document.querySelectorAll('.rec-damaged-col');

    if (selectEl.value === 'mixed') {
        if (qtyHeader) qtyHeader.textContent = 'Salable Qty';
        if (dmgHeader) dmgHeader.style.display = '';
        dmgCols.forEach(td => { td.style.display = ''; });
        hint.textContent = 'Split returned items between Salable (Stock In) and Damaged (Bad Products Discard) in one submission.';
        submitBtn.textContent = 'Confirm Mixed Inspection & Receive';
        submitBtn.style.background = '#6D28D9';
        submitBtn.style.borderColor = '#6D28D9';
    } else if (selectEl.value === 'damaged') {
        if (qtyHeader) qtyHeader.textContent = 'Receive Qty';
        if (dmgHeader) dmgHeader.style.display = 'none';
        dmgCols.forEach(td => { td.style.display = 'none'; });
        hint.textContent = 'Damaged returns will NOT enter active stock. They will be logged directly into Bad Products Discard.';
        submitBtn.textContent = 'Confirm Damaged Discard (Bad Products)';
        submitBtn.style.background = '#B91C1C';
        submitBtn.style.borderColor = '#B91C1C';
    } else {
        if (qtyHeader) qtyHeader.textContent = 'Receive Qty';
        if (dmgHeader) dmgHeader.style.display = 'none';
        dmgCols.forEach(td => { td.style.display = 'none'; });
        hint.textContent = 'Salable items will increase current inventory balance.';
        submitBtn.textContent = 'Confirm & Receive Shipment';
        submitBtn.style.background = '#15803D';
        submitBtn.style.borderColor = '#15803D';
    }
}

function openDispatchOrderModal(id) {
    const order = pendingOutMap[id];
    if (!order) return;

    document.getElementById('dispatchOutboundOrderId').value = id;
    document.getElementById('dispOrderNum').textContent = order.order_number || '—';

    let badgeHtml = '';
    const dispatchBtn = document.getElementById('btnConfirmDispatch');
    if (order.source_type === 'SALES_DELIVERY') {
        badgeHtml = '<span class="badge" style="background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE;">Sales Order Delivery</span>';
        if (dispatchBtn) dispatchBtn.textContent = 'Approve & Dispatch Order';
    } else if (order.source_type === 'MATERIAL_REQUEST') {
        badgeHtml = '<span class="badge-source-production">Raw Material Request</span>';
        if (dispatchBtn) dispatchBtn.textContent = 'Approve & Issue Materials';
    } else {
        badgeHtml = '<span class="badge-source-manual">' + escapeHtml(order.source_type) + '</span>';
        if (dispatchBtn) dispatchBtn.textContent = 'Approve & Dispatch Order';
    }
    document.getElementById('dispOrderTypeBadge').innerHTML = badgeHtml;
    document.getElementById('dispOrderEntity').textContent = order.entity_name || '—';
    document.getElementById('dispOrderDate').textContent = order.required_date ? order.required_date : '—';
    document.getElementById('dispatchRemarks').value = '';

    const tbody = document.getElementById('dispatchOrderLinesTbody');
    tbody.innerHTML = '';
    const lines = outLinesOrderData[id] || [];

    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--gray); padding: 16px;">No line items pending dispatch.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            const rem = Math.round(parseFloat(l.remaining_quantity) || 0);
            const avail = Math.round(parseFloat(l.available_stock || 0));
            const maxDispatch = Math.min(rem, avail);
            const isShort = avail < rem;

            tr.innerHTML = `
                <td style="padding: 8px 10px;">
                    <div style="font-weight: 700; color: var(--panel-ink);">${escapeHtml(l.item_name)}</div>
                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">${escapeHtml(l.item_code)}</small>
                </td>
                <td style="text-align: right; padding: 8px 10px;">
                    <span class="badge" style="${isShort ? 'background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA;' : 'background: #F0FDF4; color: #15803D; border: 1px solid #BBF7D0;'}">
                        ${formatQty(avail)} ${escapeHtml(l.unit)}
                    </span>
                </td>
                <td style="text-align: right; padding: 8px 10px; color: var(--gray);">${formatQty(l.requested_quantity)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px; font-weight: 700; color: #B91C1C;">${formatQty(rem)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px;">
                    <input type="number" step="1" min="0" max="${rem}" name="dispatched_quantity[${l.item_id}]" value="${maxDispatch}" class="search-box" style="width: 110px; height: 34px; padding: 4px 8px; text-align: right; font-weight: 700; border-radius: 6px;" required>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = document.getElementById('dispatchOrderModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function openCancelInboundOrderModal(orderId, orderRef) {
    document.getElementById('cancelInboundOrderId').value = orderId;
    document.getElementById('cancelInboundOrderRef').textContent = orderRef;
    document.getElementById('cancelInboundOrderReason').value = '';
    const modal = document.getElementById('cancelInboundOrderModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeCancelInboundOrderModal() {
    const modal = document.getElementById('cancelInboundOrderModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function closeDispatchOrderModal() {
    const modal = document.getElementById('dispatchOrderModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
