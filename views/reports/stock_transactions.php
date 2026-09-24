<?php
/**
 * View: Stock Transaction Reports (Unified Transaction Ledger Hub)
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Unifies 5 core inventory transaction and movement reports into one page with 5 tabs:
 * 1. Stock Movement Ledger
 * 2. Inbound Stock Receipts
 * 3. Outbound Stock Dispatches
 * 4. Inter-Branch Stock Transfers
 * 5. Stock Adjustments & Variances
 */

$pageTitle   = 'Stock Transaction Reports — StockPilot';
$activePage  = 'stock_transactions';
$activeGroup = 'reports';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$auth = $auth ?? new AuthController();
$pdo  = $pdo ?? Database::getConnection();
$currentUser = $currentUser ?? ($auth->getCurrentUser() ?? []);
$currentWarehouseId = $currentWarehouseId ?? (int)($_SESSION['warehouse_id'] ?? ($currentUser['warehouse_id'] ?? 1));

/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = is_array($assignedWarehouse ?? null) ? $assignedWarehouse : [
    'warehouse_id'   => $currentWarehouseId ?? 1,
    'warehouse_code' => 'WH-MAIN',
    'warehouse_name' => 'Main Warehouse',
    'location'       => 'Default Location'
];

// Fetch Warehouses
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$startDate    = isset($_GET['start_date']) && !empty($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01');
$endDate      = isset($_GET['end_date']) && !empty($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
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
    SELECT 
        sm.movement_id,
        sm.movement_type,
        sm.reference_number,
        sm.quantity_in,
        sm.quantity_out,
        sm.balance_after,
        sm.created_at,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        w.warehouse_code,
        w.warehouse_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    WHERE DATE(sm.created_at) BETWEEN ? AND ?
      AND sm.warehouse_id = ?
";
$paramsM = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlM .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR sm.reference_number LIKE ?)";
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
        si.stock_in_id,
        si.transaction_number,
        si.source_type,
        si.source_reference_no,
        si.transaction_date,
        si.status,
        si.remarks,
        w.warehouse_code,
        w.warehouse_name,
        u.name AS operator_name,
        COUNT(sii.stock_in_item_id) AS total_items,
        COALESCE(SUM(sii.quantity), 0) AS total_qty
    FROM stock_ins si
    JOIN warehouses w ON si.warehouse_id = w.warehouse_id
    JOIN users u ON si.created_by = u.user_id
    LEFT JOIN stock_in_items sii ON si.stock_in_id = sii.stock_in_id
    WHERE DATE(si.transaction_date) BETWEEN ? AND ?
      AND si.warehouse_id = ?
";
$paramsIn = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlIn .= " AND (si.transaction_number LIKE ? OR si.source_reference_no LIKE ? OR si.remarks LIKE ?)";
    $paramsIn[] = "%$search%";
    $paramsIn[] = "%$search%";
    $paramsIn[] = "%$search%";
}
$sqlIn .= " GROUP BY si.stock_in_id ORDER BY si.transaction_date DESC, si.stock_in_id DESC";
$stmtIn = $pdo->prepare($sqlIn);
$stmtIn->execute($paramsIn);
$inboundData = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

// 3. Outbound Stock Dispatches
$sqlOut = "
    SELECT 
        so.stock_out_id,
        so.transaction_number,
        so.source_type,
        so.source_reference_no,
        so.transaction_date,
        so.status,
        so.remarks,
        w.warehouse_code,
        w.warehouse_name,
        u.name AS operator_name,
        COUNT(soi.stock_out_item_id) AS total_items,
        COALESCE(SUM(soi.quantity), 0) AS total_qty
    FROM stock_outs so
    JOIN warehouses w ON so.warehouse_id = w.warehouse_id
    JOIN users u ON so.created_by = u.user_id
    LEFT JOIN stock_out_items soi ON so.stock_out_id = soi.stock_out_id
    WHERE DATE(so.transaction_date) BETWEEN ? AND ?
      AND so.warehouse_id = ?
