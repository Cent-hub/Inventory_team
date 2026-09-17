<?php
/**
 * View: Stock Transfer (Inter-Branch Transfers)
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Transfer — StockPilot';
$activePage  = 'stock_transfer';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = is_array($assignedWarehouse ?? null) ? $assignedWarehouse : [
    'warehouse_id'   => $currentWarehouseId ?? 1,
    'warehouse_code' => 'WH',
    'warehouse_name' => 'Assigned Warehouse',
    'location'       => 'Default Location'
];

$successMessage = null;
$errorMessage   = null;

// Handle New Transfer Initiation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_transfer') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        // Strictly enforce source warehouse as the admin's assigned warehouse
        $sourceWhId = $currentWarehouseId;
    $destWhId   = isset($_POST['destination_warehouse_id']) ? (int)$_POST['destination_warehouse_id'] : 0;
    $itemId     = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $quantity   = isset($_POST['quantity']) ? (float)$_POST['quantity'] : 0;
    $remarks    = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
    $userId     = (int)($currentUser['id'] ?? 1);

    if ($destWhId <= 0) {
        $errorMessage = "Please select a valid destination warehouse.";
    } elseif ($destWhId === $sourceWhId) {
        $errorMessage = "Destination warehouse cannot be the same as your source warehouse.";
    } elseif ($itemId <= 0) {
        $errorMessage = "Please select a valid item to transfer.";
    } elseif ($quantity <= 0) {
        $errorMessage = "Transfer quantity must be greater than zero.";
    } else {
        try {
            $stockService = new StockService();
            $result = $stockService->recordStockTransfer(
                $sourceWhId,
                $destWhId,
                [['item_id' => $itemId, 'quantity' => $quantity]],
                $userId,
                $remarks
            );
            $successMessage = "Transfer initiated successfully! Transaction reference: " . htmlspecialchars($result['transaction_number']);
        } catch (Exception $e) {
            $errorMessage = "Transfer failed: " . $e->getMessage();
        }
    }
    }
}

// Fetch available destination warehouses (other active warehouses only)
$destStmt = $pdo->prepare("
    SELECT warehouse_id, warehouse_code, warehouse_name, location 
    FROM warehouses 
    WHERE warehouse_id != :wid AND status = 'active' 
    ORDER BY warehouse_name ASC
");
$destStmt->execute([':wid' => $currentWarehouseId]);
$destinationWarehouses = $destStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch items available in source warehouse with positive stock
$itemsStmt = $pdo->prepare("
    SELECT 
        i.item_id, 
        i.item_code, 
        i.item_name, 
        i.item_type, 
        i.unit, 
        COALESCE(inv.quantity, 0) AS current_stock
    FROM inventory inv
    JOIN items i ON inv.item_id = i.item_id
    WHERE inv.warehouse_id = :wid 
      AND inv.quantity > 0 
      AND i.status = 'active'
    ORDER BY i.item_name ASC
");
$itemsStmt->execute([':wid' => $currentWarehouseId]);
$availableItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch transfers originating from the admin's assigned warehouse
$stmt = $pdo->prepare("
    SELECT 
        st.stock_transfer_id,
        st.transaction_number,
        st.source_warehouse_id,
        sw.warehouse_code AS src_code,
        sw.warehouse_name AS src_name,
        st.destination_warehouse_id,
        dw.warehouse_code AS dest_code,
        dw.warehouse_name AS dest_name,
        st.transaction_date,
        st.status,
        st.remarks,
        st.created_at,
        u.name AS requested_by,
        COUNT(sti.item_id) AS total_items,
        COALESCE(SUM(sti.quantity), 0) AS total_quantity,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', FORMAT(sti.quantity, 1), ' ', i.unit, ')') SEPARATOR '; ') AS items_summary
    FROM stock_transfers st
    JOIN warehouses sw ON st.source_warehouse_id = sw.warehouse_id
    JOIN warehouses dw ON st.destination_warehouse_id = dw.warehouse_id
    JOIN users u ON st.created_by = u.user_id
    LEFT JOIN stock_transfer_items sti ON st.stock_transfer_id = sti.stock_transfer_id
    LEFT JOIN items i ON sti.item_id = i.item_id
    WHERE st.source_warehouse_id = :wid
    GROUP BY st.stock_transfer_id
    ORDER BY st.stock_transfer_id DESC
");
$stmt->execute([':wid' => $currentWarehouseId]);
$transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch line items for modal inspection scoped to transfers originating from this warehouse
$stmtLines = $pdo->prepare("
    SELECT 
        sti.stock_transfer_id,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        sti.quantity
    FROM stock_transfer_items sti
    JOIN stock_transfers st ON sti.stock_transfer_id = st.stock_transfer_id
    JOIN items i ON sti.item_id = i.item_id
    WHERE st.source_warehouse_id = :wid
    ORDER BY sti.stock_transfer_item_id ASC
");
$stmtLines->execute([':wid' => $currentWarehouseId]);
$linesByTransfer = [];
while ($row = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
    $linesByTransfer[(int)$row['stock_transfer_id']][] = $row;
}

// KPI Metrics scoped strictly to assigned warehouse
$totalTransfers     = count($transfers);
$stmtComp = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers WHERE status = 'completed' AND source_warehouse_id = :wid");
$stmtComp->execute([':wid' => $currentWarehouseId]);
$completedTransfers = (int)$stmtComp->fetchColumn();

$stmtPend = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers WHERE status = 'pending' AND source_warehouse_id = :wid");
$stmtPend->execute([':wid' => $currentWarehouseId]);
$pendingTransfers   = (int)$stmtPend->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Stock Transfer Ledger</h1>
        <p class="page-subtitle">Track transfers originating from <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse') ?> (<?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? '') ?>)</strong> to other facilities</p>
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
        <button type="button" class="btn btn-primary" onclick="openNewTransferModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>+ Initiate Transfer</span>
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

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Outbound Transfers</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="17 1 21 5 17 9"/>
                    <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                    <polyline points="7 23 3 19 7 15"/>
                    <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalTransfers ?></div>
        <div class="stat-meta">From <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Completed Transfers</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $completedTransfers ?></div>
        <div class="stat-meta">Posted to destination facilities</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Pending / In Transit</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B45309; background: #FEF3C7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $pendingTransfers ?></div>
        <div class="stat-meta">Awaiting destination branch check-in</div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inter-Warehouse Movement Records</h2>
            <p class="card-desc">Transfers originating from <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?> with dual-posting ledger entries</p>
        </div>
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" id="transferSearch" class="search-box" placeholder="Filter transfer ref, destination..." onkeyup="filterTable('transferSearch', 'transfersTable')">
        </div>
    </div>

    <div class="table-responsive">
        <table id="transfersTable">
            <thead>
                <tr>
                    <th>Transfer Reference</th>
                    <th>From Warehouse (Source)</th>
                    <th>To Warehouse (Destination)</th>
                    <th>Items Transferred</th>
                    <th>Total Qty</th>
                    <th>Date</th>
                    <th>Requested By</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transfers)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--gray); padding: 36px;">No stock transfers recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transfers as $row): ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['transaction_number']) ?>
                            </td>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['src_code']) ?>"><?= htmlspecialchars($row['src_code']) ?></span>
                                <span style="font-size: 12px; color: var(--gray); margin-left: 4px;"><?= htmlspecialchars($row['src_name']) ?></span>
                            </td>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($row['dest_code']) ?>"><?= htmlspecialchars($row['dest_code']) ?></span>
                                <span style="font-size: 12px; color: var(--gray); margin-left: 4px;"><?= htmlspecialchars($row['dest_name']) ?></span>
                            </td>
                            <td style="max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['items_summary'] ?: '') ?>">
                                <span style="font-weight: 600;"><?= $row['total_items'] ?> item(s):</span>
                                <span style="color: var(--gray); font-size: 12px;"><?= htmlspecialchars($row['items_summary'] ?: 'No items') ?></span>
                            </td>
                            <td style="font-weight: 700; color: #1D4ED8;">
                                <?= number_format($row['total_quantity'], 1) ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                            </td>
                            <td style="font-size: 12.5px;">
                                <?= htmlspecialchars($row['requested_by']) ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Completed</span>
                                <?php elseif ($row['status'] === 'pending'): ?>
                                    <span class="badge status-pending">In Transit</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" onclick="openTransferDetailModal(<?= (int)$row['stock_transfer_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
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

<!-- Modal for Transfer Lines -->
<div id="transferModal" class="modal-backdrop" onclick="if(event.target === this) closeTransferDetailModal()">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalTrfTitle" class="card-title">Transfer Details</h3>
                <p id="modalTrfSub" class="card-desc">Items transferred between warehouse facilities</p>
            </div>
            <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeTransferDetailModal()">
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
                            <th>Transfer Quantity</th>
                        </tr>
                    </thead>
                    <tbody id="modalTrfTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeTransferDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal for Initiating Stock Transfer -->
<div id="newTransferModal" class="modal-backdrop" onclick="if(event.target === this) closeNewTransferModal()">
    <div class="modal-card" style="max-width: 540px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_transfer">
            <div class="modal-header">
                <div>
                    <h3 class="card-title">Initiate Stock Transfer</h3>
                    <p class="card-desc">Transfer inventory from your assigned warehouse to another facility</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeNewTransferModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px;">
                <!-- Source Warehouse (Locked to Logged-in Admin) -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        From Warehouse (Source) <span style="font-size: 11px; font-weight: normal; color: var(--gray);">(Your Assigned Facility &mdash; Locked)</span>
                    </label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 600; color: var(--panel-ink); font-size: 13px;">
                            <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Assigned Warehouse') ?>
                        </span>
                        <span class="badge" style="background: #E2E8F0; color: #475569; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Source Locked</span>
                    </div>
                    <!-- Hidden field guaranteeing source warehouse id matches session -->
                    <input type="hidden" name="source_warehouse_id" value="<?= $currentWarehouseId ?>">
                </div>

                <!-- Destination Warehouse (Selectable Other Active Warehouses) -->
                <div>
                    <label for="destinationWarehouseSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        To Warehouse (Destination) <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="destination_warehouse_id" id="destinationWarehouseSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required>
                        <option value="">-- Select Destination Facility --</option>
                        <?php foreach ($destinationWarehouses as $dw): ?>
                            <option value="<?= (int)$dw['warehouse_id'] ?>">
                                <?= htmlspecialchars($dw['warehouse_code']) ?> &mdash; <?= htmlspecialchars($dw['warehouse_name']) ?> (<?= htmlspecialchars($dw['location'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Item Selection from Source Warehouse Inventory -->
                <div>
                    <label for="transferItemSelect" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Item to Transfer <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="transferItemSelect" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="handleItemChange(this)">
                        <option value="">-- Select Item with Available Stock --</option>
                        <?php foreach ($availableItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= (float)$item['current_stock'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>">
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
                    <label for="transferQuantity" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Transfer Quantity <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.01" min="0.01" name="quantity" id="transferQuantity" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0.00" required>
                        <span id="unitIndicator" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                    <div id="availStockHint" style="font-size: 11.5px; color: var(--gray); margin-top: 4px;">Select an item to view maximum transferable balance.</div>
                </div>

                <!-- Remarks -->
                <div>
                    <label for="transferRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Transfer Notes / Remarks
                    </label>
                    <textarea name="remarks" id="transferRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 64px; padding: 8px 12px; resize: vertical;" placeholder="Optional dispatch notes or batch reference..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= empty($availableItems) ? 'disabled' : '' ?>>
                    <span>Confirm &amp; Dispatch</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const trfLinesData = <?= json_encode($linesByTransfer) ?>;

function openTransferDetailModal(id, txnNo) {
    document.getElementById('modalTrfTitle').textContent = 'Transfer ' + txnNo;
    const tbody = document.getElementById('modalTrfTableBody');
    tbody.innerHTML = '';

    const lines = trfLinesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--gray); padding: 16px;">No line items found.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-family: monospace; font-weight: 700;">${escapeHtml(l.item_code)}</td>
                <td><strong>${escapeHtml(l.item_name)}</strong></td>
                <td><span class="badge-type ${l.item_type === 'finished_good' ? 'type-fg' : 'type-raw'}">${escapeHtml(l.item_type.replace('_', ' '))}</span></td>
                <td style="font-weight: 700; color: #1D4ED8;">${parseFloat(l.quantity).toFixed(2)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('transferModal').style.display = 'flex';
}

function closeTransferDetailModal() {
    document.getElementById('transferModal').style.display = 'none';
}

function openNewTransferModal() {
    document.getElementById('newTransferModal').style.display = 'flex';
}

function closeNewTransferModal() {
    document.getElementById('newTransferModal').style.display = 'none';
}

function handleItemChange(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const unitSpan = document.getElementById('unitIndicator');
    const hintDiv = document.getElementById('availStockHint');
    const qtyInput = document.getElementById('transferQuantity');

    if (selectedOption && selectedOption.dataset.stock) {
        const maxStock = parseFloat(selectedOption.dataset.stock);
        const unit = selectedOption.dataset.unit || '';
        unitSpan.textContent = unit;
        hintDiv.innerHTML = `Available in <strong><?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH') ?></strong>: <strong>${maxStock.toFixed(2)} ${unit}</strong>`;
        qtyInput.max = maxStock;
        qtyInput.placeholder = `Max ${maxStock.toFixed(2)}`;
    } else {
        unitSpan.textContent = '—';
        hintDiv.textContent = 'Select an item to view maximum transferable balance.';
        qtyInput.removeAttribute('max');
        qtyInput.placeholder = '0.00';
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
