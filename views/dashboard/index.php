<?php
/**
 * View: Central Admin Dashboard
 * InventoryTeam — Liquor Business Inventory Management System
 */

$pageTitle   = 'Admin Dashboard — InventoryTeam';
$activePage  = 'dashboard';
$activeGroup = '';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// 1. Primary Inventory Status Table for assigned warehouse (active catalog items only)
$stmtInventory = $pdo->prepare("
    SELECT 
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        COALESCE(s.qty_on_hand, 0) AS quantity,
        COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level) AS default_reorder_level
    FROM items i
    JOIN warehouses w ON w.warehouse_id = :wid1
    LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = :wid2
    WHERE i.status = 'active'
    ORDER BY i.type ASC, i.name ASC
");
$stmtInventory->execute([':wid1' => $currentWarehouseId, ':wid2' => $currentWarehouseId]);
$inventoryRows = $stmtInventory->fetchAll(PDO::FETCH_ASSOC);

// Compute KPI metrics directly from $inventoryRows (eliminating redundant SQL queries)
$totalRawMaterials   = 0;
$totalFinishedGoods  = 0;
$totalStockDiscrete  = 0.0;
$totalStockLiquid    = 0.0;
$totalStock          = 0.0;
$lowStockCount       = 0;

foreach ($inventoryRows as $row) {
    if (($row['item_type'] ?? '') === 'raw_material') {
        $totalRawMaterials++;
    } elseif (($row['item_type'] ?? '') === 'finished_good') {
        $totalFinishedGoods++;
    }
    $qty = (float)($row['quantity'] ?? 0);
    $reorder = (float)($row['default_reorder_level'] ?? 0);
    $unit = strtolower(trim($row['unit'] ?? ''));

    if (in_array($unit, ['l', 'liter', 'liters', 'ml', 'gallon', 'gal'], true)) {
        $totalStockLiquid += $qty;
    } else {
        $totalStockDiscrete += $qty;
    }
    $totalStock += $qty;

    if ($reorder > 0 && $qty <= $reorder) {
        $lowStockCount++;
    }
}

