<?php
/*
 * Inventory reports page. Displays stock reports for raw materials, finished
 * goods, and current inventory balances, with filtering and export options.
 */

$requestedType = isset($_GET['type']) ? trim($_GET['type']) : (isset($_GET['tab']) ? trim($_GET['tab']) : '');
if ($requestedType === 'current_stock') {
    $requestedType = 'current_balance';
}

$isTransactionReport = in_array($requestedType, [
    'stock_movements', 'stock_ins', 'stock_outs', 'stock_transfers', 'stock_adjustments'
], true);

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

$activeTab = 'raw_materials';
if (in_array($requestedType, ['raw_materials', 'finished_goods', 'current_balance', 'bad_products'], true)) {
    $activeTab = $requestedType;
}

$sql = "
    SELECT 
        i.item_id,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level) AS default_reorder_level,
        CASE 
            WHEN i.type IN ('raw_material', 'Raw Material') THEN 'Raw Materials'
            WHEN i.type IN ('finished_good', 'Finished Good') THEN 'Finished Goods'
            WHEN i.type IN ('packaging', 'Packaging') THEN 'Packaging'
            ELSE 'General'
        END AS category_name,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(s.qty_on_hand, 0) AS current_quantity
    FROM items i
    JOIN warehouses w ON w.warehouse_id = ?
    LEFT JOIN stock s ON i.item_id = s.item_id AND s.warehouse_id = w.warehouse_id
    WHERE (LOWER(i.status) = 'active')
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

$rawMaterialsData   = array_values(array_filter($allInventoryData, fn($r) => in_array($r['item_type'], ['raw_material', 'Raw Material'], true)));
$finishedGoodsData  = array_values(array_filter($allInventoryData, fn($r) => in_array($r['item_type'], ['finished_good', 'Finished Good'], true)));
$currentBalanceData = $allInventoryData;

$sqlBad = "
    SELECT 
        bp.bad_product_id,
        CONCAT('BP-', LPAD(bp.bad_product_id, 6, '0')) AS bad_product_number,
        bp.quantity,
        bp.reason,
        bp.status,
        bp.reported_at,
        bp.confirmed_at,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        CASE 
            WHEN bp.reason LIKE '%Customer Return%' OR bp.reason LIKE '%RMA%' THEN 'Customer Return (RMA)'
            ELSE 'Warehouse Defect Write-Off'
        END AS source_origin,
        COALESCE(u.name, 'Warehouse Operator') AS reported_by_name,
        COALESCE(cu.name, '—') AS confirmed_by_name
    FROM bad_products bp
    JOIN items i ON bp.item_id = i.item_id
    LEFT JOIN users u ON bp.reported_by = u.user_id
    LEFT JOIN users cu ON bp.confirmed_by = cu.user_id
    WHERE bp.warehouse_id = ?
      AND bp.status != 'cancelled'
";
$paramsBad = [$currentWarehouseId];
if ($search !== '') {
    $sqlBad .= " AND (i.name LIKE ? OR i.code LIKE ? OR bp.reason LIKE ?)";
    $paramsBad[] = "%$search%";
    $paramsBad[] = "%$search%";
    $paramsBad[] = "%$search%";
}
$sqlBad .= " ORDER BY bp.bad_product_id DESC";
$stmtBad = $pdo->prepare($sqlBad);
$stmtBad->execute($paramsBad);
$badProductsData = $stmtBad->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title" id="pageTitleHeading">Inventory Reports</h1>
        <p class="page-subtitle" id="pageSubtitleText">
            Formal operational ledgers, stock reconciliations, and compliance reporting
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
            <h3 id="printDocTitle" style="font-size: 16px; font-weight: 600; color: var(--accent); margin: 0;">
                <?= htmlspecialchars($activeTab === 'finished_goods' ? 'Finished Goods Stock Report' : ($activeTab === 'current_balance' ? 'Current Inventory Balance' : ($activeTab === 'bad_products' ? 'Bad Products Report' : 'Raw Materials Stock Report'))) ?>
            </h3>
        </div>
        <div style="text-align: right; font-size: 11px; color: var(--gray);">
            <div>Generated: <?= date('Y-m-d H:i:s') ?></div>
            <div>Facility: <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? '') ?></div>
        </div>
    </div>
