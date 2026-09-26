<?php

/**
 * View: Stock Out Dispatch Ledger
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Out Dispatch — StockPilot';
$activePage  = 'stock_out';
$activeGroup = 'inventory';

// Forward browser navigation to the unified Inbound & Outbound page
if (php_sapi_name() !== 'cli' && (!defined('IN_UNIT_TEST') || !IN_UNIT_TEST)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['stay_on_legacy'])) {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
        $projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
        header("Location: {$projectRoot}/views/inbound_outbound/index.php?tab=outbound");
        exit;
    }
}

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
        $rawQuantity       = $_POST['quantity'] ?? null;
        $remarks           = trim($_POST['remarks'] ?? '');
        $sourceWhId        = (int)($currentWarehouseId ?: 1);
        $userId            = (int)($currentUser['id'] ?? 1);

        if (!in_array($sourceType, StockService::VALID_STOCK_OUT_SOURCES, true)) {
            $errorMessage = "Invalid source_type '{$sourceType}'.";
        } elseif (empty($sourceReferenceNo)) {
            $errorMessage = "Reference Number (Sales Order # or Material Request #) is required.";
        } elseif (mb_strlen($sourceReferenceNo) > 100) {
            $errorMessage = "Reference Number cannot exceed 100 characters.";
        } elseif ($itemId <= 0) {
            $errorMessage = "Please select an item to dispatch.";
        } else {
            try {
                $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity dispatched');
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
        <h1 class="page-title">Outbound / Stock Out</h1>
        <p class="page-subtitle">Inventory releases requested by Production and Sales &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH-MAIN') ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Main Warehouse') ?></p>
    </div>
</div>

<!-- Flash Alerts -->
<?php if ($successMessage): ?>
    <div style="background: var(--success-light); border: 1px solid var(--success-border); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
            <polyline points="22 4 12 14.01 9 11.01" />
        </svg>
        <span><?= htmlspecialchars($successMessage) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div style="background: var(--error-light); border: 1px solid var(--error-border); color: var(--error); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
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

    .api-workflow-icon-out {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #B91C1C;
        background: #FEE2E2;
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

    .badge-dest-production {
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

    .badge-dest-sales {
        background: #EFF6FF;
        color: #1D4ED8;
        border: 1px solid #BFDBFE;
        font-weight: 700;
        font-size: 11.5px;
        padding: 3px 9px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .badge-dest-manual {
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
            <span class="stat-label">Total Outbound</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="7" y1="17" x2="17" y2="7" />
                    <polyline points="7 7 17 7 17 17" />
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalStockOutTxns ?></div>
        <div class="stat-meta">Completed outbound dispatches</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Sales (Finished Goods)</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="13" x="1" y="5" rx="2" />
                    <polygon points="17 8 20 8 23 11 23 18 17 18 17 8" />
                    <circle cx="5.5" cy="18.5" r="2.5" />
                    <circle cx="18.5" cy="18.5" r="2.5" />
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= $salesDispatches ?></div>
        <div class="stat-meta">Customer orders fulfilled &amp; dispatched</div>
    </div>

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Production (Raw Materials)</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2" />
                    <polyline points="2 17 12 22 22 17" />
                    <polyline points="2 12 12 17 22 12" />
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
            <h2 class="card-title">Outbound / Stock Out Dispatches</h2>
        </div>
        <div class="filter-group">
            <!-- Search Filter -->
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                </span>
                <input type="text" id="stockOutSearch" class="search-box" placeholder="Filter item, ref, SO/MR #..." onkeyup="filterStockOutTable()">
            </div>

            <!-- Destination Filter -->
            <select id="stockOutDestFilter" class="select-filter" onchange="filterStockOutTable()">
                <option value="">All Destinations</option>
                <option value="SALES_DELIVERY">Sales (Sales Deliveries)</option>
                <option value="MATERIAL_REQUEST">Production (Material Requests)</option>
                <option value="MANUAL">Internal / Manual</option>
            </select>

            <!-- Item Type Filter -->
            <select id="stockOutTypeFilter" class="select-filter" onchange="filterStockOutTable()">
                <option value="">All Classifications</option>
                <option value="finished_good">Finished Goods</option>
                <option value="raw_material">Raw Materials</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table id="stockOutTable">
            <thead>
                <tr>
                    <th>Destination</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Reference / Order #</th>
                    <th>Date Dispatched</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockOuts)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 40px;">
                            No outbound Stock Out transactions recorded for this warehouse yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stockOuts as $row):
                        $lines = $linesByStockOut[(int)$row['stock_out_id']] ?? [];
                        $firstLine = $lines[0] ?? null;
                        $hasMultiple = count($lines) > 1;

                        // Derive item classification
                        $itemType = 'finished_good';
                        if ($row['source_type'] === 'MATERIAL_REQUEST') {
                            $itemType = 'raw_material';
                        } elseif (!empty($lines)) {
                            $types = array_unique(array_column($lines, 'item_type'));
                            $itemType = count($types) === 1 ? $types[0] : 'mixed';
                        }
                    ?>
                        <tr data-destination="<?= htmlspecialchars($row['source_type']) ?>" data-type="<?= htmlspecialchars($itemType) ?>">
                            <!-- Destination -->
                            <td>
                                <?php if ($row['source_type'] === 'SALES_DELIVERY'): ?>
                                    <span class="badge-dest-sales">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <rect width="16" height="13" x="1" y="5" rx="2" />
                                            <polygon points="17 8 20 8 23 11 23 18 17 18 17 8" />
                                            <circle cx="5.5" cy="18.5" r="2.5" />
                                            <circle cx="18.5" cy="18.5" r="2.5" />
                                        </svg>
                                        Sales
                                    </span>
                                <?php elseif ($row['source_type'] === 'MATERIAL_REQUEST'): ?>
                                    <span class="badge-dest-production">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <polygon points="12 2 2 7 12 12 22 7 12 2" />
                                            <polyline points="2 17 12 22 22 17" />
                                            <polyline points="2 12 12 17 22 12" />
                                        </svg>
                                        Production
                                    </span>
                                <?php else: ?>
                                    <span class="badge-dest-manual">
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
                                    <div style="font-weight: 700; color: var(--panel-ink);"><?= count($lines) ?> items dispatched</div>
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
                            <td style="font-weight: 700; color: #B91C1C; white-space: nowrap;">
                                -<?= formatQty((float)$row['total_quantity']) ?>
                                <?php if (!$hasMultiple && $firstLine): ?>
                                    <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($firstLine['unit']) ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Reference / Order # -->
                            <td>
                                <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--panel-ink);">
                                    <?= htmlspecialchars($row['source_reference_no'] ?: '—') ?>
                                </div>
                                <small style="font-family: monospace; color: var(--gray); font-size: 11px;">
                                    <?= htmlspecialchars($row['transaction_number']) ?>
                                </small>
                            </td>

                            <!-- Date Dispatched -->
                            <td style="font-size: 12.5px; white-space: nowrap;">
                                <?= date('M d, Y', strtotime($row['transaction_date'])) ?>
                                <small style="display: block; color: var(--gray); font-size: 11px;">
                                    <?= date('h:i A', strtotime($row['created_at'])) ?>
                                </small>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php if ($row['status'] === 'completed'): ?>
                                    <span class="badge status-completed">Dispatched</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled"><?= ucfirst($row['status']) ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Action -->
                            <td style="text-align: right;">
                                <button type="button" class="btn btn-secondary" style="height: 30px; padding: 0 11px; font-size: 11.5px;" onclick="openOutDetailModal(<?= (int)$row['stock_out_id'] ?>, '<?= htmlspecialchars($row['transaction_number'], ENT_QUOTES) ?>')">
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
<div id="stockOutModal" class="modal-backdrop" onclick="if(event.target === this) closeOutDetailModal()">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 id="modalOutTitle" class="card-title">Dispatch Details</h3>
                <p id="modalOutSub" class="card-desc">Outbound line items released from inventory</p>
            </div>
            <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeOutDetailModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
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
        document.getElementById('modalOutTitle').textContent = 'Dispatch: ' + txnNo;
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
                <td style="font-weight: 700; color: #B91C1C;">-${Number(parseFloat(l.quantity).toFixed(2))} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
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
        const destFilter = document.getElementById('stockOutDestFilter').value;
        const typeFilter = document.getElementById('stockOutTypeFilter').value;
        const table = document.getElementById('stockOutTable');
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            const text = row.textContent.toLowerCase();
            const rowDest = row.getAttribute('data-destination') || '';
            const rowType = row.getAttribute('data-type') || '';

            const matchesText = text.includes(term);
            const matchesDest = !destFilter || rowDest === destFilter;
            const matchesType = !typeFilter || rowType === typeFilter;

            if (matchesText && matchesDest && matchesType) {
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
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [m];
        });
    }
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>