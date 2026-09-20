<?php
/**
 * View: Comprehensive Inventory Reports Suite
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Inventory Reports — StockPilot';
$activePage  = 'reports';
$activeGroup = 'reports';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Fetch Warehouses
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Selected Report Tab
$reportType   = isset($_GET['type']) ? trim($_GET['type']) : 'current_stock';
$warehouseId  = $currentWarehouseId; // Strictly enforce logged-in admin's assigned warehouse
$startDate    = isset($_GET['start_date']) && !empty($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01');
$endDate      = isset($_GET['end_date']) && !empty($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d');
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';

// Valid report configurations
$validReports = [
    'current_stock'    => 'Current Inventory Balance',
    'raw_materials'    => 'Raw Materials Stock Report',
    'finished_goods'   => 'Finished Goods Stock Report',
    'stock_movements'  => 'Stock Movement Ledger Report',
    'stock_ins'        => 'Inbound Stock Receipts',
    'stock_outs'       => 'Outbound Stock Dispatches',
    'stock_transfers'  => 'Inter-Branch Stock Transfers',
    'stock_adjustments'=> 'Stock Adjustments & Variances',
];

if (!array_key_exists($reportType, $validReports)) {
    $reportType = 'current_stock';
}

$reportTitle = $validReports[$reportType];
$reportData  = [];

// Execute query matching exact MySQL team_inventory schema
switch ($reportType) {
    case 'current_stock':
    case 'raw_materials':
    case 'finished_goods':
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

        if ($reportType === 'raw_materials') {
            $sql .= " AND i.item_type = 'raw_material'";
        } elseif ($reportType === 'finished_goods') {
            $sql .= " AND i.item_type = 'finished_good'";
        }

        if ($search !== '') {
            $sql .= " AND (i.item_name LIKE ? OR i.item_code LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY w.warehouse_name ASC, i.item_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

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
?>

<style>
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
    .page-header, .tab-bar, .report-filter-form, .header-actions, .sidebar, .navbar, .btn {
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
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($reportTitle) ?></h1>
        <p class="page-subtitle">Formal operational ledgers, stock reconciliations, and compliance reporting for <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse') ?> (<?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?>)</strong></p>
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
            <h3 style="font-size: 16px; font-weight: 600; color: var(--accent); margin: 0;"><?= htmlspecialchars($reportTitle) ?></h3>
        </div>
        <div style="text-align: right; font-size: 11px; color: var(--gray);">
            <div>Generated: <?= date('Y-m-d H:i:s') ?></div>
            <div>Facility: <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?> — <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? '') ?></div>
            <div>Period: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></div>
        </div>
    </div>
</div>

<!-- Report Filter Card -->
<div class="card">
    <form method="GET" action="index.php" class="report-filter-form" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="type" value="<?= htmlspecialchars($reportType) ?>">

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

        <?php if ($reportType !== 'current_stock' && $reportType !== 'raw_materials' && $reportType !== 'finished_goods'): ?>
            <!-- Date Range -->
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 12px; color: var(--gray); font-weight: 500;">From</span>
                <input type="date" name="start_date" class="select-filter" style="width: 140px; padding: 0 10px;" value="<?= htmlspecialchars($startDate) ?>">
                <span style="font-size: 12px; color: var(--gray); font-weight: 500;">To</span>
                <input type="date" name="end_date" class="select-filter" style="width: 140px; padding: 0 10px;" value="<?= htmlspecialchars($endDate) ?>">
            </div>
        <?php endif; ?>

        <!-- Buttons -->
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="height: 38px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
                <span>Generate</span>
            </button>
            <a href="index.php?type=<?= htmlspecialchars($reportType) ?>" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" title="Reset Filters">
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
            <?php if (in_array($reportType, ['current_stock', 'raw_materials', 'finished_goods'])): ?>
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
                    <?php if (empty($reportData)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No inventory records found for the selected criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reportData as $row): 
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

            <?php elseif ($reportType === 'stock_movements'): ?>
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

            <?php elseif ($reportType === 'stock_ins'): ?>
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

            <?php elseif ($reportType === 'stock_outs'): ?>
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

            <?php elseif ($reportType === 'stock_transfers'): ?>
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

            <?php elseif ($reportType === 'stock_adjustments'): ?>
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

<script>
function exportReportCsv() {
    const table = document.getElementById('reportTable');
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
        if (row.length > 0 && !row[0].includes('No inventory records found') && !row[0].includes('No stock movement')) {
            csv.push(row.join(','));
        }
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    const reportType = '<?= htmlspecialchars($reportType) ?>';
    downloadLink.download = reportType + '_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
