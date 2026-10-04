<?php
/**
 * View: Stock Transaction Reports (Unified Transaction Ledger Hub)
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Unifies 5 core inventory transaction and movement reports into one page with 5 tabs:
 * 1. Stock Movement Ledger
 * 2. Inbound Stock Receipts
 * 3. Outbound Stock Dispatches
 * 4. Inter-Branch Stock Transfers
 * 5. Stock Adjustments & Variances
 */

$pageTitle   = 'Stock Transaction Reports — InventoryTeam';
$activePage  = 'stock_transactions';
$activeGroup = 'reports';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Fetch Warehouses
$warehouses = $pdo->query("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name FROM warehouses WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$rawStartDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$rawEndDate   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$dtStart      = DateTime::createFromFormat('Y-m-d', $rawStartDate);
$dtEnd        = DateTime::createFromFormat('Y-m-d', $rawEndDate);
$startDate    = ($dtStart && $dtStart->format('Y-m-d') === $rawStartDate) ? $rawStartDate : date('Y-m-01');
$endDate      = ($dtEnd && $dtEnd->format('Y-m-d') === $rawEndDate) ? $rawEndDate : date('Y-m-d');
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';

// Active Tab Mapping
$tabMap = [
    'movements'        => 'movements',
    'stock_movements'  => 'movements',
    'inbound'          => 'inbound',
    'stock_ins'        => 'inbound',
    'outbound'         => 'outbound',
    'stock_outs'       => 'outbound',
    'transfers'        => 'transfers',
    'stock_transfers'  => 'transfers',
    'adjustments'      => 'adjustments',
    'stock_adjustments'=> 'adjustments',
];

$requestedTab = $_GET['tab'] ?? $_GET['type'] ?? 'movements';
$activeTab = $tabMap[$requestedTab] ?? 'movements';

// =============================================================================
// DATA QUERIES: 5 TRANSACTION LEDGERS
// =============================================================================

// 1. Stock Movement Ledger
$sqlM = "
    WITH movement_ledger AS (
        SELECT 
            sm.movement_id,
            sm.item_id,
            sm.warehouse_id,
            sm.movement_type,
            sm.quantity,
            sm.remarks,
            sm.created_at,
            sa.difference AS adjustment_difference,
            SUM(CASE 
                WHEN sm.movement_type IN ('STOCK_IN', 'STOCK_TRANSFER_IN') THEN sm.quantity 
                WHEN sm.movement_type IN ('STOCK_OUT', 'STOCK_TRANSFER_OUT') THEN -sm.quantity 
                WHEN sm.movement_type = 'STOCK_ADJUSTMENT' THEN COALESCE(sa.difference, sm.quantity) 
                ELSE 0 
            END) OVER (PARTITION BY sm.item_id, sm.warehouse_id ORDER BY sm.created_at ASC, sm.movement_id ASC) AS balance_after
        FROM stock_movements sm
        LEFT JOIN stock_adjustments sa ON sm.movement_type = 'STOCK_ADJUSTMENT' AND sm.reference_id = sa.adjustment_id
    )
    SELECT 
        sm.movement_id,
        sm.movement_type,
        COALESCE(sm.remarks, CONCAT('MOV-', sm.movement_id)) AS reference_number,
        CASE 
            WHEN sm.movement_type IN ('STOCK_IN', 'STOCK_TRANSFER_IN') THEN sm.quantity 
            WHEN sm.movement_type = 'STOCK_ADJUSTMENT' AND sm.adjustment_difference > 0 THEN sm.adjustment_difference
            ELSE 0 
        END AS quantity_in,
        CASE 
            WHEN sm.movement_type IN ('STOCK_OUT', 'STOCK_TRANSFER_OUT') THEN sm.quantity 
            WHEN sm.movement_type = 'STOCK_ADJUSTMENT' AND sm.adjustment_difference < 0 THEN ABS(sm.adjustment_difference)
            ELSE 0 
        END AS quantity_out,
        sm.balance_after,
        sm.created_at,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        w.code AS warehouse_code,
        w.name AS warehouse_name
    FROM movement_ledger sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
      AND sm.warehouse_id = ?
";
$paramsM = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlM .= " AND (i.name LIKE ? OR i.code LIKE ? OR sm.remarks LIKE ?)";
    $paramsM[] = "%$search%";
    $paramsM[] = "%$search%";
    $paramsM[] = "%$search%";
}
$sqlM .= " ORDER BY sm.created_at DESC, sm.movement_id DESC";
$stmtM = $pdo->prepare($sqlM);
$stmtM->execute($paramsM);
$movementData = $stmtM->fetchAll(PDO::FETCH_ASSOC);

