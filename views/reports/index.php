<?php
/**
 * View: Comprehensive Inventory Reports Suite
 * InventoryTeam — Liquor Business Inventory Management System
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

// Forward transaction report requests to the unified Stock Transaction Reports hub
if ($isTransactionReport) {
    if (php_sapi_name() !== 'cli' && (!defined('IN_UNIT_TEST') || !IN_UNIT_TEST)) {
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
    $_GET['tab'] = $requestedType;
    require __DIR__ . '/stock_transactions.php';
    return;
}

$pageTitle   = 'Inventory Reports — InventoryTeam';
$activePage  = 'reports';
$activeGroup = 'reports';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Active Tab for Unified Inventory Reports (Default: raw_materials)
$activeTab = 'raw_materials';
if (in_array($requestedType, ['raw_materials', 'finished_goods', 'current_balance'], true)) {
    $activeTab = $requestedType;
}

// =============================================================================
// DATA RETRIEVAL: UNIFIED INVENTORY REPORTS
// =============================================================================
$sql = "
    SELECT 
        i.item_id,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level) AS default_reorder_level,
        CASE 
            WHEN i.type = 'raw_material' THEN 'Raw Materials'
            WHEN i.type = 'finished_good' THEN 'Finished Goods'
            WHEN i.type = 'packaging' THEN 'Packaging'
            ELSE 'General'
        END AS category_name,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(s.qty_on_hand, 0) AS current_quantity
    FROM items i
    JOIN warehouses w ON w.warehouse_id = ?
    LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = w.warehouse_id
    WHERE i.status = 'active'
";
$params = [$currentWarehouseId];

if ($search !== '') {
    $sql .= " AND (i.name LIKE ? OR i.code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY w.name ASC, i.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allInventoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rawMaterialsData   = array_values(array_filter($allInventoryData, fn($r) => $r['item_type'] === 'raw_material'));
$finishedGoodsData  = array_values(array_filter($allInventoryData, fn($r) => $r['item_type'] === 'finished_good'));
$currentBalanceData = $allInventoryData;
?>

<!-- Page Header -->
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title" id="pageTitleHeading">Inventory Reports</h1>
        <p class="page-subtitle" id="pageSubtitleText">
            Formal operational ledgers, stock reconciliations, and compliance reporting
        </p>
    </div>
</div>

<!-- Print-Only Official Document Header -->
<div class="print-banner">
    <div style="display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: var(--panel-ink); margin: 0 0 4px 0;">InventoryTeam Inventory Management System</h2>
            <h3 id="printDocTitle" style="font-size: 16px; font-weight: 600; color: var(--accent); margin: 0;">
                <?= htmlspecialchars($activeTab === 'finished_goods' ? 'Finished Goods Stock Report' : ($activeTab === 'current_balance' ? 'Current Inventory Balance' : 'Raw Materials Stock Report')) ?>
            </h3>
        </div>
        <div style="text-align: right; font-size: 11px; color: var(--gray);">
            <div>Generated: <?= date('Y-m-d H:i:s') ?></div>
            <div>Facility: <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? '') ?></div>
        </div>
    </div>
</div>

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
    <form method="GET" action="index.php" id="reportFilterForm" class="report-filter-form" onsubmit="event.preventDefault(); filterActiveReportTable(); return false;" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="type" id="filterReportTypeInput" value="<?= htmlspecialchars($activeTab) ?>">

        <!-- Search -->
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" name="search" class="search-box" aria-label="Search report items" placeholder="Search item code, description, category..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" oninput="filterActiveReportTable()">
        </div>
        <!-- Buttons -->
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary" style="height: 38px;" onclick="resetInventoryReportFilters()" title="Reset Filters">
                <span>Reset</span>
            </button>
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
                        <th style="text-align: right;">Reorder Level</th>
                        <th style="text-align: right;">Current Balance</th>
                        <th>Health Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rawMaterialsData)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--gray); padding: 36px;">No raw materials inventory records found for the selected criteria.</td></tr>
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
                        <th style="text-align: right;">Reorder Level</th>
                        <th style="text-align: right;">Current Balance</th>
                        <th>Health Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($finishedGoodsData)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--gray); padding: 36px;">No finished goods inventory records found for the selected criteria.</td></tr>
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
                        <th style="text-align: right;">Reorder Level</th>
                        <th style="text-align: right;">Current Balance</th>
                        <th>Health Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currentBalanceData)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--gray); padding: 36px;">No inventory records found for the selected criteria.</td></tr>
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

<script>
// =============================================================================
// UNIFIED INVENTORY REPORTS CONTROLLER (Raw Materials | Finished Goods | Balance)
// =============================================================================
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

    // Update Form hidden type input
    const filterInput = document.getElementById('filterReportTypeInput');
    if (filterInput) filterInput.value = tabName;

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
    const params = new URLSearchParams(window.location.search);
    let tab = params.get('type') || params.get('tab') || 'raw_materials';
    if (tab === 'current_stock') tab = 'current_balance';
    switchReportTab(tab);
});

// Instant live search across the active report table
function filterActiveReportTable() {
    const input = document.getElementById('reportSearchInput');
    if (!input) return;
    const term = input.value.toLowerCase().trim();

    const tableId = 'reportTable_' + currentActiveTab;
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

function resetInventoryReportFilters() {
    const input = document.getElementById('reportSearchInput');
    if (input) input.value = '';
    filterActiveReportTable();
}

// Export active report table as CSV (delegates to global exportTableToCsv in footer.php)
function exportReportCsv() {
    exportTableToCsv('reportTable_' + currentActiveTab, currentActiveTab);
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
