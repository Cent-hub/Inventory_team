<?php
/**
 * View: Raw Materials (Procurement Inbound Monitoring)
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Raw Materials — StockPilot';
$activePage  = 'raw_materials';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Query Raw Materials for assigned warehouse with latest Procurement receipt info
$stmt = $pdo->prepare("
    SELECT 
        i.item_id,
        i.item_code,
        i.item_name,
        COALESCE(c.category_name, 'General Raw Materials') AS category_name,
        i.unit,
        i.default_reorder_level,
        w.warehouse_id,
        w.warehouse_code,
        w.warehouse_name,
        COALESCE(inv.quantity, 0.000) AS current_stock,
        latest_in.last_received_date,
        latest_in.source_reference_no AS procurement_reference,
        latest_in.last_received_qty
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.category_id
    JOIN inventory inv ON inv.item_id = i.item_id
    JOIN warehouses w ON inv.warehouse_id = w.warehouse_id
    LEFT JOIN (
        SELECT 
            sii.item_id,
            si.warehouse_id,
            si.transaction_date AS last_received_date,
            si.source_reference_no,
            sii.quantity AS last_received_qty
        FROM stock_in_items sii
        JOIN stock_ins si ON sii.stock_in_id = si.stock_in_id
        WHERE si.status = 'completed' AND si.warehouse_id = :wid1
        ORDER BY si.transaction_date DESC, si.stock_in_id DESC
    ) latest_in ON latest_in.item_id = i.item_id AND latest_in.warehouse_id = w.warehouse_id
    WHERE i.item_type = 'raw_material' AND i.status = 'active' AND inv.warehouse_id = :wid2
    GROUP BY i.item_id, w.warehouse_id
    ORDER BY i.item_name ASC, w.warehouse_code ASC
");
$stmt->execute([':wid1' => $currentWarehouseId, ':wid2' => $currentWarehouseId]);
$rawMaterials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics scoped strictly to assigned warehouse
$stmtSKUs = $pdo->prepare("SELECT COUNT(DISTINCT inv.item_id) FROM inventory inv JOIN items i ON inv.item_id = i.item_id WHERE i.item_type = 'raw_material' AND i.status = 'active' AND inv.warehouse_id = :wid");
$stmtSKUs->execute([':wid' => $currentWarehouseId]);
$totalRawSKUs = (int)$stmtSKUs->fetchColumn();

$stmtQty = $pdo->prepare("SELECT COALESCE(SUM(inv.quantity), 0) FROM inventory inv JOIN items i ON inv.item_id = i.item_id WHERE i.item_type = 'raw_material' AND inv.warehouse_id = :wid");
$stmtQty->execute([':wid' => $currentWarehouseId]);
$totalRawQty = (float)$stmtQty->fetchColumn();

$stmtLow = $pdo->prepare("SELECT COUNT(*) FROM inventory inv JOIN items i ON inv.item_id = i.item_id WHERE i.item_type = 'raw_material' AND inv.quantity <= i.default_reorder_level AND i.status = 'active' AND inv.warehouse_id = :wid");
$stmtLow->execute([':wid' => $currentWarehouseId]);
$lowStockRaw = (int)$stmtLow->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Raw Materials Inventory</h1>
        <p class="page-subtitle">Raw materials inventory for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
    </div>
    <div class="header-actions">
        <a href="<?= BASE_URL ?>views/stock_in/index.php" class="btn btn-primary">
            <!-- Lucide PlusCircle Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="16"/>
                <line x1="8" y1="12" x2="16" y2="12"/>
            </svg>
            <span>View Inbound Stock Receipts</span>
        </a>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Raw Material SKUs</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalRawSKUs ?></div>
        <div class="stat-meta">Active catalog items</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Raw Volume</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="20" x="4" y="2" rx="2" ry="2"/>
                    <path d="M9 22v-4h6v4"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalRawQty, 1) ?></div>
        <div class="stat-meta">Stock currently in warehouses</div>
    </div>

    <div class="stat-card <?= $lowStockRaw > 0 ? 'stat-alert' : '' ?>">
        <div class="stat-header">
            <span class="stat-label">Reorder Required</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $lowStockRaw ?></div>
        <div class="stat-meta"><?= $lowStockRaw > 0 ? 'Send PO requisition to Procurement' : 'Sufficient raw stock on hand' ?></div>
    </div>
</div>

<!-- Main Raw Materials Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Procurement Raw Materials Ledger</h2>
            <p class="card-desc">Materials received and ready to be issued to the Production distillation / packaging line</p>
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
                <input type="text" id="rawSearch" class="search-box" placeholder="Filter material name or code..." onkeyup="filterRawTable()">
            </div>

            <!-- Assigned Warehouse Branch Badge -->
            <div class="wh-badge" style="margin: 0; background: var(--gray-light); border: 1px solid var(--border); color: var(--panel-ink); font-weight: 600; padding: 6px 12px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                    <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                    <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                </svg>
                <span><?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></span>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="rawMaterialsTable">
            <thead>
                <tr>
                    <th>Material Code</th>
                    <th>Material Name</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Unit</th>
                    <th>Warehouse Branch</th>
                    <th>Latest PO / Source Ref</th>
                    <th>Date Received</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rawMaterials)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No raw material records found in database.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rawMaterials as $item): ?>
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
                                    <?= number_format($item['current_stock'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <span style="color: var(--gray); font-weight: 500;"><?= htmlspecialchars($item['unit']) ?></span>
                            </td>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($item['warehouse_code'] ?? '') ?>">
                                    <?= htmlspecialchars($item['warehouse_code'] ?? 'WH-MAIN') ?>
                                </span>
                                <span style="font-size: 12px; color: var(--gray); margin-left: 4px;">
                                    <?= htmlspecialchars($item['warehouse_name'] ?? 'Warehouse') ?>
                                </span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?php if (!empty($item['procurement_reference'])): ?>
                                    <span style="color: var(--panel-ink); font-weight: 600;"><?= htmlspecialchars($item['procurement_reference']) ?></span>
                                    <?php if (!empty($item['last_received_qty'])): ?>
                                        <small style="color: #15803D;">(+<?= number_format($item['last_received_qty'], 1) ?>)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--gray);">Initial Stock</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= !empty($item['last_received_date']) ? date('M d, Y', strtotime($item['last_received_date'])) : 'System Setup' ?>
                            </td>
                            <td>
                                <?php if (!$isAvailable): ?>
                                    <span class="badge status-alert">Out of Stock</span>
                                <?php elseif ($isReorder): ?>
                                    <span class="badge status-pending">Reorder Needed</span>
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
function filterRawTable() {
    const term = document.getElementById('rawSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#rawMaterialsTable tbody tr');

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        row.style.display = (!term || text.includes(term)) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
