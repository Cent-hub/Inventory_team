<?php
/**
 * View: Stock Transfer (Two-Sided Transfer Ledger)
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Organizes warehouse-to-warehouse transfers into two distinct ledger sections:
 * 1. Received from Other Warehouse (Inbound destination)
 * 2. Transferred to Other Warehouse (Outbound source)
 */

$pageTitle   = 'Stock Transfer Ledger — StockPilot';
$activePage  = 'stock_transfer';
$activeGroup = 'inventory';

// Forward browser navigation to the unified Stock Operations hub
if (php_sapi_name() !== 'cli' && (!defined('IN_UNIT_TEST') || !IN_UNIT_TEST)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['stay_on_legacy'])) {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
        $projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
        header("Location: {$projectRoot}/views/stock_operations/index.php?tab=transfer");
        exit;
    }
}

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = is_array($assignedWarehouse ?? null) ? $assignedWarehouse : [
    'warehouse_id'   => $currentWarehouseId ?? 1,
    'warehouse_code' => 'WH',
    'warehouse_name' => 'Assigned Warehouse',
    'location'       => 'Default Location'
];

$successMessage = null;
$errorMessage   = null;

// Total active warehouses check for single-warehouse safety
$stmtTotalWh = $pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active'");
$totalActiveWarehouses = (int)$stmtTotalWh->fetchColumn();

// Fetch available destination warehouses (other active warehouses only)
$destStmt = $pdo->prepare("
    SELECT warehouse_id, warehouse_code, warehouse_name, location 
    FROM warehouses 
    WHERE warehouse_id != :wid AND status = 'active' 
    ORDER BY warehouse_name ASC
");
$destStmt->execute([':wid' => $currentWarehouseId]);
$destinationWarehouses = $destStmt->fetchAll(PDO::FETCH_ASSOC);

$canInitiateTransfer = ($totalActiveWarehouses >= 2 && count($destinationWarehouses) > 0);

// Handle New Transfer Initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_transfer') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } elseif (!$canInitiateTransfer) {
        $errorMessage = "Stock transfer is disabled because only one active warehouse exists in the system.";
    } else {
        // Strictly enforce source warehouse as the admin's assigned warehouse
        $sourceWhId  = (int)$currentWarehouseId;
        $destWhId    = isset($_POST['destination_warehouse_id']) ? (int)$_POST['destination_warehouse_id'] : 0;
        $itemId      = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $rawQuantity = $_POST['quantity'] ?? null;
        $remarks     = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
        $userId      = (int)($currentUser['id'] ?? 1);

        if ($destWhId <= 0) {
            $errorMessage = "Please select a valid destination warehouse.";
        } elseif ($destWhId === $sourceWhId) {
            $errorMessage = "Destination warehouse cannot be the same as your source warehouse.";
        } elseif ($itemId <= 0) {
            $errorMessage = "Please select a valid item to transfer.";
        } else {
            try {
                $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'transfer quantity');
                $stockService = new StockService();
                $result = $stockService->recordStockTransfer(
                    $sourceWhId,
                    $destWhId,
                    [['item_id' => $itemId, 'quantity' => $quantity]],
                    $userId,
                    $remarks ?: null
                );
                $successMessage = "Transfer initiated successfully! Transaction reference: " . htmlspecialchars($result['transaction_number']);
            } catch (Exception $e) {
                $errorMessage = "Transfer failed: " . $e->getMessage();
            }
        }
    }
}

// Handle Receiving Confirmation (Destination Warehouse)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_receipt') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $transferId = (int)($_POST['stock_transfer_id'] ?? 0);
        $userId     = (int)($currentUser['id'] ?? 1);

        if ($transferId <= 0) {
            $errorMessage = "Invalid stock transfer ID.";
        } else {
            try {
                $stockService = new StockService();
                $result = $stockService->confirmStockTransferReceipt($transferId, $userId, $currentUser);
                $successMessage = "Stock transfer " . htmlspecialchars($result['transaction_number']) . " confirmed and received successfully into your warehouse inventory!";
            } catch (Exception $e) {
                $errorMessage = "Receiving confirmation failed: " . $e->getMessage();
            }
        }
    }
}

