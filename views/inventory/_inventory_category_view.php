<?php
/**
 * Shared Inventory Category View Template
 * Used by raw_materials.php and finished_goods.php to eliminate view duplication.
 * InventoryTeam — Liquor Business Inventory Management System
 */

$cfg = $categoryConfig ?? [];

$pageTitle   = $cfg['page_title'] ?? 'Inventory — InventoryTeam';
$activePage  = $cfg['active_page'] ?? 'raw_materials';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$itemType = $cfg['item_type'] ?? 'raw_material';
$categoryLabel = $cfg['category_name'] ?? 'Materials';
$refPrefix = $cfg['ref_prefix'] ?? 'IN #';

// Query items for assigned warehouse with latest receipt/production info
$stmt = $pdo->prepare("
    SELECT 
        i.item_id,
        i.code AS item_code,
        i.name AS item_name,
        :cat_name AS category_name,
        i.unit,
        COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level) AS default_reorder_level,
        w.warehouse_id,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(s.qty_on_hand, 0.000) AS current_stock,
        latest_mov.latest_date,
        latest_mov.source_reference_no AS latest_reference,
        latest_mov.latest_qty
    FROM items i
    JOIN warehouses w ON w.warehouse_id = :wid1
    LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = w.warehouse_id
    LEFT JOIN (
        SELECT 
            sm.item_id,
            sm.warehouse_id,
            DATE(sm.created_at) AS latest_date,
            COALESCE(sm.remarks, CONCAT(:ref_prefix, sm.movement_id)) AS source_reference_no,
            sm.quantity AS latest_qty
        FROM stock_movements sm
        INNER JOIN (
            SELECT item_id, warehouse_id, MAX(movement_id) AS max_m_id
            FROM stock_movements
            WHERE movement_type IN ('STOCK_IN', 'IN') AND warehouse_id = :wid2
            GROUP BY item_id, warehouse_id
        ) latest_m ON sm.movement_id = latest_m.max_m_id
    ) latest_mov ON latest_mov.item_id = i.item_id AND latest_mov.warehouse_id = w.warehouse_id
    WHERE (i.type = :item_type OR i.type = :item_type_title) AND (LOWER(i.status) = 'active')
    ORDER BY i.name ASC, w.code ASC
");
$titleType = ($itemType === 'raw_material') ? 'Raw Material' : 'Finished Good';
$stmt->execute([
    ':cat_name'         => $categoryLabel,
    ':wid1'             => $currentWarehouseId,
    ':ref_prefix'       => $refPrefix,
    ':wid2'             => $currentWarehouseId,
    ':item_type'        => $itemType,
    ':item_type_title'  => $titleType
]);
$categoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics scoped strictly to assigned warehouse
$totalSKUs = count($categoryItems);
$totalQty  = 0.0;
$lowStockCount = 0;

