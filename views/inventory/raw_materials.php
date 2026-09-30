<?php
/**
 * View: Raw Materials (Procurement Inbound Monitoring)
 * InventoryTeam — Liquor Business Inventory Management System
 */

$pageTitle   = 'Raw Materials — InventoryTeam';
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
        COALESCE(NULLIF(inv.reorder_level, 0), i.default_reorder_level) AS default_reorder_level,
        w.warehouse_id,
        w.warehouse_code,
        w.warehouse_name,
        COALESCE(inv.quantity, 0.000) AS current_stock,
        latest_in.last_received_date,
        latest_in.source_reference_no AS procurement_reference,
        latest_in.last_received_qty
    FROM items i
    JOIN warehouses w ON w.warehouse_id = :wid1
    LEFT JOIN categories c ON i.category_id = c.category_id
    LEFT JOIN inventory inv ON inv.item_id = i.item_id AND inv.warehouse_id = w.warehouse_id
    LEFT JOIN (
        SELECT 
            sii.item_id,
            si.warehouse_id,
            si.transaction_date AS last_received_date,
            si.source_reference_no,
            sii.quantity AS last_received_qty
        FROM stock_in_items sii
        JOIN stock_ins si ON sii.stock_in_id = si.stock_in_id
        JOIN (
            SELECT sii2.item_id, si2.warehouse_id, MAX(sii2.stock_in_item_id) AS max_sii_id
            FROM stock_in_items sii2
            JOIN stock_ins si2 ON sii2.stock_in_id = si2.stock_in_id
            WHERE si2.status = 'completed' AND si2.warehouse_id = :wid2
            GROUP BY sii2.item_id, si2.warehouse_id
        ) latest_id ON sii.stock_in_item_id = latest_id.max_sii_id
    ) latest_in ON latest_in.item_id = i.item_id AND latest_in.warehouse_id = w.warehouse_id
    WHERE i.item_type = 'raw_material' AND i.status = 'active'
    ORDER BY i.item_name ASC, w.warehouse_code ASC
");
$stmt->execute([':wid1' => $currentWarehouseId, ':wid2' => $currentWarehouseId]);
$rawMaterials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics scoped strictly to assigned warehouse (computed from $rawMaterials without extra queries)
$totalRawSKUs = count($rawMaterials);
$totalRawQty  = 0.0;
$lowStockRaw  = 0;

foreach ($rawMaterials as $rm) {
    $qty = (float)($rm['current_stock'] ?? 0);
    $reorder = (float)($rm['default_reorder_level'] ?? 0);
    $totalRawQty += $qty;
    if ($qty <= $reorder) {
        $lowStockRaw++;
    }
}
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Raw Materials Inventory</h1>
        <p class="page-subtitle">Track raw material stock balances, supplier inputs, and reorder thresholds</p>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Raw Materials</span>
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
        <div class="stat-value"><?= formatQty($totalRawQty) ?></div>
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
                <input type="text" id="rawSearch" class="search-box" aria-label="Filter raw materials" placeholder="Filter material name or code..." oninput="filterTable('rawSearch', 'rawMaterialsTable')">
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
                    <th>Latest PO / Source Ref</th>
                    <th>Date Received</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rawMaterials)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No raw material records found in database.</td>
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
                                    <?= formatQty($item['current_stock']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="color: var(--gray); font-weight: 500;"><?= htmlspecialchars($item['unit']) ?></span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?php if (!empty($item['procurement_reference'])): ?>
                                    <span style="color: var(--panel-ink); font-weight: 600;"><?= htmlspecialchars($item['procurement_reference']) ?></span>
                                    <?php if (!empty($item['last_received_qty'])): ?>
                                        <small style="color: #15803D;">(+<?= formatQty($item['last_received_qty']) ?>)</small>
                                    <?php endif; ?>
                                <?php elseif ($isAvailable): ?>
                                    <span style="color: var(--gray);">Initial Stock</span>
                                <?php else: ?>
                                    <span style="color: var(--gray);">No Receipts Yet</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= !empty($item['last_received_date']) ? date('M d, Y', strtotime($item['last_received_date'])) : ($isAvailable ? 'System Setup' : '—') ?>
                            </td>
                            <td>
                                <?php if (!$isAvailable): ?>
                                    <span class="badge status-alert">Out of Stock</span>
                                <?php elseif ($isReorder): ?>
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

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
