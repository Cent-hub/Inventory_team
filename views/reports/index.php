<?php
/**
 * View: Comprehensive Inventory Reports Suite
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Restructured Unified Hub for Core Inventory Reports:
 * Section 1: Raw Materials Stock Report
 * Section 2: Finished Goods Stock Report
 * Section 3: Current Inventory Balance
 * 
 * Also preserves transaction reports (Movement, Inbounds, Outbounds, Transfers, Adjustments).
 */

// Selected Report Identifier
$requestedType = isset($_GET['type']) ? trim($_GET['type']) : (isset($_GET['tab']) ? trim($_GET['tab']) : '');
if ($requestedType === 'current_stock') {
    $requestedType = 'current_balance';
}

// Check if this is a transaction ledger report (Group 2)
$isTransactionReport = in_array($requestedType, [
    'stock_movements', 'stock_ins', 'stock_outs', 'stock_transfers', 'stock_adjustments'
], true);

// Forward transaction report requests to the unified Stock Transaction Reports hub BEFORE any output
if ($isTransactionReport && php_sapi_name() !== 'cli' && (!defined('IN_UNIT_TEST') || !IN_UNIT_TEST)) {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
    $projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
    $queryParams = $_GET;
    $queryParams['tab'] = $requestedType;
    $queryString = http_build_query($queryParams);
    if (!headers_sent()) {
        header("Location: {$projectRoot}/views/reports/stock_transactions.php?" . $queryString);
        exit;
    } else {
        echo "<script>window.location.href = '{$projectRoot}/views/reports/stock_transactions.php?{$queryString}';</script>";
        exit;
    }
}

$pageTitle   = $isTransactionReport ? 'Stock Transaction Reports — StockPilot' : 'Inventory Reports — StockPilot';
$activePage  = $isTransactionReport ? 'stock_transactions' : 'reports';
$activeGroup = 'reports';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$auth = $auth ?? new AuthController();
$pdo  = $pdo ?? Database::getConnection();
$currentUser = $currentUser ?? ($auth->getCurrentUser() ?? []);
$currentWarehouseId = $currentWarehouseId ?? (int)($_SESSION['warehouse_id'] ?? ($currentUser['warehouse_id'] ?? 1));

// Fetch Warehouses
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$warehouseId  = $currentWarehouseId; // Strictly enforce logged-in admin's assigned warehouse
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

// Active Tab for Unified Inventory Reports (Default: raw_materials)
$activeTab = 'raw_materials';
if (in_array($requestedType, ['raw_materials', 'finished_goods', 'current_balance'], true)) {
    $activeTab = $requestedType;
}

// =============================================================================
// DATA RETRIEVAL
// =============================================================================