// 2. Inbound Stock Receipts
$sqlIn = "
    SELECT 
        sm.movement_id AS stock_in_id,
        CONCAT('IN-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        CASE 
            WHEN i.type = 'finished_good' THEN 'PRODUCTION_RETURN'
            ELSE 'PURCHASE_ORDER'
        END AS source_type,
        COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(sm.remarks, ']', 1), '[', -1), ''), CONCAT('REF-', sm.movement_id)) AS source_reference_no,
        DATE(sm.created_at) AS transaction_date,
        CASE WHEN rev.movement_id IS NOT NULL THEN 'cancelled' ELSE 'completed' END AS status,
        sm.remarks,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(u.name, 'Warehouse Operator') AS operator_name,
        1 AS total_items,
        sm.quantity AS total_qty
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    LEFT JOIN stock_movements rev ON rev.reference_id = sm.movement_id 
        AND rev.movement_type = 'STOCK_OUT' 
        AND rev.remarks LIKE 'Cancelled Stock IN %'
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
      AND sm.warehouse_id = ?
      AND sm.movement_type = 'STOCK_IN'
      AND sm.remarks NOT LIKE 'Cancelled Stock OUT %'
      AND sm.remarks NOT LIKE '[INITIAL_BALANCE]%'
";
$paramsIn = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlIn .= " AND (sm.remarks LIKE ? OR i.name LIKE ? OR i.code LIKE ?)";
    $paramsIn[] = "%$search%";
    $paramsIn[] = "%$search%";
    $paramsIn[] = "%$search%";
}
$sqlIn .= " ORDER BY sm.created_at DESC, sm.movement_id DESC";
$stmtIn = $pdo->prepare($sqlIn);
$stmtIn->execute($paramsIn);
$inboundData = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

// 3. Outbound Stock Dispatches
$sqlOut = "
    SELECT 
        sm.movement_id AS stock_out_id,
        CONCAT('OUT-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        CASE 
            WHEN i.type = 'raw_material' THEN 'MATERIAL_REQUEST'
            ELSE 'SALES_DELIVERY'
        END AS source_type,
        COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(sm.remarks, ']', 1), '[', -1), ''), CONCAT('REF-', sm.movement_id)) AS source_reference_no,
        DATE(sm.created_at) AS transaction_date,
        CASE WHEN rev.movement_id IS NOT NULL THEN 'cancelled' ELSE 'completed' END AS status,
        sm.remarks,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(u.name, 'Warehouse Operator') AS operator_name,
        1 AS total_items,
        sm.quantity AS total_qty
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    LEFT JOIN stock_movements rev ON rev.reference_id = sm.movement_id 
        AND rev.movement_type = 'STOCK_IN' 
        AND rev.remarks LIKE 'Cancelled Stock OUT %'
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
      AND sm.warehouse_id = ?
      AND sm.movement_type = 'STOCK_OUT'
      AND sm.remarks NOT LIKE 'Cancelled Stock IN %'
";
$paramsOut = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlOut .= " AND (sm.remarks LIKE ? OR i.name LIKE ? OR i.code LIKE ?)";
    $paramsOut[] = "%$search%";
    $paramsOut[] = "%$search%";
    $paramsOut[] = "%$search%";
}
$sqlOut .= " ORDER BY sm.created_at DESC, sm.movement_id DESC";
$stmtOut = $pdo->prepare($sqlOut);
$stmtOut->execute($paramsOut);
$outboundData = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