// Fetch items available in source warehouse with positive stock
$itemsStmt = $pdo->prepare("
    SELECT 
        i.item_id, 
        i.item_code, 
        i.item_name, 
        i.item_type, 
        i.unit, 
        COALESCE(inv.quantity, 0) AS current_stock
    FROM inventory inv
    JOIN items i ON inv.item_id = i.item_id
    WHERE inv.warehouse_id = :wid 
      AND inv.quantity > 0 
      AND i.status = 'active'
    ORDER BY i.item_type ASC, i.item_name ASC
");
$itemsStmt->execute([':wid' => $currentWarehouseId]);
$availableItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// 1. RECEIVED FROM OTHER WAREHOUSE (Destination = Current Warehouse)
// -----------------------------------------------------------------------------
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
        st.remarks,
        st.created_at,
        u.name AS requested_by,
        COUNT(sti.item_id) AS total_items,
        COALESCE(SUM(sti.quantity), 0) AS total_quantity,
        MIN(i.item_name) AS first_item_name,
        MIN(i.item_code) AS first_item_code,
        MIN(i.item_type) AS first_item_type,
        MIN(i.unit) AS first_unit,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', FORMAT(sti.quantity, 1), ' ', i.unit, ')') SEPARATOR '; ') AS items_summary
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    JOIN users u ON st.created_by = u.user_id
    LEFT JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
    LEFT JOIN items i ON sti.item_id = i.item_id
    WHERE st.destination_warehouse_id = :wid
    GROUP BY st.stock_transfer_id
    ORDER BY st.created_at DESC, st.stock_transfer_id DESC
");
$stmtRecv->execute([':wid' => $currentWarehouseId]);
$receivedTransfers = $stmtRecv->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// 2. TRANSFERRED TO OTHER WAREHOUSE (Source = Current Warehouse)
// -----------------------------------------------------------------------------
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
        st.remarks,
        st.created_at,
        u.name AS requested_by,
        COUNT(sti.item_id) AS total_items,
        COALESCE(SUM(sti.quantity), 0) AS total_quantity,
        MIN(i.item_name) AS first_item_name,
        MIN(i.item_code) AS first_item_code,
        MIN(i.item_type) AS first_item_type,
        MIN(i.unit) AS first_unit,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', FORMAT(sti.quantity, 1), ' ', i.unit, ')') SEPARATOR '; ') AS items_summary
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    JOIN users u ON st.created_by = u.user_id
    LEFT JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
    LEFT JOIN items i ON sti.item_id = i.item_id
    WHERE st.source_warehouse_id = :wid
    GROUP BY st.stock_transfer_id
    ORDER BY st.created_at DESC, st.stock_transfer_id DESC
