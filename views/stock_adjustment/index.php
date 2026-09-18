<?php
/**
 * View: Stock Adjustment & Discrepancy Corrections
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Adjustment — StockPilot';
$activePage  = 'stock_adjustment';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Fetch stock adjustments strictly for assigned warehouse
$stmt = $pdo->prepare("
    SELECT 
        sa.stock_adjustment_id,
        sa.transaction_number,
        sa.adjustment_date,
        sa.reason,
        sa.status,
        sa.created_at,
        w.warehouse_code,
        w.warehouse_name,
        u.name AS logged_by,
        ua.name AS approved_by_name,
        sai.previous_quantity,
        sai.adjusted_quantity,
        sai.difference,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit
    FROM stock_adjustments sa
    JOIN warehouses w ON sa.warehouse_id = w.warehouse_id
    JOIN users u ON sa.created_by = u.user_id
    LEFT JOIN users ua ON sa.approved_by = ua.user_id
    LEFT JOIN stock_adjustment_items sai ON sa.stock_adjustment_id = sai.stock_adjustment_id
    LEFT JOIN items i ON sai.item_id = i.item_id
    WHERE sa.warehouse_id = :wid
    ORDER BY sa.stock_adjustment_id DESC
");
$stmt->execute([':wid' => $currentWarehouseId]);
$adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch bad product defect records strictly for assigned warehouse
$stmtBad = $pdo->prepare("
    SELECT 
        bp.bad_product_id,
        bp.bad_product_number,
        bp.condition_type,
        bp.quantity,
        bp.reason,
        bp.status,
        bp.created_at,
        w.warehouse_code,
        w.warehouse_name,
        i.item_code,
        i.item_name,
        i.unit,
        u.name AS reported_by_name
    FROM bad_products bp
    JOIN warehouses w ON bp.warehouse_id = w.warehouse_id
    JOIN items i ON bp.item_id = i.item_id
    JOIN users u ON bp.reported_by = u.user_id
    WHERE bp.warehouse_id = :wid
    ORDER BY bp.bad_product_id DESC
");
$stmtBad->execute([':wid' => $currentWarehouseId]);
$badProducts = $stmtBad->fetchAll(PDO::FETCH_ASSOC);

// KPI Metrics scoped strictly to assigned warehouse
$totalAdjustments = count($adjustments);
$totalBadProducts = count($badProducts);
$netVariance = 0.0;
foreach ($adjustments as $a) {
    $netVariance += (float)($a['difference'] ?? 0);
}
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Stock Adjustments &amp; Corrections</h1>
        <p class="page-subtitle">Audited inventory corrections for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Adjustments</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B45309; background: #FEF3C7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalAdjustments ?></div>
        <div class="stat-meta">Variance adjustments logged</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Damaged / Defect Records</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalBadProducts ?></div>
        <div class="stat-meta">Damaged / spoiled / broken units</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Net Adjustment Variance</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: <?= $netVariance >= 0 ? '#15803D' : '#B91C1C' ?>; background: <?= $netVariance >= 0 ? '#DCFCE7' : '#FEE2E2' ?>;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: <?= $netVariance >= 0 ? '#15803D' : '#B91C1C' ?>;">
            <?= ($netVariance >= 0 ? '+' : '') . number_format($netVariance, 2) ?>
        </div>
        <div class="stat-meta">Cumulative count adjustment diff</div>
    </div>
</div>

<!-- Main Table Card: Stock Adjustments -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Stock Adjustment Reconciliation Records</h2>
            <p class="card-desc">Every adjustment records previous vs. physical count and generates an immutable ledger entry</p>
        </div>
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" id="adjSearch" class="search-box" placeholder="Filter adjustment ref, item..." onkeyup="filterTable('adjSearch', 'adjustmentsTable')">
        </div>
    </div>

    <div class="table-responsive">
        <table id="adjustmentsTable">
            <thead>
                <tr>
                    <th>Adjustment Ref</th>
                    <th>Facility</th>
                    <th>Item</th>
                    <th>Previous System Qty</th>
                    <th>Adjusted Physical Qty</th>
                    <th>Difference</th>
                    <th>Stated Reason</th>
                    <th>Date</th>
                    <th>Logged By</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($adjustments)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">No stock adjustments recorded.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($adjustments as $row): ?>
                        <?php $diff = (float)($row['difference'] ?? 0); ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['transaction_number']) ?>
                            </td>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>">
                                    <?= htmlspecialchars($row['warehouse_code']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($row['item_name'])): ?>
                                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                                    <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($row['item_code']) ?></div>
                                <?php else: ?>
                                    <span style="color: var(--gray);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= number_format($row['previous_quantity'] ?? 0, 2) ?> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit'] ?? '') ?></small>
                            </td>
                            <td>
                                <strong style="color: var(--panel-ink);"><?= number_format($row['adjusted_quantity'] ?? 0, 2) ?></strong> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit'] ?? '') ?></small>
                            </td>
                            <td style="font-weight: 700; color: <?= $diff >= 0 ? '#15803D' : '#B91C1C' ?>;">
                                <?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?>
                            </td>
                            <td style="max-width: 220px; font-size: 12px; color: var(--gray);" title="<?= htmlspecialchars($row['reason']) ?>">
                                <?= htmlspecialchars($row['reason']) ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['adjustment_date'])) ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?= htmlspecialchars($row['logged_by']) ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'approved' || $row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Approved</span>
                                <?php elseif ($row['status'] === 'pending'): ?>
                                    <span class="badge status-pending">Pending</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Secondary Table: Damaged / Spoiled Liquor Units -->
<?php if (!empty($badProducts)): ?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Damaged &amp; Defective Liquor Goods</h2>
            <p class="card-desc">Losses from bottle breakage, cork defects, barrel leakage, or expired batches</p>
        </div>
    </div>
    <div class="table-responsive">
        <table id="badProductsTable">
            <thead>
                <tr>
                    <th>Report Ref #</th>
                    <th>Facility</th>
                    <th>Item</th>
                    <th>Condition / Defect</th>
                    <th>Quantity Written Off</th>
                    <th>Reason / Details</th>
                    <th>Reported By</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($badProducts as $bp): ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 700;">
                            <?= htmlspecialchars($bp['bad_product_number']) ?>
                        </td>
                        <td>
                            <span class="badge-wh"><?= htmlspecialchars($bp['warehouse_code']) ?></span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($bp['item_name']) ?></strong>
                            <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($bp['item_code']) ?></div>
                        </td>
                        <td>
                            <span class="badge status-alert" style="text-transform: capitalize;">
                                <?= htmlspecialchars($bp['condition_type']) ?>
                            </span>
                        </td>
                        <td style="font-weight: 700; color: #B91C1C;">
                            -<?= number_format($bp['quantity'], 2) ?> <small style="color: var(--gray);"><?= htmlspecialchars($bp['unit']) ?></small>
                        </td>
                        <td style="font-size: 12px; color: var(--gray);">
                            <?= htmlspecialchars($bp['reason']) ?>
                        </td>
                        <td style="font-size: 12px;">
                            <?= htmlspecialchars($bp['reported_by_name']) ?>
                        </td>
                        <td style="font-size: 12px; color: var(--gray);">
                            <?= date('M d, Y', strtotime($bp['created_at'])) ?>
                        </td>
                        <td>
                            <span class="badge status-completed"><?= ucfirst($bp['status']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