// 4. Inter-Branch Stock Transfers
$sqlTr = "
    SELECT 
        st.transfer_id AS stock_transfer_id,
        CONCAT('TRF-', LPAD(st.transfer_id, 6, '0')) AS transaction_number,
        DATE(st.requested_at) AS transfer_date,
        st.status,
        '' AS remarks,
        sw.code AS from_code,
        sw.name AS from_name,
        dw.code AS to_code,
        dw.name AS to_name,
        COALESCE(u.name, 'Warehouse Operator') AS operator_name,
        1 AS total_items,
        st.quantity AS total_qty
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    LEFT JOIN users u ON st.requested_by = u.user_id
    JOIN items i ON st.item_id = i.item_id
    WHERE DATE(st.requested_at) BETWEEN ? AND ?
      AND (st.source_warehouse_id = ? OR st.destination_warehouse_id = ?)
";
$paramsTr = [$startDate, $endDate, $currentWarehouseId, $currentWarehouseId];
if ($search !== '') {
    $sqlTr .= " AND (i.name LIKE ? OR i.code LIKE ? OR sw.name LIKE ? OR dw.name LIKE ?)";
    $paramsTr[] = "%$search%";
    $paramsTr[] = "%$search%";
    $paramsTr[] = "%$search%";
    $paramsTr[] = "%$search%";
}
$sqlTr .= " ORDER BY st.requested_at DESC, st.transfer_id DESC";
$stmtTr = $pdo->prepare($sqlTr);
$stmtTr->execute($paramsTr);
$transferData = $stmtTr->fetchAll(PDO::FETCH_ASSOC);

// 5. Stock Adjustments & Variances
$sqlAdj = "
    SELECT 
        sa.adjustment_id AS stock_adjustment_id,
        CONCAT('ADJ-', LPAD(sa.adjustment_id, 6, '0')) AS transaction_number,
        DATE(sa.requested_at) AS adjustment_date,
        sa.reason,
        sa.status,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(u.name, 'Warehouse Operator') AS operator_name,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        sa.previous_quantity,
        sa.adjusted_quantity,
        sa.difference
    FROM stock_adjustments sa
    JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sa.requested_by = u.user_id
    JOIN items i ON sa.item_id = i.item_id
    WHERE DATE(sa.requested_at) BETWEEN ? AND ?
      AND sa.warehouse_id = ?
";
$paramsAdj = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlAdj .= " AND (i.name LIKE ? OR i.code LIKE ? OR sa.reason LIKE ?)";
    $paramsAdj[] = "%$search%";
    $paramsAdj[] = "%$search%";
    $paramsAdj[] = "%$search%";
}
$sqlAdj .= " ORDER BY sa.requested_at DESC, sa.adjustment_id DESC";
$stmtAdj = $pdo->prepare($sqlAdj);
$stmtAdj->execute($paramsAdj);
$adjustmentData = $stmtAdj->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Page Header -->
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Stock Transaction Reports</h1>
        <p class="page-subtitle">
            Audited ledgers for Stock Movements, Inbounds, Outbounds, Transfers, and Adjustments
        </p>
    </div>
</div>

<!-- Print-Only Official Document Header -->
<div class="print-banner">
    <div style="display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: var(--panel-ink); margin: 0 0 4px 0;">InventoryTeam Inventory Management System</h2>
            <h3 id="printDocTitle" style="font-size: 16px; font-weight: 600; color: var(--accent); margin: 0;">Stock Movement Ledger Report</h3>
        </div>
        <div style="text-align: right; font-size: 11px; color: var(--gray);">
            <div>Generated: <?= date('Y-m-d H:i:s') ?></div>
            <div>Facility: <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? '') ?></div>
            <div>Period: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- NAVIGATION SWITCHER: 5 TABS                                               -->
