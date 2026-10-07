<?php
/**
 * View: Inventory Inbound & Outbound (Unified Movement Hub)
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Unifies two core inventory transaction flows into a single interface:
 * 1. Inbound (Stock In receipts from Procurement & Production)
 * 2. Outbound (Stock Out dispatches to Sales & Production)
 */

$pageTitle   = 'Inventory Inbound & Outbound — InventoryTeam';
$activePage  = 'inbound_outbound';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

$successMessage = null;
$errorMessage   = null;

// Determine Initial Active Tab
$activeTab = 'inbound';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['inbound', 'outbound'], true)) {
    $activeTab = $_GET['tab'];
} elseif (isset($_POST['active_tab']) && in_array($_POST['active_tab'], ['inbound', 'outbound'], true)) {
    $activeTab = $_POST['active_tab'];
}

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
                // 1. Stock In (Inbound Receipt)
                // -------------------------------------------------------------
                case 'create_stock_in':
                    $activeTab = 'inbound';
                    $sourceType        = trim($_POST['source_type'] ?? 'PURCHASE_ORDER');
                    $sourceReferenceNo = trim($_POST['source_reference_no'] ?? '');
                    $itemId            = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity       = $_POST['quantity'] ?? null;
                    $remarks           = trim($_POST['remarks'] ?? '');
                    $targetWhId        = (int)($currentWarehouseId ?: 1);

                    if (!in_array($sourceType, StockService::VALID_STOCK_IN_SOURCES, true)) {
                        throw new InvalidArgumentException("Invalid source_type '{$sourceType}'.");
                    } elseif (empty($sourceReferenceNo)) {
                        throw new InvalidArgumentException("Reference Number (PO # or Work Order #) is required.");
                    } elseif (mb_strlen($sourceReferenceNo) > 100) {
                        throw new InvalidArgumentException("Reference Number cannot exceed 100 characters.");
                    } elseif ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select an item to receive.");
                    }
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity received');

                    $res = $stockService->recordStockIn(
                        $targetWhId,
                        $sourceType,
                        $sourceReferenceNo,
                        [['item_id' => $itemId, 'quantity' => $quantity]],
                        $userId,
                        $remarks ?: null,
                        $currentUser
                    );
                    $successMessage = "Inbound stock received successfully! Transaction reference: " . htmlspecialchars($res['transaction_number']);
                    break;

                // -------------------------------------------------------------
                // 2. Stock Out (Outbound Dispatch)
                // -------------------------------------------------------------
                case 'create_stock_out':
                    $activeTab = 'outbound';
                    $sourceType        = trim($_POST['source_type'] ?? 'SALES_DELIVERY');
                    $sourceReferenceNo = trim($_POST['source_reference_no'] ?? '');
                    $itemId            = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity       = $_POST['quantity'] ?? null;
                    $remarks           = trim($_POST['remarks'] ?? '');
                    $sourceWhId        = (int)($currentWarehouseId ?: 1);

                    if (!in_array($sourceType, StockService::VALID_STOCK_OUT_SOURCES, true)) {
                        throw new InvalidArgumentException("Invalid source_type '{$sourceType}'.");
                    } elseif (empty($sourceReferenceNo)) {
                        throw new InvalidArgumentException("Reference Number (Sales Order # or Material Request #) is required.");
                    } elseif (mb_strlen($sourceReferenceNo) > 100) {
                        throw new InvalidArgumentException("Reference Number cannot exceed 100 characters.");
                    } elseif ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select an item to dispatch.");
                    }
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity dispatched');

                    $res = $stockService->recordStockOut(
                        $sourceWhId,
                        $sourceType,
                        $sourceReferenceNo,
                        [['item_id' => $itemId, 'quantity' => $quantity]],
                        $userId,
                        $remarks ?: null,
                        $currentUser
                    );
                    $successMessage = "Outbound stock dispatched successfully! Transaction reference: " . htmlspecialchars($res['transaction_number']);
                    break;

                case 'cancel_stock_in':
                    $activeTab = 'inbound';
                    $stockInId = (int)($_POST['stock_in_id'] ?? 0);
                    $reason    = trim($_POST['cancellation_reason'] ?? '');
                    if ($stockInId <= 0) {
                        throw new InvalidArgumentException("Invalid Stock In transaction ID.");
                    }
                    $res = $stockService->cancelStockIn($stockInId, $reason, $userId, $currentUser);
                    $successMessage = "Inbound receipt " . htmlspecialchars($res['transaction_number']) . " has been cancelled and reversed from inventory.";
                    break;

                case 'cancel_stock_out':
                    $activeTab  = 'outbound';
                    $stockOutId = (int)($_POST['stock_out_id'] ?? 0);
                    $reason     = trim($_POST['cancellation_reason'] ?? '');
                    if ($stockOutId <= 0) {
                        throw new InvalidArgumentException("Invalid Stock Out transaction ID.");
                    }
                    $res = $stockService->cancelStockOut($stockOutId, $reason, $userId, $currentUser);
                    $successMessage = "Outbound dispatch " . htmlspecialchars($res['transaction_number']) . " has been cancelled and stock restored to inventory.";
                    break;

                // -------------------------------------------------------------
                // 1. Document-Driven Inbound Order Receiving
                // -------------------------------------------------------------
                case 'receive_inbound_order':
                    $activeTab = 'inbound';
                    $orderId = (int)($_POST['inbound_order_id'] ?? 0);
                    $receivedQtys = $_POST['received_quantity'] ?? [];
                    $condition = trim($_POST['item_condition'] ?? 'salable');
                    $remarks = trim($_POST['remarks'] ?? '');
                    if ($orderId <= 0) {
                        throw new InvalidArgumentException("Invalid inbound order ID.");
                    }
                    $res = $stockService->receiveInboundOrder($orderId, $receivedQtys, $userId, $remarks ?: null, $currentUser, $condition);
                    $successMessage = "Inbound order " . htmlspecialchars($res['order_number']) . " received successfully! " . 
                        ($res['condition'] === 'damaged' ? "Damaged items were routed to Bad Products Discard." : "Transaction ref: " . htmlspecialchars($res['transaction_number']));
                    break;

                // -------------------------------------------------------------
                // 2. Register New Inbound Order (PO / Production Receipt / RMA)
                // -------------------------------------------------------------
                case 'create_inbound_order':
                    $activeTab = 'inbound';
                    $orderNumber  = trim($_POST['order_number'] ?? '');
                    $sourceType   = trim($_POST['source_type'] ?? 'PURCHASE_ORDER');
                    $entityName   = trim($_POST['entity_name'] ?? '');
                    $expectedDate = trim($_POST['expected_date'] ?? date('Y-m-d'));
                    $itemId       = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity  = $_POST['quantity'] ?? null;
                    $notes        = trim($_POST['notes'] ?? '');
                    $targetWhId   = (int)($currentWarehouseId ?: 1);

                    if (empty($orderNumber)) throw new InvalidArgumentException("Order number is required.");
                    if (empty($entityName)) throw new InvalidArgumentException("Supplier / Entity name is required.");
                    if ($itemId <= 0) throw new InvalidArgumentException("Please select an item.");
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'expected quantity');

                    $pdo->prepare("INSERT INTO inbound_orders (order_number, source_type, entity_name, warehouse_id, expected_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)")
                        ->execute([$orderNumber, $sourceType, $entityName, $targetWhId, $expectedDate ?: null, $notes ?: null, $userId]);
                    $newOrderId = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO inbound_order_items (inbound_order_id, item_id, expected_quantity, received_quantity) VALUES (?, ?, ?, 0)")
                        ->execute([$newOrderId, $itemId, $quantity]);

                    $successMessage = "Inbound order " . htmlspecialchars($orderNumber) . " registered in the receiving queue.";
                    break;

                // -------------------------------------------------------------
                // 3. Document-Driven Outbound Order Dispatching
                // -------------------------------------------------------------
                case 'dispatch_outbound_order':
                    $activeTab = 'outbound';
                    $orderId = (int)($_POST['outbound_order_id'] ?? 0);
                    $dispatchedQtys = $_POST['dispatched_quantity'] ?? [];
                    $remarks = trim($_POST['remarks'] ?? '');
                    if ($orderId <= 0) {
                        throw new InvalidArgumentException("Invalid outbound order ID.");
                    }
                    $res = $stockService->dispatchOutboundOrder($orderId, $dispatchedQtys, $userId, $remarks ?: null, $currentUser);
                    $successMessage = "Outbound order " . htmlspecialchars($res['order_number']) . " dispatched successfully! Transaction ref: " . htmlspecialchars($res['transaction_number']);
                    break;

                // -------------------------------------------------------------
                // 4. Register New Outbound Order (Sales Order / Material Request / RTV)
                // -------------------------------------------------------------
                case 'create_outbound_order':
                    $activeTab = 'outbound';
                    $orderNumber  = trim($_POST['order_number'] ?? '');
                    $sourceType   = trim($_POST['source_type'] ?? 'SALES_DELIVERY');
                    $entityName   = trim($_POST['entity_name'] ?? '');
                    $requiredDate = trim($_POST['required_date'] ?? date('Y-m-d'));
                    $itemId       = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity  = $_POST['quantity'] ?? null;
                    $notes        = trim($_POST['notes'] ?? '');
                    $sourceWhId   = (int)($currentWarehouseId ?: 1);

                    if (empty($orderNumber)) throw new InvalidArgumentException("Order number is required.");
                    if (empty($entityName)) throw new InvalidArgumentException("Customer / Department name is required.");
                    if ($itemId <= 0) throw new InvalidArgumentException("Please select an item.");
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'requested quantity');

                    $pdo->prepare("INSERT INTO outbound_orders (order_number, source_type, entity_name, warehouse_id, required_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)")
                        ->execute([$orderNumber, $sourceType, $entityName, $sourceWhId, $requiredDate ?: null, $notes ?: null, $userId]);
                    $newOrderId = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO outbound_order_items (outbound_order_id, item_id, requested_quantity, dispatched_quantity) VALUES (?, ?, ?, 0)")
                        ->execute([$newOrderId, $itemId, $quantity]);

                    $successMessage = "Outbound order " . htmlspecialchars($orderNumber) . " registered in the dispatch queue.";
                    break;

                default:
                    break;
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

