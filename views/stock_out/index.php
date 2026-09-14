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
    </div>
</div>

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

<script>
const outLinesData = <?= json_encode($linesByStockOut) ?>;

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