<!-- ========================================================================= -->
<div class="stock-ops-nav-wrapper">
    <div class="stock-ops-nav-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
        </svg>
        <span>Stock Transaction Reports</span>
    </div>
    <div class="stock-ops-tabs">
        <button type="button" id="tab-btn-movements" class="stock-tab-btn <?= $activeTab === 'movements' ? 'active' : '' ?>" onclick="switchTransactionTab('movements')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span>Stock Movement</span>
            <span class="tab-badge-count"><?= count($movementData) ?></span>
        </button>

        <button type="button" id="tab-btn-inbound" class="stock-tab-btn <?= $activeTab === 'inbound' ? 'active' : '' ?>" onclick="switchTransactionTab('inbound')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="17" y1="7" x2="7" y2="17"/>
                <polyline points="17 17 7 17 7 7"/>
            </svg>
            <span>Inbound</span>
            <span class="tab-badge-count"><?= count($inboundData) ?></span>
        </button>

        <button type="button" id="tab-btn-outbound" class="stock-tab-btn <?= $activeTab === 'outbound' ? 'active' : '' ?>" onclick="switchTransactionTab('outbound')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="7" y1="17" x2="17" y2="7"/>
                <polyline points="7 7 17 7 17 17"/>
            </svg>
            <span>Outbound</span>
            <span class="tab-badge-count"><?= count($outboundData) ?></span>
        </button>

        <button type="button" id="tab-btn-transfers" class="stock-tab-btn <?= $activeTab === 'transfers' ? 'active' : '' ?>" onclick="switchTransactionTab('transfers')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m16 3 4 4-4 4"/>
                <path d="M20 7H4"/>
                <path d="m8 21-4-4 4-4"/>
                <path d="M4 17h16"/>
            </svg>
            <span>Transfers</span>
            <span class="tab-badge-count"><?= count($transferData) ?></span>
        </button>

        <button type="button" id="tab-btn-adjustments" class="stock-tab-btn <?= $activeTab === 'adjustments' ? 'active' : '' ?>" onclick="switchTransactionTab('adjustments')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
            </svg>
            <span>Adjustments</span>
            <span class="tab-badge-count"><?= count($adjustmentData) ?></span>
        </button>
    </div>
</div>

<!-- Transaction Report Filter Card -->
<div class="card" style="margin-bottom: 20px;">
    <form method="GET" action="stock_transactions.php" id="reportFilterForm" class="report-filter-form" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="tab" id="filterTabInput" value="<?= htmlspecialchars($activeTab) ?>">

        <!-- Search -->
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" name="search" class="search-box" aria-label="Search transaction records" placeholder="Search reference, item, operator..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" oninput="filterActiveTransactionTable()" onkeydown="if(event.key==='Enter'){event.preventDefault();filterActiveTransactionTable();}">
        </div>

        <!-- Date Range -->
        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
            <span style="font-size: 12px; color: var(--gray); font-weight: 500;">From</span>
            <input type="date" name="start_date" aria-label="Start date" class="select-filter" style="width: 140px; padding: 0 10px;" value="<?= htmlspecialchars($startDate) ?>" onchange="this.form.submit()">
            <span style="font-size: 12px; color: var(--gray); font-weight: 500;">To</span>
            <input type="date" name="end_date" aria-label="End date" class="select-filter" style="width: 140px; padding: 0 10px;" value="<?= htmlspecialchars($endDate) ?>" onchange="this.form.submit()">
        </div>

        <!-- Buttons -->
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a id="resetFilterBtn" href="stock_transactions.php?tab=<?= urlencode($activeTab) ?>" class="btn btn-secondary" style="height: 38px; text-decoration: none;" title="Reset Filters">
                <span>Reset</span>
            </a>
            <button type="button" class="btn btn-secondary" style="height: 38px;" onclick="exportReportCsv()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span>Export CSV</span>
            </button>
            <button type="button" class="btn btn-secondary" style="height: 38px;" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect width="12" height="8" x="6" y="14"/>
                </svg>
                <span>Print Report</span>
            </button>
        </div>
    </form>
</div>