";
$paramsOut = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlOut .= " AND (so.transaction_number LIKE ? OR so.source_reference_no LIKE ? OR so.remarks LIKE ?)";
    $paramsOut[] = "%$search%";
    $paramsOut[] = "%$search%";
    $paramsOut[] = "%$search%";
}
$sqlOut .= " GROUP BY so.stock_out_id ORDER BY so.transaction_date DESC, so.stock_out_id DESC";
$stmtOut = $pdo->prepare($sqlOut);
$stmtOut->execute($paramsOut);
$outboundData = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

// 4. Inter-Branch Stock Transfers
$sqlTr = "
    SELECT 
        st.stock_transfer_id,
        st.transaction_number,
        st.transaction_date AS transfer_date,
        st.status,
        st.remarks,
        sw.warehouse_code AS from_code,
        sw.warehouse_name AS from_name,
        dw.warehouse_code AS to_code,
        dw.warehouse_name AS to_name,
        u.name AS operator_name,
        COUNT(sti.stock_transfer_item_id) AS total_items,
        COALESCE(SUM(sti.quantity), 0) AS total_qty
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    JOIN users u ON st.created_by = u.user_id
    LEFT JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
    WHERE DATE(st.transaction_date) BETWEEN ? AND ?
      AND (st.source_warehouse_id = ? OR st.destination_warehouse_id = ?)
";
$paramsTr = [$startDate, $endDate, $currentWarehouseId, $currentWarehouseId];
if ($search !== '') {
    $sqlTr .= " AND (st.transaction_number LIKE ? OR st.remarks LIKE ?)";
    $paramsTr[] = "%$search%";
    $paramsTr[] = "%$search%";
}
$sqlTr .= " GROUP BY st.stock_transfer_id ORDER BY st.transaction_date DESC, st.stock_transfer_id DESC";
$stmtTr = $pdo->prepare($sqlTr);
$stmtTr->execute($paramsTr);
$transferData = $stmtTr->fetchAll(PDO::FETCH_ASSOC);

// 5. Stock Adjustments & Variances
$sqlAdj = "
    SELECT 
        sa.stock_adjustment_id,
        sa.transaction_number,
        sa.adjustment_date,
        sa.reason,
        sa.status,
        w.warehouse_code,
        w.warehouse_name,
        u.name AS operator_name,
        i.item_code,
        i.item_name,
        i.unit,
        sai.previous_quantity,
        sai.adjusted_quantity,
        sai.difference
    FROM stock_adjustments sa
    JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
    JOIN users u ON sa.created_by = u.user_id
    JOIN stock_adjustment_items sai ON sa.stock_adjustment_id = sai.stock_adjustment_id
    JOIN items i ON sai.item_id = i.item_id
    WHERE DATE(sa.adjustment_date) BETWEEN ? AND ?
      AND sa.warehouse_id = ?
";
$paramsAdj = [$startDate, $endDate, $currentWarehouseId];
if ($search !== '') {
    $sqlAdj .= " AND (sa.transaction_number LIKE ? OR i.item_name LIKE ? OR i.item_code LIKE ? OR sa.reason LIKE ?)";
    $paramsAdj[] = "%$search%";
    $paramsAdj[] = "%$search%";
    $paramsAdj[] = "%$search%";
    $paramsAdj[] = "%$search%";
}
$sqlAdj .= " ORDER BY sa.adjustment_date DESC, sa.stock_adjustment_id DESC";
$stmtAdj = $pdo->prepare($sqlAdj);
$stmtAdj->execute($paramsAdj);
$adjustmentData = $stmtAdj->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* Tab Navigation Bar */
.stock-ops-nav-wrapper {
    background: #FFFFFF;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px 18px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}
.stock-ops-nav-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--gray);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.stock-ops-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.stock-tab-btn {
    appearance: none;
    background: #F8FAFC;
    border: 1.5px solid #E2E8F0;
    color: #475569;
    padding: 10px 18px;
    border-radius: 9px;
    font-family: var(--font-body);
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    transition: all 0.16s ease;
    user-select: none;
}
.stock-tab-btn:hover {
    background: #F1F5F9;
    color: var(--panel-ink);
    border-color: #CBD5E1;
    transform: translateY(-1px);
}
.stock-tab-btn.active {
    background: var(--panel-ink);
    color: #FFFFFF;
    border-color: var(--panel-ink);
    box-shadow: 0 4px 14px rgba(20, 33, 61, 0.20);
    font-weight: 700;
    transform: none;
}
.stock-tab-btn .tab-badge-count {
    background: #E2E8F0;
    color: #475569;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    transition: all 0.16s ease;
}
.stock-tab-btn.active .tab-badge-count {
    background: rgba(255, 255, 255, 0.22);
    color: #FFFFFF;
}