// =============================================================================
// DATA QUERIES: SECTION 1 (INBOUND / STOCK IN)
// =============================================================================

// Consolidated query: Fetch all active items with warehouse inventory balance
$allItemsStmt = $pdo->prepare("
    SELECT i.item_id, i.code AS item_code, i.name AS item_name, i.type AS item_type, i.unit, COALESCE(s.qty_on_hand, 0.000) AS current_stock
    FROM items i 
    LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = :wid
    WHERE i.status = 'active' 
    ORDER BY i.type ASC, i.name ASC
");
$allItemsStmt->execute([':wid' => $currentWarehouseId]);
$allItems = $allItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock In transactions strictly for assigned warehouse
$stmtIn = $pdo->prepare("
    SELECT 
        sm.movement_id AS stock_in_id,
        CONCAT('IN-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        CASE 
            WHEN i.type = 'finished_good' THEN 'PRODUCTION_RETURN'
            WHEN sm.remarks LIKE '%PO%' OR sm.remarks LIKE '%PURCHASE%' THEN 'PURCHASE_ORDER'
            ELSE 'PURCHASE_ORDER'
        END AS source_type,
        COALESCE(
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
        CONCAT(i.name, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ROUND(sm.quantity, 2))), ' ', i.unit, ')') AS item_breakdown,
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
      AND sm.movement_type = 'STOCK_IN'
      AND sm.remarks NOT LIKE 'Cancelled Stock OUT %'
      AND sm.remarks NOT LIKE '[INITIAL_BALANCE]%'
    ORDER BY sm.movement_id DESC
");
$stmtIn->execute([':wid' => $currentWarehouseId]);
$stockIns = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

// Build line items array for modal inspection
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

// KPI Metrics scoped strictly to assigned warehouse (completed transactions only)
$totalStockInTxns    = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed'));
$procurementInbounds = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'PURCHASE_ORDER'));
$productionInbounds  = count(array_filter($stockIns, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'PRODUCTION_RETURN'));

// =============================================================================
// DATA QUERIES: SECTION 2 (OUTBOUND / STOCK OUT)
// =============================================================================

// Items available in this warehouse with positive balance (reusing consolidated item query)
$availableItems = array_values(array_filter($allItems, fn($it) => (float)$it['current_stock'] > 0));