<!-- ######################################################################### -->
<!-- SECTION 1: STOCK MOVEMENT LEDGER                                          -->
<!-- ######################################################################### -->
<div id="pane-movements" class="stock-op-pane" style="display: <?= $activeTab === 'movements' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Stock Movement Ledger Report</h2>
                <p class="card-desc">Chronological ledger of inventory balance debits and credits &middot; Generated on <?= date('M d, Y H:i') ?></p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="table-movements">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Reference #</th>
                        <th>Product / Item</th>
                        <th>Classification</th>
                        <th>Movement Type</th>
                        <th style="text-align: right;">In (+)</th>
                        <th style="text-align: right;">Out (-)</th>
                        <th style="text-align: right;">Balance After</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movementData)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No stock movement transactions recorded in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($movementData as $row): 
                            $type = $row['movement_type'];
                            $isIncoming = (float)$row['quantity_in'] > 0;
                            $isTransfer = strpos($type, 'TRANSFER') !== false;
                            $isAdj = strpos($type, 'ADJUSTMENT') !== false;
                            $pillClass = $isTransfer ? 'mov-transfer' : ($isAdj ? 'mov-adj' : ($isIncoming ? 'mov-in' : 'mov-out'));
                        ?>
                            <tr>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['reference_number'] ?: '—') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                    <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-type <?= $row['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $row['item_type'])) ?>
                                    </span>
                                </td>
                                <td><span class="badge <?= $pillClass ?>"><?= htmlspecialchars(str_replace('_', ' ', $row['movement_type'])) ?></span></td>
                                <td style="text-align: right; font-weight: 700; color: #15803D;">
                                    <?= (float)$row['quantity_in'] > 0 ? '+' . formatQty((float)$row['quantity_in']) : '—' ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #B91C1C;">
                                    <?= (float)$row['quantity_out'] > 0 ? '-' . formatQty((float)$row['quantity_out']) : '—' ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--panel-ink);">
                                    <?= formatQty((float)$row['balance_after']) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
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
<!-- SECTION 2: INBOUND STOCK RECEIPTS                                         -->
<!-- ######################################################################### -->
<div id="pane-inbound" class="stock-op-pane" style="display: <?= $activeTab === 'inbound' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Inbound Stock Receipts</h2>
                <p class="card-desc">Audited inbound receiving shipments from Procurement POs and Production &middot; Generated on <?= date('M d, Y H:i') ?></p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="table-inbound">
                <thead>
                    <tr>
                        <th>Transaction #</th>
                        <th>Source PO / Ref #</th>
                        <th>Source Type</th>
                        <th>Date Received</th>
                        <th>Processed By</th>
                        <th style="text-align: right;">Item Lines</th>
                        <th style="text-align: right;">Total Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inboundData)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No inbound stock receipts found in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($inboundData as $row): 
                            $isCancelled = ($row['status'] === 'cancelled');
                            $stClass = ($row['status'] === 'completed') ? 'status-completed' : (($row['status'] === 'pending') ? 'status-pending' : 'status-cancelled');
                        ?>
                            <tr style="<?= $isCancelled ? 'opacity: 0.72;' : '' ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-family: monospace; color: var(--panel-ink);"><?= htmlspecialchars($row['source_reference_no'] ?: '—') ?></td>
                                <td><span class="badge" style="background: var(--gray-light);"><?= htmlspecialchars(str_replace('_', ' ', $row['source_type'])) ?></span></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transaction_date'])) ?></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; font-size: 12px;"><?= (int)$row['total_items'] ?> Lines</td>
                                <td style="text-align: right; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: #15803D;' ?>">+<?= formatQty((float)$row['total_qty']) ?></td>
                                <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ######################################################################### -->