foreach ($categoryItems as $row) {
    $qty = (float)($row['current_stock'] ?? 0);
    $reorder = (float)($row['default_reorder_level'] ?? 0);
    $totalQty += $qty;
    if ($reorder > 0 && $qty <= $reorder) {
        $lowStockCount++;
    }
}
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($cfg['page_heading'] ?? '') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($cfg['page_subheading'] ?? '') ?></p>
    </div>
    <div class="header-actions">
        <?php require __DIR__ . '/../layouts/notification_bell.php'; ?>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="<?= htmlspecialchars($cfg['stat_card_class'] ?? 'stat-card') ?>">
        <div class="stat-header">
            <span class="stat-label"><?= htmlspecialchars($categoryLabel) ?></span>
            <div class="stat-icon-wrap" aria-hidden="true" <?= ($cfg['stat_icon'] ?? '') === 'package' ? 'style="color: var(--accent); background: var(--accent-light);"' : '' ?>>
                <?php if (($cfg['stat_icon'] ?? '') === 'package'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m7.5 4.27 9 5.15"/>
                        <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                        <path d="m3.3 7 8.7 5 8.7-5"/>
                        <path d="M12 22V12"/>
                    </svg>
                <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                        <polyline points="2 17 12 22 22 17"/>
                        <polyline points="2 12 12 17 22 12"/>
                    </svg>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-value"><?= $totalSKUs ?></div>
        <div class="stat-meta">Active catalog items</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label"><?= htmlspecialchars($cfg['volume_label'] ?? 'Total Volume') ?></span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="20" x="4" y="2" rx="2" ry="2"/>
                    <path d="M9 22v-4h6v4"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= formatQty($totalQty) ?></div>
        <div class="stat-meta">Stock currently in warehouses</div>
    </div>

    <div class="stat-card <?= $lowStockCount > 0 ? 'stat-alert' : '' ?>">
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
        <div class="stat-value"><?= $lowStockCount ?></div>
        <div class="stat-meta"><?= $lowStockCount > 0 ? htmlspecialchars($cfg['alert_meta_active'] ?? '') : htmlspecialchars($cfg['alert_meta_idle'] ?? '') ?></div>
    </div>
</div>

<!-- Main Inventory Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= htmlspecialchars($cfg['card_title'] ?? '') ?></h2>
            <p class="card-desc"><?= htmlspecialchars($cfg['card_desc'] ?? '') ?></p>
        </div>
        <div class="filter-group">
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="<?= htmlspecialchars($cfg['search_id'] ?? 'invSearch') ?>" class="search-box" aria-label="Filter items" placeholder="<?= htmlspecialchars($cfg['search_placeholder'] ?? 'Filter items...') ?>" oninput="filterTable('<?= htmlspecialchars($cfg['search_id'] ?? 'invSearch') ?>', '<?= htmlspecialchars($cfg['table_id'] ?? 'invTable') ?>')">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="<?= htmlspecialchars($cfg['table_id'] ?? 'invTable') ?>">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($cfg['code_col_header'] ?? 'Item Code') ?></th>
                    <th><?= htmlspecialchars($cfg['name_col_header'] ?? 'Item Name') ?></th>
                    <th>Category</th>
                    <th class="text-center">Current Stock</th>
                    <th class="text-center">Unit</th>
                    <th><?= htmlspecialchars($cfg['source_col_header'] ?? 'Source Ref') ?></th>
                    <th>Date</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categoryItems)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;"><?= htmlspecialchars($cfg['empty_message'] ?? 'No records found.') ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categoryItems as $item): ?>
                        <?php 
                            $isAvailable = (float)$item['current_stock'] > 0;
                            $isReorder   = (float)$item['default_reorder_level'] > 0 && (float)$item['current_stock'] <= (float)$item['default_reorder_level'];
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
                            <td class="text-center">
                                <span style="font-weight: 700; font-size: 14.5px; color: <?= $isReorder ? '#B91C1C' : '#14213D' ?>;">
                                    <?= formatQty($item['current_stock']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span style="color: var(--gray); font-weight: 500;"><?= htmlspecialchars($item['unit']) ?></span>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;">
                                <?php if (!empty($item['latest_reference'])): ?>
                                    <span style="color: var(--panel-ink); font-weight: 600;"><?= htmlspecialchars($item['latest_reference']) ?></span>
                                    <?php if (!empty($item['latest_qty'])): ?>
                                        <small style="color: #15803D;">(+<?= formatQty($item['latest_qty']) ?>)</small>
                                    <?php endif; ?>
                                <?php elseif ($isAvailable): ?>
                                    <span style="color: var(--gray);">Initial Stock</span>
                                <?php else: ?>
                                    <span style="color: var(--gray);">No Receipts Yet</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= !empty($item['latest_date']) ? date('M d, Y', strtotime($item['latest_date'])) : ($isAvailable ? 'System Setup' : '—') ?>
                            </td>
                            <td class="text-center">
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