</div>

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

        <button type="button" id="tab-btn-bad_products" class="stock-tab-btn <?= $activeTab === 'bad_products' ? 'active' : '' ?>" onclick="switchReportTab('bad_products')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span>Bad Products</span>
            <span class="tab-badge-count"><?= count($badProductsData) ?></span>
        </button>
    </div>
</div>

<?php
$reportCardMeta = [
    'raw_materials'   => ['title' => 'Raw Materials Stock Report', 'desc' => 'Current stock and reorder levels for raw materials'],
    'finished_goods'  => ['title' => 'Finished Goods Stock Report', 'desc' => 'Current stock of finished goods ready for sale'],
    'current_balance' => ['title' => 'Current Inventory Balance', 'desc' => 'Complete stock levels for all raw materials and finished goods'],
    'bad_products'    => ['title' => 'Bad Products Report', 'desc' => 'List of damaged or defective items (not counted in sellable stock)'],
];
$activeCardMeta = $reportCardMeta[$activeTab] ?? $reportCardMeta['raw_materials'];
?>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div>
            <h2 class="card-title" id="reportCardTitle"><?= htmlspecialchars($activeCardMeta['title']) ?></h2>
            <p class="card-desc" id="reportCardDesc"><?= htmlspecialchars($activeCardMeta['desc']) ?></p>
        </div>
        <form method="GET" action="index.php" id="reportFilterForm" class="report-filter-form no-print" onsubmit="event.preventDefault(); filterActiveReportTable(); return false;" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="type" id="filterReportTypeInput" value="<?= htmlspecialchars($activeTab) ?>">

            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" name="search" class="search-box" aria-label="Search report items" placeholder="Search item code, description, category..." value="<?= htmlspecialchars($search) ?>" id="reportSearchInput" oninput="filterActiveReportTable()">
            </div>

            <select id="reportHealthFilter" class="select-filter" aria-label="Filter by health status" onchange="filterActiveReportTable()">
                <option value="">All Statuses</option>
                <option value="optimal">In Stock</option>
                <option value="low_stock">Low Stock</option>
                <option value="out_of_stock">Out of Stock</option>
            </select>

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

<div id="pane-raw_materials" class="stock-op-pane" style="display: <?= $activeTab === 'raw_materials' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="reportTable_raw_materials">
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Item Description</th>
                        <th>Category</th>
                        <th>Classification</th>
                        <th class="text-center">Reorder Level</th>
                        <th class="text-center">Current Balance</th>
                        <th class="text-center">Health Status</th>
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
                            $healthStatus = ($qty == 0) ? 'out_of_stock' : ($isLow ? 'low_stock' : 'optimal');
                        ?>
                            <tr data-health="<?= $healthStatus ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['item_code']) ?></td>
                                <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                <td style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                <td>
                                    <span class="badge-type type-raw">Raw Material</span>
                                </td>
                                <td class="text-center" style="color: var(--gray);"><?= formatQty($reorder) ?> <small><?= htmlspecialchars($row['unit']) ?></small></td>
                                <td class="text-center" style="font-weight: 700; font-size: 14px; color: <?= $isLow ? '#B91C1C' : 'var(--panel-ink)' ?>;">
                                    <?= formatQty($qty) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($qty == 0): ?>
                                        <span class="badge status-alert">Out of Stock</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge status-pending">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge status-optimal">In Stock</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<div id="pane-finished_goods" class="stock-op-pane" style="display: <?= $activeTab === 'finished_goods' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="reportTable_finished_goods">
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Item Description</th>
                        <th>Category</th>
                        <th>Classification</th>
                        <th class="text-center">Reorder Level</th>
                        <th class="text-center">Current Balance</th>
                        <th class="text-center">Health Status</th>
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
                            $healthStatus = ($qty == 0) ? 'out_of_stock' : ($isLow ? 'low_stock' : 'optimal');
                        ?>
                            <tr data-health="<?= $healthStatus ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['item_code']) ?></td>
                                <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                <td style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                <td>
                                    <span class="badge-type type-fg">Finished Good</span>
                                </td>
                                <td class="text-center" style="color: var(--gray);"><?= formatQty($reorder) ?> <small><?= htmlspecialchars($row['unit']) ?></small></td>
                                <td class="text-center" style="font-weight: 700; font-size: 14px; color: <?= $isLow ? '#B91C1C' : 'var(--panel-ink)' ?>;">
                                    <?= formatQty($qty) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($qty == 0): ?>
                                        <span class="badge status-alert">Out of Stock</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge status-pending">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge status-optimal">In Stock</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<div id="pane-current_balance" class="stock-op-pane" style="display: <?= $activeTab === 'current_balance' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="reportTable_current_balance">
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Item Description</th>
                        <th>Category</th>
                        <th>Classification</th>
                        <th class="text-center">Reorder Level</th>
                        <th class="text-center">Current Balance</th>
                        <th class="text-center">Health Status</th>
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
                            $healthStatus = ($qty == 0) ? 'out_of_stock' : ($isLow ? 'low_stock' : 'optimal');
                        ?>
                            <tr data-health="<?= $healthStatus ?>">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($row['item_code']) ?></td>
                                <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                <td style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></td>
                                <td>
                                    <span class="badge-type <?= in_array($row['item_type'], ['finished_good', 'Finished Good'], true) ? 'type-fg' : 'type-raw' ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', $row['item_type'])) ?>
                                    </span>
                                </td>
                                <td class="text-center" style="color: var(--gray);"><?= formatQty($reorder) ?> <small><?= htmlspecialchars($row['unit']) ?></small></td>
                                <td class="text-center" style="font-weight: 700; font-size: 14px; color: <?= $isLow ? '#B91C1C' : 'var(--panel-ink)' ?>;">
                                    <?= formatQty($qty) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($qty == 0): ?>
                                        <span class="badge status-alert">Out of Stock</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge status-pending">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge status-optimal">In Stock</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<style>