");
$stmtSent->execute([':wid' => $currentWarehouseId]);
$transferredTransfers = $stmtSent->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Fetch line items for modal inspection (scoped to transfers involving this warehouse)
// -----------------------------------------------------------------------------
$stmtLines = $pdo->prepare("
    SELECT 
        sti.stock_transfer_id,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        sti.quantity
    FROM stock_transfer_items sti
    JOIN stock_transfers st ON sti.stock_transfer_id = st.stock_transfer_id
    JOIN items i ON sti.item_id = i.item_id
    WHERE st.source_warehouse_id = :wid1 OR st.destination_warehouse_id = :wid2
    ORDER BY sti.stock_transfer_item_id ASC
");
$stmtLines->execute([':wid1' => $currentWarehouseId, ':wid2' => $currentWarehouseId]);
$linesByTransfer = [];
while ($row = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
    $linesByTransfer[(int)$row['stock_transfer_id']][] = $row;
}

// KPI Metrics
$totalReceived    = count($receivedTransfers);
$totalTransferred = count($transferredTransfers);
$allInvolved      = array_merge($receivedTransfers, $transferredTransfers);
$completedCount   = count(array_filter($allInvolved, fn($t) => ($t['status'] ?? '') === 'completed'));
$pendingCount     = count(array_filter($allInvolved, fn($t) => ($t['status'] ?? '') === 'pending'));
?>

<style>
.section-badge-recv {
    background: #DCFCE7;
    color: #15803D;
    border: 1px solid #BBF7D0;
    font-weight: 700;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.section-badge-send {
    background: #EFF6FF;
    color: #1D4ED8;
    border: 1px solid #BFDBFE;
    font-weight: 700;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.modal-meta-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.modal-meta-item {
    font-size: 12.5px;
}
.modal-meta-label {
    color: var(--gray);
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 2px;
}
.modal-meta-val {
    font-weight: 600;
    color: var(--panel-ink);
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Stock Transfer Ledger</h1>
        <p class="page-subtitle">Two-sided inter-warehouse movements for <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse') ?> (<?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?>)</strong></p>
    </div>
    <div class="header-actions">
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

<!-- Flash Alerts -->
<?php if ($successMessage): ?>
    <div style="background: var(--success-light); border: 1px solid var(--success-border); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span><?= htmlspecialchars($successMessage) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div style="background: var(--error-light); border: 1px solid var(--error-border); color: var(--error); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?= htmlspecialchars($errorMessage) ?></span>
    </div>
<?php endif; ?>

<?php if (!$canInitiateTransfer): ?>
    <div style="background: #FEF3C7; border: 1px solid #FDE68A; color: #92400E; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-weight: 500;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
            <strong>Single Warehouse Notice:</strong> There is only 1 active warehouse registered in the system. Inter-warehouse transfers are disabled until additional facilities are active.
        </div>
    </div>
<?php endif; ?>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <!-- Received (Inbound) -->
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
        <div class="stat-meta">Inbound transfers to <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?></div>
    </div>

    <!-- Transferred (Outbound) -->
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
        <div class="stat-meta">Outbound transfers from <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?></div>
    </div>

    <!-- Completed Movements -->
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

    <!-- Connected Facilities -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Available Partner Warehouses</span>
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

<!-- ========================================================================= -->
<!-- SECTION 1: RECEIVED FROM OTHER WAREHOUSE (Inbound Transfers)             -->
<!-- ========================================================================= -->
<div class="card mb-6" style="margin-bottom: 28px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                <span class="section-badge-recv">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                    Inbound
                </span>
                <h2 class="card-title" style="margin: 0; font-size: 16px;">Received from Other Warehouse</h2>
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
            <input type="text" id="receivedSearch" class="search-box" placeholder="Filter received transfers..." onkeyup="filterTable('receivedSearch', 'receivedTable')">
        </div>
    </div>

    <div class="table-responsive">
        <table id="receivedTable">
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
                            <!-- Date & Time -->
                            <td style="font-size: 12.5px; white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['created_at'] ?: $row['transaction_date'])) ?>
                                <small style="display: block; color: var(--gray); font-size: 11px;">
                                    <?= date('h:i A', strtotime($row['created_at'])) ?>
                                </small>
                            </td>

                            <!-- Transfer Reference -->
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['transaction_number']) ?>
                            </td>

                            <!-- Item -->
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

                            <!-- Item Type -->
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

                            <!-- Quantity (Positive Inbound) -->
                            <td style="font-weight: 700; color: #15803D; white-space: nowrap;">
                                +<?= formatQty((float)$row['total_quantity']) ?>
                                <?php if (!$hasMultiple && !empty($row['first_unit'])): ?>
                                    <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($row['first_unit']) ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- From Warehouse -->
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['src_code']) ?>"><?= htmlspecialchars($row['src_code']) ?></span>
                                <span style="font-size: 12px; color: var(--panel-ink); margin-left: 4px; font-weight: 500;"><?= htmlspecialchars($row['src_name']) ?></span>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Received</span>
                                <?php elseif ($row['status'] === 'pending'): ?>
                                    <span class="badge status-pending">In Transit</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Action -->
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <button type="button" class="btn btn-primary" style="height: 30px; padding: 0 11px; font-size: 11.5px; background: #15803D; border-color: #15803D; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;" onclick='openConfirmReceiptModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                            <span>Received Stock</span>
                                        </button>
                                    <?php elseif ($row['status'] === 'completed'): ?>
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

<!-- ========================================================================= -->
<!-- SECTION 2: TRANSFERRED TO OTHER WAREHOUSE (Outbound Transfers)            -->
<!-- ========================================================================= -->
<div class="card mb-6">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                <span class="section-badge-send">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
                    Outbound
                </span>
                <h2 class="card-title" style="margin: 0; font-size: 16px;">Transferred to Other Warehouse</h2>
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
            <input type="text" id="transferredSearch" class="search-box" placeholder="Filter transferred records..." onkeyup="filterTable('transferredSearch', 'transferredTable')">
        </div>
    </div>

    <div class="table-responsive">
        <table id="transferredTable">
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
                            <!-- Date & Time -->
                            <td style="font-size: 12.5px; white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['created_at'] ?: $row['transaction_date'])) ?>
                                <small style="display: block; color: var(--gray); font-size: 11px;">
                                    <?= date('h:i A', strtotime($row['created_at'])) ?>
                                </small>
                            </td>

                            <!-- Transfer Reference -->
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['transaction_number']) ?>
                            </td>

                            <!-- Item -->
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

                            <!-- Item Type -->
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

                            <!-- Quantity (Negative Outbound) -->
                            <td style="font-weight: 700; color: #1D4ED8; white-space: nowrap;">
                                -<?= formatQty((float)$row['total_quantity']) ?>
                                <?php if (!$hasMultiple && !empty($row['first_unit'])): ?>
                                    <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($row['first_unit']) ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- To Warehouse -->
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['dest_code']) ?>"><?= htmlspecialchars($row['dest_code']) ?></span>
                                <span style="font-size: 12px; color: var(--panel-ink); margin-left: 4px; font-weight: 500;"><?= htmlspecialchars($row['dest_name']) ?></span>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Received</span>
                                <?php elseif ($row['status'] === 'pending'): ?>
                                    <span class="badge status-pending">In Transit</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Action -->
                            <td style="text-align: right;">
                                <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" onclick='openTransferDetailModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "outbound")'>
                                    View Details
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Enhanced Transfer Details                                         -->
<!-- ========================================================================= -->
<div id="transferModal" class="modal-backdrop" onclick="if(event.target === this) closeTransferDetailModal()">
    <div class="modal-card" style="max-width: 620px;">
        <div class="modal-header">
            <div>
                <h3 id="modalTrfTitle" class="card-title">Transfer Details</h3>
                <p id="modalTrfSub" class="card-desc">Warehouse-to-warehouse movement record</p>
            </div>
            <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeTransferDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <!-- Metadata Summary Grid -->
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

            <!-- Optional Remarks -->
            <div id="modalTrfRemarksContainer" style="margin-bottom: 16px; background: #F1F5F9; border-radius: 6px; padding: 10px 14px; font-size: 12.5px; display: none;">
                <strong style="color: #475569; display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 2px;">Transfer Notes:</strong>
                <span id="modalTrfRemarks" style="color: var(--panel-ink);"></span>
            </div>

            <!-- Line Items Table -->
            <div style="font-size: 13px; font-weight: 700; color: var(--panel-ink); margin-bottom: 8px;">
                Transferred Items
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>SKU / Code</th>
                            <th>Item Name</th>
                            <th>Classification</th>
                            <th style="text-align: right;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody id="modalTrfTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeTransferDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Initiate Stock Transfer                                            -->
