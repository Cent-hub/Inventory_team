<?php
/*
 * Shared inventory list template used by both the Raw Materials and Finished
 * Goods pages to display stock counts, low-stock alerts, and item tables.
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

$stmt = $pdo->prepare("
    SELECT 
        i.item_id,
        i.code AS item_code,
        i.name AS item_name,
        i.status AS item_status,
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
            DATE(sm.created_at) AS latest_date,
            COALESCE(NULLIF(sm.reference_no, ''), sm.remarks, CONCAT(:ref_prefix, sm.movement_id)) AS source_reference_no,
            sm.quantity AS latest_qty,
            sm.balance_after
        FROM stock_movements sm
        INNER JOIN (
            SELECT item_id, MAX(movement_id) AS max_m_id
            FROM stock_movements
            WHERE movement_type = 'STOCK_IN'
              AND (warehouse_id = :wid2 OR :wid3 = 1)
            GROUP BY item_id
        ) latest_m ON sm.movement_id = latest_m.max_m_id
    ) latest_mov ON latest_mov.item_id = i.item_id
    WHERE (i.type = :item_type OR i.type = :item_type_title) AND (LOWER(i.status) IN ('active', 'inactive'))
    ORDER BY i.name ASC, w.code ASC
");
$titleType = ($itemType === 'raw_material') ? 'Raw Material' : 'Finished Good';
$stmt->execute([
    ':cat_name'         => $categoryLabel,
    ':wid1'             => $currentWarehouseId,
    ':ref_prefix'       => $refPrefix,
    ':wid2'             => $currentWarehouseId,
    ':wid3'             => $currentWarehouseId,
    ':item_type'        => $itemType,
    ':item_type_title'  => $titleType
]);
$categoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalSKUs     = 0;
$inactiveCount = 0;
$totalQty      = 0.0;
$lowStockCount = 0;

foreach ($categoryItems as $row) {
    $isInactiveRow = (strtolower((string)($row['item_status'] ?? 'active')) === 'inactive');
    if ($isInactiveRow) {
        $inactiveCount++;
        continue;
    }
    $totalSKUs++;
    $qty = (float)($row['current_stock'] ?? 0);
    $reorder = (float)($row['default_reorder_level'] ?? 0);
    $totalQty += $qty;
    if ($reorder > 0 && $qty <= $reorder) {
        $lowStockCount++;
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($cfg['page_heading'] ?? '') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($cfg['page_subheading'] ?? '') ?></p>
    </div>
    <div class="header-actions">
        <?php require __DIR__ . '/../layouts/notification_bell.php'; ?>
    </div>
</div>

<div class="stats-grid stats-grid-4">
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
        <div class="stat-meta">Total active items</div>
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
        <div class="stat-meta">Total quantity on hand</div>
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

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Inactive Products</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #64748B; background: #F1F5F9;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $inactiveCount ?></div>
        <div class="stat-meta">Not used for 2+ months</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= htmlspecialchars($cfg['card_title'] ?? '') ?></h2>
            <p class="card-desc"><?= htmlspecialchars($cfg['card_desc'] ?? '') ?></p>
        </div>
        <div class="filter-group">
            <?php
                $searchElemId = htmlspecialchars($cfg['search_id'] ?? 'invSearch');
                $tableElemId  = htmlspecialchars($cfg['table_id'] ?? 'invTable');
                $statusElemId = $searchElemId . 'Status';
            ?>
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="<?= $searchElemId ?>" class="search-box" aria-label="Filter items" placeholder="<?= htmlspecialchars($cfg['search_placeholder'] ?? 'Filter items...') ?>" oninput="filterCategoryInventoryTable('<?= $searchElemId ?>', '<?= $statusElemId ?>', '<?= $tableElemId ?>')">
            </div>
            <select id="<?= $statusElemId ?>" class="select-filter" aria-label="Filter by product status" onchange="filterCategoryInventoryTable('<?= $searchElemId ?>', '<?= $statusElemId ?>', '<?= $tableElemId ?>')">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
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
                            $isInactive  = (strtolower((string)($item['item_status'] ?? 'active')) === 'inactive');
                            $isAvailable = (float)$item['current_stock'] > 0;
                            $isReorder   = (float)$item['default_reorder_level'] > 0 && (float)$item['current_stock'] <= (float)$item['default_reorder_level'];
                        ?>
                        <tr data-warehouse="<?= htmlspecialchars($item['warehouse_code'] ?? '') ?>" data-status="<?= $isInactive ? 'inactive' : 'active' ?>">
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
                                    <span style="color: var(--gray);">Starting stock</span>
                                <?php else: ?>
                                    <span style="color: var(--gray);">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= !empty($item['latest_date']) ? date('M d, Y', strtotime($item['latest_date'])) : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isInactive): ?>
                                    <span class="badge status-cancelled">Inactive</span>
                                <?php elseif (!$isAvailable): ?>
                                    <span class="badge status-alert">Out of Stock</span>
                                <?php elseif ($isReorder): ?>
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

<script>
function filterCategoryInventoryTable(searchId, statusId, tableId) {
    const query = (document.getElementById(searchId)?.value || '').toLowerCase().trim();
    const statusFilter = (document.getElementById(statusId)?.value || '').toLowerCase().trim();
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = (row.textContent || '').toLowerCase();
        const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
        const matchesQuery = !query || text.includes(query);
        const matchesStatus = !statusFilter || rowStatus === statusFilter;
        row.style.display = (matchesQuery && matchesStatus) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