if (!$isTransactionReport) {
    // -------------------------------------------------------------------------
    // UNIFIED INVENTORY REPORTS (Raw Materials | Finished Goods | Current Balance)
    // -------------------------------------------------------------------------
    $sql = "
        SELECT 
            i.item_id,
            i.item_code,
            i.item_name,
            i.item_type,
            i.unit,
            i.default_reorder_level,
            COALESCE(c.category_name, 'General') AS category_name,
            w.warehouse_code,
            w.warehouse_name,
            COALESCE(inv.quantity, 0) AS current_quantity
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.category_id
        JOIN inventory inv ON i.item_id = inv.item_id
        JOIN warehouses w ON inv.warehouse_id = w.warehouse_id
        WHERE i.status = 'active'
          AND inv.warehouse_id = ?
    ";
    $params = [$currentWarehouseId];

    if ($search !== '') {
        $sql .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR c.category_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY w.warehouse_name ASC, i.item_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allInventoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rawMaterialsData   = array_values(array_filter($allInventoryData, fn($r) => $r['item_type'] === 'raw_material'));
    $finishedGoodsData  = array_values(array_filter($allInventoryData, fn($r) => $r['item_type'] === 'finished_good'));
    $currentBalanceData = $allInventoryData;

} else {
    // -------------------------------------------------------------------------
    // TRANSACTION REPORTS SUITE
    // -------------------------------------------------------------------------
    $validReports = [
        'stock_movements'   => 'Stock Movement Ledger Report',
        'stock_ins'         => 'Inbound Stock Receipts',
        'stock_outs'        => 'Outbound Stock Dispatches',
        'stock_transfers'   => 'Inter-Branch Stock Transfers',
        'stock_adjustments' => 'Stock Adjustments & Variances',
    ];
    $reportTitle = $validReports[$requestedType] ?? 'Transaction Report';
    $reportData  = [];

    switch ($requestedType) {
        case 'stock_movements':
            $sql = "
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
            $params = [$startDate, $endDate, $currentWarehouseId];
            if ($search !== '') {
                $sql .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR sm.reference_number LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            $sql .= " ORDER BY sm.created_at DESC, sm.movement_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'stock_ins':
            $sql = "
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
            $params = [$startDate, $endDate, $currentWarehouseId];
            if ($search !== '') {
                $sql .= " AND (si.transaction_number LIKE ? OR si.source_reference_no LIKE ? OR si.remarks LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            $sql .= " GROUP BY si.stock_in_id ORDER BY si.transaction_date DESC, si.stock_in_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'stock_outs':
            $sql = "
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
            $params = [$startDate, $endDate, $currentWarehouseId];
            if ($search !== '') {
                $sql .= " AND (so.transaction_number LIKE ? OR so.source_reference_no LIKE ? OR so.remarks LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            $sql .= " GROUP BY so.stock_out_id ORDER BY so.transaction_date DESC, so.stock_out_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'stock_transfers':
            $sql = "
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
                  AND st.source_warehouse_id = ?
            ";
            $params = [$startDate, $endDate, $currentWarehouseId];
            if ($search !== '') {
                $sql .= " AND (st.transaction_number LIKE ? OR st.remarks LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            $sql .= " GROUP BY st.stock_transfer_id ORDER BY st.transaction_date DESC, st.stock_transfer_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'stock_adjustments':
            $sql = "
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
            $params = [$startDate, $endDate, $currentWarehouseId];
            if ($search !== '') {
                $sql .= " AND (sa.transaction_number LIKE ? OR i.item_name LIKE ? OR i.item_code LIKE ? OR sa.reason LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            $sql .= " ORDER BY sa.adjustment_date DESC, sa.stock_adjustment_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
}
?>

<style>
/* Operations & Reports Tab Navigation Bar */
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
    padding: 10px 20px;
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
        <h1 class="page-title" id="pageTitleHeading"><?= $isTransactionReport ? htmlspecialchars($reportTitle) : 'Inventory Reports' ?></h1>
        <p class="page-subtitle" id="pageSubtitleText">
            Formal operational ledgers, stock reconciliations, and compliance reporting for <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse') ?> (<?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?>)</strong>
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
            <h3 id="printDocTitle" style="font-size: 16px; font-weight: 600; color: var(--accent); margin: 0;">
                <?php 
                if ($isTransactionReport) {
                    echo htmlspecialchars($reportTitle);
                } else {
                    echo htmlspecialchars($activeTab === 'finished_goods' ? 'Finished Goods Stock Report' : ($activeTab === 'current_balance' ? 'Current Inventory Balance' : 'Raw Materials Stock Report'));
                }
                ?>
            </h3>
        </div>
        <div style="text-align: right; font-size: 11px; color: var(--gray);">
            <div>Generated: <?= date('Y-m-d H:i:s') ?></div>
            <div>Facility: <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? '') ?></div>
            <?php if ($isTransactionReport): ?>
                <div>Period: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$isTransactionReport): ?>
    <!-- ===================================================================== -->
    <!-- SECTION NAVIGATION TABS: RAW MATERIALS | FINISHED GOODS | BALANCE    -->
    <!-- ===================================================================== -->
    <div class="stock-ops-nav-wrapper">
        <div class="stock-ops-nav-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="7" height="7" x="3" y="3" rx="1"/>
                <rect width="7" height="7" x="14" y="3" rx="1"/>
                <rect width="7" height="7" x="14" y="14" rx="1"/>
                <rect width="7" height="7" x="3" y="14" rx="1"/>
            </svg>
            <span>Inventory Reports</span>
        </div>
        <div class="stock-ops-tabs">
            <button type="button" id="tab-btn-raw_materials" class="stock-tab-btn <?= $activeTab === 'raw_materials' ? 'active' : '' ?>" onclick="switchReportTab('raw_materials')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
                <span>Raw Materials</span>
                <span class="tab-badge-count"><?= count($rawMaterialsData) ?></span>
            </button>

            <button type="button" id="tab-btn-finished_goods" class="stock-tab-btn <?= $activeTab === 'finished_goods' ? 'active' : '' ?>" onclick="switchReportTab('finished_goods')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m16 16 2 2 4-4"/>
                    <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/>
                </svg>
                <span>Finished Goods</span>
                <span class="tab-badge-count"><?= count($finishedGoodsData) ?></span>
            </button>

            <button type="button" id="tab-btn-current_balance" class="stock-tab-btn <?= $activeTab === 'current_balance' ? 'active' : '' ?>" onclick="switchReportTab('current_balance')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                    <path d="M3 9h18"/>
                    <path d="M9 21V9"/>
                </svg>
                <span>Current Balance</span>
                <span class="tab-badge-count"><?= count($currentBalanceData) ?></span>
            </button>
        </div>
    </div>

    <!-- Inventory Report Filter Card -->
    <div class="card" style="margin-bottom: 20px;">
        <form method="GET" action="index.php" id="reportFilterForm" class="report-filter-form" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="type" id="filterReportTypeInput" value="<?= htmlspecialchars($activeTab) ?>">

            <!-- Search -->
            <div class="search-wrap" style="flex: 1; min-width: 220px;">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" name="search" class="search-box" placeholder="Search item code, description, category..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" onkeyup="filterActiveReportTable()">
            </div>

            <!-- Assigned Facility (Locked) -->
            <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 0 12px; height: 38px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray)" stroke-width="2"><path d="M3 21h18M3 7v14M21 7v14M6 3h12l3 4H3l3-4z"/></svg>
                <span style="font-size: 13px; font-weight: 600; color: var(--panel-ink);">
                    <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Facility') ?>
                </span>
                <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Assigned</span>
            </div>

            <!-- Buttons -->
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="height: 38px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                    </svg>
                    <span>Filter</span>
                </button>
                <a href="index.php" id="resetFilterBtn" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" title="Reset Filters">
                    <span>Reset</span>
                </a>
            </div>
        </form>
    </div>

    <!-- ##################################################################### -->
    <!-- SECTION 1: RAW MATERIALS STOCK REPORT                                 -->
    <!-- ##################################################################### -->
    <div id="pane-raw_materials" class="stock-op-pane" style="display: <?= $activeTab === 'raw_materials' ? 'block' : 'none' ?>;">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Raw Materials Stock Report</h2>
                    <p class="card-desc">Audited raw ingredients inventory and reorder thresholds &middot; Generated on <?= date('M d, Y H:i') ?></p>
                </div>
            </div>
            <div class="table-responsive">
                <table id="reportTable_raw_materials">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Description</th>
                            <th>Category</th>
                            <th>Classification</th>
                            <th>Facility</th>
                            <th style="text-align: right;">Reorder Level</th>
                            <th style="text-align: right;">Current Balance</th>
                            <th>Health Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rawMaterialsData)): ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No raw materials inventory records found for the selected criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rawMaterialsData as $row): 
                                $qty = (float)$row['current_quantity'];
                                $reorder = (float)$row['default_reorder_level'];
                                $isLow = $reorder > 0 && $qty <= $reorder;
                            ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['item_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                    <td style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                    <td>
                                        <span class="badge-type type-raw">Raw Material</span>
                                    </td>
                                    <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                                    <td style="text-align: right; color: var(--gray);"><?= formatQty($reorder) ?> <small><?= htmlspecialchars($row['unit']) ?></small></td>
                                    <td style="text-align: right; font-weight: 700; font-size: 14px; color: <?= $isLow ? '#B91C1C' : 'var(--panel-ink)' ?>;">
                                        <?= formatQty($qty) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($qty == 0): ?>
                                            <span class="badge status-alert">Out of Stock</span>
                                        <?php elseif ($isLow): ?>
                                            <span class="badge status-pending">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge status-optimal">Optimal</span>
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

    <!-- ##################################################################### -->
    <!-- SECTION 2: FINISHED GOODS STOCK REPORT                                -->
    <!-- ##################################################################### -->
    <div id="pane-finished_goods" class="stock-op-pane" style="display: <?= $activeTab === 'finished_goods' ? 'block' : 'none' ?>;">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Finished Goods Stock Report</h2>
                    <p class="card-desc">Audited packaged and bottled stock inventory &middot; Generated on <?= date('M d, Y H:i') ?></p>
                </div>
            </div>
            <div class="table-responsive">
                <table id="reportTable_finished_goods">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Description</th>
                            <th>Category</th>
                            <th>Classification</th>
                            <th>Facility</th>
                            <th style="text-align: right;">Reorder Level</th>
                            <th style="text-align: right;">Current Balance</th>
                            <th>Health Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($finishedGoodsData)): ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No finished goods inventory records found for the selected criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($finishedGoodsData as $row): 
                                $qty = (float)$row['current_quantity'];
                                $reorder = (float)$row['default_reorder_level'];
                                $isLow = $reorder > 0 && $qty <= $reorder;
                            ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['item_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                    <td style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                    <td>
                                        <span class="badge-type type-fg">Finished Good</span>
                                    </td>
                                    <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                                    <td style="text-align: right; color: var(--gray);"><?= formatQty($reorder) ?> <small><?= htmlspecialchars($row['unit']) ?></small></td>
                                    <td style="text-align: right; font-weight: 700; font-size: 14px; color: <?= $isLow ? '#B91C1C' : 'var(--panel-ink)' ?>;">
                                        <?= formatQty($qty) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($qty == 0): ?>
                                            <span class="badge status-alert">Out of Stock</span>
                                        <?php elseif ($isLow): ?>
                                            <span class="badge status-pending">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge status-optimal">Optimal</span>
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

    <!-- ##################################################################### -->
    <!-- SECTION 3: CURRENT INVENTORY BALANCE                                  -->
    <!-- ##################################################################### -->
    <div id="pane-current_balance" class="stock-op-pane" style="display: <?= $activeTab === 'current_balance' ? 'block' : 'none' ?>;">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Current Inventory Balance</h2>
                    <p class="card-desc">Comprehensive facility balance across all item classifications &middot; Generated on <?= date('M d, Y H:i') ?></p>
                </div>
            </div>
            <div class="table-responsive">
                <table id="reportTable_current_balance">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Description</th>
                            <th>Category</th>
                            <th>Classification</th>
                            <th>Facility</th>
                            <th style="text-align: right;">Reorder Level</th>
                            <th style="text-align: right;">Current Balance</th>
                            <th>Health Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($currentBalanceData)): ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No inventory records found for the selected criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($currentBalanceData as $row): 
                                $qty = (float)$row['current_quantity'];
                                $reorder = (float)$row['default_reorder_level'];
                                $isLow = $reorder > 0 && $qty <= $reorder;
                            ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['item_code']) ?></td>
                                    <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                    <td style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                    <td>
                                        <span class="badge-type <?= $row['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                                            <?= htmlspecialchars(str_replace('_', ' ', $row['item_type'])) ?>
                                        </span>
                                    </td>
                                    <td><span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                                    <td style="text-align: right; color: var(--gray);"><?= formatQty($reorder) ?> <small><?= htmlspecialchars($row['unit']) ?></small></td>
                                    <td style="text-align: right; font-weight: 700; font-size: 14px; color: <?= $isLow ? '#B91C1C' : 'var(--panel-ink)' ?>;">
                                        <?= formatQty($qty) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($qty == 0): ?>
                                            <span class="badge status-alert">Out of Stock</span>
                                        <?php elseif ($isLow): ?>
                                            <span class="badge status-pending">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge status-optimal">Optimal</span>
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

