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
        <h1 class="page-title">Inbound / Stock In</h1>
        <p class="page-subtitle">Transactions received from Procurement and Production &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH-MAIN') ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Main Warehouse') ?></p>
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

<style>
.api-workflow-banner {
    background: #FFFFFF;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    margin-bottom: 22px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.api-workflow-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #15803D;
    background: #DCFCE7;
}
.api-tag-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #F8FAFC;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 5px 11px;
    font-size: 12px;
    color: var(--panel-ink);
}
.badge-source-procurement {
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid #FDE68A;
    font-weight: 700;
    font-size: 11.5px;
    padding: 3px 9px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.badge-source-production {
    background: #E0F2FE;
    color: #0369A1;
    border: 1px solid #BAE6FD;
    font-weight: 700;
    font-size: 11.5px;
    padding: 3px 9px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.badge-source-manual {
    background: #F1F5F9;
    color: #475569;
    border: 1px solid #CBD5E1;
    font-weight: 700;
    font-size: 11.5px;
    padding: 3px 9px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
</style>
<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Inbound</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="17" y1="7" x2="7" y2="17"/>
                    <polyline points="17 17 7 17 7 7"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalStockInTxns ?></div>
        <div class="stat-meta">Completed inbound receipts</div>
    </div>

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Procurement (Raw Materials)</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $procurementInbounds ?></div>
        <div class="stat-meta">Inbound raw ingredients received via PO</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Production (Finished Goods)</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--accent); background: var(--accent-light);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m16 16 2 2 4-4"/>
                    <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $productionInbounds ?></div>
        <div class="stat-meta">Distilled / packaged bottles received</div>
    </div>
</div>

<!-- Inbound Transaction Ledger Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Inbound / Stock In Transactions</h2>
            <p class="card-desc">Real-time log of stock received through Procurement and Production API integrations</p>
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
                <input type="text" id="stockInSearch" class="search-box" placeholder="Filter item, ref, PO #..." onkeyup="filterStockInTable()">
            </div>

            <!-- Source Filter -->
            <select id="sourceTypeFilter" class="select-filter" onchange="filterStockInTable()">
                <option value="">All Sources</option>
                <option value="PURCHASE_ORDER">Procurement (Purchase Orders)</option>
                <option value="PRODUCTION_RETURN">Production (Work Orders)</option>
                <option value="MANUAL">Internal / Adjustment</option>
            </select>

            <!-- Item Type Filter -->
            <select id="itemTypeFilter" class="select-filter" onchange="filterStockInTable()">
                <option value="">All Classifications</option>
                <option value="raw_material">Raw Materials</option>
                <option value="finished_good">Finished Goods</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table id="stockInTable">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Reference / Tracking #</th>
                    <th>Date Received</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockIns)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 40px;">
                            No inbound Stock In transactions recorded for this warehouse yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stockIns as $row): 
                        $lines = $linesByStockIn[(int)$row['stock_in_id']] ?? [];
                        $firstLine = $lines[0] ?? null;
                        $hasMultiple = count($lines) > 1;

                        // Derive item classification
                        $itemType = 'raw_material';
                        if ($row['source_type'] === 'PRODUCTION_RETURN') {
                            $itemType = 'finished_good';
                        } elseif (!empty($lines)) {
                            $types = array_unique(array_column($lines, 'item_type'));
                            $itemType = count($types) === 1 ? $types[0] : 'mixed';
                        }
                    ?>
                        <tr data-source="<?= htmlspecialchars($row['source_type']) ?>" data-type="<?= htmlspecialchars($itemType) ?>">
                            <!-- Source -->
                            <td>
                                <?php if ($row['source_type'] === 'PURCHASE_ORDER'): ?>
                                    <span class="badge-source-procurement">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                                        Procurement
                                    </span>
                                <?php elseif ($row['source_type'] === 'PRODUCTION_RETURN'): ?>
                                    <span class="badge-source-production">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m16 16 2 2 4-4"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/></svg>
                                        Production
                                    </span>
                                <?php else: ?>
                                    <span class="badge-source-manual">
                                        Internal
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Item -->
                            <td style="max-width: 260px;">
                                <?php if (!$hasMultiple && $firstLine): ?>
                                    <div style="font-weight: 700; color: var(--panel-ink);"><?= htmlspecialchars($firstLine['item_name']) ?></div>
                                    <small style="font-family: monospace; color: var(--gray); font-size: 11.5px;"><?= htmlspecialchars($firstLine['item_code']) ?></small>
                                <?php elseif ($hasMultiple): ?>
                                    <div style="font-weight: 700; color: var(--panel-ink);"><?= count($lines) ?> items received</div>
                                    <small style="color: var(--gray); font-size: 11.5px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['item_breakdown'] ?: '') ?>">
                                        <?= htmlspecialchars($row['item_breakdown'] ?: 'Multiple items') ?>
                                    </small>
                                <?php else: ?>
                                    <span style="color: var(--gray); font-style: italic;">No items specified</span>
                                <?php endif; ?>
                            </td>

                            <!-- Type -->
                            <td>
                                <?php if ($itemType === 'finished_good'): ?>
                                    <span class="badge-type type-fg">Finished Good</span>
                                <?php elseif ($itemType === 'raw_material'): ?>
                                    <span class="badge-type type-raw">Raw Material</span>
                                <?php else: ?>
                                    <span class="badge-type type-raw">Mixed (<?= count($lines) ?>)</span>
                                <?php endif; ?>
                            </td>

                            <!-- Quantity -->
                            <td style="font-weight: 700; color: #15803D; white-space: nowrap;">
                                +<?= number_format((float)$row['total_quantity'], 1) ?>
                                <?php if (!$hasMultiple && $firstLine): ?>
                                    <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($firstLine['unit']) ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Reference / Tracking # -->
                            <td>
                                <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                                </div>
                                <small style="font-family: monospace; color: var(--gray); font-size: 11px;">
                                    <?= htmlspecialchars($row['transaction_number']) ?>
                                </small>
                            </td>

                            <!-- Date Received -->
                            <td style="font-size: 12.5px; white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                                <small style="display: block; color: var(--gray); font-size: 11px;">
                                    <?= date('h:i A', strtotime($row['created_at'])) ?>
                                </small>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Received</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Action -->
                            <td style="text-align: right;">
                                <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 11px; font-size: 11.5px;" onclick="openDetailModal(<?= (int)$row['stock_in_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
                                    View Details
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

<script>
const linesData = <?= json_encode($linesByStockIn) ?>;

function openDetailModal(id, txnNo) {
    document.getElementById('modalTitle').textContent = 'Inbound Receipt: ' + txnNo;
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
    const sourceFilter = document.getElementById('sourceTypeFilter').value;
    const typeFilter = document.getElementById('itemTypeFilter').value;
    const table = document.getElementById('stockInTable');
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const rowSource = row.getAttribute('data-source') || '';
        const rowType = row.getAttribute('data-type') || '';

        const matchesText = text.includes(term);
        const matchesSource = !sourceFilter || rowSource === sourceFilter;
        const matchesType = !typeFilter || rowType === typeFilter;

        if (matchesText && matchesSource && matchesType) {
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

