<?php
/**
 * View: Stock Out Dispatch Ledger
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Out Dispatch — StockPilot';
$activePage  = 'stock_out';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

$successMessage = null;
$errorMessage   = null;

// Handle manual Stock Out creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_stock_out') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $sourceType        = trim($_POST['source_type'] ?? 'SALES_DELIVERY');
    $sourceReferenceNo = trim($_POST['source_reference_no'] ?? '');
    $itemId            = (int)($_POST['item_id'] ?? 0);
    $quantity          = (float)($_POST['quantity'] ?? 0);
    $remarks           = trim($_POST['remarks'] ?? '');
    $sourceWhId        = (int)($currentWarehouseId ?: 1);
    $userId            = (int)($currentUser['id'] ?? 1);

    if (empty($sourceReferenceNo)) {
        $errorMessage = "Reference Number (Sales Order # or Material Request #) is required.";
    } elseif ($itemId <= 0) {
        $errorMessage = "Please select an item to dispatch.";
    } elseif ($quantity <= 0) {
        $errorMessage = "Quantity dispatched must be greater than zero.";
    } else {
        try {
            $service = new StockService();
            $res = $service->recordStockOut(
                $sourceWhId,
                $sourceType,
                $sourceReferenceNo,
                [['item_id' => $itemId, 'quantity' => $quantity]],
                $userId,
                $remarks ?: null
            );
            $successMessage = "Outbound stock dispatched successfully! Transaction reference: " . htmlspecialchars($res['transaction_number']);
        } catch (Exception $e) {
            $errorMessage = "Stock Out failed: " . $e->getMessage();
        }
    }
    }
}

// Fetch items available in this warehouse with positive balance
$availStmt = $pdo->prepare("
    SELECT i.item_id, i.item_code, i.item_name, i.item_type, i.unit, inv.quantity AS current_stock
    FROM inventory inv
    JOIN items i ON inv.item_id = i.item_id
    WHERE inv.warehouse_id = :wid AND inv.quantity > 0 AND i.status = 'active'
    ORDER BY i.item_type ASC, i.item_name ASC
");
$availStmt->execute([':wid' => $currentWarehouseId]);
$availableItems = $availStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock Out transactions strictly for assigned warehouse
$stmt = $pdo->prepare("
    SELECT 
        so.stock_out_id,
        so.transaction_number,
        so.source_type,
        so.source_reference_no,
        so.transaction_date,
        so.status,
        so.remarks,
        so.created_at,
        w.warehouse_code,
        w.warehouse_name,
        u.name AS operator_name,
        COUNT(soi.item_id) AS total_item_count,
        COALESCE(SUM(soi.quantity), 0) AS total_quantity,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', FORMAT(soi.quantity, 1), ' ', i.unit, ')') SEPARATOR ', ') AS item_breakdown
    FROM stock_outs so
    JOIN warehouses w ON so.warehouse_id = w.warehouse_id
    JOIN users u ON so.created_by = u.user_id
    LEFT JOIN stock_out_items soi ON so.stock_out_id = soi.stock_out_id
    LEFT JOIN items i ON soi.item_id = i.item_id
    WHERE so.warehouse_id = :wid
    GROUP BY so.stock_out_id
    ORDER BY so.stock_out_id DESC
");
$stmt->execute([':wid' => $currentWarehouseId]);
$stockOuts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch line items strictly for this warehouse's stock outs
$stmtLines = $pdo->prepare("
    SELECT 
        soi.stock_out_id,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        soi.quantity
    FROM stock_out_items soi
    JOIN stock_outs so ON soi.stock_out_id = so.stock_out_id
    JOIN items i ON soi.item_id = i.item_id
    WHERE so.warehouse_id = :wid
    ORDER BY soi.stock_out_item_id ASC
");
$stmtLines->execute([':wid' => $currentWarehouseId]);
$linesByStockOut = [];
while ($row = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
    $linesByStockOut[(int)$row['stock_out_id']][] = $row;
}

// KPI Metrics scoped strictly to assigned warehouse
$totalStockOutTxns = count($stockOuts);
$stmtSales = $pdo->prepare("SELECT COUNT(*) FROM stock_outs WHERE source_type = 'SALES_DELIVERY' AND warehouse_id = :wid");
$stmtSales->execute([':wid' => $currentWarehouseId]);
$salesDispatches   = (int)$stmtSales->fetchColumn();

$stmtMat = $pdo->prepare("SELECT COUNT(*) FROM stock_outs WHERE source_type = 'MATERIAL_REQUEST' AND warehouse_id = :wid");
$stmtMat->execute([':wid' => $currentWarehouseId]);
$materialRequests  = (int)$stmtMat->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Stock Out Dispatch Ledger</h1>
        <p class="page-subtitle">Outbound inventory dispatch transactions for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect width="12" height="8" x="6" y="14"/>
            </svg>
            <span>Print Ledger</span>
        </button>
        <button type="button" class="btn btn-primary" onclick="openNewStockOutModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>+ Issue Stock Out</span>
        </button>
    </div>
</div>

<!-- Flash Alerts -->
<?php if ($successMessage): ?>
    <div style="background: var(--success-light); border: 1px solid var(--success-border); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span><?= htmlspecialchars($successMessage) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div style="background: var(--error-light); border: 1px solid var(--error-border); color: var(--error); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?= htmlspecialchars($errorMessage) ?></span>
    </div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Outbound Dispatches</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="7" y1="17" x2="17" y2="7"/>
                    <polyline points="7 7 17 7 17 17"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalStockOutTxns ?></div>
        <div class="stat-meta">Completed outbound dispatches</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Sales Deliveries</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="13" x="1" y="5" rx="2"/>
                    <polygon points="17 8 20 8 23 11 23 18 17 18 17 8"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $salesDispatches ?></div>
        <div class="stat-meta">Customer orders fulfilled &amp; dispatched</div>
    </div>

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Production Requisitions</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $materialRequests ?></div>
        <div class="stat-meta">Raw materials issued to distilling line</div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Outbound Stock Dispatch Records</h2>
            <p class="card-desc">Reduces available warehouse stock and generates immutable stock movement records</p>
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
                <input type="text" id="stockOutSearch" class="search-box" placeholder="Filter ref, item, Order #..." onkeyup="filterStockOutTable()">
            </div>

            <!-- Source Type Filter -->
            <select id="stockOutTypeFilter" class="select-filter" onchange="filterStockOutTable()">
                <option value="">All Reason Types</option>
                <option value="SALES_DELIVERY">Sales Delivery (Commercial)</option>
                <option value="MATERIAL_REQUEST">Material Request (Production)</option>
                <option value="MANUAL">Manual Dispatch</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table id="stockOutTable">
            <thead>
                <tr>
                    <th>Reference Number</th>
                    <th>Reason / Type</th>
                    <th>Source Ref #</th>
                    <th>Warehouse Branch</th>
                    <th>Items Dispatched</th>
                    <th>Total Qty</th>
                    <th>Date</th>
                    <th>Dispatched By</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockOuts)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">No Stock Out transactions recorded.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stockOuts as $row): ?>
                        <tr data-source="<?= htmlspecialchars($row['source_type']) ?>">
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['transaction_number']) ?>
                            </td>
                            <td>
                                <?php if ($row['source_type'] === 'SALES_DELIVERY'): ?>
                                    <span class="badge" style="background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD;">
                                        Sales Delivery
                                    </span>
                                <?php elseif ($row['source_type'] === 'MATERIAL_REQUEST'): ?>
                                    <span class="badge" style="background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;">
                                        Material Request
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1;">
                                        Manual Out
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: monospace; font-size: 12px; font-weight: 600;">
                                <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                            </td>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['warehouse_code']) ?>">
                                    <?= htmlspecialchars($row['warehouse_code']) ?>
                                </span>
                                <small style="color: var(--gray); margin-left: 4px;"><?= htmlspecialchars($row['warehouse_name']) ?></small>
                            </td>
                            <td style="max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['item_breakdown'] ?: '') ?>">
                                <span style="font-weight: 600;"><?= $row['total_item_count'] ?> item(s):</span>
                                <span style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['item_breakdown'] ?: 'No items') ?></span>
                            </td>
                            <td style="font-weight: 700; color: #B91C1C;">
                                -<?= number_format($row['total_quantity'], 1) ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                            </td>
                            <td style="font-size: 12.5px;">
                                <?= htmlspecialchars($row['operator_name']) ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Completed</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" onclick="openOutDetailModal(<?= (int)$row['stock_out_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
                                    View Items
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Line Item Details Modal -->
<div id="stockOutModal" class="modal-backdrop" onclick="if(event.target === this) closeOutDetailModal()">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalOutTitle" class="card-title">Dispatch Details</h3>
                <p id="modalOutSub" class="card-desc">Outbound line items released from inventory</p>
            </div>
            <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeOutDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Classification</th>
                            <th>Quantity Dispatched</th>
                        </tr>
                    </thead>
                    <tbody id="modalOutTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeOutDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal: New Stock Out Dispatch Form -->
<div id="newStockOutModal" class="modal-backdrop" onclick="if(event.target === this) closeNewStockOutModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_stock_out">
            <div class="modal-header">
                <div>
                    <h3 class="card-title">Issue Outbound Stock</h3>
                    <p class="card-desc">Dispatch finished goods for Sales orders or release raw ingredients to Production</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeNewStockOutModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Source Warehouse (Locked to Current Warehouse) -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Dispatching Warehouse Facility
                    </label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 600; color: var(--panel-ink); font-size: 13px;">
                            <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?>
                        </span>
                        <span class="badge" style="background: #FEE2E2; color: #B91C1C; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Source Facility Locked</span>
                    </div>
                </div>

                <!-- Outbound Destination / Reason Type -->
                <div>
                    <label for="modalOutSourceType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Outbound Operation Type <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="source_type" id="modalOutSourceType" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="onOutboundTypeChange(this.value)">
                        <option value="SALES_DELIVERY">Sales Delivery &mdash; Customer Dispatch (Finished Goods Only)</option>
                        <option value="MATERIAL_REQUEST">Material Request &mdash; Production Line (Raw Materials Only)</option>
                        <option value="MANUAL">Manual Stock Out &mdash; Sample / Write-Down</option>
                    </select>
                </div>

                <!-- Reference Number -->
                <div>
                    <label for="modalOutRefNo" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Sales Order # / Material Request # <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="source_reference_no" id="modalOutRefNo" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" placeholder="e.g. SO-2026-001" required>
                    <small style="color: var(--gray); font-size: 11px;">Unique identifier to prevent duplicate dispatch.</small>
                </div>

                <!-- Item Selection from Available Warehouse Stock -->
                <div>
                    <label for="modalOutItem" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Available Item to Issue <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="modalOutItem" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="updateOutboundLimits(this)">
                        <option value="">-- Select Item with Positive Stock --</option>
                        <?php foreach ($availableItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>" data-type="<?= htmlspecialchars($item['item_type']) ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
                                <?= htmlspecialchars($item['item_code']) ?> &mdash; <?= htmlspecialchars($item['item_name']) ?> [Stock: <?= number_format((float)$item['current_stock'], 2) ?> <?= htmlspecialchars($item['unit']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($availableItems)): ?>
                        <div style="font-size: 11.5px; color: #DC2626; margin-top: 4px;">No items currently available with positive stock in this warehouse.</div>
                    <?php endif; ?>
                </div>

                <!-- Quantity -->
                <div>
                    <label for="modalOutQty" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Quantity to Dispatch <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.01" min="0.01" name="quantity" id="modalOutQty" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0.00" required>
                        <span id="outUnitIndicator" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                    <div id="outStockHint" style="font-size: 11.5px; color: var(--gray); margin-top: 4px;">Select an item to view maximum available stock.</div>
                </div>

                <!-- Remarks -->
                <div>
                    <label for="modalOutRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Dispatch Notes / Remarks
                    </label>
                    <textarea name="remarks" id="modalOutRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 50px; padding: 8px 12px;" placeholder="Optional notes (client, sales order details, production batch)..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewStockOutModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= empty($availableItems) ? 'disabled' : '' ?>>Confirm Dispatch</button>
            </div>
        </form>
    </div>
</div>

<script>
const outLinesData = <?= json_encode($linesByStockOut) ?>;

function openNewStockOutModal() {
    document.getElementById('newStockOutModal').classList.add('open');
    onOutboundTypeChange(document.getElementById('modalOutSourceType').value);
}

function closeNewStockOutModal() {
    document.getElementById('newStockOutModal').classList.remove('open');
}

function onOutboundTypeChange(type) {
    const itemSelect = document.getElementById('modalOutItem');
    const refInput   = document.getElementById('modalOutRefNo');
    const options    = itemSelect.querySelectorAll('option');

    if (type === 'SALES_DELIVERY') {
        refInput.placeholder = 'e.g. SO-2026-001';
    } else if (type === 'MATERIAL_REQUEST') {
        refInput.placeholder = 'e.g. MR-2026-001';
    } else {
        refInput.placeholder = 'e.g. OUT-2026-001';
    }

    // Filter items according to business rules
    options.forEach(opt => {
        if (!opt.value) return;
        const itType = opt.getAttribute('data-type');
        if (type === 'SALES_DELIVERY') {
            opt.hidden = (itType !== 'finished_good');
        } else if (type === 'MATERIAL_REQUEST') {
            opt.hidden = (itType !== 'raw_material');
        } else {
            opt.hidden = false;
        }
    });

    const selectedOpt = itemSelect.options[itemSelect.selectedIndex];
    if (selectedOpt && selectedOpt.hidden) {
        itemSelect.value = '';
        document.getElementById('outUnitIndicator').textContent = '—';
        document.getElementById('outStockHint').textContent = 'Select an item to view maximum available stock.';
    }
}

function updateOutboundLimits(select) {
    const opt = select.options[select.selectedIndex];
    const qtyInput = document.getElementById('modalOutQty');
    const hint = document.getElementById('outStockHint');
    const unitIndicator = document.getElementById('outUnitIndicator');

    if (opt && opt.value) {
        const maxStock = parseFloat(opt.getAttribute('data-stock') || 0);
        const unit = opt.getAttribute('data-unit') || '';
        qtyInput.max = maxStock;
        unitIndicator.textContent = unit.toUpperCase();
        hint.textContent = `Available physical balance: ${maxStock.toFixed(2)} ${unit}`;
        hint.style.color = 'var(--gray)';
    } else {
        qtyInput.removeAttribute('max');
        unitIndicator.textContent = '—';
        hint.textContent = 'Select an item to view maximum available stock.';
        hint.style.color = 'var(--gray)';
    }
}

function openOutDetailModal(id, txnNo) {
    document.getElementById('modalOutTitle').textContent = 'Dispatch ' + txnNo;
    const tbody = document.getElementById('modalOutTableBody');
    tbody.innerHTML = '';

    const lines = outLinesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--gray); padding: 16px;">No line items found.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-family: monospace; font-weight: 700;">${escapeHtml(l.item_code)}</td>
                <td><strong>${escapeHtml(l.item_name)}</strong></td>
                <td><span class="badge-type ${l.item_type === 'finished_good' ? 'type-fg' : 'type-raw'}">${escapeHtml(l.item_type.replace('_', ' '))}</span></td>
                <td style="font-weight: 700; color: #B91C1C;">-${parseFloat(l.quantity).toFixed(2)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = document.getElementById('stockOutModal');
    modal.style.display = 'flex';
}

function closeOutDetailModal() {
    document.getElementById('stockOutModal').style.display = 'none';
}

function filterStockOutTable() {
    const term = document.getElementById('stockOutSearch').value.toLowerCase().trim();
    const typeFilter = document.getElementById('stockOutTypeFilter').value;
    const table = document.getElementById('stockOutTable');
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const rowSource = row.getAttribute('data-source') || '';

        const matchesText = text.includes(term);
        const matchesSource = !typeFilter || rowSource === typeFilter;

        if (matchesText && matchesSource) {
            delete row.dataset.filteredOut;
        } else {
            row.dataset.filteredOut = 'true';
        }
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