<!-- SECTION 3: OUTBOUND STOCK DISPATCHES                                      -->
<!-- ######################################################################### -->
<div id="pane-outbound" class="stock-op-pane" style="display: <?= $activeTab === 'outbound' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Outbound Stock Dispatches</h2>
                <p class="card-desc">Audited outbound releases for Sales Orders and Distilling Material Requests &middot; Generated on <?= date('M d, Y H:i') ?></p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="table-outbound">
                <thead>
                    <tr>
                        <th>Transaction #</th>
                        <th>Source Order / Ref #</th>
                        <th>Source Type</th>
                        <th>Date Dispatched</th>
                        <th>Issued By</th>
                        <th style="text-align: right;">Item Lines</th>
                        <th style="text-align: right;">Total Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($outboundData)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No outbound stock shipments found in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($outboundData as $row): 
                            $isCancelled = ($row['status'] === 'cancelled');
                            $stClass = ($row['status'] === 'completed') ? 'status-completed' : (($row['status'] === 'pending') ? 'status-pending' : 'status-cancelled');
                        ?>
                            <tr style="<?= $isCancelled ? 'opacity: 0.72;' : '' ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-family: monospace; color: var(--panel-ink);"><?= htmlspecialchars($row['source_reference_no'] ?: '—') ?></td>
                                <td><span class="badge" style="background: var(--gray-light);"><?= htmlspecialchars(str_replace('_', ' ', $row['source_type'])) ?></span></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transaction_date'])) ?></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; font-size: 12px;"><?= (int)$row['total_items'] ?> Lines</td>
                                <td style="text-align: right; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: #B91C1C;' ?>">-<?= formatQty((float)$row['total_qty']) ?></td>
                                <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ######################################################################### -->
<!-- SECTION 4: INTER-BRANCH STOCK TRANSFERS                                   -->
<!-- ######################################################################### -->
<div id="pane-transfers" class="stock-op-pane" style="display: <?= $activeTab === 'transfers' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Inter-Branch Stock Transfers</h2>
                <p class="card-desc">Audited movement between inventory facilities &middot; Generated on <?= date('M d, Y H:i') ?></p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="table-transfers">
                <thead>
                    <tr>
                        <th>Transfer Reference</th>
                        <th>Transfer Date</th>
                        <th>Origin Facility</th>
                        <th>Destination Facility</th>
                        <th>Processed By</th>
                        <th style="text-align: right;">Line Items</th>
                        <th style="text-align: right;">Total Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transferData)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No inter-branch transfers recorded in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transferData as $row): 
                            $isCancelled = ($row['status'] === 'cancelled');
                            $stClass = ($row['status'] === 'completed') ? 'status-completed' : (($row['status'] === 'pending') ? 'status-pending' : 'status-cancelled');
                        ?>
                            <tr style="<?= $isCancelled ? 'opacity: 0.72;' : '' ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transfer_date'])) ?></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['from_code']) ?>"><?= htmlspecialchars($row['from_code']) ?></span> <span style="font-size: 12px; color: var(--gray);"><?= htmlspecialchars($row['from_name']) ?></span></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['to_code']) ?>"><?= htmlspecialchars($row['to_code']) ?></span> <span style="font-size: 12px; color: var(--gray);"><?= htmlspecialchars($row['to_name']) ?></span></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; font-size: 12px;"><?= (int)$row['total_items'] ?></td>
                                <td style="text-align: right; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: #1D4ED8;' ?>"><?= formatQty((float)$row['total_qty']) ?></td>
                                <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ######################################################################### -->