<!-- ========================================================================= -->
<div id="newTransferModal" class="modal-backdrop" onclick="if(event.target === this) closeNewTransferModal()">
    <div class="modal-card" style="max-width: 540px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_transfer">
            <div class="modal-header">
                <div>
                    <h3 class="card-title">Initiate Stock Transfer</h3>
                    <p class="card-desc">Transfer inventory from your assigned warehouse to another facility</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeNewTransferModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px;">
                <!-- Source Warehouse (Locked to Logged-in Admin's Warehouse) -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        From Warehouse (Source) <span style="font-size: 11px; font-weight: normal; color: var(--gray);">(Your Assigned Facility &mdash; Locked)</span>
                    </label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 600; color: var(--panel-ink); font-size: 13px;">
                            <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse') ?>
                        </span>
                        <span class="badge" style="background: #E2E8F0; color: #475569; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Source Locked</span>
                    </div>
                    <input type="hidden" name="source_warehouse_id" value="<?= $currentWarehouseId ?>">
                </div>

                <!-- Destination Warehouse (Selectable Other Active Warehouses) -->
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

                <!-- Item Selection from Source Warehouse Inventory -->
                <div>
                    <label for="transferItemSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Item to Transfer <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="transferItemSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="handleItemChange(this)">
                        <option value="">-- Select Item with Available Stock --</option>
                        <?php foreach ($availableItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [Stock: <?= formatQty((float)$item['current_stock']) ?> <?= htmlspecialchars($item['unit']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($availableItems)): ?>
                        <div style="font-size: 11.5px; color: #DC2626; margin-top: 4px;">No items currently available with positive stock in this warehouse.</div>
                    <?php endif; ?>
                </div>

                <!-- Quantity -->
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

                <!-- Remarks -->
                <div>
                    <label for="transferRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Transfer Notes / Remarks
                    </label>
                    <textarea name="remarks" id="transferRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 64px; padding: 8px 12px; resize: vertical;" placeholder="Optional dispatch notes or batch reference..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= empty($availableItems) ? 'disabled' : '' ?>>
                    <span>Confirm &amp; Dispatch</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Confirm Received Stock                                             -->
<!-- ========================================================================= -->
<div id="confirmReceiptModal" class="modal-backdrop" onclick="if(event.target === this) closeConfirmReceiptModal()">
    <div class="modal-card" style="max-width: 480px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="confirm_receipt">
            <input type="hidden" name="stock_transfer_id" id="confirmReceiptId" value="">
            
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: #DCFCE7; color: #15803D; display: flex; align-items: center; justify-content: center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <h3 class="card-title" style="margin: 0;">Confirm Received Stock</h3>
                        <p class="card-desc" style="margin: 0;">Verify physical delivery of items into your warehouse</p>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeConfirmReceiptModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
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

                <div style="font-size: 12px; color: #475569; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px 12px;">
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

<script>
const trfLinesData = <?= json_encode($linesByTransfer) ?>;

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
    
    // Status Badge
    let statusBadge = '<span class="badge status-completed">Completed</span>';
    if (trf.status === 'pending') {
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

    // Line Items
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
                <td style="font-weight: 700; color: ${qtyColor}; text-align: right;">${qtySign}${Number(parseFloat(l.quantity).toFixed(2))} <small style="color: var(--gray); font-weight: normal;">${escapeHtml(l.unit)}</small></td>
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

function handleItemChange(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const unitSpan = document.getElementById('unitIndicator');
    const hintDiv = document.getElementById('availStockHint');
    const qtyInput = document.getElementById('transferQuantity');

    if (selectedOption && selectedOption.dataset.stock) {
        const maxStock = parseFloat(selectedOption.dataset.stock);
        const unit = selectedOption.dataset.unit || '';
        unitSpan.textContent = unit;
        hintDiv.innerHTML = `Available in <strong><?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?></strong>: <strong>${Number(maxStock.toFixed(2))} ${unit}</strong>`;
        qtyInput.max = maxStock;
        qtyInput.placeholder = `Max ${Number(maxStock.toFixed(2))}`;
    } else {
        unitSpan.textContent = '—';
        hintDiv.textContent = 'Select an item to view maximum transferable balance.';
        qtyInput.removeAttribute('max');
        qtyInput.placeholder = '0';
    }
}

function filterTable(inputId, tableId) {
    const filter = document.getElementById(inputId).value.toUpperCase();
    const table = document.getElementById(tableId);
    if (!table) return;
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        // Skip empty row if present
        if (tr[i].cells.length <= 1) continue;
        let visible = false;
        const tds = tr[i].getElementsByTagName('td');
        for (let j = 0; j < tds.length; j++) {
            if (tds[j]) {
                const txt = tds[j].textContent || tds[j].innerText;
                if (txt.toUpperCase().indexOf(filter) > -1) {
                    visible = true;
                    break;
                }
            }
        }
        tr[i].style.display = visible ? '' : 'none';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