// Fetch Stock Out transactions strictly for assigned warehouse
$stmtOut = $pdo->prepare("
    SELECT 
        sm.movement_id AS stock_out_id,
        CONCAT('OUT-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        CASE 
            WHEN i.type = 'raw_material' THEN 'MATERIAL_REQUEST'
            ELSE 'SALES_DELIVERY'
        END AS source_type,
        COALESCE(
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
        CONCAT(i.name, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ROUND(sm.quantity, 2))), ' ', i.unit, ')') AS item_breakdown,
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
      AND sm.movement_type = 'STOCK_OUT'
      AND sm.remarks NOT LIKE 'Cancelled Stock IN %'
    ORDER BY sm.movement_id DESC
");
$stmtOut->execute([':wid' => $currentWarehouseId]);
$stockOuts = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

// Build line items array for modal inspection
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

// KPI Metrics scoped strictly to assigned warehouse (completed transactions only)
$totalStockOutTxns = count(array_filter($stockOuts, fn($r) => ($r['status'] ?? '') === 'completed'));
$salesDispatches   = count(array_filter($stockOuts, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'SALES_DELIVERY'));
$materialRequests  = count(array_filter($stockOuts, fn($r) => ($r['status'] ?? '') === 'completed' && ($r['source_type'] ?? '') === 'MATERIAL_REQUEST'));

// =============================================================================
// ERP DOCUMENT QUEUES: PENDING INBOUND & OUTBOUND ORDERS
// =============================================================================

// 1. Pending Inbound Shipments (Receiving Dock Queue)
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

// Inbound line items
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

// 2. Pending Outbound Orders (Fulfillment & Shipping Dock Queue)
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

// Outbound line items with current available stock
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
        COALESCE(s.qty_on_hand, 0.00) AS available_stock
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
$customerReturnsCount = count(array_filter($stockIns, fn($r) => ($r['source_type'] ?? '') === 'CUSTOMER_RETURN'));
$supplierReturnsCount = count(array_filter($stockOuts, fn($r) => ($r['source_type'] ?? '') === 'SUPPLIER_RETURN'));
?>

<!-- Page Header -->
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Inventory Inbound &amp; Outbound</h1>
        <p class="page-subtitle">Unified transaction ledger for receipts and dispatches</p>
    </div>
</div>

<!-- ========================================================================= -->
<!-- NAVIGATION SWITCHER: INBOUND | OUTBOUND                                   -->
<!-- ========================================================================= -->
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
<!-- PANE 1: INBOUND SECTION                                                   -->
<!-- ######################################################################### -->
<div id="pane-inbound" class="stock-op-pane" style="display: <?= $activeTab === 'inbound' ? 'block' : 'none' ?>;">
    <!-- Inbound KPI Cards -->
    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card" style="border-left: 4px solid #F59E0B;">
            <div class="stat-header">
                <span class="stat-label">Pending Inbounds</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #D97706; background: #FEF3C7;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $pendingInboundCount ?></div>
            <div class="stat-meta">Awaiting warehouse dock receiving</div>
        </div>

        <div class="stat-card stat-gold">
            <div class="stat-header">
                <span class="stat-label">Procurement (Raw Materials)</span>
                <div class="stat-icon-wrap" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                        <polyline points="2 17 12 22 22 17"/>
                        <polyline points="2 12 12 17 22 12"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $procurementInbounds ?></div>
            <div class="stat-meta">Inbound ingredients received via PO</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Production (Finished Goods)</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--accent); background: var(--accent-light);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m16 16 2 2 4-4"/>
                        <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $productionInbounds ?></div>
            <div class="stat-meta">Bottles received from distillery</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Customer Returns (RMA)</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #7C3AED; background: #F3E8FF;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 14 4 9 9 4"/>
                        <path d="M20 20v-7a4 4 0 0 0-4-4H4"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $customerReturnsCount ?></div>
            <div class="stat-meta">RMA sales returns processed</div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- INBOUND QUEUE: EXPECTED SHIPMENTS (RECEIVING DOCK)                   -->
    <!-- ===================================================================== -->
    <div class="card" style="margin-bottom: 24px; border-top: 3px solid #15803D;">
        <div class="card-header">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 class="card-title" style="margin: 0;">Expected Inbound Shipments (Receiving Dock)</h2>
                    <span class="badge status-pending" style="font-size: 11px; padding: 2px 8px;"><?= $pendingInboundCount ?> Orders Awaiting Check-In</span>
                </div>
                <p class="card-desc" style="margin: 4px 0 0 0;">
                    Document-driven receiving: Select an open PO, Production Batch, or Customer RMA return to verify and accept into stock
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" onclick="openNewInboundOrderModal()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>+ Register Inbound Order / RMA</span>
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table>
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
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($pIn['order_number']) ?>
                                </td>
                                <td>
                                    <?php if ($pIn['source_type'] === 'PURCHASE_ORDER'): ?>
                                        <span class="badge-source-procurement">Purchase Order</span>
                                    <?php elseif ($pIn['source_type'] === 'PRODUCTION_RECEIPT' || $pIn['source_type'] === 'PRODUCTION_RETURN'): ?>
                                        <span class="badge-source-production">Production Batch</span>
                                    <?php elseif ($pIn['source_type'] === 'CUSTOMER_RETURN'): ?>
                                        <span class="badge" style="background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE;">Customer RMA Return</span>
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
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openReceiveOrderModal(<?= (int)$pIn['inbound_order_id'] ?>)">
                                        Receive Shipment &rarr;
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Inbound Transaction Ledger Card -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Completed Inbound Receipts (Transaction Ledger)</h2>
                <p class="card-desc">Audited chronological ledger of completed stock-in movements</p>
            </div>
            <div class="filter-group">
                <!-- Search Filter -->
                <div class="search-wrap">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="stockInSearch" class="search-box" aria-label="Filter inbound transactions" placeholder="Filter item, ref, PO #..." oninput="filterStockInTable()">
                </div>

                <!-- Source Filter -->
                <select id="sourceTypeFilter" class="select-filter" aria-label="Filter by inbound source" onchange="filterStockInTable()">
                    <option value="">All Sources</option>
                    <option value="PURCHASE_ORDER">Procurement (Purchase Orders)</option>
                    <option value="PRODUCTION_RETURN">Production (Work Orders)</option>
                    <option value="CUSTOMER_RETURN">Customer (RMA Returns)</option>
                    <option value="MANUAL">Internal / Direct Exception</option>
                </select>

                <!-- Item Type Filter -->
                <select id="itemTypeFilter" class="select-filter" aria-label="Filter inbound by item classification" onchange="filterStockInTable()">
                    <option value="">All Classifications</option>
                    <option value="raw_material">Raw Materials</option>
                    <option value="finished_good">Finished Goods</option>
                </select>

                <button type="button" class="btn btn-secondary" onclick="resetStockInFilters()">
                    Reset
                </button>

                <?php if (in_array($currentUser['role'] ?? '', ['super_admin', 'admin'], true)): ?>
                    <button type="button" class="btn btn-secondary" onclick="openRecordStockInModal()" title="Direct emergency receipt override">
                        + Direct Stock In (Admin)
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table id="stockInTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Quantity</th>
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

                            // Derive item classification
                            $itemType = 'raw_material';
                            if ($row['source_type'] === 'PRODUCTION_RETURN') {
                                $itemType = 'finished_good';
                            } elseif (!empty($lines)) {
                                $types = array_unique(array_column($lines, 'item_type'));
                                $itemType = count($types) === 1 ? $types[0] : 'mixed';
                            }
                        ?>
                            <tr data-source="<?= htmlspecialchars($row['source_type']) ?>" data-type="<?= htmlspecialchars($itemType) ?>">
                                <!-- Source -->
                                <td>
                                    <?php if ($row['source_type'] === 'PURCHASE_ORDER'): ?>
                                        <span class="badge-source-procurement">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                                            Procurement
                                        </span>
                                    <?php elseif ($row['source_type'] === 'PRODUCTION_RETURN'): ?>
                                        <span class="badge-source-production">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m16 16 2 2 4-4"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/></svg>
                                            Production
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-source-manual">
                                            Internal
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Item -->
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

                                <!-- Type -->
                                <td>
                                    <?php if ($itemType === 'finished_good'): ?>
                                        <span class="badge-type type-fg">Finished Good</span>
                                    <?php elseif ($itemType === 'raw_material'): ?>
                                        <span class="badge-type type-raw">Raw Material</span>
                                    <?php else: ?>
                                        <span class="badge-type type-raw">Mixed (<?= count($lines) ?>)</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Quantity -->
                                <td style="font-weight: 700; color: #15803D; white-space: nowrap;">
                                    +<?= formatQty((float)$row['total_quantity']) ?>
                                    <?php if (!$hasMultiple && $firstLine): ?>
                                        <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($firstLine['unit']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <!-- Reference / Tracking # -->
                                <td>
                                    <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--panel-ink);">
                                        <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                                    </div>
                                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">
                                        <?= htmlspecialchars($row['transaction_number']) ?>
                                    </small>
                                </td>

                                <!-- Date Received -->
                                <td style="font-size: 12.5px; white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                                    <small style="display: block; color: var(--gray); font-size: 11px;">
                                        <?= date('h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>

                                <!-- Status -->
                                <td>
                                    <?php if ($row['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Received</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Action -->
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px; color: #B91C1C; border-color: #FCA5A5;" onclick="openCancelStockInModal(<?= (int)$row['stock_in_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
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

<!-- Line Item Details Modal (Inbound) -->
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
                            <th>Quantity Received</th>
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


<!-- ######################################################################### -->
<!-- PANE 2: OUTBOUND SECTION                                                  -->
<!-- ######################################################################### -->
<div id="pane-outbound" class="stock-op-pane" style="display: <?= $activeTab === 'outbound' ? 'block' : 'none' ?>;">
    <!-- Outbound KPI Cards -->
    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card" style="border-left: 4px solid #DC2626;">
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
            <div class="stat-meta">Awaiting warehouse picking &amp; shipping</div>
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
            <div class="stat-meta">Customer orders fulfilled &amp; dispatched</div>
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
            <div class="stat-meta">Raw materials issued to distilling line</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Supplier Returns (RTV)</span>
                <div class="stat-icon-wrap" aria-hidden="true" style="color: #B45309; background: #FEF3C7;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 10 20 15 15 20"/>
                        <path d="M4 4v7a4 4 0 0 0 4 4h12"/>
                    </svg>
                </div>
            </div>
            <div class="stat-value"><?= $supplierReturnsCount ?></div>
            <div class="stat-meta">Defective items returned to vendors</div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- OUTBOUND QUEUE: PENDING ORDERS (FULFILLMENT & SHIPPING DOCK)          -->
    <!-- ===================================================================== -->
    <div class="card" style="margin-bottom: 24px; border-top: 3px solid #DC2626;">
        <div class="card-header">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 class="card-title" style="margin: 0;">Pending Outbound Orders (Fulfillment &amp; Shipping Dock)</h2>
                    <span class="badge status-pending" style="font-size: 11px; padding: 2px 8px;"><?= $pendingOutboundCount ?> Orders Awaiting Dispatch</span>
                </div>
                <p class="card-desc" style="margin: 4px 0 0 0;">
                    Document-driven fulfillment: Select an approved Sales Order, Material Requisition, or Return to Vendor (RTV) to pack and ship
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Order / Requisition #</th>
                        <th>Document Type</th>
                        <th>Customer / Recipient / Vendor</th>
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
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($pOut['order_number']) ?>
                                </td>
                                <td>
                                    <?php if ($pOut['source_type'] === 'SALES_DELIVERY'): ?>
                                        <span class="badge" style="background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE;">Sales Order Delivery</span>
                                    <?php elseif ($pOut['source_type'] === 'MATERIAL_REQUEST'): ?>
                                        <span class="badge-source-production">Material Requisition</span>
                                    <?php elseif ($pOut['source_type'] === 'SUPPLIER_RETURN'): ?>
                                        <span class="badge" style="background: #FEF3C7; color: #B45309; border: 1px solid #FCD34D;">Return to Vendor (RTV)</span>
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
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openDispatchOrderModal(<?= (int)$pOut['outbound_order_id'] ?>)">
                                        Dispatch Order &rarr;
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Outbound Main Table Card -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Completed Outbound Dispatches (Transaction Ledger)</h2>
                <p class="card-desc">Audited permanent ledger of completed stock-out dispatches</p>
            </div>
            <div class="filter-group">
                <!-- Search Filter -->
                <div class="search-wrap">
                    <span class="search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="stockOutSearch" class="search-box" aria-label="Filter outbound transactions" placeholder="Filter item, ref, SO/MR #..." oninput="filterStockOutTable()">
                </div>

                <!-- Destination Filter -->
                <select id="stockOutDestFilter" class="select-filter" aria-label="Filter by outbound destination" onchange="filterStockOutTable()">
                    <option value="">All Destinations</option>
                    <option value="SALES_DELIVERY">Sales (Sales Deliveries)</option>
                    <option value="MATERIAL_REQUEST">Production (Material Requests)</option>
                    <option value="SUPPLIER_RETURN">Supplier Returns (RTV)</option>
                    <option value="MANUAL">Internal / Direct Exception</option>
                </select>

                <!-- Item Type Filter -->
                <select id="stockOutTypeFilter" class="select-filter" aria-label="Filter outbound by item classification" onchange="filterStockOutTable()">
                    <option value="">All Classifications</option>
                    <option value="finished_good">Finished Goods</option>
                    <option value="raw_material">Raw Materials</option>
                </select>

                <button type="button" class="btn btn-secondary" onclick="resetStockOutFilters()">
                    Reset
                </button>

                <?php if (in_array($currentUser['role'] ?? '', ['super_admin', 'admin'], true)): ?>
                    <button type="button" class="btn btn-secondary" onclick="openRecordStockOutModal()" title="Emergency direct dispatch override">
                        + Direct Stock Out (Admin)
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table id="stockOutTable" class="table-sticky-actions">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Quantity</th>
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

                            // Derive item classification
                            $itemType = 'finished_good';
                            if ($row['source_type'] === 'MATERIAL_REQUEST') {
                                $itemType = 'raw_material';
                            } elseif (!empty($lines)) {
                                $types = array_unique(array_column($lines, 'item_type'));
                                $itemType = count($types) === 1 ? $types[0] : 'mixed';
                            }
                        ?>
                            <tr data-destination="<?= htmlspecialchars($row['source_type']) ?>" data-type="<?= htmlspecialchars($itemType) ?>">
                                <!-- Destination -->
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

                                <!-- Item -->
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

                                <!-- Type -->
                                <td>
                                    <?php if ($itemType === 'finished_good'): ?>
                                        <span class="badge-type type-fg">Finished Good</span>
                                    <?php elseif ($itemType === 'raw_material'): ?>
                                        <span class="badge-type type-raw">Raw Material</span>
                                    <?php else: ?>
                                        <span class="badge-type type-raw">Mixed (<?= count($lines) ?>)</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Quantity -->
                                <td style="font-weight: 700; color: #B91C1C; white-space: nowrap;">
                                    -<?= formatQty((float)$row['total_quantity']) ?>
                                    <?php if (!$hasMultiple && $firstLine): ?>
                                        <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($firstLine['unit']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <!-- Reference / Order # -->
                                <td>
                                    <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--panel-ink);">
                                        <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                                    </div>
                                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">
                                        <?= htmlspecialchars($row['transaction_number']) ?>
                                    </small>
                                </td>

                                <!-- Date Dispatched -->
                                <td style="font-size: 12.5px; white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                                    <small style="display: block; color: var(--gray); font-size: 11px;">
                                        <?= date('h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </td>

                                <!-- Status -->
                                <td>
                                    <?php if ($row['status'] === 'completed'): ?>
                                        <span class="badge status-completed">Dispatched</span>
                                    <?php else: ?>
                                        <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Action -->
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px; color: #B91C1C; border-color: #FCA5A5;" onclick="openCancelStockOutModal(<?= (int)$row['stock_out_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
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

<!-- Line Item Details Modal (Outbound) -->
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
                            <th>Quantity Dispatched</th>
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

<!-- Modal: Cancel Stock In (Inbound Receipt) -->
<div id="cancelStockInModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cancelStockInModalTitle" onclick="if(event.target === this) closeCancelStockInModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php?tab=inbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_stock_in">
            <input type="hidden" name="active_tab" value="inbound">
            <input type="hidden" name="stock_in_id" id="cancelStockInId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="cancelStockInModalTitle" class="card-title" style="margin: 0; color: #B91C1C;">Cancel Inbound Receipt</h3>
                    <p class="card-desc" style="margin: 0;">Reverse Stock In and deduct received quantity from inventory</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCancelStockInModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Are you sure you want to cancel inbound receipt <strong id="cancelStockInRef" style="font-family: monospace;">—</strong>?
                </p>
                <div>
                    <label for="cancelStockInReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelStockInReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px;" placeholder="Reason for cancelling this inbound receipt..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelStockInModal()">Close</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Cancel Stock Out (Outbound Dispatch) -->
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

<!-- Modal: Record Stock In (Inbound Receipt) -->
<div id="recordStockInModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="recordStockInModalTitle" onclick="if(event.target === this) handleRecordBackdropClose('recordStockInModal', closeRecordStockInModal)">
    <div class="modal-card" style="max-width: 540px;">
        <form method="POST" action="index.php?tab=inbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_stock_in">
            <input type="hidden" name="active_tab" value="inbound">

            <div class="modal-header">
                <div>
                    <h3 id="recordStockInModalTitle" class="card-title">Record Stock In (Inbound Receipt)</h3>
                    <p class="card-desc">Receive inventory into <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeRecordStockInModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Source Type -->
                <div>
                    <label for="stockInSourceType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Inbound Source <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="source_type" id="stockInSourceType" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="filterStockInModalItems()">
                        <option value="PURCHASE_ORDER">Procurement Purchase Order (Raw Materials)</option>
                        <option value="PRODUCTION_RETURN">Production Batch Receipt (Finished Goods)</option>
                    </select>
                </div>

                <!-- Source Reference Number -->
                <div>
                    <label for="stockInRefNo" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Reference Number (PO # / Work Order #) <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="source_reference_no" id="stockInRefNo" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" maxlength="100" placeholder="e.g. PO-2026-001 or WO-2026-001" required>
                </div>

                <!-- Item Selection -->
                <div>
                    <label for="stockInItemSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Item to Receive <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="stockInItemSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="handleStockInItemChange(this)">
                        <option value="">-- Select Item to Receive --</option>
                        <?php foreach ($allItems as $it): ?>
                            <option value="<?= (int)$it['item_id'] ?>" data-type="<?= htmlspecialchars($it['item_type']) ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>">
                                [<?= $it['item_type'] === 'finished_good' ? 'Finished Good' : 'Raw Material' ?>] <?= htmlspecialchars($it['item_code']) ?> &mdash; <?= htmlspecialchars($it['item_name']) ?> (<?= htmlspecialchars($it['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Quantity Received -->
                <div>
                    <label for="stockInQuantity" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Quantity Received <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="any" min="0.001" name="quantity" id="stockInQuantity" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0" required>
                        <span id="stockInUnitLabel" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                </div>

                <!-- Remarks -->
                <div>
                    <label for="stockInRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Remarks / Receiving Notes
                    </label>
                    <textarea name="remarks" id="stockInRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px; resize: vertical;" placeholder="Optional supplier, delivery, or QC inspection notes..."></textarea>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeRecordStockInModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Record Stock In</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Record Stock Out (Outbound Dispatch) -->
<div id="recordStockOutModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="recordStockOutModalTitle" onclick="if(event.target === this) handleRecordBackdropClose('recordStockOutModal', closeRecordStockOutModal)">
    <div class="modal-card" style="max-width: 540px;">
        <form method="POST" action="index.php?tab=outbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_stock_out">
            <input type="hidden" name="active_tab" value="outbound">

            <div class="modal-header">
                <div>
                    <h3 id="recordStockOutModalTitle" class="card-title">Record Stock Out (Outbound Dispatch)</h3>
                    <p class="card-desc">Dispatch inventory from <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeRecordStockOutModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Destination / Source Type -->
                <div>
                    <label for="stockOutSourceType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Outbound Destination <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="source_type" id="stockOutSourceType" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="filterStockOutModalItems()">
                        <option value="SALES_DELIVERY">Sales Delivery (Finished Goods)</option>
                        <option value="MATERIAL_REQUEST">Production Material Request (Raw Materials)</option>
                    </select>
                </div>

                <!-- Reference Number -->
                <div>
                    <label for="stockOutRefNo" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Reference Number (Sales Order # / Material Request #) <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="source_reference_no" id="stockOutRefNo" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" maxlength="100" placeholder="e.g. SO-2026-001 or MR-2026-001" required>
                </div>

                <!-- Item Selection -->
                <div>
                    <label for="stockOutItemSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Item to Dispatch <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="stockOutItemSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="handleStockOutItemChange(this)">
                        <option value="">-- Select Item with Available Stock --</option>
                        <?php foreach ($availableItems as $it): ?>
                            <option value="<?= (int)$it['item_id'] ?>" data-type="<?= htmlspecialchars($it['item_type']) ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>" data-stock="<?= (float)$it['current_stock'] ?>">
                                <?= htmlspecialchars($it['item_code']) ?> &mdash; <?= htmlspecialchars($it['item_name']) ?> [Stock: <?= formatQty((float)$it['current_stock']) ?> <?= htmlspecialchars($it['unit']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Quantity Dispatched -->
                <div>
                    <label for="stockOutQuantity" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Quantity to Dispatch <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="any" min="0.001" name="quantity" id="stockOutQuantity" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0" required>
                        <span id="stockOutUnitLabel" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                    <small id="stockOutAvailHint" style="color: var(--gray); font-size: 11.5px; display: block; margin-top: 4px;">Select an item to view maximum available balance.</small>
                </div>

                <!-- Remarks -->
                <div>
                    <label for="stockOutRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Remarks / Dispatch Notes
                    </label>
                    <textarea name="remarks" id="stockOutRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 60px; padding: 8px 12px; resize: vertical;" placeholder="Optional customer delivery or production line notes..."></textarea>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeRecordStockOutModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= empty($availableItems) ? 'disabled' : '' ?>>Record Stock Out</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: RECEIVE INBOUND ORDER / SHIPMENT (DOCUMENT-DRIVEN RECEIVING & QA)  -->
<!-- ========================================================================= -->
<div id="receiveOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="receiveOrderModalTitle" onclick="if(event.target === this) closeReceiveOrderModal()">
    <div class="modal-card" style="max-width: 680px;">
        <form method="POST" action="index.php?tab=inbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="receive_inbound_order">
            <input type="hidden" name="active_tab" value="inbound">
            <input type="hidden" name="inbound_order_id" id="receiveInboundOrderId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="receiveOrderModalTitle" class="card-title" style="margin: 0; color: #15803D;">Receive Inbound Shipment</h3>
                    <p class="card-desc" style="margin: 0;">Perform quality inspection, confirm quantities, and receive into warehouse</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeReceiveOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Summary Card -->
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

                <!-- Condition Selection (QA Disposition & Customer RMA routing) -->
                <div id="recConditionGroup" style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 12px 14px;">
                    <label for="receiveCondition" style="font-size: 12.5px; font-weight: 700; color: #166534; display: block; margin-bottom: 6px;">
                        Quality Inspection &amp; Stock Disposition <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_condition" id="receiveCondition" class="select-filter" style="width: 100%; height: 38px; border-radius: 6px; font-size: 13px;" onchange="handleReceiveConditionChange(this)">
                        <option value="salable">Passed Inspection / Salable &mdash; Receive into Available Stock (Stock In)</option>
                        <option value="damaged">Damaged / Defective / Expired &mdash; Route directly to Bad Products Discard</option>
                    </select>
                    <small id="recConditionHint" style="display: block; margin-top: 4px; font-size: 11.5px; color: #15803D;">
                        Salable items will increase current inventory balance.
                    </small>
                </div>

                <!-- Line Items Table -->
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Line Items to Receive
                    </label>
                    <div class="table-responsive" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
                        <table style="width: 100%; font-size: 12.5px; margin: 0;">
                            <thead>
                                <tr style="background: #F8FAFC;">
                                    <th>Item Code &amp; Name</th>
                                    <th style="text-align: right;">Expected</th>
                                    <th style="text-align: right;">Received</th>
                                    <th style="text-align: right;">Remaining</th>
                                    <th style="width: 140px; text-align: right;">Receive Qty</th>
                                </tr>
                            </thead>
                            <tbody id="receiveOrderLinesTbody">
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Receiving Remarks -->
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

<!-- ========================================================================= -->
<!-- MODAL: REGISTER INBOUND ORDER (PO / PRODUCTION RECEIPT / CUSTOMER RMA)    -->
<!-- ========================================================================= -->
<div id="newInboundOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="newInboundOrderModalTitle" onclick="if(event.target === this) closeNewInboundOrderModal()">
    <div class="modal-card" style="max-width: 540px;">
        <form method="POST" action="index.php?tab=inbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_inbound_order">
            <input type="hidden" name="active_tab" value="inbound">

            <div class="modal-header">
                <div>
                    <h3 id="newInboundOrderModalTitle" class="card-title">Register Inbound Order / RMA</h3>
                    <p class="card-desc">Add an expected shipment document to the receiving queue for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?></p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeNewInboundOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Source Type -->
                <div>
                    <label for="newInSourceType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Document Type <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="source_type" id="newInSourceType" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="filterNewInboundItems()">
                        <option value="PURCHASE_ORDER">Purchase Order &mdash; Supplier Delivery (Raw Materials)</option>
                        <option value="PRODUCTION_RECEIPT">Production Receipt &mdash; Distillery Output (Finished Goods)</option>
                        <option value="CUSTOMER_RETURN">Customer Return &mdash; RMA Return (Finished Goods)</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- Order / Tracking Number -->
                    <div>
                        <label for="newInOrderNumber" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Document / RMA # <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="order_number" id="newInOrderNumber" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" maxlength="100" placeholder="e.g. PO-2026-088 or RMA-042" required>
                    </div>

                    <!-- Expected Date -->
                    <div>
                        <label for="newInExpectedDate" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Expected Date
                        </label>
                        <input type="date" name="expected_date" id="newInExpectedDate" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <!-- Supplier / Customer / Origin -->
                <div>
                    <label for="newInEntityName" id="newInEntityLabel" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Supplier / Origin Name <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="entity_name" id="newInEntityName" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" maxlength="150" placeholder="e.g. Apex Glassware Ltd. or Customer Name" required>
                </div>

                <!-- Item Selection -->
                <div>
                    <label for="newInItemSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Expected Item <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="newInItemSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="handleNewInItemChange(this)">
                        <option value="">-- Select Item --</option>
                        <?php foreach ($allItems as $it): ?>
                            <option value="<?= (int)$it['item_id'] ?>" data-type="<?= htmlspecialchars($it['item_type']) ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>">
                                [<?= $it['item_type'] === 'finished_good' ? 'Finished Good' : 'Raw Material' ?>] <?= htmlspecialchars($it['item_code']) ?> &mdash; <?= htmlspecialchars($it['item_name']) ?> (<?= htmlspecialchars($it['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Expected Quantity -->
                <div>
                    <label for="newInQuantity" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Expected Quantity <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="any" min="0.001" name="quantity" id="newInQuantity" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0" required>
                        <span id="newInUnitLabel" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                </div>

                <!-- Notes -->
                <div>
                    <label for="newInNotes" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Order Instructions / Notes
                    </label>
                    <textarea name="notes" id="newInNotes" class="search-box" style="width: 100%; border-radius: 8px; height: 50px; padding: 8px 12px; resize: vertical;" placeholder="Optional details, return reason, or tracking information..."></textarea>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewInboundOrderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Add to Receiving Queue</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: DISPATCH OUTBOUND ORDER (FULFILLMENT, ALLOCATION & DISPATCH)        -->
<!-- ========================================================================= -->
<div id="dispatchOrderModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="dispatchOrderModalTitle" onclick="if(event.target === this) closeDispatchOrderModal()">
    <div class="modal-card" style="max-width: 680px;">
        <form method="POST" action="index.php?tab=outbound">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="dispatch_outbound_order">
            <input type="hidden" name="active_tab" value="outbound">
            <input type="hidden" name="outbound_order_id" id="dispatchOutboundOrderId" value="">

            <div class="modal-header">
                <div>
                    <h3 id="dispatchOrderModalTitle" class="card-title" style="margin: 0; color: #1D4ED8;">Fulfill &amp; Dispatch Outbound Order</h3>
                    <p class="card-desc" style="margin: 0;">Verify available inventory, pack quantities, and dispatch to carrier or department</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeDispatchOrderModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Summary Card -->
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

                <!-- Line Items Table -->
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Requested Items &amp; Stock Availability
                    </label>
                    <div class="table-responsive" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px;">
                        <table style="width: 100%; font-size: 12.5px; margin: 0;">
                            <thead>
                                <tr style="background: #F8FAFC;">
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

                <!-- Dispatch Remarks -->
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
// =============================================================================
// RECORD STOCK IN & RECORD STOCK OUT MODAL CONTROLLERS
// =============================================================================
function handleRecordBackdropClose(modalId, closeFn) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    const inputs = Array.from(modal.querySelectorAll('input[type="text"], input[type="number"], textarea'));
    const hasUnsaved = inputs.some(el => el.value.trim() !== '');
    if (hasUnsaved) return;
    closeFn();
}

function openRecordStockInModal() {
    filterStockInModalItems();
    const modal = document.getElementById('recordStockInModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeRecordStockInModal() {
    const modal = document.getElementById('recordStockInModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterStockInModalItems() {
    const sourceSelect = document.getElementById('stockInSourceType');
    const itemSelect = document.getElementById('stockInItemSelect');
    if (!sourceSelect || !itemSelect) return;

    const source = sourceSelect.value;
    const targetType = (source === 'PRODUCTION_RETURN') ? 'finished_good' : 'raw_material';

    let validCount = 0;
    Array.from(itemSelect.options).forEach((opt, idx) => {
        if (idx === 0) return;
        const itemType = opt.getAttribute('data-type');
        const matches = (itemType === targetType);
        opt.hidden = !matches;
        opt.disabled = !matches;
        opt.style.display = matches ? '' : 'none';
        if (matches) validCount++;
    });

    const selectedOpt = itemSelect.selectedOptions[0];
    if (selectedOpt && (selectedOpt.disabled || selectedOpt.hidden)) {
        itemSelect.value = '';
        handleStockInItemChange(itemSelect);
    }

    const defaultOpt = itemSelect.options[0];
    if (defaultOpt) {
        if (targetType === 'finished_good') {
            defaultOpt.textContent = validCount > 0 ? '-- Select Finished Good to Receive --' : '-- No active finished goods available --';
        } else {
            defaultOpt.textContent = validCount > 0 ? '-- Select Raw Material to Receive --' : '-- No active raw materials available --';
        }
    }
}

function handleStockInItemChange(selectEl) {
    const opt = selectEl.selectedOptions[0];
    const unitLabel = document.getElementById('stockInUnitLabel');
    if (opt && opt.value) {
        unitLabel.textContent = opt.getAttribute('data-unit') || '—';
    } else {
        unitLabel.textContent = '—';
    }
}

function openRecordStockOutModal() {
    filterStockOutModalItems();
    const modal = document.getElementById('recordStockOutModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeRecordStockOutModal() {
    const modal = document.getElementById('recordStockOutModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterStockOutModalItems() {
    const sourceSelect = document.getElementById('stockOutSourceType');
    const itemSelect = document.getElementById('stockOutItemSelect');
    if (!sourceSelect || !itemSelect) return;

    const source = sourceSelect.value;
    const targetType = (source === 'SALES_DELIVERY') ? 'finished_good' : 'raw_material';

    let validCount = 0;
    Array.from(itemSelect.options).forEach((opt, idx) => {
        if (idx === 0) return;
        const itemType = opt.getAttribute('data-type');
        const matches = (itemType === targetType);
        opt.hidden = !matches;
        opt.disabled = !matches;
        opt.style.display = matches ? '' : 'none';
        if (matches) validCount++;
    });

    const selectedOpt = itemSelect.selectedOptions[0];
    if (selectedOpt && (selectedOpt.disabled || selectedOpt.hidden)) {
        itemSelect.value = '';
        handleStockOutItemChange(itemSelect);
    }

    const defaultOpt = itemSelect.options[0];
    if (defaultOpt) {
        if (targetType === 'finished_good') {
            defaultOpt.textContent = validCount > 0 ? '-- Select Finished Good to Dispatch --' : '-- No finished goods with available stock in warehouse --';
        } else {
            defaultOpt.textContent = validCount > 0 ? '-- Select Raw Material to Dispatch --' : '-- No raw materials with available stock in warehouse --';
        }
    }
}

function handleStockOutItemChange(selectEl) {
    const opt = selectEl.selectedOptions[0];
    const unitLabel = document.getElementById('stockOutUnitLabel');
    const qtyInput = document.getElementById('stockOutQuantity');
    const hint = document.getElementById('stockOutAvailHint');

    if (opt && opt.value) {
        const unit = opt.getAttribute('data-unit') || '';
        const stock = parseFloat(opt.getAttribute('data-stock') || '0');
        unitLabel.textContent = unit || '—';
        qtyInput.max = stock;
        hint.textContent = `Available balance: ${stock} ${unit}`;
    } else {
        unitLabel.textContent = '—';
        qtyInput.removeAttribute('max');
        hint.textContent = 'Select an item to view maximum available balance.';
    }
}

// =============================================================================
// TAB SWITCHING CONTROLLER
// =============================================================================
function switchStockTab(tabName) {
    const validTabs = ['inbound', 'outbound'];
    if (!validTabs.includes(tabName)) tabName = 'inbound';

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
    window.history.replaceState({ tab: tabName }, '', url.toString());

    // Trigger pagination re-calculation for the newly visible table
    const activeTable = (tabName === 'inbound') 
        ? document.getElementById('stockInTable') 
        : document.getElementById('stockOutTable');
    if (activeTable && typeof activeTable.paginationUpdate === 'function') {
        activeTable.paginationUpdate(false);
    }
}

// Support browser back/forward buttons
window.addEventListener('popstate', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || 'inbound';
    switchStockTab(tab);
});

// =============================================================================
// SECTION 1: INBOUND SCRIPTS
// =============================================================================
const linesData = <?= json_encode($linesByStockIn) ?>;
const stockInList = <?= json_encode($stockIns) ?>;
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
                <td style="font-weight: 700; color: #15803D;">+${formatQty(l.quantity)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
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

// =============================================================================
// SECTION 2: OUTBOUND SCRIPTS
// =============================================================================
const outLinesData = <?= json_encode($linesByStockOut) ?>;
const stockOutList = <?= json_encode($stockOuts) ?>;
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
                <td style="font-weight: 700; color: #B91C1C;">-${formatQty(l.quantity)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
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

function openCancelStockInModal(id, txnNo) {
    document.getElementById('cancelStockInId').value = id;
    document.getElementById('cancelStockInRef').textContent = txnNo;
    document.getElementById('cancelStockInReason').value = '';
    const modal = document.getElementById('cancelStockInModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeCancelStockInModal() {
    const modal = document.getElementById('cancelStockInModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
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

// =============================================================================
// ERP DOCUMENT QUEUES: RECEIVING & FULFILLMENT CONTROLLERS
// =============================================================================
const pendingInOrdersData   = <?= json_encode($pendingInboundOrders) ?>;
const inLinesData           = <?= json_encode($inboundOrderLines) ?>;
const pendingOutOrdersData  = <?= json_encode($pendingOutboundOrders) ?>;
const outLinesOrderData     = <?= json_encode($outboundOrderLines) ?>;

const pendingInMap = {};
pendingInOrdersData.forEach(o => { pendingInMap[o.inbound_order_id] = o; });

const pendingOutMap = {};
pendingOutOrdersData.forEach(o => { pendingOutMap[o.outbound_order_id] = o; });

// -----------------------------------------------------------------------------
// Inbound Order Receiving
// -----------------------------------------------------------------------------
function openReceiveOrderModal(id) {
    const order = pendingInMap[id];
    if (!order) return;

    document.getElementById('receiveInboundOrderId').value = id;
    document.getElementById('recOrderNum').textContent = order.order_number || '—';

    let badgeHtml = '';
    if (order.source_type === 'PURCHASE_ORDER') {
        badgeHtml = '<span class="badge-source-procurement">Purchase Order</span>';
    } else if (order.source_type === 'PRODUCTION_RECEIPT' || order.source_type === 'PRODUCTION_RETURN') {
        badgeHtml = '<span class="badge-source-production">Production Batch</span>';
    } else if (order.source_type === 'CUSTOMER_RETURN') {
        badgeHtml = '<span class="badge" style="background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE;">Customer RMA Return</span>';
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

    if (order.source_type === 'CUSTOMER_RETURN') {
        condSelect.value = 'salable';
        condHint.textContent = 'Customer returns: Select whether returned items passed QA for restock or should be discarded.';
        submitBtn.textContent = 'Confirm & Receive RMA';
        submitBtn.style.background = '#6D28D9';
        submitBtn.style.borderColor = '#6D28D9';
    } else {
        condSelect.value = 'salable';
        condHint.textContent = 'Salable items will increase current inventory balance.';
        submitBtn.textContent = 'Confirm & Receive Shipment';
        submitBtn.style.background = '#15803D';
        submitBtn.style.borderColor = '#15803D';
    }

    const tbody = document.getElementById('receiveOrderLinesTbody');
    tbody.innerHTML = '';
    const lines = inLinesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--gray); padding: 16px;">No line items pending receipt.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            const rem = parseFloat(l.remaining_quantity);
            tr.innerHTML = `
                <td style="padding: 8px 10px;">
                    <div style="font-weight: 700; color: var(--panel-ink);">${escapeHtml(l.item_name)}</div>
                    <small style="font-family: monospace; color: var(--gray); font-size: 11px;">${escapeHtml(l.item_code)}</small>
                </td>
                <td style="text-align: right; padding: 8px 10px; color: var(--gray);">${formatQty(l.expected_quantity)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px; color: var(--gray);">${formatQty(l.received_quantity)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px; font-weight: 700; color: #15803D;">${formatQty(rem)} ${escapeHtml(l.unit)}</td>
                <td style="text-align: right; padding: 8px 10px;">
                    <input type="number" step="any" min="0" max="${rem}" name="received_quantity[${l.item_id}]" value="${rem}" class="search-box" style="width: 110px; height: 34px; padding: 4px 8px; text-align: right; font-weight: 700; border-radius: 6px;" required>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

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

function handleReceiveConditionChange(selectEl) {
    const hint = document.getElementById('recConditionHint');
    const submitBtn = document.getElementById('btnConfirmReceive');
    if (selectEl.value === 'damaged') {
        hint.textContent = 'Damaged returns will NOT enter active stock. They will be logged directly into Bad Products Discard.';
        submitBtn.textContent = 'Confirm Damaged Discard (Bad Products)';
        submitBtn.style.background = '#B91C1C';
        submitBtn.style.borderColor = '#B91C1C';
    } else {
        hint.textContent = 'Salable items will increase current inventory balance.';
        submitBtn.textContent = 'Confirm & Receive Shipment';
        submitBtn.style.background = '#15803D';
        submitBtn.style.borderColor = '#15803D';
    }
}

function openNewInboundOrderModal() {
    filterNewInboundItems();
    const modal = document.getElementById('newInboundOrderModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}

function closeNewInboundOrderModal() {
    const modal = document.getElementById('newInboundOrderModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterNewInboundItems() {
    const sourceSelect = document.getElementById('newInSourceType');
    const itemSelect = document.getElementById('newInItemSelect');
    const entityLabel = document.getElementById('newInEntityLabel');
    const entityInput = document.getElementById('newInEntityName');
    if (!sourceSelect || !itemSelect) return;

    const source = sourceSelect.value;
    const targetType = (source === 'PURCHASE_ORDER') ? 'raw_material' : 'finished_good';

    if (source === 'PURCHASE_ORDER') {
        if (entityLabel) entityLabel.innerHTML = 'Supplier / Vendor Name <span style="color: #DC2626;">*</span>';
        if (entityInput) entityInput.placeholder = 'e.g. Apex Glassware Ltd. or Grain Harvest Co.';
    } else if (source === 'PRODUCTION_RECEIPT') {
        if (entityLabel) entityLabel.innerHTML = 'Production Facility / Plant <span style="color: #DC2626;">*</span>';
        if (entityInput) entityInput.placeholder = 'e.g. Distillery Plant 1 — Bottling Line A';
    } else if (source === 'CUSTOMER_RETURN') {
        if (entityLabel) entityLabel.innerHTML = 'Customer / Client Name <span style="color: #DC2626;">*</span>';
        if (entityInput) entityInput.placeholder = 'e.g. The Grand Hotel & Bar (Client RMA)';
    }

    let validCount = 0;
    Array.from(itemSelect.options).forEach((opt, idx) => {
        if (idx === 0) return;
        const itemType = opt.getAttribute('data-type');
        const matches = (itemType === targetType);
        opt.hidden = !matches;
        opt.disabled = !matches;
        opt.style.display = matches ? '' : 'none';
        if (matches) validCount++;
    });

    const selectedOpt = itemSelect.selectedOptions[0];
    if (selectedOpt && (selectedOpt.disabled || selectedOpt.hidden)) {
        itemSelect.value = '';
        handleNewInItemChange(itemSelect);
    }
    const defaultOpt = itemSelect.options[0];
    if (defaultOpt) {
        defaultOpt.textContent = (targetType === 'finished_good')
            ? (validCount > 0 ? '-- Select Finished Good --' : '-- No active finished goods --')
            : (validCount > 0 ? '-- Select Raw Material --' : '-- No active raw materials --');
    }
}

function handleNewInItemChange(selectEl) {
    const opt = selectEl.selectedOptions[0];
    const unitLabel = document.getElementById('newInUnitLabel');
    if (opt && opt.value) {
        unitLabel.textContent = opt.getAttribute('data-unit') || '—';
    } else {
        unitLabel.textContent = '—';
    }
}

// -----------------------------------------------------------------------------
// Outbound Order Dispatching
// -----------------------------------------------------------------------------
function openDispatchOrderModal(id) {
    const order = pendingOutMap[id];
    if (!order) return;

    document.getElementById('dispatchOutboundOrderId').value = id;
    document.getElementById('dispOrderNum').textContent = order.order_number || '—';

    let badgeHtml = '';
    if (order.source_type === 'SALES_DELIVERY') {
        badgeHtml = '<span class="badge" style="background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE;">Sales Order Delivery</span>';
    } else if (order.source_type === 'MATERIAL_REQUEST') {
        badgeHtml = '<span class="badge-source-production">Material Requisition</span>';
    } else if (order.source_type === 'SUPPLIER_RETURN') {
        badgeHtml = '<span class="badge" style="background: #FEF3C7; color: #B45309; border: 1px solid #FCD34D;">Return to Vendor (RTV)</span>';
    } else {
        badgeHtml = '<span class="badge-source-manual">' + escapeHtml(order.source_type) + '</span>';
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
            const rem = parseFloat(l.remaining_quantity);
            const avail = parseFloat(l.available_stock || 0);
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
                    <input type="number" step="any" min="0" max="${rem}" name="dispatched_quantity[${l.item_id}]" value="${maxDispatch}" class="search-box" style="width: 110px; height: 34px; padding: 4px 8px; text-align: right; font-weight: 700; border-radius: 6px;" required>
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

function closeDispatchOrderModal() {
    const modal = document.getElementById('dispatchOrderModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    filterStockInModalItems();
    filterStockOutModalItems();
    filterNewInboundItems();
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
