<?php
/**
 * View: Finished Goods (Production Output Monitoring)
 * InventoryTeam — Liquor Business Inventory Management System
 */

$pageTitle   = 'Finished Goods — InventoryTeam';
$activePage  = 'finished_goods';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Query Finished Goods for assigned warehouse with latest Production receipt info
$stmt = $pdo->prepare("
    SELECT 
        i.item_id,
        i.item_code,
        i.item_name,
        COALESCE(c.category_name, 'Bottled Spirits & Liquors') AS category_name,
        i.unit,
        i.default_reorder_level,
        w.warehouse_id,
        w.warehouse_code,
        w.warehouse_name,
        COALESCE(inv.quantity, 0.000) AS current_stock,
        latest_prod.production_date,
        latest_prod.source_reference_no AS batch_reference,
        latest_prod.last_produced_qty
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.category_id
    JOIN inventory inv ON inv.item_id = i.item_id
    JOIN warehouses w ON inv.warehouse_id = w.warehouse_id
    LEFT JOIN (
        SELECT 
            sii.item_id,
            si.warehouse_id,
            si.transaction_date AS production_date,
            si.source_reference_no,
            sii.quantity AS last_produced_qty
        FROM stock_in_items sii
        JOIN stock_ins si ON sii.stock_in_id = si.stock_in_id
        WHERE si.status = 'completed' AND si.warehouse_id = :wid1
        ORDER BY si.transaction_date DESC, si.stock_in_id DESC
    ) latest_prod ON latest_prod.item_id = i.item_id AND latest_prod.warehouse_id = w.warehouse_id
    WHERE i.item_type = 'finished_good' AND i.status = 'active' AND inv.warehouse_id = :wid2
    GROUP BY i.item_id, w.warehouse_id
    ORDER BY i.item_name ASC, w.warehouse_code ASC
");
$stmt->execute([':wid1' => $currentWarehouseId, ':wid2' => $currentWarehouseId]);
$finishedGoods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics scoped strictly to assigned warehouse
$stmtFGSKUs = $pdo->prepare("SELECT COUNT(DISTINCT inv.item_id) FROM inventory inv JOIN items i ON inv.item_id = i.item_id WHERE i.item_type = 'finished_good' AND i.status = 'active' AND inv.warehouse_id = :wid");
$stmtFGSKUs->execute([':wid' => $currentWarehouseId]);
$totalFGSKUs = (int)$stmtFGSKUs->fetchColumn();

$stmtFGQty = $pdo->prepare("SELECT COALESCE(SUM(inv.quantity), 0) FROM inventory inv JOIN items i ON inv.item_id = i.item_id WHERE i.item_type = 'finished_good' AND inv.warehouse_id = :wid");
$stmtFGQty->execute([':wid' => $currentWarehouseId]);
$totalFGQty = (float)$stmtFGQty->fetchColumn();

$stmtLowFG = $pdo->prepare("SELECT COUNT(*) FROM inventory inv JOIN items i ON inv.item_id = i.item_id WHERE i.item_type = 'finished_good' AND inv.quantity <= i.default_reorder_level AND i.status = 'active' AND inv.warehouse_id = :wid");
$stmtLowFG->execute([':wid' => $currentWarehouseId]);
$lowStockFG = (int)$stmtLowFG->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Finished Goods Inventory</h1>
        <p class="page-subtitle">Monitor finished product stock levels, batch readiness, and distribution availability</p>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Finished Product</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--accent); background: var(--accent-light);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m7.5 4.27 9 5.15"/>
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalFGSKUs ?></div>
        <div class="stat-meta">Ready for commercial distribution</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Finished Units</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="20" x="4" y="2" rx="2" ry="2"/>
                    <path d="M9 22v-4h6v4"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= formatQty($totalFGQty) ?></div>
        <div class="stat-meta">In assigned warehouse storage</div>
    </div>

    <div class="stat-card <?= $lowStockFG > 0 ? 'stat-alert' : '' ?>">
        <div class="stat-header">
            <span class="stat-label">Replenish Work Order</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $lowStockFG ?></div>
        <div class="stat-meta"><?= $lowStockFG > 0 ? 'Request new batch run from Production' : 'All finished lines fully stocked' ?></div>
    </div>
</div>

<!-- Main Finished Goods Table Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Bottled Spirits &amp; Finished Inventory</h2>
            <p class="card-desc">Manufactured products delivered by the Producer team into Central Distribution</p>
        </div>
        <div class="filter-group">
            <!-- Search Filter -->
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="fgSearch" class="search-box" aria-label="Filter finished goods" placeholder="Filter product name or code..." oninput="filterFGTable()">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="finishedGoodsTable">
            <thead>
                <tr>
                    <th>Product Code</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Available Stock</th>
                    <th>Unit</th>
                    <th>Production Batch Ref</th>
                    <th>Production Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($finishedGoods)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No finished products found in database.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($finishedGoods as $item): ?>
                        <?php 
                            $isAvailable = (float)$item['current_stock'] > 0;
                            $isReorder   = (float)$item['current_stock'] <= (float)$item['default_reorder_level'];
                        ?>
                        <tr data-warehouse="<?= htmlspecialchars($item['warehouse_code'] ?? '') ?>">
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($item['item_code']) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                            </td>
                            <td>
                                <span style="font-size: 12px; color: var(--gray);">
                                    <?= htmlspecialchars($item['category_name']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 700; font-size: 14.5px; color: <?= $isReorder ? '#B91C1C' : '#14213D' ?>;">
                                    <?= formatQty($item['current_stock']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="color: var(--gray); font-weight: 500;"><?= htmlspecialchars($item['unit']) ?></span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?php if (!empty($item['batch_reference'])): ?>
                                    <span style="color: var(--panel-ink); font-weight: 600;"><?= htmlspecialchars($item['batch_reference']) ?></span>
                                    <?php if (!empty($item['last_produced_qty'])): ?>
                                        <small style="color: #15803D;">(+<?= formatQty($item['last_produced_qty']) ?>)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--gray);">Batch Distilled</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= !empty($item['production_date']) ? date('M d, Y', strtotime($item['production_date'])) : 'Distillery Base' ?>
                            </td>
                            <td>
                                <?php if (!$isAvailable): ?>
                                    <span class="badge status-alert">Out of Stock</span>
                                <?php elseif ($isReorder): ?>
                                    <span class="badge status-pending">Batch Needed</span>
                                <?php else: ?>
                                    <span class="badge status-optimal">Available</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterFGTable() {
    const term = document.getElementById('fgSearch').value.toLowerCase().trim();
    const table = document.getElementById('finishedGoodsTable');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const match = !term || text.includes(term);
        row.dataset.filteredOut = match ? 'false' : 'true';
    });
    if (table.paginationUpdate) table.paginationUpdate(true);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