<?php else: ?>
    <!-- ===================================================================== -->
    <!-- TRANSACTION REPORT VIEW (Group 2)                                     -->
    <!-- ===================================================================== -->
    <div class="card" style="margin-bottom: 20px;">
        <form method="GET" action="index.php" class="report-filter-form" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="type" value="<?= htmlspecialchars($requestedType) ?>">

            <!-- Search -->
            <div class="search-wrap" style="flex: 1; min-width: 200px;">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" name="search" class="search-box" placeholder="Search report items or ref..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" onkeyup="filterTable('reportSearchInput', 'reportTable')">
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
                    <span>Generate</span>
                </button>
                <a href="index.php?type=<?= htmlspecialchars($requestedType) ?>" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" title="Reset Filters">
                    <span>Reset</span>
                </a>
            </div>
        </form>
    </div>

    <!-- Report Content Table Card -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title"><?= htmlspecialchars($reportTitle) ?></h2>
                <p class="card-desc">Audited ledger report generated on <?= date('M d, Y H:i') ?></p>
            </div>
        </div>
        <div class="table-responsive">
            <table id="reportTable">
                <?php if ($requestedType === 'stock_movements'): ?>
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
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No stock movement transactions recorded in this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): 
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

                <?php elseif ($requestedType === 'stock_ins'): ?>
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
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No inbound stock receipts found in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
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

                <?php elseif ($requestedType === 'stock_outs'): ?>
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
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No outbound stock shipments found in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
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

                <?php elseif ($requestedType === 'stock_transfers'): ?>
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
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No inter-branch transfers recorded in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
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

                <?php elseif ($requestedType === 'stock_adjustments'): ?>
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
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No stock adjustments recorded in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): 
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
                <?php endif; ?>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