// 2. Recent Stock In (combined count & volume received for assigned warehouse in last 30 days)
$stmtSI = $pdo->prepare("
    SELECT 
        COUNT(sm.movement_id) AS txn_count,
        COALESCE(SUM(sm.quantity), 0) AS total_qty
    FROM stock_movements sm
    WHERE sm.movement_type = 'STOCK_IN'
      AND sm.warehouse_id = :wid
      AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmtSI->execute([':wid' => $currentWarehouseId]);
$siRow = $stmtSI->fetch(PDO::FETCH_ASSOC);
$recentStockInCount  = (int)($siRow['txn_count'] ?? 0);
$recentStockInVolume = (float)($siRow['total_qty'] ?? 0);

// 3. Recent Stock Out (combined count & volume dispatched for assigned warehouse in last 30 days)
$stmtSO = $pdo->prepare("
    SELECT 
        COUNT(sm.movement_id) AS txn_count,
        COALESCE(SUM(sm.quantity), 0) AS total_qty
    FROM stock_movements sm
    WHERE sm.movement_type = 'STOCK_OUT'
      AND sm.warehouse_id = :wid
      AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmtSO->execute([':wid' => $currentWarehouseId]);
$soRow = $stmtSO->fetch(PDO::FETCH_ASSOC);
$recentStockOutCount  = (int)($soRow['txn_count'] ?? 0);
$recentStockOutVolume = (float)($soRow['total_qty'] ?? 0);

// 4. Recent Inventory Activity in assigned warehouse
$stmtRecent = $pdo->prepare("
    SELECT 
        sm.movement_id,
        sm.movement_type,
        COALESCE(sm.remarks, CONCAT(sm.movement_type, ' #', sm.movement_id)) AS reference_number,
        CASE 
            WHEN sm.movement_type IN ('STOCK_IN', 'STOCK_TRANSFER_IN', 'CANCELLED_OUTBOUND', 'CANCELLED_BAD_PRODUCT') THEN sm.quantity 
            ELSE 0 
        END AS quantity_in,
        CASE 
            WHEN sm.movement_type IN ('STOCK_OUT', 'STOCK_TRANSFER_OUT', 'BAD_PRODUCT_DISCARD', 'CANCELLED_INBOUND') THEN sm.quantity 
            ELSE 0 
        END AS quantity_out,
        0 AS balance_after,
        sm.created_at,
        i.code AS item_code,
        i.name AS item_name,
        i.type AS item_type,
        i.unit,
        w.code AS warehouse_code,
        w.name AS warehouse_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    WHERE sm.warehouse_id = :wid
    ORDER BY sm.movement_id DESC
    LIMIT 100
");
$stmtRecent->execute([':wid' => $currentWarehouseId]);
$recentActivities = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Liquor Inventory Dashboard</h1>
        <p class="page-subtitle">Overview, stock levels, and recent warehouse activity in <strong><?= htmlspecialchars($assignedWarehouse['warehouse_code'] . ' (' . $assignedWarehouse['warehouse_name'] . ')') ?></strong></p>
    </div>
</div>

<!-- 6 Core Summary KPI Cards -->
<div class="stats-grid stats-grid-3">
    <!-- 1. Total Raw Materials -->
    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Total Raw Materials</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <!-- Lucide Layers Icon -->
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalRawMaterials) ?></div>
        <div class="stat-meta">Active Raw Materials</div>
    </div>

    <!-- 2. Total Finished Goods -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Finished Goods</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <!-- Lucide PackageCheck Icon -->
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m7.5 4.27 9 5.15"/>
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                    <path d="m3.3 7 8.7 5 8.7-5"/>
                    <path d="M12 22V12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalFinishedGoods) ?></div>
        <div class="stat-meta">Active Finished Goods</div>
    </div>

    <!-- 3. Total Stock -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total System Stock</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <!-- Lucide Warehouse Icon -->
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="20" x="4" y="2" rx="2" ry="2"/>
                    <path d="M9 22v-4h6v4"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= formatQty($totalStock) ?></div>
        <div class="stat-meta">
            <span><strong><?= formatQty($totalStockDiscrete) ?></strong> discrete (pcs/box)</span> &bull; 
            <span><strong><?= formatQty($totalStockLiquid) ?></strong> liquid (L/ml)</span>
        </div>
    </div>

    <!-- 4. Low Stock Items -->
    <div class="stat-card <?= $lowStockCount > 0 ? 'stat-alert' : '' ?>">
        <div class="stat-header">
            <span class="stat-label">Low Stock Items</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <!-- Lucide AlertTriangle Icon -->
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $lowStockCount ?></div>
        <div class="stat-meta">
            <?php if ($lowStockCount > 0): ?>
                <a href="<?= BASE_URL ?>views/inbound_outbound/index.php" style="color: var(--error); font-weight: 600; text-decoration: underline;">Replenish via Stock In &rarr;</a>
            <?php else: ?>
                All items at healthy levels
            <?php endif; ?>
        </div>
    </div>

    <!-- 5. Recent Stock In -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Recent Stock In (30d)</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                <!-- Lucide ArrowDownLeft Icon -->
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="17" y1="7" x2="7" y2="17"/>
                    <polyline points="17 17 7 17 7 7"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= formatQty($recentStockInVolume) ?></div>
        <div class="stat-meta"><?= $recentStockInCount ?> completed inbound receipts</div>
    </div>

    <!-- 6. Recent Stock Out -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Recent Stock Out (30d)</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">
                <!-- Lucide ArrowUpRight Icon -->
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="7" y1="17" x2="17" y2="7"/>
                    <polyline points="7 7 17 7 17 17"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= formatQty($recentStockOutVolume) ?></div>
        <div class="stat-meta"><?= $recentStockOutCount ?> completed outbound dispatches</div>
    </div>
</div>

<!-- Recent Inventory Activity Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Recent Inventory Activity</h2>
        </div>
    </div>
    <div class="table-responsive">
        <table id="recentActivityTable">
            <thead>
                <tr>
                    <th>Product / Item</th>
                    <th>Classification</th>
                    <th>Movement</th>
                    <th>Quantity</th>
                    <th>Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentActivities)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--gray); padding: 32px;">No inventory movements recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentActivities as $act): ?>
                        <?php 
                            $isIncoming = (float)$act['quantity_in'] > 0;
                            $isTransfer = strpos($act['movement_type'], 'TRANSFER') !== false;
                            $isAdj = strpos($act['movement_type'], 'ADJUSTMENT') !== false;
                            $isBad = strpos($act['movement_type'], 'BAD') !== false;
                            $isCancel = strpos($act['movement_type'], 'CANCEL') !== false;
                            $pillClass = $isCancel ? 'status-cancelled' : ($isBad ? 'mov-out' : ($isTransfer ? 'mov-transfer' : ($isAdj ? 'mov-adj' : ($isIncoming ? 'mov-in' : 'mov-out'))));
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($act['item_name']) ?></strong>
                                <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($act['item_code']) ?></div>
                            </td>
                            <td>
                                <span class="badge-type <?= $act['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $act['item_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $pillClass ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $act['movement_type'])) ?>
                                </span>
                            </td>
                            <td style="font-weight: 700; font-size: 13.5px;">
                                <?php if ($isIncoming): ?>
                                    <span style="color: #15803D;">+<?= formatQty($act['quantity_in']) ?></span>
                                <?php else: ?>
                                    <span style="color: #B91C1C;">-<?= formatQty($act['quantity_out']) ?></span>
                                <?php endif; ?>
                                <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($act['unit']) ?></small>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y H:i', strtotime($act['created_at'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Primary Real-Time Stock Status Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Warehouse Inventory Stock Snapshot</h2>
        </div>
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" id="dashSearchInput" class="search-box" aria-label="Search warehouse inventory" placeholder="Search item code or name..." oninput="filterTable('dashSearchInput', 'dashStockTable')">
        </div>
    </div>
    <div class="table-responsive">
        <table id="dashStockTable">
            <thead>
                <tr>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Type</th>
                    <th>Quantity on Hand</th>
                    <th>Reorder Level</th>
                    <th>Stock Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventoryRows)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray); padding: 32px;">No inventory records in database.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventoryRows as $row): ?>
                        <?php
                            $qtyOnHand = (float)$row['quantity'];
                            $reorderThreshold = (float)$row['default_reorder_level'];
                            $isOutOfStock = $qtyOnHand <= 0;
                            $isLowStock = !$isOutOfStock && $qtyOnHand <= $reorderThreshold;
                        ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: 600; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['item_code']) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge-type <?= $row['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $row['item_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 700; font-size: 14.5px;"><?= formatQty($row['quantity']) ?></span>
                                <small style="color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                            </td>
                            <td>
                                <?= formatQty($row['default_reorder_level']) ?> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                            </td>
                            <td>
                                <?php if ($isOutOfStock): ?>
                                    <span class="badge status-alert">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        Out of Stock
                                    </span>
                                <?php elseif ($isLowStock): ?>
                                    <span class="badge status-pending">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="badge status-optimal">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        Optimal
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
