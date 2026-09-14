<?php
/**
 * View: Item Stock Card Ledger
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Card — StockPilot';
$activePage  = 'stock_card';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Fetch active items in assigned warehouse inventory for selector dropdown
$stmtItems = $pdo->prepare("
    SELECT DISTINCT i.item_id, i.item_code, i.item_name, i.item_type, i.unit, i.default_reorder_level 
    FROM items i 
    JOIN inventory inv ON i.item_id = inv.item_id
    WHERE i.status = 'active' AND inv.warehouse_id = :wid
    ORDER BY i.item_type ASC, i.item_name ASC
");
$stmtItems->execute([':wid' => $currentWarehouseId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    $items = $pdo->query("
        SELECT item_id, item_code, item_name, item_type, unit, default_reorder_level 
        FROM items 
        WHERE status = 'active' 
        ORDER BY item_type ASC, item_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Selected filters - lock warehouse strictly to assigned warehouse
$selectedItemId = isset($_GET['item_id']) && is_numeric($_GET['item_id']) ? (int)$_GET['item_id'] : ($items[0]['item_id'] ?? 0);
$selectedWhId   = $currentWarehouseId;
$movementType   = isset($_GET['movement_type']) ? trim($_GET['movement_type']) : '';
$startDate      = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$endDate        = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

// Retrieve selected item profile
$selectedItem = null;
foreach ($items as $it) {
    if ((int)$it['item_id'] === $selectedItemId) {
        $selectedItem = $it;
        break;
    }
}

// Fetch current stock snapshot for selected item in assigned warehouse
$currentStock = 0.0;
if ($selectedItem) {
    $stockStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE item_id = ? AND warehouse_id = ?");
    $stockStmt->execute([$selectedItemId, $currentWarehouseId]);
    $currentStock = (float)$stockStmt->fetchColumn();
}

// Build Stock Card query from stock_movements strictly scoped to assigned warehouse
$movements = [];
if ($selectedItemId > 0) {
    $sql = "
        SELECT 
            sm.movement_id,
            sm.movement_type,
            sm.reference_number,
            sm.quantity_in,
            sm.quantity_out,
            sm.balance_after,
            sm.created_at,
            w.warehouse_code,
            w.warehouse_name,
            COALESCE(si.remarks, so.remarks, st.remarks, sa.reason, bp.reason, '') AS notes
        FROM stock_movements sm
        JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        LEFT JOIN stock_ins si ON sm.stock_in_id = si.stock_in_id
        LEFT JOIN stock_outs so ON sm.stock_out_id = so.stock_out_id
        LEFT JOIN stock_transfers st ON sm.stock_transfer_id = st.stock_transfer_id
        LEFT JOIN stock_adjustments sa ON sm.stock_adjustment_id = sa.stock_adjustment_id
        LEFT JOIN bad_products bp ON sm.bad_product_id = bp.bad_product_id
        WHERE sm.item_id = ? AND sm.warehouse_id = ?
    ";
    $params = [$selectedItemId, $currentWarehouseId];

    if (!empty($movementType)) {
        $sql .= " AND sm.movement_type = ?";
        $params[] = $movementType;
    }

    if (!empty($startDate)) {
        $sql .= " AND DATE(sm.created_at) >= ?";
        $params[] = $startDate;
    }

    if (!empty($endDate)) {
        $sql .= " AND DATE(sm.created_at) <= ?";
        $params[] = $endDate;
    }

    $sql .= " ORDER BY sm.created_at ASC, sm.movement_id ASC";

    $stmtMovements = $pdo->prepare($sql);
    $stmtMovements->execute($params);
    $movements = $stmtMovements->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Item Stock Card Ledger</h1>
        <p class="page-subtitle">Inspect the complete audit trail for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect width="12" height="8" x="6" y="14"/>
            </svg>
            <span>Print Stock Card</span>
        </button>
    </div>
</div>

<!-- Interactive Item & Filter Toolbar -->
<div class="card" style="padding: 18px 22px;">
    <form method="GET" action="stock_card.php" style="display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;">
        <!-- Item Selector -->
        <div style="display: flex; flex-direction: column; gap: 4px; min-width: 260px; flex: 1;">
            <label for="item_id" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">Select Inventory Item:</label>
            <select name="item_id" id="item_id" class="select-filter" style="width: 100%; font-weight: 600;" onchange="this.form.submit()">
                <?php foreach ($items as $it): ?>
                    <option value="<?= (int)$it['item_id'] ?>" <?= (int)$it['item_id'] === $selectedItemId ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($it['item_code']) ?>] <?= htmlspecialchars($it['item_name']) ?> (<?= $it['item_type'] === 'raw_material' ? 'Raw' : 'Finished' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Assigned Warehouse Branch Badge -->
        <div style="display: flex; flex-direction: column; gap: 4px; min-width: 180px;">
            <label style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">Warehouse Branch:</label>
            <div class="wh-badge" style="margin: 0; background: var(--gray-light); border: 1px solid var(--border); color: var(--panel-ink); font-weight: 600; padding: 7px 12px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; height: 38px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                    <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                    <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                </svg>
                <span><?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></span>
            </div>
        </div>

        <!-- Movement Type Filter -->
        <div style="display: flex; flex-direction: column; gap: 4px; min-width: 160px;">
            <label for="movement_type" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">Movement Type:</label>
            <select name="movement_type" id="movement_type" class="select-filter">
                <option value="">All Types</option>
                <option value="STOCK_IN" <?= $movementType === 'STOCK_IN' ? 'selected' : '' ?>>Stock In</option>
                <option value="STOCK_OUT" <?= $movementType === 'STOCK_OUT' ? 'selected' : '' ?>>Stock Out</option>
                <option value="STOCK_TRANSFER_IN" <?= $movementType === 'STOCK_TRANSFER_IN' ? 'selected' : '' ?>>Transfer In</option>
                <option value="STOCK_TRANSFER_OUT" <?= $movementType === 'STOCK_TRANSFER_OUT' ? 'selected' : '' ?>>Transfer Out</option>
                <option value="STOCK_ADJUSTMENT" <?= $movementType === 'STOCK_ADJUSTMENT' ? 'selected' : '' ?>>Stock Adjustment</option>
                <option value="BAD_PRODUCT" <?= $movementType === 'BAD_PRODUCT' ? 'selected' : '' ?>>Damaged / Defective</option>
            </select>
        </div>

        <!-- Start Date -->
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label for="start_date" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">From:</label>
            <input type="date" id="start_date" name="start_date" class="select-filter" value="<?= htmlspecialchars($startDate) ?>">
        </div>

        <!-- End Date -->
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label for="end_date" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">To:</label>
            <input type="date" id="end_date" name="end_date" class="select-filter" value="<?= htmlspecialchars($endDate) ?>">
        </div>

        <!-- Filter Submit Button -->
        <button type="submit" class="btn btn-primary" style="height: 38px;">Filter</button>
        <a href="stock_card.php?item_id=<?= $selectedItemId ?>" class="btn btn-secondary" style="height: 38px;">Reset</a>
    </form>
</div>

<!-- Item Profile Summary Banner -->
<?php if ($selectedItem): ?>
<div class="card" style="background: linear-gradient(135deg, #14213D 0%, #1c2e54 100%); color: #ffffff; border: none;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                <span class="badge-type <?= $selectedItem['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                    <?= $selectedItem['item_type'] === 'finished_good' ? 'Finished Product' : 'Raw Material' ?>
                </span>
                <span style="font-family: monospace; font-size: 13px; color: var(--gold); font-weight: 700;">
                    <?= htmlspecialchars($selectedItem['item_code']) ?>
                </span>
            </div>
            <h2 style="font-family: var(--font-display); font-size: 22px; font-weight: 800; color: #ffffff; margin-bottom: 4px;">
                <?= htmlspecialchars($selectedItem['item_name']) ?>
            </h2>
            <p style="font-size: 13px; color: #94A3B8;">
                Standard Inventory Unit: <strong><?= htmlspecialchars($selectedItem['unit']) ?></strong> &middot; Reorder Threshold: <?= number_format($selectedItem['default_reorder_level'], 2) ?> <?= htmlspecialchars($selectedItem['unit']) ?>
            </p>
        </div>

        <!-- Current Stock Big Badge -->
        <div style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: var(--radius-lg); padding: 14px 22px; text-align: right;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #5EEAD4;">
                Current Balance on Hand
            </div>
            <div style="font-family: var(--font-display); font-size: 28px; font-weight: 800; color: #ffffff;">
                <?= number_format($currentStock, 2) ?> <small style="font-size: 14px; font-weight: 500; color: #E2E8F0;"><?= htmlspecialchars($selectedItem['unit']) ?></small>
            </div>
            <div style="font-size: 11.5px; color: #CBD5E1; margin-top: 2px;">
                <?= count($movements) ?> ledger transactions recorded
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Stock Card Ledger Table -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Chronological Stock Card Entries</h2>
            <p class="card-desc">Every transaction debit, credit, and resulting running stock balance</p>
        </div>
    </div>
    <div class="table-responsive">
        <table id="stockCardTable">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Reference / Document #</th>
                    <th>Movement Type</th>
                    <th style="text-align: right;">Stock In (+)</th>
                    <th style="text-align: right;">Stock Out (-)</th>
                    <th style="text-align: right;">Balance After</th>
                    <th>Facility</th>
                    <th>Notes / Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;"><?= empty($items) ? 'No inventory items registered in system. Add items to view stock card ledger.' : 'No transactions recorded for this item under the selected filter criteria.' ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                        <?php 
                            $qtyIn  = (float)$m['quantity_in'];
                            $qtyOut = (float)$m['quantity_out'];
                            $isTransfer = strpos($m['movement_type'], 'TRANSFER') !== false;
                            $pillClass = $isTransfer ? 'mov-transfer' : ($qtyIn > 0 ? 'mov-in' : 'mov-out');
                        ?>
                        <tr>
                            <td style="font-size: 12.5px; white-space: nowrap; color: var(--gray);">
                                <?= date('M d, Y H:i', strtotime($m['created_at'])) ?>
                            </td>
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($m['reference_number']) ?>
                            </td>
                            <td>
                                <span class="badge <?= $pillClass ?>">
                                    <?= htmlspecialchars($m['movement_type']) ?>
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #15803D;">
                                <?= $qtyIn > 0 ? ('+' . number_format($qtyIn, 2)) : '—' ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #B91C1C;">
                                <?= $qtyOut > 0 ? ('-' . number_format($qtyOut, 2)) : '—' ?>
                            </td>
                            <td style="text-align: right; font-weight: 800; font-size: 14px; color: var(--panel-ink); background: #F8FAFC;">
                                <?= number_format($m['balance_after'] ?? 0, 2) ?>
                            </td>
                            <td>
                                <span class="badge-wh"><?= htmlspecialchars($m['warehouse_code']) ?></span>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); max-width: 220px;" title="<?= htmlspecialchars($m['notes']) ?>">
                                <?= htmlspecialchars($m['notes'] ?: 'Standard movement') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
