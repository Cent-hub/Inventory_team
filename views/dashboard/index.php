<?php
/**
 * View: Central Admin Dashboard
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Admin Dashboard — StockPilot';
$activePage  = 'dashboard';
$activeGroup = '';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// 1. Total Raw Materials (count of unique active raw materials with inventory > 0)
$stmtRM = $pdo->prepare("
    SELECT COUNT(DISTINCT inv.item_id) 
    FROM inventory inv 
    JOIN items i ON inv.item_id = i.item_id 
    WHERE i.item_type = 'raw_material' 
      AND i.status = 'active'
      AND inv.quantity > 0
");
$stmtRM->execute();
$totalRawMaterials = (int)$stmtRM->fetchColumn();

// 2. Total Finished Goods (count of unique active finished goods with inventory > 0)
$stmtFG = $pdo->prepare("
    SELECT COUNT(DISTINCT inv.item_id) 
    FROM inventory inv 
    JOIN items i ON inv.item_id = i.item_id 
    WHERE i.item_type = 'finished_good' 
      AND i.status = 'active'
      AND inv.quantity > 0
");
$stmtFG->execute();
$totalFinishedGoods = (int)$stmtFG->fetchColumn();

// 3. Total Stock (net physical quantity in assigned warehouse)
$stmtStock = $pdo->prepare("
    SELECT COALESCE(SUM(quantity), 0) 
    FROM inventory
    WHERE warehouse_id = :wid
");
$stmtStock->execute([':wid' => $currentWarehouseId]);
$totalStock = (float)$stmtStock->fetchColumn();

// 4. Low Stock Items count in assigned warehouse
$stmtLow = $pdo->prepare("
    SELECT COUNT(*) 
    FROM inventory inv 
    JOIN items i ON inv.item_id = i.item_id 
    WHERE inv.quantity <= i.default_reorder_level AND i.status = 'active' AND inv.warehouse_id = :wid
");
$stmtLow->execute([':wid' => $currentWarehouseId]);
$lowStockCount = (int)$stmtLow->fetchColumn();

// 5. Recent Stock In (inbound volume received for assigned warehouse in last 30 days)
$stmtSI = $pdo->prepare("
    SELECT COALESCE(SUM(sii.quantity), 0)
    FROM stock_ins si
    JOIN stock_in_items sii ON si.stock_in_id = sii.stock_in_id
    WHERE si.status = 'completed' AND si.warehouse_id = :wid AND si.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmtSI->execute([':wid' => $currentWarehouseId]);
$recentStockInVolume = (float)$stmtSI->fetchColumn();

$stmtSICount = $pdo->prepare("
    SELECT COUNT(*) FROM stock_ins WHERE status = 'completed' AND warehouse_id = :wid
");
$stmtSICount->execute([':wid' => $currentWarehouseId]);
$recentStockInCount = (int)$stmtSICount->fetchColumn();

// 6. Recent Stock Out (outbound volume dispatched for assigned warehouse in last 30 days)
$stmtSO = $pdo->prepare("
    SELECT COALESCE(SUM(soi.quantity), 0)
    FROM stock_outs so
    JOIN stock_out_items soi ON so.stock_out_id = soi.stock_out_id
    WHERE so.status = 'completed' AND so.warehouse_id = :wid AND so.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmtSO->execute([':wid' => $currentWarehouseId]);
$recentStockOutVolume = (float)$stmtSO->fetchColumn();

$stmtSOCount = $pdo->prepare("
    SELECT COUNT(*) FROM stock_outs WHERE status = 'completed' AND warehouse_id = :wid
");
$stmtSOCount->execute([':wid' => $currentWarehouseId]);
$recentStockOutCount = (int)$stmtSOCount->fetchColumn();

// 7. Recent Inventory Activity in assigned warehouse
$stmtRecent = $pdo->prepare("
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
    WHERE sm.warehouse_id = :wid
    ORDER BY sm.movement_id DESC
    LIMIT 100
");
$stmtRecent->execute([':wid' => $currentWarehouseId]);
$recentActivities = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

// 8. Primary Inventory Status Table for assigned warehouse
$stmtInventory = $pdo->prepare("
    SELECT 
        w.warehouse_code,
        w.warehouse_name,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        inv.quantity,
        i.default_reorder_level
    FROM inventory inv
    JOIN items i ON inv.item_id = i.item_id
    JOIN warehouses w ON inv.warehouse_id = w.warehouse_id
    WHERE inv.warehouse_id = :wid
    ORDER BY i.item_type ASC, i.item_name ASC
");
$stmtInventory->execute([':wid' => $currentWarehouseId]);
$inventoryRows = $stmtInventory->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Liquor Inventory Dashboard</h1>
        <p class="page-subtitle">Central monitoring and integration for Procurement inbounds, Warehouse inventory, and Production outputs</p>
    </div>
    <div class="header-actions">
        <a href="<?= BASE_URL ?>views/inventory/raw_materials.php" class="btn btn-secondary">
            <!-- Lucide Boxes Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l6 3.43a2 2 0 0 0 2.06 0l6-3.43a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71l-6-3.43a2 2 0 0 0-2.06 0l-6 3.43Z"/>
            </svg>
            <span>Raw Materials</span>
        </a>
        <a href="<?= BASE_URL ?>views/inventory/finished_goods.php" class="btn btn-secondary">
            <!-- Lucide PackageCheck Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="m16 16 2 2 4-4"/>
                <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/>
            </svg>
            <span>Finished Goods</span>
        </a>
        <a href="<?= BASE_URL ?>views/stock_in/index.php" class="btn btn-primary">
            <!-- Lucide ArrowDownLeft Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <line x1="17" y1="7" x2="7" y2="17"/>
                <polyline points="17 17 7 17 7 7"/>
            </svg>
            <span>Inbound / Stock In</span>
        </a>
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
        <div class="stat-meta">Inbounded from Procurement</div>
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
        <div class="stat-meta">Distillery &amp; packaging output</div>
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
        <div class="stat-meta">Net inventory in <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?></div>
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
        <div class="stat-meta"><?= $lowStockCount > 0 ? 'Reorder needed from Procurement' : 'All items at healthy levels' ?></div>
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
            <p class="card-desc">Chronological ledger activity from Procurement receipts, Production, and Sales</p>
        </div>
        <a href="<?= BASE_URL ?>views/inventory/movement.php" class="btn btn-secondary" style="height: 32px; padding: 0 12px; font-size: 12px;">
            <span>View Full Ledger</span>
        </a>
    </div>
    <div class="table-responsive">
        <table id="recentActivityTable">
            <thead>
                <tr>
                    <th>Product / Item</th>
                    <th>Classification</th>
                    <th>Movement</th>
                    <th>Quantity</th>
                    <th>Facility</th>
                    <th>Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentActivities)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray); padding: 32px;">No inventory movements recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentActivities as $act): ?>
                        <?php 
                            $isIncoming = (float)$act['quantity_in'] > 0;
                            $isTransfer = strpos($act['movement_type'], 'TRANSFER') !== false;
                            $pillClass = $isTransfer ? 'mov-transfer' : ($isIncoming ? 'mov-in' : 'mov-out');
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
                                    <?= htmlspecialchars($act['movement_type']) ?>
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
                            <td>
                                <span class="badge-wh"><?= htmlspecialchars($act['warehouse_code']) ?></span>
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
            <p class="card-desc">Current physical quantities recorded across all storage facilities</p>
        </div>
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" id="dashSearchInput" class="search-box" placeholder="Search item code or name..." onkeyup="filterTable('dashSearchInput', 'dashStockTable')">
        </div>
    </div>
    <div class="table-responsive">
        <table id="dashStockTable">
            <thead>
                <tr>
                    <th>Facility</th>
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
                        <td colspan="7" style="text-align: center; color: var(--gray); padding: 32px;">No inventory records in database.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventoryRows as $row): ?>
                        <?php $isOptimal = (float)$row['quantity'] > (float)$row['default_reorder_level']; ?>
                        <tr>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>">
                                    <?= htmlspecialchars($row['warehouse_code']) ?>
                                </span>
                                <span style="font-size: 12px; color: var(--gray); margin-left: 6px;">
                                    <?= htmlspecialchars($row['warehouse_name']) ?>
                                </span>
                            </td>
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
                                <?php if ($isOptimal): ?>
                                    <span class="badge status-optimal">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        Optimal
                                    </span>
                                <?php else: ?>
                                    <span class="badge status-alert">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        Low Stock
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
