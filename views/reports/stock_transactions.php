<?php
/*
 * Stock transaction reports page. Shows detailed ledgers for stock movements,
 * inbound receipts, outbound dispatches, warehouse transfers, and stock
 * adjustments.
 */

$pageTitle   = 'Stock Transaction Reports — InventoryTeam';
$activePage  = 'stock_transactions';
$activeGroup = 'reports';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$warehouses = $pdo->query("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name FROM warehouses WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$datePreset   = isset($_GET['preset']) ? trim($_GET['preset']) : '';
$rawStartDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$rawEndDate   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

if ($datePreset === '' && ($rawStartDate !== '' || $rawEndDate !== '')) {
    $datePreset = 'custom';
} elseif (!in_array($datePreset, ['today', 'this_month', 'all_time', 'custom'], true)) {
    $datePreset = 'this_month';
}

if ($datePreset === 'today') {
    $startDate = date('Y-m-d');
    $endDate   = date('Y-m-d');
} elseif ($datePreset === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-d');
} elseif ($datePreset === 'all_time') {
    $startDate = '2000-01-01';
    $endDate   = date('Y-m-d', strtotime('+1 day'));
} else {
    $dtStart   = DateTime::createFromFormat('Y-m-d', $rawStartDate);
    $dtEnd     = DateTime::createFromFormat('Y-m-d', $rawEndDate);
    $startDate = ($dtStart && $dtStart->format('Y-m-d') === $rawStartDate) ? $rawStartDate : date('Y-m-01');
    $endDate   = ($dtEnd && $dtEnd->format('Y-m-d') === $rawEndDate) ? $rawEndDate : date('Y-m-d');
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
}
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$limitParam   = $_GET['limit'] ?? '';
$isAll        = ($limitParam === 'all' || (isset($_GET['export']) && $_GET['export'] === 'csv' && empty($_GET['limit'])));
$limitVal     = $isAll ? 0 : max(10, min(1000, (int)($limitParam ?: 100)));

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

$sqlM = "
    WITH movement_base AS (
        SELECT 
            sm.movement_id,
            sm.item_id,
            sm.warehouse_id,
            sm.movement_type,
            sm.reference_no,
            sm.reference_number,
            sm.quantity,
            sm.remarks,
            sm.created_at,
            COALESCE(sm.quantity_delta, CASE 
                WHEN sm.movement_type IN ('STOCK_IN', 'STOCK_TRANSFER_IN', 'CANCELLED_OUTBOUND', 'CANCELLED_BAD_PRODUCT', 'CANCELLED_TRANSFER', 'IN') THEN sm.quantity 
                WHEN sm.movement_type IN ('STOCK_OUT', 'STOCK_TRANSFER_OUT', 'BAD_PRODUCT_DISCARD', 'CANCELLED_INBOUND', 'OUT') THEN -sm.quantity 
                WHEN sm.movement_type = 'STOCK_ADJUSTMENT' THEN COALESCE(sa.difference, sm.quantity) 
                WHEN sm.movement_type = 'CANCELLED_ADJUSTMENT' THEN COALESCE(-sa.difference, -sm.quantity) 
                ELSE 0 
            END) AS signed_delta
        FROM stock_movements sm
        LEFT JOIN stock_adjustments sa ON sm.movement_type IN ('STOCK_ADJUSTMENT', 'CANCELLED_ADJUSTMENT') AND sm.reference_id = sa.adjustment_id
    ),
    movement_ledger AS (
        SELECT
            mb.*,
            (COALESCE(s.qty_on_hand, 0) - SUM(mb.signed_delta) OVER (PARTITION BY mb.item_id, mb.warehouse_id))
            + SUM(mb.signed_delta) OVER (PARTITION BY mb.item_id, mb.warehouse_id ORDER BY mb.created_at ASC, mb.movement_id ASC) AS balance_after
        FROM movement_base mb
        LEFT JOIN stock s ON s.item_id = mb.item_id AND s.warehouse_id = mb.warehouse_id
    )
    SELECT 
        sm.movement_id,
        sm.movement_type,
        COALESCE(NULLIF(sm.reference_number, ''), NULLIF(sm.reference_no, ''), sm.remarks, CONCAT('MOV-', sm.movement_id)) AS reference_number,
        CASE WHEN sm.signed_delta > 0 THEN sm.signed_delta ELSE 0 END AS quantity_in,
        CASE WHEN sm.signed_delta < 0 THEN ABS(sm.signed_delta) WHEN sm.movement_type = 'BAD_PRODUCT_DISCARD' THEN sm.quantity ELSE 0 END AS quantity_out,
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
if ($limitVal > 0) $sqlM .= " LIMIT " . (int)$limitVal;
$stmtM = $pdo->prepare($sqlM);
$stmtM->execute($paramsM);
$movementData = $stmtM->fetchAll(PDO::FETCH_ASSOC);

$sqlIn = "
    SELECT 
        sm.movement_id AS stock_in_id,
        CONCAT('IN-', LPAD(sm.movement_id, 6, '0')) AS transaction_number,
        COALESCE(
            NULLIF(sm.source_type, ''),
            CASE 
                WHEN sm.remarks LIKE '%CUSTOMER_RETURN%' OR sm.remarks LIKE '%RMA%' OR sm.reference_no LIKE 'RMA-%' OR sm.reference_number LIKE 'RMA-%' THEN 'CUSTOMER_RETURN'
                WHEN sm.reference_type = 'PROD_RECEIPT' OR sm.remarks LIKE '%Finished Goods%' THEN 'PRODUCTION_RECEIPT'
                WHEN i.type IN ('finished_good', 'Finished Good') THEN 'PRODUCTION_RECEIPT'
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
        AND rev.movement_type IN ('CANCELLED_INBOUND', 'STOCK_OUT')
        AND (rev.movement_type = 'CANCELLED_INBOUND' OR rev.remarks LIKE 'Cancelled Stock IN %')
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
      AND sm.warehouse_id = ?
      AND sm.movement_type IN ('STOCK_IN', 'IN')
      AND (sm.remarks IS NULL OR (
          sm.remarks NOT LIKE 'Cancelled Stock OUT %'
          AND sm.remarks NOT LIKE '[INITIAL_BALANCE]%'
          AND sm.remarks NOT LIKE 'Customer RMA Inbound (%'
      ))
";
$paramsIn = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlIn .= " AND (sm.remarks LIKE ? OR i.name LIKE ? OR i.code LIKE ?)";
    $paramsIn[] = "%$search%";
    $paramsIn[] = "%$search%";
    $paramsIn[] = "%$search%";
}
$sqlIn .= " ORDER BY sm.created_at DESC, sm.movement_id DESC";
if ($limitVal > 0) $sqlIn .= " LIMIT " . (int)$limitVal;
$stmtIn = $pdo->prepare($sqlIn);
$stmtIn->execute($paramsIn);
$inboundData = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

$sqlOut = "
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
        AND rev.movement_type IN ('CANCELLED_OUTBOUND', 'STOCK_IN')
        AND (rev.movement_type = 'CANCELLED_OUTBOUND' OR rev.remarks LIKE 'Cancelled Stock OUT %')
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
      AND sm.warehouse_id = ?
      AND sm.movement_type IN ('STOCK_OUT', 'OUT')
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
if ($limitVal > 0) $sqlOut .= " LIMIT " . (int)$limitVal;
$stmtOut = $pdo->prepare($sqlOut);
$stmtOut->execute($paramsOut);
$outboundData = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

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
if ($limitVal > 0) $sqlTr .= " LIMIT " . (int)$limitVal;
$stmtTr = $pdo->prepare($sqlTr);
$stmtTr->execute($paramsTr);
$transferData = $stmtTr->fetchAll(PDO::FETCH_ASSOC);

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
if ($limitVal > 0) $sqlAdj .= " LIMIT " . (int)$limitVal;
$stmtAdj = $pdo->prepare($sqlAdj);
$stmtAdj->execute($paramsAdj);
$adjustmentData = $stmtAdj->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Stock Transaction Reports</h1>
        <p class="page-subtitle">
            Audited ledgers for Stock Movements, Inbounds, Outbounds, Transfers, and Adjustments
        </p>
    </div>
    <div class="header-actions">
        <?php require __DIR__ . '/../layouts/notification_bell.php'; ?>
    </div>
</div>

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

<?php
$txnCardMeta = [
    'movements'   => ['title' => 'Stock Movement Ledger Report', 'desc' => 'History of all stock ins, stock outs, and balance changes'],
    'inbound'     => ['title' => 'Inbound Stock Receipts', 'desc' => 'Items received from suppliers and production'],
    'outbound'    => ['title' => 'Outbound Stock Dispatches', 'desc' => 'Items shipped for sales orders and sent to production'],
    'transfers'   => ['title' => 'Inter-Branch Stock Transfers', 'desc' => 'Stock transfers between warehouses'],
    'adjustments' => ['title' => 'Stock Adjustments & Variances', 'desc' => 'Stock adjustments made to match physical counts'],
];
$activeTxnMeta = $txnCardMeta[$activeTab] ?? $txnCardMeta['movements'];
?>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
        <div>
            <h2 class="card-title" id="txnCardTitle"><?= htmlspecialchars($activeTxnMeta['title']) ?></h2>
            <p class="card-desc" id="txnCardDesc"><?= htmlspecialchars($activeTxnMeta['desc']) ?></p>
        </div>
        <form method="GET" action="stock_transactions.php" id="reportFilterForm" class="report-filter-form no-print" onsubmit="event.preventDefault(); applyTxnFiltersSmooth(); return false;" style="display: flex; gap: 8px; align-items: center; justify-content: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="tab" id="filterTabInput" value="<?= htmlspecialchars($activeTab) ?>">

            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" name="search" class="search-box" aria-label="Search transaction records" placeholder="Search reference, item, operator..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" oninput="filterActiveTransactionTable()" onkeydown="if(event.key==='Enter'){event.preventDefault();filterActiveTransactionTable();}">
            </div>

            <select name="preset" id="datePresetSelect" class="select-filter" aria-label="Date range preset" onchange="onTxnPresetChange(this.value)">
                <option value="today" <?= $datePreset === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="this_month" <?= $datePreset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="all_time" <?= $datePreset === 'all_time' ? 'selected' : '' ?>>All Time</option>
                <option value="custom" <?= $datePreset === 'custom' ? 'selected' : '' ?>>Custom Date</option>
            </select>

            <div id="customDateRangeWrap" style="display: <?= $datePreset === 'custom' ? 'flex' : 'none' ?>; align-items: center; gap: 6px;">
                <span style="font-size: 12px; color: var(--gray); font-weight: 500;">From</span>
                <input type="date" name="start_date" id="txnStartDate" aria-label="Start date" class="select-filter" style="width: 132px; padding: 0 8px;" value="<?= htmlspecialchars($startDate) ?>" onchange="applyTxnFiltersSmooth()">
                <span style="font-size: 12px; color: var(--gray); font-weight: 500;">To</span>
                <input type="date" name="end_date" id="txnEndDate" aria-label="End date" class="select-filter" style="width: 132px; padding: 0 8px;" value="<?= htmlspecialchars($endDate) ?>" onchange="applyTxnFiltersSmooth()">
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary" style="height: 38px; white-space: nowrap;" onclick="exportReportCsv()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span>Export CSV</span>
                </button>
                <button type="button" class="btn btn-secondary" style="height: 38px; white-space: nowrap;" onclick="window.print()">
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

<div id="pane-movements" class="stock-op-pane" style="display: <?= $activeTab === 'movements' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="table-movements">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>Reference #</th>
                        <th>Product / Item</th>
                        <th>Classification</th>
                        <th>Movement Type</th>
                        <th class="text-center" style="text-align: center;">In (+)</th>
                        <th class="text-center" style="text-align: center;">Out (-)</th>
                        <th class="text-center" style="text-align: center;">Balance After</th>
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
                            $isBad = strpos($type, 'BAD') !== false;
                            $isCancel = strpos($type, 'CANCEL') !== false;
                            $pillClass = $isCancel ? 'status-cancelled' : ($isBad ? 'mov-out' : ($isTransfer ? 'mov-transfer' : ($isAdj ? 'mov-adj' : ($isIncoming ? 'mov-in' : 'mov-out'))));
                        ?>
                            <tr>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                                <td style="font-weight: 600; color: var(--panel-ink);"><?= htmlspecialchars($row['reference_number'] ?: '—') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                    <div style="font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-type <?= in_array($row['item_type'], ['finished_good', 'Finished Good'], true) ? 'type-fg' : 'type-raw' ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $row['item_type'])) ?>
                                    </span>
                                </td>
                                <td><span class="badge <?= $pillClass ?>"><?= htmlspecialchars(str_replace('_', ' ', $row['movement_type'])) ?></span></td>
                                <td class="text-center" style="text-align: center; font-weight: 700; color: #15803D;">
                                    <?= (float)$row['quantity_in'] > 0 ? formatQty((float)$row['quantity_in'], 0) : '—' ?>
                                </td>
                                <td class="text-center" style="text-align: center; font-weight: 700; color: #B91C1C;">
                                    <?= (float)$row['quantity_out'] > 0 ? formatQty((float)$row['quantity_out'], 0) : '—' ?>
                                </td>
                                <td class="text-center" style="text-align: center; font-weight: 700; color: var(--panel-ink);">
                                    <?= formatQty((float)$row['balance_after'], 0) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<div id="pane-inbound" class="stock-op-pane" style="display: <?= $activeTab === 'inbound' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="table-inbound">
                <thead>
                    <tr>
                        <th>Transaction #</th>
                        <th>Source PO / Ref #</th>
                        <th>Source Type</th>
                        <th>Date Received</th>
                        <th>Processed By</th>
                        <th class="text-center" style="text-align: center;">Item Lines</th>
                        <th class="text-center" style="text-align: center;">Total Quantity</th>
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
                                <td style="font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="color: var(--panel-ink);"><?= htmlspecialchars($row['source_reference_no'] ?: '—') ?></td>
                                <td><span class="badge" style="background: var(--gray-light);"><?= htmlspecialchars(str_replace('_', ' ', $row['source_type'])) ?></span></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transaction_date'])) ?></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td class="text-center" style="text-align: center; font-size: 12px;"><?= (int)$row['total_items'] ?> Lines</td>
                                <td class="text-center" style="text-align: center; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: #15803D;' ?>"><?= formatQty((float)$row['total_qty'], 0) ?></td>
                                <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<div id="pane-outbound" class="stock-op-pane" style="display: <?= $activeTab === 'outbound' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="table-outbound">
                <thead>
                    <tr>
                        <th>Transaction #</th>
                        <th>Source Order / Ref #</th>
                        <th>Source Type</th>
                        <th>Date Dispatched</th>
                        <th>Issued By</th>
                        <th class="text-center" style="text-align: center;">Item Lines</th>
                        <th class="text-center" style="text-align: center;">Total Quantity</th>
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
                                <td style="font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="color: var(--panel-ink);"><?= htmlspecialchars($row['source_reference_no'] ?: '—') ?></td>
                                <td><span class="badge" style="background: var(--gray-light);"><?= htmlspecialchars(str_replace('_', ' ', $row['source_type'])) ?></span></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transaction_date'])) ?></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td class="text-center" style="text-align: center; font-size: 12px;"><?= (int)$row['total_items'] ?> Lines</td>
                                <td class="text-center" style="text-align: center; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: #B91C1C;' ?>"><?= formatQty((float)$row['total_qty'], 0) ?></td>
                                <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<div id="pane-transfers" class="stock-op-pane" style="display: <?= $activeTab === 'transfers' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="table-transfers">
                <thead>
                    <tr>
                        <th>Transfer Reference</th>
                        <th>Transfer Date</th>
                        <th>Origin Facility</th>
                        <th>Destination Facility</th>
                        <th>Processed By</th>
                        <th class="text-center" style="text-align: center;">Line Items</th>
                        <th class="text-center" style="text-align: center;">Total Quantity</th>
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
                                <td style="font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transfer_date'])) ?></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['from_code']) ?>"><?= htmlspecialchars($row['from_code']) ?></span> <span style="font-size: 12px; color: var(--gray);"><?= htmlspecialchars($row['from_name']) ?></span></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['to_code']) ?>"><?= htmlspecialchars($row['to_code']) ?></span> <span style="font-size: 12px; color: var(--gray);"><?= htmlspecialchars($row['to_name']) ?></span></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td class="text-center" style="text-align: center; font-size: 12px;"><?= (int)$row['total_items'] ?></td>
                                <td class="text-center" style="text-align: center; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: #1D4ED8;' ?>"><?= formatQty((float)$row['total_qty'], 0) ?></td>
                                <td><span class="badge <?= $stClass ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<div id="pane-adjustments" class="stock-op-pane" style="display: <?= $activeTab === 'adjustments' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="table-adjustments">
                <thead>
                    <tr>
                        <th>Adjustment Ref</th>
                        <th>Adjustment Date</th>
                        <th>Item Affected</th>
                        <th>Logged By</th>
                        <th class="text-center" style="text-align: center;">Previous Qty</th>
                        <th class="text-center" style="text-align: center;">Adjusted Qty</th>
                        <th class="text-center" style="text-align: center;">Variance Delta</th>
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
                                <td style="font-weight: 700; color: var(--panel-ink); <?= $isCancelled ? 'text-decoration: line-through;' : '' ?>"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['adjustment_date'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                    <div style="font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                </td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td class="text-center" style="text-align: center; color: var(--gray);"><?= formatQty((float)$row['previous_quantity'], 0) ?></td>
                                <td class="text-center" style="text-align: center; font-weight: 600; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: var(--panel-ink);' ?>"><?= formatQty((float)$row['adjusted_quantity'], 0) ?></td>
                                <td class="text-center" style="text-align: center; font-weight: 700; <?= $isCancelled ? 'color: var(--gray); text-decoration: line-through;' : 'color: ' . ($diff >= 0 ? '#15803D' : '#B91C1C') . ';' ?>">
                                    <?= formatQty(abs($diff), 0) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
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

let currentActiveTab = '<?= $activeTab ?>';

const reportTitles = {
    'movements': 'Stock Movement Ledger Report',
    'inbound': 'Inbound Stock Receipts',
    'outbound': 'Outbound Stock Dispatches',
    'transfers': 'Inter-Branch Stock Transfers',
    'adjustments': 'Stock Adjustments & Variances'
};
const reportDescs = {
    'movements': 'History of all stock ins, stock outs, and balance changes',
    'inbound': 'Items received from suppliers and production',
    'outbound': 'Items shipped for sales orders and sent to production',
    'transfers': 'Stock transfers between warehouses',
    'adjustments': 'Stock adjustments made to match physical counts'
};

function onTxnPresetChange(val) {
    const wrap = document.getElementById('customDateRangeWrap');
    if (val === 'custom') {
        if (wrap) wrap.style.display = 'flex';
        return;
    }
    if (wrap) wrap.style.display = 'none';
    applyTxnFiltersSmooth();
}

let txnFilterAbortCtrl = null;
async function applyTxnFiltersSmooth() {
    const form = document.getElementById('reportFilterForm');
    if (!form) return;

    const params = new URLSearchParams(new FormData(form));
    params.set('tab', currentActiveTab);
    const targetUrl = 'stock_transactions.php?' + params.toString();

    if (txnFilterAbortCtrl) {
        txnFilterAbortCtrl.abort();
    }
    txnFilterAbortCtrl = new AbortController();

    try {
        const res = await fetch(targetUrl, { signal: txnFilterAbortCtrl.signal });
        if (!res.ok) throw new Error('Failed to fetch filtered data');
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');

        ['movements', 'inbound', 'outbound', 'transfers', 'adjustments'].forEach(t => {
            const newTbody = doc.querySelector('#table-' + t + ' tbody');
            const curTbody = document.querySelector('#table-' + t + ' tbody');
            if (newTbody && curTbody) {
                curTbody.innerHTML = newTbody.innerHTML;
            }
            const curTable = document.getElementById('table-' + t);
            if (curTable && typeof curTable.paginationUpdate === 'function') {
                curTable.paginationUpdate(false);
            }
        });

        const newStart = doc.getElementById('txnStartDate');
        const newEnd   = doc.getElementById('txnEndDate');
        const curStart = document.getElementById('txnStartDate');
        const curEnd   = document.getElementById('txnEndDate');
        if (newStart && curStart) curStart.value = newStart.value;
        if (newEnd && curEnd)     curEnd.value   = newEnd.value;

        window.history.replaceState({ tab: currentActiveTab }, '', targetUrl);
        filterActiveTransactionTable();
    } catch (err) {
        if (err.name !== 'AbortError') {
            form.submit();
        }
    }
}

function switchTransactionTab(tabName) {
    const validTabs = ['movements', 'inbound', 'outbound', 'transfers', 'adjustments'];
    if (!validTabs.includes(tabName)) tabName = 'movements';
    currentActiveTab = tabName;

    const cardTitleEl = document.getElementById('txnCardTitle');
    const cardDescEl  = document.getElementById('txnCardDesc');
    if (cardTitleEl && reportTitles[tabName]) cardTitleEl.textContent = reportTitles[tabName];
    if (cardDescEl && reportDescs[tabName])   cardDescEl.textContent  = reportDescs[tabName];

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

    const filterInput = document.getElementById('filterTabInput');
    if (filterInput) filterInput.value = tabName;

    const printDocTitle = document.getElementById('printDocTitle');
    if (printDocTitle && reportTitles[tabName]) {
        printDocTitle.textContent = reportTitles[tabName];
    }

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    url.searchParams.delete('type');
    window.history.replaceState({ tab: tabName }, '', url.toString());

    filterActiveTransactionTable();

    const activeTable = document.getElementById('table-' + tabName);
    if (activeTable && typeof activeTable.paginationUpdate === 'function') {
        activeTable.paginationUpdate(false);
    }
}

window.addEventListener('popstate', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || params.get('type') || 'movements';
    switchTransactionTab(tab);
});

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
