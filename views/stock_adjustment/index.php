<?php
/**
 * View: Stock Adjustment & Discrepancy Corrections
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Adjustment — StockPilot';
$activePage  = 'stock_adjustment';
$activeGroup = 'inventory';

// Forward browser navigation to the unified Stock Operations hub
if (php_sapi_name() !== 'cli' && (!defined('IN_UNIT_TEST') || !IN_UNIT_TEST)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['stay_on_legacy'])) {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
        $projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
        header("Location: {$projectRoot}/views/stock_operations/index.php?tab=adjustment");
        exit;
    }
}

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

$successMessage = null;
$errorMessage   = null;

// =============================================================================
// POST ACTION HANDLERS
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh and try again.";
    } else {
        $stockService = new StockService();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            switch ($action) {
                case 'create_adjustment':
                    $itemId       = (int)($_POST['item_id'] ?? 0);
                    $adjustedQty  = (float)($_POST['adjusted_quantity'] ?? 0);
                    $reason       = trim($_POST['reason'] ?? '');
                    $adjDate      = !empty($_POST['adjustment_date']) ? trim($_POST['adjustment_date']) : date('Y-m-d');

                    if ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select a valid item to adjust.");
                    }
                    if ($adjustedQty < 0) {
                        throw new InvalidArgumentException("Physical adjusted count cannot be negative.");
                    }
                    if (empty($reason)) {
                        throw new InvalidArgumentException("A reconciliation note or reason is required.");
                    }

                    $result = $stockService->recordStockAdjustment(
                        $currentWarehouseId,
                        $adjDate,
                        $reason,
                        [['item_id' => $itemId, 'adjusted_quantity' => $adjustedQty]],
                        $userId,
                        $currentUser
                    );
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " created successfully and marked as Pending approval.";
                    break;

                case 'approve_adjustment':
                    $adjId = (int)($_POST['stock_adjustment_id'] ?? 0);
                    if ($adjId <= 0) {
                        throw new InvalidArgumentException("Invalid adjustment reference.");
                    }
                    $result = $stockService->approveStockAdjustment($adjId, $userId, $currentUser);
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " approved! Stock ledger movement posted and warehouse inventory updated.";
                    break;

                case 'reject_adjustment':
                    $adjId  = (int)($_POST['stock_adjustment_id'] ?? 0);
                    $reason = trim($_POST['rejection_reason'] ?? 'Discrepancy count rejected by administrator');
                    if ($adjId <= 0) {
                        throw new InvalidArgumentException("Invalid adjustment reference.");
                    }
                    $result = $stockService->rejectStockAdjustment($adjId, $userId, $reason, $currentUser);
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " has been rejected. No inventory changes were made.";
                    break;

                case 'cancel_adjustment':
                    $adjId  = (int)($_POST['stock_adjustment_id'] ?? 0);
                    $reason = trim($_POST['cancellation_reason'] ?? 'Administrative cancellation');
                    if ($adjId <= 0) {
                        throw new InvalidArgumentException("Invalid adjustment reference.");
                    }
                    $result = $stockService->cancelStockAdjustment($adjId, $reason, $userId, $currentUser);
                    $successMessage = "Stock adjustment " . htmlspecialchars($result['transaction_number']) . " has been cancelled and reversed.";
                    break;

                case 'create_bad_product':
                    $itemId        = (int)($_POST['item_id'] ?? 0);
                    $conditionType = trim($_POST['condition_type'] ?? 'damaged');
                    $quantity      = (float)($_POST['quantity'] ?? 0);
                    $reason        = trim($_POST['reason'] ?? '');

                    if ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select a valid item.");
                    }
                    if ($quantity <= 0) {
                        throw new InvalidArgumentException("Quantity of damaged stock must be greater than zero.");
                    }
                    if (empty($reason)) {
                        throw new InvalidArgumentException("Please provide details or a reason for the damage/defect.");
                    }

                    $result = $stockService->recordBadProduct(
                        $currentWarehouseId,
                        $itemId,
                        $conditionType,
                        $quantity,
                        $reason,
                        $userId,
                        $currentUser
                    );
                    $successMessage = "Damaged product write-off recorded (" . htmlspecialchars($result['bad_product_number']) . "). Stock has been deducted from your warehouse inventory.";
                    break;

                case 'cancel_bad_product':
                    $bpId   = (int)($_POST['bad_product_id'] ?? 0);
                    $reason = trim($_POST['cancellation_reason'] ?? 'Logged in error / Stock recovered');
                    if ($bpId <= 0) {
                        throw new InvalidArgumentException("Invalid defect record ID.");
                    }
                    $result = $stockService->cancelBadProduct($bpId, $reason, $userId, $currentUser);
                    $successMessage = "Damaged product report " . htmlspecialchars($result['bad_product_number']) . " cancelled. Deducted stock has been refunded back into your warehouse inventory.";
                    break;
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

// =============================================================================
// DATA QUERIES (Scoped strictly to assigned warehouse)
// =============================================================================

// 1. Fetch available warehouse inventory items for dropdown pickers
$itemsStmt = $pdo->prepare("
    SELECT 
        i.item_id, 
        i.item_code, 
        i.item_name, 
        i.item_type, 
        i.unit,
        COALESCE(inv.quantity, 0.000) AS current_stock
    FROM items i
    LEFT JOIN inventory inv ON i.item_id = inv.item_id AND inv.warehouse_id = :wid
    WHERE i.status = 'active'
    ORDER BY i.item_name ASC
");
$itemsStmt->execute([':wid' => $currentWarehouseId]);
$availableItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch stock adjustments for assigned warehouse
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
        ux.name AS cancelled_by_name,
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
    LEFT JOIN users ux ON sa.cancelled_by = ux.user_id
    LEFT JOIN stock_adjustment_items sai ON sa.stock_adjustment_id = sai.stock_adjustment_id
    LEFT JOIN items i ON sai.item_id = i.item_id
    WHERE sa.warehouse_id = :wid
    ORDER BY sa.stock_adjustment_id DESC
");
$stmt->execute([':wid' => $currentWarehouseId]);
$adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch bad product defect records for assigned warehouse
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
        u.name AS reported_by_name,
        ux.name AS cancelled_by_name
    FROM bad_products bp
    JOIN warehouses w ON bp.warehouse_id = w.warehouse_id
    JOIN items i ON bp.item_id = i.item_id
    JOIN users u ON bp.reported_by = u.user_id
    LEFT JOIN users ux ON bp.cancelled_by = ux.user_id
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
    if (($a['status'] ?? '') === 'approved') {
        $netVariance += (float)($a['difference'] ?? 0);
    }
}
?>

<!-- Alert Feedback Banners -->
<?php if (!empty($successMessage)): ?>
    <div style="background: #DCFCE7; border: 1px solid #86EFAC; color: #166534; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= htmlspecialchars($successMessage) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: #166534; font-size: 16px;">&times;</button>
    </div>
<?php endif; ?>

<?php if (!empty($errorMessage)): ?>
    <div style="background: #FEE2E2; border: 1px solid #FCA5A5; color: #991B1B; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errorMessage) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: #991B1B; font-size: 16px;">&times;</button>
    </div>
<?php endif; ?>

<!-- Page Header & Action Toolbar -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
    <div>
        <h1 class="page-title">Stock Adjustments &amp; Corrections</h1>
        <p class="page-subtitle">Audited physical count reconciliation &amp; defect write-offs for <strong><?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></strong></p>
    </div>
    <div style="display: flex; align-items: center; gap: 10px;">
        <button type="button" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" onclick="openReportBadProductModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <span> Report Damaged Goods</span>
        </button>
        <button type="button" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;" onclick="openNewAdjustmentModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span> New Stock Adjustment</span>
        </button>
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
        <div class="stat-meta">Discrepancy count records logged</div>
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
    </div>
</div>

<!-- ========================================================================= -->
<!-- SECTION 1: Stock Adjustments (Physical Count Reconciliation)              -->
<!-- ========================================================================= -->
<div class="card mb-6">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 class="card-title" style="margin: 0; font-size: 16px;">Stock Adjustment Reconciliation Records</h2>
            <p class="card-desc" style="margin: 0;">Audited inventory corrections between recorded balance and physical stocktake counts</p>
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
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($adjustments)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; color: var(--gray); padding: 36px;">No stock adjustments recorded for this warehouse yet.</td>
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
                                <?= formatQty($row['previous_quantity'] ?? 0) ?> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit'] ?? '') ?></small>
                            </td>
                            <td>
                                <strong style="color: var(--panel-ink);"><?= formatQty($row['adjusted_quantity'] ?? 0) ?></strong> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit'] ?? '') ?></small>
                            </td>
                            <td style="font-weight: 700; color: <?= $diff >= 0 ? '#15803D' : '#B91C1C' ?>;">
                                <?= ($diff >= 0 ? '+' : '') . formatQty($diff) ?>
                            </td>
                            <td style="max-width: 200px; font-size: 12px; color: var(--gray); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['reason']) ?>">
                                <?= htmlspecialchars($row['reason']) ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['adjustment_date'])) ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?= htmlspecialchars($row['logged_by']) ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'approved'): ?>
                                    <span class="badge status-completed">Approved</span>
                                <?php elseif ($row['status'] === 'pending'): ?>
                                    <span class="badge status-pending">Pending</span>
                                <?php elseif ($row['status'] === 'rejected'): ?>
                                    <span class="badge status-cancelled">Rejected</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <button type="button" class="btn btn-primary" style="height: 28px; padding: 0 10px; font-size: 11.5px; background: #15803D; border-color: #15803D;" onclick='openApproveModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            Approve
                                        </button>
                                        <button type="button" class="btn btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11.5px; color: #B91C1C; border-color: #FCA5A5;" onclick='openRejectModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            Reject
                                        </button>
                                    <?php elseif ($row['status'] === 'approved'): ?>
                                        <button type="button" class="btn btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11.5px; color: #475569;" onclick='openCancelAdjModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            Cancel
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11.5px;" onclick='openAdjDetailModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        Details
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- SECTION 2: Damaged & Defective Goods (Write-offs)                         -->
<!-- ========================================================================= -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 class="card-title" style="margin: 0; font-size: 16px;">Damaged &amp; Defective Liquor Goods</h2>
            <p class="card-desc" style="margin: 0;">Losses from bottle breakage, cork defects, barrel leakage, or expired batches written off from active inventory</p>
        </div>
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" id="badSearch" class="search-box" placeholder="Filter report ref, item..." onkeyup="filterTable('badSearch', 'badProductsTable')">
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
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($badProducts)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">No damaged or defective products reported for this warehouse yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($badProducts as $bp): ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
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
                            <td style="font-weight: 700; color: #B91C1C; white-space: nowrap;">
                                -<?= formatQty($bp['quantity']) ?> <small style="color: var(--gray);"><?= htmlspecialchars($bp['unit']) ?></small>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($bp['reason']) ?>">
                                <?= htmlspecialchars($bp['reason']) ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?= htmlspecialchars($bp['reported_by_name']) ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y', strtotime($bp['created_at'])) ?>
                            </td>
                            <td>
                                <?php if ($bp['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Written Off</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled">Cancelled</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($bp['status'] === 'completed'): ?>
                                    <button type="button" class="btn btn-secondary" style="height: 28px; padding: 0 9px; font-size: 11.5px; color: #B91C1C; border-color: #FCA5A5;" onclick='openCancelBadModal(<?= json_encode($bp, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        Cancel / Restore
                                    </button>
                                <?php else: ?>
                                    <span style="font-size: 11.5px; color: var(--gray); font-style: italic;">Restored</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: New Stock Adjustment                                              -->
<!-- ========================================================================= -->
<div id="newAdjustmentModal" class="modal-backdrop" onclick="if(event.target === this) closeNewAdjustmentModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_adjustment">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" style="margin: 0;">New Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Record discrepancy between system records and physical shelf count</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeNewAdjustmentModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Warehouse (Locked) -->
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Facility</label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; font-size: 13px; font-weight: 600; color: var(--panel-ink);">
                        <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?>
                    </div>
                </div>

                <!-- Item Selector -->
                <div>
                    <label for="adjItemSelect" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Item <span style="color: #DC2626;">*</span></label>
                    <select name="item_id" id="adjItemSelect" class="select-filter" style="width: 100%; height: 38px; border-radius: 6px;" required onchange="handleAdjItemChange(this)">
                        <option value="">-- Select Item to Adjust --</option>
                        <?php foreach ($availableItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [System Stock: <?= formatQty((float)$item['current_stock']) ?> <?= htmlspecialchars($item['unit']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Counts & Diff Calculation -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="font-size: 12px; font-weight: 600; color: var(--gray); margin-bottom: 4px; display: block;">Previous System Stock</label>
                        <div id="adjPrevStock" style="background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 6px; height: 38px; display: flex; align-items: center; padding: 0 12px; font-weight: 700; color: var(--panel-ink);">
                            —
                        </div>
                    </div>
                    <div>
                        <label for="adjPhysicalCount" style="font-size: 12px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Actual Physical Count <span style="color: #DC2626;">*</span></label>
                        <input type="number" step="0.01" min="0" name="adjusted_quantity" id="adjPhysicalCount" class="search-box" style="width: 100%; height: 38px; border-radius: 6px;" placeholder="0" required oninput="calcAdjDiff()">
                    </div>
                </div>

                <!-- Calculated Difference Callout -->
                <div id="adjDiffContainer" style="display: none; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px 14px; font-size: 13px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--gray); font-weight: 600;">Calculated Variance:</span>
                        <strong id="adjDiffValue" style="font-size: 15px;">—</strong>
                    </div>
                    <small id="adjDiffDesc" style="display: block; color: var(--gray); font-size: 11.5px; margin-top: 3px;"></small>
                </div>

                <!-- Adjustment Date -->
                <div>
                    <label for="adjDate" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Count Date</label>
                    <input type="date" name="adjustment_date" id="adjDate" class="search-box" style="width: 100%; height: 38px; border-radius: 6px;" value="<?= date('Y-m-d') ?>" required>
                </div>

                <!-- Reason Notes -->
                <div>
                    <label for="adjReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Reconciliation Notes / Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="reason" id="adjReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px; resize: vertical;" placeholder="e.g. Discrepancy discovered during monthly physical cycle count..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewAdjustmentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Report Damaged Goods                                               -->
<!-- ========================================================================= -->
<div id="reportBadProductModal" class="modal-backdrop" onclick="if(event.target === this) closeReportBadProductModal()">
    <div class="modal-card" style="max-width: 500px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_bad_product">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" style="margin: 0;">Report Damaged / Defective Stock</h3>
                    <p class="card-desc" style="margin: 0;">Immediately write off spoiled, broken, or defective goods</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeReportBadProductModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Item Selector -->
                <div>
                    <label for="badItemSelect" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Item <span style="color: #DC2626;">*</span></label>
                    <select name="item_id" id="badItemSelect" class="select-filter" style="width: 100%; height: 38px; border-radius: 6px;" required onchange="handleBadItemChange(this)">
                        <option value="">-- Select Damaged Item --</option>
                        <?php foreach ($availableItems as $item): ?>
                            <?php if ((float)$item['current_stock'] > 0): ?>
                                <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                    <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [Available: <?= formatQty((float)$item['current_stock']) ?> <?= htmlspecialchars($item['unit']) ?>]
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Condition Type -->
                <div>
                    <label for="badConditionSelect" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Condition / Defect Type <span style="color: #DC2626;">*</span></label>
                    <select name="condition_type" id="badConditionSelect" class="select-filter" style="width: 100%; height: 38px; border-radius: 6px;" required>
                        <option value="damaged">Damaged (e.g. Broken bottle, cracked crate)</option>
                        <option value="defective">Defective (e.g. Bad seal, cork taint, cloudy liquid)</option>
                        <option value="expired">Expired (e.g. Passed shelf life / best before)</option>
                        <option value="spoiled">Spoiled / Sourced</option>
                        <option value="unusable">Unusable Raw Material</option>
                        <option value="other">Other Incident</option>
                    </select>
                </div>

                <!-- Quantity -->
                <div>
                    <label for="badQuantity" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Quantity to Write Off <span style="color: #DC2626;">*</span></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.01" min="0.01" name="quantity" id="badQuantity" class="search-box" style="flex: 1; height: 38px; border-radius: 6px;" placeholder="0" required>
                        <span id="badUnitIndicator" style="font-size: 12.5px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                    <small id="badStockHint" style="color: var(--gray); font-size: 11.5px; display: block; margin-top: 3px;">Select an item to view maximum write-off balance.</small>
                </div>

                <!-- Reason Notes -->
                <div>
                    <label for="badReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Incident Details / Cause <span style="color: #DC2626;">*</span></label>
                    <textarea name="reason" id="badReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px; resize: vertical;" placeholder="e.g. Pallet slipped during restack causing bottle breakage..." required></textarea>
                </div>

                <div style="font-size: 11.5px; color: #991B1B; background: #FEE2E2; border: 1px solid #FCA5A5; border-radius: 6px; padding: 8px 12px;">
                    <strong>Note:</strong> Submitting this report will immediately deduct the written-off quantity from active inventory.
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeReportBadProductModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Record Write-Off</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Confirm Approve Adjustment                                         -->
<!-- ========================================================================= -->
<div id="confirmApproveModal" class="modal-backdrop" onclick="if(event.target === this) closeApproveModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve_adjustment">
            <input type="hidden" name="stock_adjustment_id" id="approveAdjId" value="">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" style="margin: 0;">Approve Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Confirm variance and update warehouse inventory</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeApproveModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Are you sure you want to approve adjustment <strong id="approveTrfRef" style="font-family: monospace;">—</strong>?
                </p>
                <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 12px; font-size: 13px;">
                    <div><strong>Item:</strong> <span id="approveItemName">—</span></div>
                    <div style="margin-top: 4px;"><strong>Variance:</strong> <span id="approveDiff">—</span></div>
                </div>
                <div style="font-size: 12px; color: #475569; background: #F1F5F9; border-radius: 6px; padding: 8px 12px;">
                    Approving this adjustment will post an immutable stock movement and update the warehouse balance.
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #15803D; border-color: #15803D;">Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Confirm Reject Adjustment                                          -->
<!-- ========================================================================= -->
<div id="confirmRejectModal" class="modal-backdrop" onclick="if(event.target === this) closeRejectModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject_adjustment">
            <input type="hidden" name="stock_adjustment_id" id="rejectAdjId" value="">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" style="margin: 0; color: #B91C1C;">Reject Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Decline count discrepancy correction</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeRejectModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Rejecting adjustment <strong id="rejectTrfRef" style="font-family: monospace;">—</strong> will close this record without altering any stock balances.
                </p>
                <div>
                    <label for="rejectReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Rejection Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="rejection_reason" id="rejectReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px;" placeholder="Reason for rejecting adjustment..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Cancel Approved Adjustment                                         -->
<!-- ========================================================================= -->
<div id="confirmCancelAdjModal" class="modal-backdrop" onclick="if(event.target === this) closeCancelAdjModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_adjustment">
            <input type="hidden" name="stock_adjustment_id" id="cancelAdjId" value="">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" style="margin: 0; color: #B91C1C;">Cancel Stock Adjustment</h3>
                    <p class="card-desc" style="margin: 0;">Reverse approved adjustment and restore previous balance</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeCancelAdjModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Cancelling adjustment <strong id="cancelAdjRef" style="font-family: monospace;">—</strong> will generate a reversing entry (`STOCK_ADJUSTMENT_CANCEL`) and restore the previous stock level.
                </p>
                <div>
                    <label for="cancelAdjReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelAdjReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px;" placeholder="Reason for cancelling approved adjustment..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelAdjModal()">Go Back</button>
                <button type="submit" class="btn btn-primary" style="background: #B91C1C; border-color: #B91C1C;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Cancel Bad Product Report                                          -->
<!-- ========================================================================= -->
<div id="confirmCancelBadModal" class="modal-backdrop" onclick="if(event.target === this) closeCancelBadModal()">
    <div class="modal-card" style="max-width: 460px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_bad_product">
            <input type="hidden" name="bad_product_id" id="cancelBadId" value="">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" style="margin: 0; color: #15803D;">Restore Damaged Product Stock</h3>
                    <p class="card-desc" style="margin: 0;">Cancel write-off report and return items to inventory</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeCancelBadModal()">&times;</button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
                <p style="font-size: 13.5px; margin: 0; color: var(--panel-ink);">
                    Cancel write-off <strong id="cancelBadRef" style="font-family: monospace;">—</strong> and restore stock to active inventory?
                </p>
                <div>
                    <label for="cancelBadReason" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); margin-bottom: 4px; display: block;">Cancellation / Recovery Reason <span style="color: #DC2626;">*</span></label>
                    <textarea name="cancellation_reason" id="cancelBadReason" class="search-box" style="width: 100%; border-radius: 6px; height: 60px; padding: 8px 12px;" placeholder="e.g. Logged in error; items passed secondary QC inspection..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelBadModal()">Go Back</button>
                <button type="submit" class="btn btn-primary" style="background: #15803D; border-color: #15803D;">Confirm &amp; Restore Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Adjustment Details                                                 -->
<!-- ========================================================================= -->
<div id="adjustmentDetailModal" class="modal-backdrop" onclick="if(event.target === this) closeAdjDetailModal()">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <div>
                <h3 id="modalAdjTitle" class="card-title" style="margin: 0;">Adjustment Details</h3>
                <p class="card-desc" style="margin: 0;">Physical count discrepancy audit trail</p>
            </div>
            <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeAdjDetailModal()">&times;</button>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 12px;">
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Reference</span>
                    <strong id="modalAdjRef" style="font-family: monospace;">—</strong>
                </div>
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Status</span>
                    <span id="modalAdjStatus">—</span>
                </div>
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Logged By</span>
                    <span id="modalAdjUser">—</span>
                </div>
                <div>
                    <span style="color: var(--gray); font-size: 11px; text-transform: uppercase; font-weight: 600; display: block;">Date</span>
                    <span id="modalAdjDate">—</span>
                </div>
            </div>

            <div style="background: #F1F5F9; border-radius: 6px; padding: 10px 12px; font-size: 12.5px;">
                <strong style="color: #475569; display: block; font-size: 11px; text-transform: uppercase; margin-bottom: 2px;">Item &amp; Discrepancy:</strong>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                    <div>
                        <strong id="modalAdjItemName">—</strong>
                        <div id="modalAdjItemCode" style="font-family: monospace; font-size: 11px; color: var(--gray);">—</div>
                    </div>
                    <div style="text-align: right;">
                        <span id="modalAdjDiff" style="font-size: 14px; font-weight: 700;">—</span>
                    </div>
                </div>
                <div style="margin-top: 8px; font-size: 12px; color: #475569;">
                    Previous System: <span id="modalAdjPrev">—</span> &rarr; Adjusted Physical: <span id="modalAdjCount">—</span>
                </div>
            </div>

            <div style="font-size: 12.5px;">
                <strong style="color: var(--panel-ink); display: block; margin-bottom: 2px;">Reason / Notes:</strong>
                <div id="modalAdjReason" style="color: var(--gray); background: #FAF5FF; border: 1px solid #E9D5FF; border-radius: 6px; padding: 8px 12px;">—</div>
            </div>

            <div id="modalAdjAuditRow" style="font-size: 11.5px; color: var(--gray);"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAdjDetailModal()">Close</button>
        </div>
    </div>
</div>

<script>
let currentSelectedStock = 0;
let currentSelectedUnit  = '';

function openNewAdjustmentModal() {
    document.getElementById('newAdjustmentModal').style.display = 'flex';
}
function closeNewAdjustmentModal() {
    document.getElementById('newAdjustmentModal').style.display = 'none';
}

function handleAdjItemChange(select) {
    const option = select.options[select.selectedIndex];
    if (!option || !option.value) {
        currentSelectedStock = 0;
        currentSelectedUnit  = '';
        document.getElementById('adjPrevStock').textContent = '—';
        document.getElementById('adjDiffContainer').style.display = 'none';
        return;
    }
    currentSelectedStock = parseFloat(option.getAttribute('data-stock')) || 0;
    currentSelectedUnit  = option.getAttribute('data-unit') || '';

    document.getElementById('adjPrevStock').textContent = Number(currentSelectedStock.toFixed(2)) + ' ' + currentSelectedUnit;
    calcAdjDiff();
}

function calcAdjDiff() {
    const inputVal = document.getElementById('adjPhysicalCount').value;
    const container = document.getElementById('adjDiffContainer');
    const valElem = document.getElementById('adjDiffValue');
    const descElem = document.getElementById('adjDiffDesc');

    if (inputVal === '' || isNaN(inputVal)) {
        container.style.display = 'none';
        return;
    }

    const physical = parseFloat(inputVal);
    const diff = physical - currentSelectedStock;
    container.style.display = 'block';

    if (diff > 0) {
        valElem.textContent = '+' + Number(diff.toFixed(2)) + ' ' + currentSelectedUnit;
        valElem.style.color = '#15803D';
        descElem.textContent = 'Surplus: Stock count will increase available inventory upon approval.';
    } else if (diff < 0) {
        valElem.textContent = Number(diff.toFixed(2)) + ' ' + currentSelectedUnit;
        valElem.style.color = '#B91C1C';
        descElem.textContent = 'Shortage / Loss: Stock count will decrease available inventory upon approval.';
    } else {
        valElem.textContent = '0.00 ' + currentSelectedUnit;
        valElem.style.color = '#475569';
        descElem.textContent = 'No variance: Physical count exactly matches recorded inventory balance.';
    }
}

function openReportBadProductModal() {
    document.getElementById('reportBadProductModal').style.display = 'flex';
}
function closeReportBadProductModal() {
    document.getElementById('reportBadProductModal').style.display = 'none';
}

function handleBadItemChange(select) {
    const option = select.options[select.selectedIndex];
    const qtyInput = document.getElementById('badQuantity');
    const unitInd = document.getElementById('badUnitIndicator');
    const hint = document.getElementById('badStockHint');

    if (!option || !option.value) {
        unitInd.textContent = '—';
        hint.textContent = 'Select an item to view maximum write-off balance.';
        qtyInput.removeAttribute('max');
        return;
    }

    const stock = parseFloat(option.getAttribute('data-stock')) || 0;
    const unit = option.getAttribute('data-unit') || '';
    unitInd.textContent = unit;
    hint.textContent = 'Maximum transferable/deductible stock: ' + Number(stock.toFixed(2)) + ' ' + unit;
    qtyInput.max = stock;
}

function openApproveModal(row) {
    if (!row) return;
    document.getElementById('approveAdjId').value = row.stock_adjustment_id;
    document.getElementById('approveTrfRef').textContent = row.transaction_number;
    document.getElementById('approveItemName').textContent = row.item_name || 'Item';
    
    const diff = parseFloat(row.difference) || 0;
    const unit = row.unit || '';
    document.getElementById('approveDiff').textContent = (diff >= 0 ? '+' : '') + Number(diff.toFixed(2)) + ' ' + unit;
    document.getElementById('approveDiff').style.color = (diff >= 0) ? '#15803D' : '#B91C1C';

    document.getElementById('confirmApproveModal').style.display = 'flex';
}
function closeApproveModal() {
    document.getElementById('confirmApproveModal').style.display = 'none';
}

function openRejectModal(row) {
    if (!row) return;
    document.getElementById('rejectAdjId').value = row.stock_adjustment_id;
    document.getElementById('rejectTrfRef').textContent = row.transaction_number;
    document.getElementById('confirmRejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('confirmRejectModal').style.display = 'none';
}

function openCancelAdjModal(row) {
    if (!row) return;
    document.getElementById('cancelAdjId').value = row.stock_adjustment_id;
    document.getElementById('cancelAdjRef').textContent = row.transaction_number;
    document.getElementById('confirmCancelAdjModal').style.display = 'flex';
}
function closeCancelAdjModal() {
    document.getElementById('confirmCancelAdjModal').style.display = 'none';
}

function openCancelBadModal(row) {
    if (!row) return;
    document.getElementById('cancelBadId').value = row.bad_product_id;
    document.getElementById('cancelBadRef').textContent = row.bad_product_number;
    document.getElementById('confirmCancelBadModal').style.display = 'flex';
}
function closeCancelBadModal() {
    document.getElementById('confirmCancelBadModal').style.display = 'none';
}

function openAdjDetailModal(row) {
    if (!row) return;
    document.getElementById('modalAdjTitle').textContent = 'Adjustment ' + row.transaction_number;
    document.getElementById('modalAdjRef').textContent = row.transaction_number;
    
    let badge = '<span class="badge status-pending">Pending</span>';
    if (row.status === 'approved') badge = '<span class="badge status-completed">Approved</span>';
    else if (row.status === 'rejected') badge = '<span class="badge status-cancelled">Rejected</span>';
    else if (row.status === 'cancelled') badge = '<span class="badge status-cancelled">Cancelled</span>';
    document.getElementById('modalAdjStatus').innerHTML = badge;

    document.getElementById('modalAdjUser').textContent = row.logged_by || '—';
    document.getElementById('modalAdjDate').textContent = row.adjustment_date || '—';

    document.getElementById('modalAdjItemName').textContent = row.item_name || '—';
    document.getElementById('modalAdjItemCode').textContent = row.item_code || '';

    const diff = parseFloat(row.difference) || 0;
    const unit = row.unit || '';
    document.getElementById('modalAdjDiff').textContent = (diff >= 0 ? '+' : '') + Number(diff.toFixed(2)) + ' ' + unit;
    document.getElementById('modalAdjDiff').style.color = (diff >= 0) ? '#15803D' : '#B91C1C';

    document.getElementById('modalAdjPrev').textContent = Number((parseFloat(row.previous_quantity) || 0).toFixed(2)) + ' ' + unit;
    document.getElementById('modalAdjCount').textContent = Number((parseFloat(row.adjusted_quantity) || 0).toFixed(2)) + ' ' + unit;

    document.getElementById('modalAdjReason').textContent = row.reason || 'No notes provided';

    let audit = '';
    if (row.approved_by_name) audit += 'Approved by: ' + row.approved_by_name;
    if (row.cancelled_by_name) audit += (audit ? ' &middot; ' : '') + 'Cancelled by: ' + row.cancelled_by_name;
    document.getElementById('modalAdjAuditRow').innerHTML = audit;

    document.getElementById('adjustmentDetailModal').style.display = 'flex';
}
function closeAdjDetailModal() {
    document.getElementById('adjustmentDetailModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