// =============================================================================
// UNIFIED INVENTORY REPORTS CONTROLLER (Raw Materials | Finished Goods | Balance)
// =============================================================================
const isTransactionReport = <?= $isTransactionReport ? 'true' : 'false' ?>;
let currentActiveTab = '<?= $activeTab ?>';

const reportTitles = {
    'raw_materials': 'Raw Materials Stock Report',
    'finished_goods': 'Finished Goods Stock Report',
    'current_balance': 'Current Inventory Balance'
};

function switchReportTab(tabName) {
    const validTabs = ['raw_materials', 'finished_goods', 'current_balance'];
    if (!validTabs.includes(tabName)) tabName = 'raw_materials';
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

    // Update Form hidden type input and Reset link
    const filterInput = document.getElementById('filterReportTypeInput');
    if (filterInput) filterInput.value = tabName;
    const resetBtn = document.getElementById('resetFilterBtn');
    if (resetBtn) resetBtn.href = 'index.php?type=' + tabName;

    // Update printable document subtitle
    const printSubtitle = document.getElementById('printDocTitle');
    if (printSubtitle && reportTitles[tabName]) {
        printSubtitle.textContent = reportTitles[tabName];
    }

    // Sync URL without reloading
    const url = new URL(window.location.href);
    url.searchParams.set('type', tabName);
    url.searchParams.delete('tab');
    window.history.replaceState({ tab: tabName }, '', url.toString());

    // Update instant filter on the newly active table
    filterActiveReportTable();

    // Trigger pagination re-calculation on active table
    const activeTable = document.getElementById('reportTable_' + tabName);
    if (activeTable && typeof activeTable.paginationUpdate === 'function') {
        activeTable.paginationUpdate(false);
    }
}

// Support browser back/forward buttons
window.addEventListener('popstate', function() {
    if (!isTransactionReport) {
        const params = new URLSearchParams(window.location.search);
        let tab = params.get('type') || params.get('tab') || 'raw_materials';
        if (tab === 'current_stock') tab = 'current_balance';
        switchReportTab(tab);
    }
});

// Instant live search across the active report table
function filterActiveReportTable() {
    const input = document.getElementById('reportSearchInput');
    if (!input) return;
    const term = input.value.toLowerCase().trim();

    if (isTransactionReport) {
        filterTable('reportSearchInput', 'reportTable');
        return;
    }

    const tableId = 'reportTable_' + currentActiveTab;
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
    let table = null;
    let exportFileName = 'inventory_report';

    if (isTransactionReport) {
        table = document.getElementById('reportTable');
        exportFileName = '<?= htmlspecialchars($requestedType) ?>';
    } else {
        table = document.getElementById('reportTable_' + currentActiveTab);
        exportFileName = currentActiveTab;
    }

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
        if (row.length > 0 && !row[0].includes('No inventory records found') && !row[0].includes('No raw materials') && !row[0].includes('No finished goods') && !row[0].includes('No stock movement')) {
            csv.push(row.join(','));
        }
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = exportFileName + '_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