/* Tab Panes */
.stock-op-pane {
    animation: fadeIn 0.18s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(3px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Printable Banner */
.print-banner {
    display: none;
    padding: 16px;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--panel-ink);
}
@media print {
    .print-banner {
        display: block !important;
    }
    .page-header, .stock-ops-nav-wrapper, .report-filter-form, .header-actions, .sidebar, .navbar, .btn {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    table {
        font-size: 11px !important;
    }
    .stock-op-pane[style*="display: none"] {
        display: none !important;
    }
}
</style>

<!-- Page Header -->
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Stock Transaction Reports</h1>
        <p class="page-subtitle">
            Audited ledgers for Stock Movements, Inbounds, Outbounds, Transfers, and Adjustments for <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Main Warehouse') ?> (<?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH-MAIN') ?>)</strong>
        </p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect width="12" height="8" x="6" y="14"/>
            </svg>
            <span>Print Report</span>
        </button>
        <button type="button" class="btn btn-secondary" onclick="exportReportCsv()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <span>Export CSV</span>
        </button>
    </div>
</div>

<!-- Print-Only Official Document Header -->
<div class="print-banner">
    <div style="display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: var(--panel-ink); margin: 0 0 4px 0;">StockPilot Inventory Management System</h2>
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
        <div class="search-wrap" style="flex: 1; min-width: 200px;">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" name="search" class="search-box" placeholder="Search reference, item, operator..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" onkeyup="filterActiveTransactionTable()">
        </div>

        <!-- Assigned Facility (Locked) -->
        <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 0 12px; height: 38px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray)" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 3h12l3 4H3l3-4z"/></svg>
            <span style="font-size: 13px; font-weight: 600; color: var(--panel-ink);">
                <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Facility') ?>
            </span>
            <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Assigned</span>
        </div>

        <!-- Date Range -->
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 12px; color: var(--gray); font-weight: 500;">From</span>
            <input type="date" name="start_date" class="select-filter" style="width: 140px; padding: 0 10px;" value="<?= htmlspecialchars($startDate) ?>">
            <span style="font-size: 12px; color: var(--gray); font-weight: 500;">To</span>
            <input type="date" name="end_date" class="select-filter" style="width: 140px; padding: 0 10px;" value="<?= htmlspecialchars($endDate) ?>">
        </div>

        <!-- Buttons -->
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="height: 38px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
                <span>Filter</span>
            </button>
            <a href="stock_transactions.php" id="resetFilterBtn" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" title="Reset Filters">
                <span>Reset</span>
            </a>
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
                <p class="card-desc"> ledger of inventory balance debits and credits &middot; Generated on <?= date('M d, Y H:i') ?></p>
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
                        <th>Facility</th>
                        <th>Movement Type</th>
                        <th style="text-align: right;">In (+)</th>
                        <th style="text-align: right;">Out (-)</th>
                        <th style="text-align: right;">Balance After</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movementData)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No stock movement transactions recorded in this period.</td></tr>
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
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
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
                        <th>Warehouse Facility</th>
                        <th>Processed By</th>
                        <th style="text-align: right;">Item Lines</th>
                        <th style="text-align: right;">Total Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inboundData)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No inbound stock receipts found in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($inboundData as $row): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-family: monospace; color: var(--panel-ink);"><?= htmlspecialchars($row['source_reference_no'] ?: '—') ?></td>
                                <td><span class="badge" style="background: var(--gray-light);"><?= htmlspecialchars(str_replace('_', ' ', $row['source_type'])) ?></span></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transaction_date'])) ?></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; font-size: 12px;"><?= (int)$row['total_items'] ?> Lines</td>
                                <td style="text-align: right; font-weight: 700; color: #15803D;">+<?= formatQty((float)$row['total_qty']) ?></td>
                                <td><span class="badge status-completed"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
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
                        <th>Warehouse Facility</th>
                        <th>Issued By</th>
                        <th style="text-align: right;">Item Lines</th>
                        <th style="text-align: right;">Total Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($outboundData)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No outbound stock shipments found in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($outboundData as $row): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-family: monospace; color: var(--panel-ink);"><?= htmlspecialchars($row['source_reference_no'] ?: '—') ?></td>
                                <td><span class="badge" style="background: var(--gray-light);"><?= htmlspecialchars(str_replace('_', ' ', $row['source_type'])) ?></span></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transaction_date'])) ?></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; font-size: 12px;"><?= (int)$row['total_items'] ?> Lines</td>
                                <td style="text-align: right; font-weight: 700; color: #B91C1C;">-<?= formatQty((float)$row['total_qty']) ?></td>
                                <td><span class="badge status-completed"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
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
                        <?php foreach ($transferData as $row): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['transfer_date'])) ?></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['from_code']) ?>"><?= htmlspecialchars($row['from_code']) ?></span> <span style="font-size: 12px; color: var(--gray);"><?= htmlspecialchars($row['from_name']) ?></span></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['to_code']) ?>"><?= htmlspecialchars($row['to_code']) ?></span> <span style="font-size: 12px; color: var(--gray);"><?= htmlspecialchars($row['to_name']) ?></span></td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; font-size: 12px;"><?= (int)$row['total_items'] ?></td>
                                <td style="text-align: right; font-weight: 700; color: #1D4ED8;"><?= formatQty((float)$row['total_qty']) ?></td>
                                <td><span class="badge status-completed"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
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
                        <th>Warehouse Facility</th>
                        <th>Item Affected</th>
                        <th>Logged By</th>
                        <th style="text-align: right;">Previous Qty</th>
                        <th style="text-align: right;">Adjusted Qty</th>
                        <th style="text-align: right;">Variance Delta</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adjustmentData)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No stock adjustments recorded in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($adjustmentData as $row): 
                            $diff = (float)$row['difference'];
                        ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['transaction_number']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y', strtotime($row['adjustment_date'])) ?></td>
                                <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                    <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                </td>
                                <td style="font-size: 12.5px;"><?= htmlspecialchars($row['operator_name'] ?? 'System') ?></td>
                                <td style="text-align: right; color: var(--gray);"><?= formatQty((float)$row['previous_quantity']) ?></td>
                                <td style="text-align: right; font-weight: 600; color: var(--panel-ink);"><?= formatQty((float)$row['adjusted_quantity']) ?></td>
                                <td style="text-align: right; font-weight: 700; color: <?= $diff >= 0 ? '#15803D' : '#B91C1C' ?>;">
                                    <?= ($diff >= 0 ? '+' : '') . formatQty($diff) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                </td>
                                <td style="font-size: 12px; color: var(--gray); max-width: 200px;" title="<?= htmlspecialchars($row['reason']) ?>">
                                    <?= htmlspecialchars($row['reason'] ?: 'Cycle count adjustment') ?>
                                </td>
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
        if (text.includes(term)) {
            delete row.dataset.filteredOut;
        } else {
            row.dataset.filteredOut = 'true';
        }
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

// Export active report table as CSV
function exportReportCsv() {
    const table = document.getElementById('table-' + currentActiveTab);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
            text = text.replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        if (row.length > 0 && !row[0].includes('No records found') && !row[0].includes('No stock movement') && !row[0].includes('No inbound') && !row[0].includes('No outbound') && !row[0].includes('No inter-branch') && !row[0].includes('No stock adjustment')) {
            csv.push(row.join(','));
        }
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = currentActiveTab + '_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