.print-only-col { display: none; }
@media print {
    .print-only-col { display: table-cell !important; }
}
</style>
<div id="pane-bad_products" class="stock-op-pane" style="display: <?= $activeTab === 'bad_products' ? 'block' : 'none' ?>;">
        <div class="table-responsive">
            <table id="reportTable_bad_products">
                <thead>
                    <tr>
                        <th>Report Ref #</th>
                        <th>Item Code &amp; Description</th>
                        <th>Classification</th>
                        <th>Source / Origin</th>
                        <th class="print-only-col">Defect Reason / QA Notes</th>
                        <th>Verified / Reported By</th>
                        <th>Date Logged</th>
                        <th class="text-center">Bad Quantity</th>
                        <th class="text-center">Status</th>
                        <th class="text-center no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($badProductsData)): ?>
                        <tr><td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">No bad products recorded for this warehouse.</td></tr>
                    <?php else: ?>
                        <?php foreach ($badProductsData as $bp): 
                            $isFg = in_array($bp['item_type'], ['finished_good', 'Finished Good'], true);
                            $isRma = str_contains($bp['source_origin'], 'Customer Return');
                            $bpPayload = json_encode([
                                'ref'          => $bp['bad_product_number'],
                                'item_name'    => $bp['item_name'],
                                'item_code'    => $bp['item_code'],
                                'type'         => $isFg ? 'Finished Good' : 'Raw Material',
                                'source'       => $bp['source_origin'],
                                'reason'       => $bp['reason'],
                                'reported_by'  => $bp['reported_by_name'],
                                'confirmed_by' => $bp['confirmed_by_name'],
                                'reported_at'  => date('M d, Y H:i', strtotime($bp['reported_at'])),
                                'quantity'     => formatQty((float)$bp['quantity']) . ' ' . $bp['unit'],
                                'status'       => $bp['status'] === 'confirmed' ? 'Confirmed Bad Product' : ($bp['status'] === 'disposed' ? 'Disposed' : 'Pending QC'),
                            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                        ?>
                            <tr data-health="out_of_stock">
                                <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($bp['bad_product_number']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($bp['item_name']) ?></strong>
                                    <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($bp['item_code']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-type <?= $isFg ? 'type-fg' : 'type-raw' ?>">
                                        <?= $isFg ? 'Finished Good' : 'Raw Material' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isRma): ?>
                                        <span class="badge" style="background: #EDE9FE; color: #6D28D9; border: 1px solid #DDD6FE;">Customer Return (RMA)</span>
                                    <?php else: ?>
                                        <span class="badge status-alert">Warehouse Write-Off</span>
                                    <?php endif; ?>
                                </td>
                                <td class="print-only-col" style="font-size: 12px; color: var(--gray); max-width: 240px;">
                                    <?= htmlspecialchars($bp['reason']) ?>
                                </td>
                                <td style="font-size: 12px;"><?= htmlspecialchars($bp['reported_by_name']) ?></td>
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;"><?= date('M d, Y H:i', strtotime($bp['reported_at'])) ?></td>
                                <td class="text-center" style="font-weight: 700; font-size: 14px; color: #B91C1C;">
                                    <?= formatQty((float)$bp['quantity']) ?> <small style="font-weight: normal; color: var(--gray);"><?= htmlspecialchars($bp['unit']) ?></small>
                                </td>
                                <td class="text-center">
                                    <?php if ($bp['status'] === 'confirmed'): ?>
                                        <span class="badge status-approved" style="background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD;">Confirmed Bad Product</span>
                                    <?php elseif ($bp['status'] === 'disposed'): ?>
                                        <span class="badge status-completed" style="background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1;">Disposed</span>
                                    <?php else: ?>
                                        <span class="badge status-pending">Pending QC</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center no-print">
                                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 10px; font-size: 12px;" onclick='openBadProductDetailModal(<?= $bpPayload ?>)'>View</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>
</div>

<div id="badProductDetailModal" class="modal-backdrop no-print" role="dialog" aria-modal="true" aria-labelledby="bpModalTitle" onclick="if(event.target === this) closeBadProductDetailModal()">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <div>
                <h3 id="bpModalTitle" class="card-title">Bad Product Details</h3>
                <p class="card-desc" id="bpModalRef" style="font-family: monospace; font-weight: 700;"></p>
            </div>
            <button type="button" class="modal-close" aria-label="Close modal" onclick="closeBadProductDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #F8FAFC; padding: 12px; border-radius: 8px; border: 1px solid var(--border);">
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Item</span><strong id="bpModalItem"></strong></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Bad Quantity</span><strong id="bpModalQty" style="color: #B91C1C;"></strong></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Classification</span><span id="bpModalType"></span></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Source / Origin</span><span id="bpModalSource"></span></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Reported By</span><span id="bpModalReportedBy"></span></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Date Logged</span><span id="bpModalDate"></span></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Confirmed By</span><span id="bpModalConfirmedBy"></span></div>
                <div><span style="color: var(--gray); font-size: 11px; display: block;">Status</span><strong id="bpModalStatus"></strong></div>
            </div>
            <div>
                <span style="font-size: 12px; font-weight: 700; color: var(--panel-ink); display: block; margin-bottom: 6px;">Defect Reason / QA Notes</span>
                <div id="bpModalReason" style="background: #FFFBEB; border: 1px solid #FDE68A; color: #78350F; padding: 12px; border-radius: 8px; line-height: 1.5; white-space: pre-wrap;"></div>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeBadProductDetailModal()">Close</button>
        </div>
    </div>
</div>

<script>
function openBadProductDetailModal(bp) {
    if (!bp) return;
    document.getElementById('bpModalRef').textContent = bp.ref || '';
    document.getElementById('bpModalItem').textContent = (bp.item_name || '') + (bp.item_code ? ' (' + bp.item_code + ')' : '');
    document.getElementById('bpModalQty').textContent = bp.quantity || '';
    document.getElementById('bpModalType').textContent = bp.type || '';
    document.getElementById('bpModalSource').textContent = bp.source || '';
    document.getElementById('bpModalReportedBy').textContent = bp.reported_by || '—';
    document.getElementById('bpModalDate').textContent = bp.reported_at || '—';
    document.getElementById('bpModalConfirmedBy').textContent = bp.confirmed_by || '—';
    document.getElementById('bpModalStatus').textContent = bp.status || '';
    document.getElementById('bpModalReason').textContent = bp.reason || 'No defect notes provided.';
    document.getElementById('badProductDetailModal').classList.add('open');
}
function closeBadProductDetailModal() {
    document.getElementById('badProductDetailModal').classList.remove('open');
}

let currentActiveTab = '<?= $activeTab ?>';

const reportTitles = {
    'raw_materials': 'Raw Materials Stock Report',
    'finished_goods': 'Finished Goods Stock Report',
    'current_balance': 'Current Inventory Balance',
    'bad_products': 'Bad Products Report'
};
const reportDescs = {
    'raw_materials': 'Current stock and reorder levels for raw materials',
    'finished_goods': 'Current stock of finished goods ready for sale',
    'current_balance': 'Complete stock levels for all raw materials and finished goods',
    'bad_products': 'List of damaged or defective items (not counted in sellable stock)'
};

function switchReportTab(tabName) {
    const validTabs = ['raw_materials', 'finished_goods', 'current_balance', 'bad_products'];
    if (!validTabs.includes(tabName)) tabName = 'raw_materials';
    currentActiveTab = tabName;

    const cardTitleEl = document.getElementById('reportCardTitle');
    const cardDescEl  = document.getElementById('reportCardDesc');
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

    const filterInput = document.getElementById('filterReportTypeInput');
    if (filterInput) filterInput.value = tabName;

    const printSubtitle = document.getElementById('printDocTitle');
    if (printSubtitle && reportTitles[tabName]) {
        printSubtitle.textContent = reportTitles[tabName];
    }

    const url = new URL(window.location.href);
    url.searchParams.set('type', tabName);
    url.searchParams.delete('tab');
    window.history.replaceState({ tab: tabName }, '', url.toString());

    filterActiveReportTable();

    const activeTable = document.getElementById('reportTable_' + tabName);
    if (activeTable && typeof activeTable.paginationUpdate === 'function') {
        activeTable.paginationUpdate(false);
    }
}

window.addEventListener('popstate', function() {
    const params = new URLSearchParams(window.location.search);
    let tab = params.get('type') || params.get('tab') || 'raw_materials';
    if (tab === 'current_stock') tab = 'current_balance';
    switchReportTab(tab);
});

function filterActiveReportTable() {
    const input = document.getElementById('reportSearchInput');
    const healthSelect = document.getElementById('reportHealthFilter');
    const term = input ? input.value.toLowerCase().trim() : '';
    const healthFilter = healthSelect ? healthSelect.value : '';

    const tabNames = ['raw_materials', 'finished_goods', 'current_balance', 'bad_products'];
    tabNames.forEach(tab => {
        const table = document.getElementById('reportTable_' + tab);
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        let matchCount = 0;
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(row => {
            if (row.classList.contains('empty-filter-row')) return;
            if (row.classList.contains('no-filter') || row.querySelector('td[colspan]')) return;

            const text = row.textContent.toLowerCase();
            let rowHealth = row.getAttribute('data-health') || '';
            if (!rowHealth) {
                if (row.querySelector('.status-alert')) rowHealth = 'out_of_stock';
                else if (row.querySelector('.status-pending')) rowHealth = 'low_stock';
                else rowHealth = 'optimal';
            }

            const matchesText = !term || text.includes(term);
            const matchesHealth = !healthFilter || rowHealth === healthFilter;
            const isMatch = matchesText && matchesHealth;

            if (isMatch) {
                delete row.dataset.filteredOut;
                row.style.display = '';
                matchCount++;
            } else {
                row.dataset.filteredOut = 'true';
                row.style.display = 'none';
            }
        });

        let emptyRow = tbody.querySelector('tr.empty-filter-row');
        const hasRealDataRows = Array.from(rows).some(r => !r.classList.contains('empty-filter-row') && !r.querySelector('td[colspan]'));
        if (hasRealDataRows && matchCount === 0) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.className = 'empty-filter-row no-paginate';
                emptyRow.innerHTML = '<td colspan="7" style="text-align: center; color: var(--gray); padding: 36px;">No inventory records found matching the selected filter criteria.</td>';
                tbody.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }

        if (typeof table.paginationUpdate === 'function') {
            table.paginationUpdate(true);
        }
    });
}

function resetInventoryReportFilters() {
    const input = document.getElementById('reportSearchInput');
    const healthSelect = document.getElementById('reportHealthFilter');
    if (input) input.value = '';
    if (healthSelect) healthSelect.value = '';
    filterActiveReportTable();
}

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
