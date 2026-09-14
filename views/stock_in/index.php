<?php
/**
 * View: Stock In Receiving Ledger
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock In Receiving — StockPilot';
$activePage  = 'stock_in';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../helpers/StockService.php';

$successMessage = null;
$errorMessage   = null;

// Handle manual Stock In creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_stock_in') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $sourceType        = trim($_POST['source_type'] ?? 'PURCHASE_ORDER');
    $sourceReferenceNo = trim($_POST['source_reference_no'] ?? '');
    $itemId            = (int)($_POST['item_id'] ?? 0);
    $quantity          = (float)($_POST['quantity'] ?? 0);
    $remarks           = trim($_POST['remarks'] ?? '');
    $targetWhId        = (int)($currentWarehouseId ?: 1);
    $userId            = (int)($currentUser['id'] ?? 1);

    if (empty($sourceReferenceNo)) {
        $errorMessage = "Reference Number (PO # or Work Order #) is required.";
    } elseif ($itemId <= 0) {
        $errorMessage = "Please select an item to receive.";
    } elseif ($quantity <= 0) {
        $errorMessage = "Quantity received must be greater than zero.";
    } else {
        try {
            $service = new StockService();
            $res = $service->recordStockIn(
                $targetWhId,
                $sourceType,
                $sourceReferenceNo,
                [['item_id' => $itemId, 'quantity' => $quantity]],
                $userId,
                $remarks ?: null
            );
            $successMessage = "Inbound stock received successfully! Transaction reference: " . htmlspecialchars($res['transaction_number']);
        } catch (Exception $e) {
            $errorMessage = "Stock In failed: " . $e->getMessage();
        }
    }
    }
}

// Fetch all active items for receiving dropdown
$allItems = $pdo->query("
    SELECT item_id, item_code, item_name, item_type, unit 
    FROM items 
    WHERE status = 'active' 
    ORDER BY item_type ASC, item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock In transactions strictly for assigned warehouse
$stmt = $pdo->prepare("
    SELECT 
        si.stock_in_id,
        si.transaction_number,
        si.source_type,
        si.source_reference_no,
        si.transaction_date,
        si.status,
        si.remarks,
        si.created_at,
        w.warehouse_code,
        w.warehouse_name,
        u.name AS operator_name,
        COUNT(sii.item_id) AS total_item_count,
        COALESCE(SUM(sii.quantity), 0) AS total_quantity,
        GROUP_CONCAT(CONCAT(i.item_name, ' (', FORMAT(sii.quantity, 1), ' ', i.unit, ')') SEPARATOR ', ') AS item_breakdown
    FROM stock_ins si
    JOIN warehouses w ON si.warehouse_id = w.warehouse_id
    JOIN users u ON si.created_by = u.user_id
    LEFT JOIN stock_in_items sii ON si.stock_in_id = sii.stock_in_id
    LEFT JOIN items i ON sii.item_id = i.item_id
    WHERE si.warehouse_id = :wid
    GROUP BY si.stock_in_id
    ORDER BY si.stock_in_id DESC
");
$stmt->execute([':wid' => $currentWarehouseId]);
$stockIns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch line items strictly for this warehouse's stock ins
$stmtLines = $pdo->prepare("
    SELECT 
        sii.stock_in_id,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        sii.quantity
    FROM stock_in_items sii
    JOIN stock_ins si ON sii.stock_in_id = si.stock_in_id
    JOIN items i ON sii.item_id = i.item_id
    WHERE si.warehouse_id = :wid
    ORDER BY sii.stock_in_item_id ASC
");
$stmtLines->execute([':wid' => $currentWarehouseId]);
$linesByStockIn = [];
while ($row = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
    $linesByStockIn[(int)$row['stock_in_id']][] = $row;
}

// KPI Metrics scoped strictly to assigned warehouse
$totalStockInTxns = count($stockIns);
$stmtProc = $pdo->prepare("SELECT COUNT(*) FROM stock_ins WHERE source_type = 'PURCHASE_ORDER' AND warehouse_id = :wid");
$stmtProc->execute([':wid' => $currentWarehouseId]);
$procurementInbounds = (int)$stmtProc->fetchColumn();

$stmtProd = $pdo->prepare("SELECT COUNT(*) FROM stock_ins WHERE source_type = 'PRODUCTION_RETURN' AND warehouse_id = :wid");
$stmtProd->execute([':wid' => $currentWarehouseId]);
$productionInbounds  = (int)$stmtProd->fetchColumn();
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Stock In Receiving Ledger</h1>
        <p class="page-subtitle">Inbound inventory receiving transactions for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <!-- Lucide Printer Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect width="12" height="8" x="6" y="14"/>
            </svg>
            <span>Print Ledger</span>
        </button>
        <button type="button" class="btn btn-primary" onclick="openNewStockInModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>+ Receive Stock In</span>
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
            <span class="stat-label">Total Inbound Receipts</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="17" y1="7" x2="7" y2="17"/>
                    <polyline points="17 17 7 17 7 7"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalStockInTxns ?></div>
        <div class="stat-meta">Completed inbound operations</div>
    </div>

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Procurement POs</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $procurementInbounds ?></div>
        <div class="stat-meta">Raw materials received from vendors</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Production Receipts</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--accent); background: var(--accent-light);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m16 16 2 2 4-4"/>
                    <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $productionInbounds ?></div>
        <div class="stat-meta">Finished goods delivered to warehouse</div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inbound Stock Ledger Records</h2>
            <p class="card-desc">Every transaction updates physical inventory and generates an immutable stock movement entry</p>
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
                <input type="text" id="stockInSearch" class="search-box" placeholder="Filter ref, item, PO #..." onkeyup="filterStockInTable()">
            </div>

            <!-- Source Type Filter -->
            <select id="sourceTypeFilter" class="select-filter" onchange="filterStockInTable()">
                <option value="">All Source Types</option>
                <option value="PURCHASE_ORDER">Purchase Order (Procurement)</option>
                <option value="PRODUCTION_RETURN">Production Receipt</option>
                <option value="MANUAL">Manual Inbound</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table id="stockInTable">
            <thead>
                <tr>
                    <th>Reference Number</th>
                    <th>Source Type</th>
                    <th>Source PO / Ref #</th>
                    <th>Warehouse Branch</th>
                    <th>Items Received</th>
                    <th>Total Qty</th>
                    <th>Date</th>
                    <th>Received By</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockIns)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">No Stock In transactions recorded.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stockIns as $row): ?>
                        <tr data-source="<?= htmlspecialchars($row['source_type']) ?>">
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['transaction_number']) ?>
                            </td>
                            <td>
                                <?php if ($row['source_type'] === 'PURCHASE_ORDER'): ?>
                                    <span class="badge" style="background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;">
                                        Procurement PO
                                    </span>
                                <?php elseif ($row['source_type'] === 'PRODUCTION_RETURN'): ?>
                                    <span class="badge" style="background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD;">
                                        Production Batch
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1;">
                                        Manual Inbound
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
                            <td style="font-weight: 700; color: #15803D;">
                                +<?= number_format($row['total_quantity'], 1) ?>
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
                                <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 10px; font-size: 11.5px;" onclick="openDetailModal(<?= (int)$row['stock_in_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
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
<div id="stockInModal" class="modal-backdrop" onclick="if(event.target === this) closeDetailModal()">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalTitle" class="card-title">Transaction Details</h3>
                <p id="modalSub" class="card-desc">Inbound line items received into warehouse</p>
            </div>
            <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeDetailModal()">
                <!-- Close Icon -->
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
                            <th>Quantity Received</th>
                        </tr>
                    </thead>
                    <tbody id="modalLineTableBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal: New Stock In Receiving Form -->
<div id="newStockInModal" class="modal-backdrop" onclick="if(event.target === this) closeNewStockInModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_stock_in">
            <div class="modal-header">
                <div>
                    <h3 class="card-title">Receive Inbound Inventory</h3>
                    <p class="card-desc">Record raw materials from Procurement or finished goods from Production</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeNewStockInModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Target Warehouse (Locked to Current Warehouse) -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Receiving Warehouse Facility
                    </label>
                    <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 600; color: var(--panel-ink); font-size: 13px;">
                            <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &mdash; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?>
                        </span>
                        <span class="badge" style="background: #DCFCE7; color: #15803D; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Destination Locked</span>
                    </div>
                </div>

                <!-- Inbound Source Type -->
                <div>
                    <label for="modalSourceType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Inbound Source Type <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="source_type" id="modalSourceType" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="onInboundTypeChange(this.value)">
                        <option value="PURCHASE_ORDER">Purchase Order &mdash; Procurement (Raw Materials Only)</option>
                        <option value="PRODUCTION_RETURN">Production Receipt &mdash; Distilling/Packaging (Finished Goods Only)</option>
                        <option value="MANUAL">Manual Inbound &mdash; Inventory Team Adjustment</option>
                    </select>
                </div>

                <!-- Source Reference Number -->
                <div>
                    <label for="modalRefNo" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Source Reference / Tracking # <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="source_reference_no" id="modalRefNo" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" placeholder="e.g. PO-2026-001" required>
                    <small style="color: var(--gray); font-size: 11px;">Unique identifier to prevent duplicate receiving.</small>
                </div>

                <!-- Item Selection -->
                <div>
                    <label for="modalInItem" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Item to Receive <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_id" id="modalInItem" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="updateInboundUnit(this)">
                        <option value="">-- Select Item --</option>
                        <?php foreach ($allItems as $it): ?>
                            <option value="<?= (int)$it['item_id'] ?>" data-type="<?= htmlspecialchars($it['item_type']) ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>">
                                <?= htmlspecialchars($it['item_code']) ?> &mdash; <?= htmlspecialchars($it['item_name']) ?> [<?= $it['item_type'] === 'raw_material' ? 'Raw Material' : 'Finished Good' ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Quantity -->
                <div>
                    <label for="modalInQty" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Quantity Received <span style="color: #DC2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.01" min="0.01" name="quantity" id="modalInQty" class="search-box" style="flex: 1; height: 40px; border-radius: 8px;" placeholder="0.00" required>
                        <span id="inUnitIndicator" style="font-size: 13px; font-weight: 600; color: var(--gray); min-width: 40px;">—</span>
                    </div>
                </div>

                <!-- Remarks -->
                <div>
                    <label for="modalInRemarks" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Remarks / Delivery Notes
                    </label>
                    <textarea name="remarks" id="modalInRemarks" class="search-box" style="width: 100%; border-radius: 8px; height: 50px; padding: 8px 12px;" placeholder="Optional notes (carrier, batch quality, inspection notes)..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewStockInModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
const linesData = <?= json_encode($linesByStockIn) ?>;

function openNewStockInModal() {
    document.getElementById('newStockInModal').classList.add('open');
    onInboundTypeChange(document.getElementById('modalSourceType').value);
}

function closeNewStockInModal() {
    document.getElementById('newStockInModal').classList.remove('open');
}

function onInboundTypeChange(type) {
    const itemSelect = document.getElementById('modalInItem');
    const refInput   = document.getElementById('modalRefNo');
    const options    = itemSelect.querySelectorAll('option');

    if (type === 'PURCHASE_ORDER') {
        refInput.placeholder = 'e.g. PO-2026-001';
    } else if (type === 'PRODUCTION_RETURN') {
        refInput.placeholder = 'e.g. WO-BATCH-2026-001';
    } else {
        refInput.placeholder = 'e.g. MAN-2026-001';
    }

    // Filter items according to business rules
    options.forEach(opt => {
        if (!opt.value) return;
        const itType = opt.getAttribute('data-type');
        if (type === 'PURCHASE_ORDER') {
            opt.hidden = (itType !== 'raw_material');
        } else if (type === 'PRODUCTION_RETURN') {
            opt.hidden = (itType !== 'finished_good');
        } else {
            opt.hidden = false;
        }
    });

    // Reset selection if currently selected option became hidden
    const selectedOpt = itemSelect.options[itemSelect.selectedIndex];
    if (selectedOpt && selectedOpt.hidden) {
        itemSelect.value = '';
        document.getElementById('inUnitIndicator').textContent = '—';
    }
}

function updateInboundUnit(select) {
    const opt = select.options[select.selectedIndex];
    const unit = opt ? opt.getAttribute('data-unit') : '—';
    document.getElementById('inUnitIndicator').textContent = unit ? unit.toUpperCase() : '—';
}

function openDetailModal(id, txnNo) {
    document.getElementById('modalTitle').textContent = 'Receipt ' + txnNo;
    const tbody = document.getElementById('modalLineTableBody');
    tbody.innerHTML = '';

    const lines = linesData[id] || [];
    if (lines.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--gray); padding: 16px;">No line items found.</td></tr>';
    } else {
        lines.forEach(l => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-family: monospace; font-weight: 700;">${escapeHtml(l.item_code)}</td>
                <td><strong>${escapeHtml(l.item_name)}</strong></td>
                <td><span class="badge-type ${l.item_type === 'finished_good' ? 'type-fg' : 'type-raw'}">${escapeHtml(l.item_type.replace('_', ' '))}</span></td>
                <td style="font-weight: 700; color: #15803D;">+${parseFloat(l.quantity).toFixed(2)} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
            `;
            tbody.appendChild(tr);
        });
    }

    const modal = document.getElementById('stockInModal');
    modal.style.display = 'flex';
}

function closeDetailModal() {
    document.getElementById('stockInModal').style.display = 'none';
}

function filterStockInTable() {
    const term = document.getElementById('stockInSearch').value.toLowerCase().trim();
    const typeFilter = document.getElementById('sourceTypeFilter').value;
    const table = document.getElementById('stockInTable');
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
