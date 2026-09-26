<?php
/**
 * View: Stock Movement Master Ledger
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Movements — StockPilot';
$activePage  = 'movement';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Fetch warehouse options
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters
// Filters - strictly enforce assigned warehouse
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$movementType = isset($_GET['movement_type']) ? trim($_GET['movement_type']) : '';
$warehouseId  = $currentWarehouseId;
$itemType     = isset($_GET['item_type']) ? trim($_GET['item_type']) : '';
$rawStartDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$rawEndDate   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$dtStart      = DateTime::createFromFormat('Y-m-d', $rawStartDate);
$dtEnd        = DateTime::createFromFormat('Y-m-d', $rawEndDate);
$startDate    = ($dtStart && $dtStart->format('Y-m-d') === $rawStartDate) ? $rawStartDate : '';
$endDate      = ($dtEnd && $dtEnd->format('Y-m-d') === $rawEndDate) ? $rawEndDate : '';
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

// Build Query matching exact MySQL team_inventory schema strictly for assigned warehouse
$sql = "
    SELECT 
        sm.movement_id,
        sm.movement_type,
        sm.reference_number,
        sm.quantity_in,
        sm.quantity_out,
        sm.balance_after,
        sm.created_at,
        i.item_id,
        i.item_code,
        i.item_name,
        i.item_type,
        i.unit,
        w.warehouse_code,
        w.warehouse_name,
        COALESCE(si.remarks, so.remarks, st.remarks, sa.reason, bp.reason, '') AS notes
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN stock_ins si ON sm.stock_in_id = si.stock_in_id
    LEFT JOIN stock_outs so ON sm.stock_out_id = so.stock_out_id
    LEFT JOIN stock_transfers st ON sm.stock_transfer_id = st.stock_transfer_id
    LEFT JOIN stock_adjustments sa ON sm.stock_adjustment_id = sa.stock_adjustment_id
    LEFT JOIN bad_products bp ON sm.bad_product_id = bp.bad_product_id
    WHERE sm.warehouse_id = ?
";

$params = [$currentWarehouseId];

if ($search !== '') {
    $sql .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR sm.reference_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($movementType !== '') {
    $sql .= " AND sm.movement_type = ?";
    $params[] = $movementType;
}

if ($itemType !== '') {
    $sql .= " AND i.item_type = ?";
    $params[] = $itemType;
}

if ($startDate !== '') {
    $sql .= " AND DATE(sm.created_at) >= ?";
    $params[] = $startDate;
}

if ($endDate !== '') {
    $sql .= " AND DATE(sm.created_at) <= ?";
    $params[] = $endDate;
}

$sql .= " ORDER BY sm.created_at DESC, sm.movement_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics calculation
$totalRecords = count($movements);
$sumQtyIn = 0.0;
$sumQtyOut = 0.0;
foreach ($movements as $m) {
    $sumQtyIn += (float)$m['quantity_in'];
    $sumQtyOut += (float)$m['quantity_out'];
}
$netFlow = $sumQtyIn - $sumQtyOut;
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Stock Movements</h1>
        <p class="page-subtitle">Master transaction ledger for <?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></p>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <!-- Inflow Volume -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Inflow Volume</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <polyline points="19 12 12 19 5 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: #15803D;">+<?= formatQty($sumQtyIn) ?></div>
        <div class="stat-meta">Sum of incoming unit volume</div>
    </div>

    <!-- Outflow Volume -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Outflow Volume</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="19" x2="12" y2="5"/>
                    <polyline points="5 12 12 5 19 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: #B91C1C;">-<?= formatQty($sumQtyOut) ?></div>
        <div class="stat-meta">Sum of outgoing unit volume</div>
    </div>

    <!-- Net Flow Delta -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Net Movement Delta</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--gold); background: #FEF3C7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: <?= $netFlow >= 0 ? '#15803D' : '#B91C1C' ?>;">
            <?= $netFlow >= 0 ? '+' : '' ?><?= formatQty($netFlow) ?>
        </div>
        <div class="stat-meta">Inflow minus outflow balance</div>
    </div>

    <!-- Ledger Count -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Ledger Transactions</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--panel-ink); background: var(--gray-light);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="16" height="20" x="4" y="2" rx="2" ry="2"/>
                    <line x1="8" y1="6" x2="16" y2="6"/>
                    <line x1="8" y1="10" x2="16" y2="10"/>
                    <line x1="8" y1="14" x2="12" y2="14"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalRecords) ?></div>
        <div class="stat-meta">Total immutable audit records</div>
    </div>
</div>

<!-- Ledger Table Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Chronological Movement Records</h2>
            <p class="card-desc">Master double-entry ledger tracking every physical debit and credit across facilities</p>
        </div>
    </div>

    <!-- Filter Controls Toolbar -->
    <form method="GET" action="movement.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; padding-bottom: 18px; border-bottom: 1px solid var(--border);">
        <!-- Search -->
        <div class="search-wrap" style="flex: 1; min-width: 200px;">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" name="search" class="search-box" placeholder="Search item code, name, ref..." value="<?= htmlspecialchars($search) ?>" onkeyup="filterTable('movSearchInput', 'movementTable')" id="movSearchInput">
        </div>

        <!-- Movement Type -->
        <select name="movement_type" class="select-filter">
            <option value="">All Movement Types</option>
            <option value="STOCK_IN" <?= $movementType === 'STOCK_IN' ? 'selected' : '' ?>>Stock In (Procurement/Receipt)</option>
            <option value="STOCK_OUT" <?= $movementType === 'STOCK_OUT' ? 'selected' : '' ?>>Stock Out (Sales/Dispatch)</option>
            <option value="STOCK_TRANSFER_IN" <?= $movementType === 'STOCK_TRANSFER_IN' ? 'selected' : '' ?>>Transfer In</option>
            <option value="STOCK_TRANSFER_OUT" <?= $movementType === 'STOCK_TRANSFER_OUT' ? 'selected' : '' ?>>Transfer Out</option>
            <option value="STOCK_ADJUSTMENT" <?= $movementType === 'STOCK_ADJUSTMENT' ? 'selected' : '' ?>>Stock Adjustment</option>
            <option value="BAD_PRODUCT" <?= $movementType === 'BAD_PRODUCT' ? 'selected' : '' ?>>Bad / Damaged Goods</option>
            <option value="STOCK_IN_CANCEL" <?= $movementType === 'STOCK_IN_CANCEL' ? 'selected' : '' ?>>Stock In Reversal (Cancel)</option>
            <option value="STOCK_OUT_CANCEL" <?= $movementType === 'STOCK_OUT_CANCEL' ? 'selected' : '' ?>>Stock Out Reversal (Cancel)</option>
            <option value="STOCK_TRANSFER_CANCEL" <?= $movementType === 'STOCK_TRANSFER_CANCEL' ? 'selected' : '' ?>>Transfer Reversal (Cancel)</option>
        </select>

        <!-- Assigned Warehouse Indicator -->
        <div class="wh-badge" style="margin: 0; background: var(--gray-light); border: 1px solid var(--border); color: var(--panel-ink); font-weight: 600; padding: 7px 12px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; height: 38px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
            </svg>
            <span><?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?> &middot; <?= htmlspecialchars($assignedWarehouse['warehouse_name']) ?></span>
        </div>

        <!-- Classification -->
        <select name="item_type" class="select-filter">
            <option value="">All Classifications</option>
            <option value="raw_material" <?= $itemType === 'raw_material' ? 'selected' : '' ?>>Raw Materials</option>
            <option value="finished_good" <?= $itemType === 'finished_good' ? 'selected' : '' ?>>Finished Goods</option>
        </select>

        <!-- Actions -->
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="height: 38px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
                <span>Filter</span>
            </button>
            <a href="movement.php" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" title="Reset Filters">
                <span>Reset</span>
            </a>
        </div>
    </form>

    <div class="table-responsive">
        <table id="movementTable">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Reference #</th>
                    <th>Product / Item</th>
                    <th>Classification</th>
                    <th>Facility</th>
                    <th>Movement Type</th>
                    <th style="text-align: right;">Qty In (+)</th>
                    <th style="text-align: right;">Qty Out (-)</th>
                    <th style="text-align: right;">Balance After</th>
                    <th>Audit Context</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--gray); padding: 36px;">
                            No stock movement transactions recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): 
                        $type = $m['movement_type'];
                        $isIncoming = (float)$m['quantity_in'] > 0;
                        $isTransfer = strpos($type, 'TRANSFER') !== false;
                        $isAdj = strpos($type, 'ADJUSTMENT') !== false;
                        $isBad = strpos($type, 'BAD') !== false;
                        $isCancel = strpos($type, 'CANCEL') !== false;

                        $pillClass = 'mov-in';
                        if ($isCancel) {
                            $pillClass = 'status-cancelled';
                        } elseif ($isBad) {
                            $pillClass = 'mov-out';
                        } elseif ($isAdj) {
                            $pillClass = 'mov-adj';
                        } elseif ($isTransfer) {
                            $pillClass = 'mov-transfer';
                        } elseif (!$isIncoming) {
                            $pillClass = 'mov-out';
                        }
                    ?>
                        <tr>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y H:i', strtotime($m['created_at'])) ?>
                            </td>
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($m['reference_number'] ?: '—') ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($m['item_name']) ?></strong>
                                <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($m['item_code']) ?></div>
                            </td>
                            <td>
                                <span class="badge-type <?= $m['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $m['item_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-wh <?= getWarehouseBadgeClass($m['warehouse_code']) ?>">
                                    <?= htmlspecialchars($m['warehouse_code']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $pillClass ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $type)) ?>
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700;">
                                <?php if ((float)$m['quantity_in'] > 0): ?>
                                    <span style="color: #15803D;">+<?= formatQty((float)$m['quantity_in']) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--gray-light);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: 700;">
                                <?php if ((float)$m['quantity_out'] > 0): ?>
                                    <span style="color: #B91C1C;">-<?= formatQty((float)$m['quantity_out']) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--gray-light);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: var(--panel-ink);">
                                <?= formatQty((float)$m['balance_after']) ?>
                                <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($m['unit']) ?></small>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); max-width: 220px;" title="<?= htmlspecialchars($m['notes']) ?>">
                                <?= htmlspecialchars($m['notes'] ?: 'Standard ledger record') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportMovementCsv() {
    const table = document.getElementById('movementTable');
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
            text = text.replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        if (row.length > 0 && !row[0].includes('No stock movement')) {
            csv.push(row.join(','));
        }
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'stock_movements_' + new Date().toISOString().slice(0, 10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