<!-- SECTION 5: STOCK ADJUSTMENTS & VARIANCES                                  -->
<!-- ######################################################################### -->
<div id="pane-adjustments" class="stock-op-pane" style="display: <?= $activeTab === 'adjustments' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Stock Adjustments &amp; Variances</h2>
                <p class="card-desc">Audited cycle counts, discrepancy reconciliation, and shrinkage write-offs &middot; Generated on <?= date('M d, Y H:i') ?></p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="table-adjustments">
                <thead>
                    <tr>
                        <th>Adjustment Ref</th>
                        <th>Adjustment Date</th>
                        <th>Item Affected</th>
                        <th>Logged By</th>
                        <th style="text-align: right;">Previous Qty</th>
                        <th style="text-align: right;">Adjusted Qty</th>
                        <th style="text-align: right;">Variance Delta</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adjustmentData)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No stock adjustments recorded in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($adjustmentData as $row): 
                            $diff = (float)$row['difference'];
                            $adjStatus = $row['status'] ?? 'approved';
                            $isCancelled = in_array($adjStatus, ['cancelled', 'rejected'], true);
                            $adjStClass = in_array($adjStatus, ['approved', 'completed'], true) ? 'status-completed' : (($adjStatus === 'pending') ? 'status-pending' : 'status-cancelled');
                        ?>
                            <tr style="<?= $isCancelled ? 'opacity: 0.72;' : '' ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['adjustment_date'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                    <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                </td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; color: var(--gray);"><?= formatQty((float)$row['previous_quantity']) ?></td>
                                <td style="text-align: right; font-weight: 600; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: var(--panel-ink);' ?>"><?= formatQty((float)$row['adjusted_quantity']) ?></td>
                                <td style="text-align: right; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: ' . ($diff >= 0 ? '#15803D' : '#B91C1C') . ';' ?>">
                                    <?= ($diff >= 0 ? '+' : '') . formatQty($diff) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                </td>
                                <td style="font-size: 12px; color: var(--gray); max-width: 200px;" title="<?= htmlspecialchars($row['reason']) ?>">
                                    <?= htmlspecialchars($row['reason'] ?: 'Cycle count adjustment') ?>
                                </td>
                                <td><span class="badge <?= $adjStClass ?>"><?= htmlspecialchars(ucfirst($adjStatus)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// =============================================================================
// TRANSACTION REPORTS TAB SWITCHER (Movements | Inbound | Outbound | Transfers | Adjustments)
// =============================================================================
let currentActiveTab = '<?= $activeTab ?>';

const reportTitles = {
    'movements': 'Stock Movement Ledger Report',
    'inbound': 'Inbound Stock Receipts',
    'outbound': 'Outbound Stock Dispatches',
    'transfers': 'Inter-Branch Stock Transfers',
    'adjustments': 'Stock Adjustments & Variances'
};

function switchTransactionTab(tabName) {
    const validTabs = ['movements', 'inbound', 'outbound', 'transfers', 'adjustments'];
    if (!validTabs.includes(tabName)) tabName = 'movements';
    currentActiveTab = tabName;

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

    // Update Form hidden input and reset button
    const filterInput = document.getElementById('filterTabInput');
    if (filterInput) filterInput.value = tabName;
    const resetBtn = document.getElementById('resetFilterBtn');
    if (resetBtn) resetBtn.href = 'stock_transactions.php?tab=' + tabName;

    // Update printable document subtitle
    const printDocTitle = document.getElementById('printDocTitle');
    if (printDocTitle && reportTitles[tabName]) {
        printDocTitle.textContent = reportTitles[tabName];
    }

    // Sync URL without reloading
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    url.searchParams.delete('type');
    window.history.replaceState({ tab: tabName }, '', url.toString());

    // Update search on the newly active table
    filterActiveTransactionTable();

    // Trigger pagination re-calculation on active table
    const activeTable = document.getElementById('table-' + tabName);
    if (activeTable && typeof activeTable.paginationUpdate === 'function') {
        activeTable.paginationUpdate(false);
    }
}

// Support browser back/forward buttons
window.addEventListener('popstate', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || params.get('type') || 'movements';
    switchTransactionTab(tab);
});

// Instant live search across the active transaction table
function filterActiveTransactionTable() {
    const input = document.getElementById('reportSearchInput');
    if (!input) return;
    const term = input.value.toLowerCase().trim();

    const tableId = 'table-' + currentActiveTab;
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        if (row.classList.contains('no-filter') || row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        row.dataset.filteredOut = text.includes(term) ? 'false' : 'true';
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

// Export active report table as CSV (delegates to global exportTableToCsv in footer.php)
function exportReportCsv() {
    exportTableToCsv('table-' + currentActiveTab, currentActiveTab);
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('export') === 'csv') {
        exportReportCsv();
        const url = new URL(window.location.href);
        url.searchParams.delete('export');
        window.history.replaceState({ tab: currentActiveTab }, '', url.toString());
    }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
