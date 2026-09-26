<?php
/**
 * View: Item Stock Card Ledger
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Stock Card — StockPilot';
$activePage  = 'stock_card';
$activeGroup = 'inventory';

// Forward browser navigation to the unified Stock Operations hub
if (php_sapi_name() !== 'cli' && (!defined('IN_UNIT_TEST') || !IN_UNIT_TEST)) {
    if (!isset($_GET['stay_on_legacy'])) {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
        $projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
        $qs = !empty($_SERVER['QUERY_STRING']) ? ('&' . $_SERVER['QUERY_STRING']) : '';
        header("Location: {$projectRoot}/views/stock_operations/index.php?tab=stock_card{$qs}");
        exit;
    }
}

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
$rawStartDate   = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$rawEndDate     = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$dtStart        = DateTime::createFromFormat('Y-m-d', $rawStartDate);
$dtEnd          = DateTime::createFromFormat('Y-m-d', $rawEndDate);
$startDate      = ($dtStart && $dtStart->format('Y-m-d') === $rawStartDate) ? $rawStartDate : '';
$endDate        = ($dtEnd && $dtEnd->format('Y-m-d') === $rawEndDate) ? $rawEndDate : '';
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

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
</div>

<style>
.searchable-select-wrap {
    position: relative;
    width: 100%;
}
.searchable-dropdown-list {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 280px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15);
    z-index: 1050;
    padding: 4px 0;
}
.searchable-dropdown-list .item-result-row {
    padding: 9px 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #F1F5F9;
    transition: background 0.12s ease;
}
.searchable-dropdown-list .item-result-row:hover,
.searchable-dropdown-list .item-result-row.highlighted {
    background-color: #F8FAFC;
}
.searchable-dropdown-list .item-result-row.selected {
    background-color: #EFF6FF;
    border-left: 3px solid #2563EB;
}
.searchable-dropdown-list .item-result-row:last-child {
    border-bottom: none;
}
.search-match-highlight {
    background-color: #FEF08A;
    color: #854D0E;
    font-weight: 700;
    border-radius: 2px;
    padding: 0 1px;
}
</style>

<!-- Interactive Item & Filter Toolbar -->
<div class="card" style="padding: 18px 22px;">
    <form method="GET" action="stock_card.php" id="stockCardForm" style="display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;">
        <!-- Searchable Item Autocomplete Selector -->
        <div style="display: flex; flex-direction: column; gap: 4px; min-width: 280px; flex: 1; position: relative;">
            <label for="itemSearchInput" style="font-size: 12px; font-weight: 700; color: var(--panel-ink);">Select Inventory Item:</label>
            <input type="hidden" name="item_id" id="selectedItemId" value="<?= (int)$selectedItemId ?>">
            
            <div id="itemSearchWrapper" class="searchable-select-wrap">
                <div style="position: relative; display: flex; align-items: center;">
                    <input type="text" 
                           id="itemSearchInput" 
                           class="select-filter" 
                           style="width: 100%; font-weight: 600; padding-right: 32px; height: 38px; cursor: text;" 
                           placeholder="Type item name or ID... 🔍" 
                           value="<?= $selectedItem ? htmlspecialchars($selectedItem['item_name'] . ' — ' . $selectedItem['item_code']) : '' ?>" 
                           autocomplete="off"
                           onfocus="openItemDropdown()"
                           oninput="filterItemDropdown(this.value)"
                           onkeydown="handleItemDropdownKeydown(event)">
                    <button type="button" id="clearItemSearchBtn" onclick="clearItemSearch()" style="position: absolute; right: 8px; background: none; border: none; cursor: pointer; color: var(--gray); font-size: 16px; display: <?= $selectedItem ? 'inline-block' : 'none' ?>; line-height: 1; padding: 2px;" title="Clear search">&times;</button>
                </div>

                <!-- Dropdown Search Results Container -->
                <div id="itemDropdownList" class="searchable-dropdown-list"></div>
            </div>
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
                Standard Inventory Unit: <strong><?= htmlspecialchars($selectedItem['unit']) ?></strong> &middot; Reorder Threshold: <?= formatQty($selectedItem['default_reorder_level']) ?> <?= htmlspecialchars($selectedItem['unit']) ?>
            </p>
        </div>

        <!-- Current Stock Big Badge -->
        <div style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: var(--radius-lg); padding: 14px 22px; text-align: right;">
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #5EEAD4;">
                Current Balance on Hand
            </div>
            <div style="font-family: var(--font-display); font-size: 28px; font-weight: 800; color: #ffffff;">
                <?= formatQty($currentStock) ?> <small style="font-size: 14px; font-weight: 500; color: #E2E8F0;"><?= htmlspecialchars($selectedItem['unit']) ?></small>
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
                                <?= $qtyIn > 0 ? ('+' . formatQty($qtyIn)) : '—' ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #B91C1C;">
                                <?= $qtyOut > 0 ? ('-' . formatQty($qtyOut)) : '—' ?>
                            </td>
                            <td style="text-align: right; font-weight: 800; font-size: 14px; color: var(--panel-ink); background: #F8FAFC;">
                                <?= formatQty($m['balance_after'] ?? 0) ?>
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

<script>
const allInventoryItems = <?= json_encode(array_map(function($it) {
    return [
        'id'   => (int)$it['item_id'],
        'code' => (string)$it['item_code'],
        'name' => (string)$it['item_name'],
        'type' => (string)$it['item_type'],
        'unit' => (string)($it['unit'] ?? '')
    ];
}, $items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let currentHighlightedIndex = -1;
let currentFilteredItems = [];

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function highlightMatches(text, query) {
    if (!query || !text) return escapeHtml(text || '');
    const escapedText = escapeHtml(text);
    const escapedQuery = escapeHtml(query);
    const regex = new RegExp('(' + escapedQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return escapedText.replace(regex, '<span class="search-match-highlight">$1</span>');
}

function renderItemDropdown(matches, query) {
    const list = document.getElementById('itemDropdownList');
    currentFilteredItems = matches;
    currentHighlightedIndex = -1;

    if (!matches || matches.length === 0) {
        list.innerHTML = '<div style="padding: 14px 16px; text-align: center; color: var(--gray); font-size: 13px; font-weight: 600;">No inventory items found.</div>';
        list.style.display = 'block';
        return;
    }

    const selectedId = parseInt(document.getElementById('selectedItemId').value, 10);

    let html = '';
    matches.forEach((item, index) => {
        const isSelected = (item.id === selectedId);
        const typeBadge = item.type === 'finished_good' 
            ? '<span class="badge-type type-fg" style="font-size: 10px; padding: 2px 6px;">Finished</span>'
            : '<span class="badge-type type-raw" style="font-size: 10px; padding: 2px 6px;">Raw</span>';

        html += `
            <div class="item-result-row ${isSelected ? 'selected' : ''}" 
                 id="item-opt-${index}"
                 data-index="${index}"
                 onclick="selectInventoryItem(${item.id})">
                <div style="display: flex; flex-direction: column;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-weight: 700; color: var(--panel-ink); font-size: 13px;">${highlightMatches(item.name, query)}</span>
                        <span style="color: var(--gray); font-size: 12px;">—</span>
                        <span style="font-family: monospace; font-size: 12px; font-weight: 600; color: #475569;">${highlightMatches(item.code, query)}</span>
                    </div>
                    <small style="color: var(--gray); font-size: 11px;">Item ID: ${item.id} ${item.unit ? '&middot; Unit: ' + escapeHtml(item.unit) : ''}</small>
                </div>
                ${typeBadge}
            </div>
        `;
    });

    list.innerHTML = html;
    list.style.display = 'block';
}

function filterItemDropdown(query) {
    const q = (query || '').trim().toLowerCase();
    const clearBtn = document.getElementById('clearItemSearchBtn');
    if (clearBtn) {
        clearBtn.style.display = (query && query.length > 0) ? 'inline-block' : 'none';
    }

    if (!q) {
        renderItemDropdown(allInventoryItems, '');
        return;
    }

    const matches = allInventoryItems.filter(item => {
        const nameMatch = (item.name || '').toLowerCase().includes(q);
        const codeMatch = (item.code || '').toLowerCase().includes(q);
        const idMatch   = String(item.id).includes(q) || ('rm-' + item.id).includes(q) || ('fg-' + item.id).includes(q);
        return nameMatch || codeMatch || idMatch;
    });

    renderItemDropdown(matches, query);
}

function openItemDropdown() {
    const input = document.getElementById('itemSearchInput');
    input.select();
    filterItemDropdown('');
}

function closeItemDropdown() {
    const list = document.getElementById('itemDropdownList');
    if (list) {
        list.style.display = 'none';
    }
    currentHighlightedIndex = -1;
}

function selectInventoryItem(itemId) {
    const item = allInventoryItems.find(it => it.id === itemId);
    if (!item) return;

    document.getElementById('selectedItemId').value = item.id;
    document.getElementById('itemSearchInput').value = item.name + ' — ' + item.code;
    const clearBtn = document.getElementById('clearItemSearchBtn');
    if (clearBtn) clearBtn.style.display = 'inline-block';
    closeItemDropdown();

    document.getElementById('stockCardForm').submit();
}

function clearItemSearch() {
    const input = document.getElementById('itemSearchInput');
    input.value = '';
    const clearBtn = document.getElementById('clearItemSearchBtn');
    if (clearBtn) clearBtn.style.display = 'none';
    input.focus();
    filterItemDropdown('');
}

function restoreSelectedItemDisplay() {
    const selectedId = parseInt(document.getElementById('selectedItemId').value, 10);
    const item = allInventoryItems.find(it => it.id === selectedId);
    if (item) {
        document.getElementById('itemSearchInput').value = item.name + ' — ' + item.code;
        const clearBtn = document.getElementById('clearItemSearchBtn');
        if (clearBtn) clearBtn.style.display = 'inline-block';
    }
}

function handleItemDropdownKeydown(e) {
    const list = document.getElementById('itemDropdownList');
    const isOpen = (list && list.style.display === 'block');

    if (!isOpen) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            openItemDropdown();
            e.preventDefault();
        }
        return;
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (currentFilteredItems.length === 0) return;
        currentHighlightedIndex = (currentHighlightedIndex + 1) % currentFilteredItems.length;
        updateHighlightedRow();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (currentFilteredItems.length === 0) return;
        currentHighlightedIndex = (currentHighlightedIndex - 1 + currentFilteredItems.length) % currentFilteredItems.length;
        updateHighlightedRow();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (currentHighlightedIndex >= 0 && currentHighlightedIndex < currentFilteredItems.length) {
            selectInventoryItem(currentFilteredItems[currentHighlightedIndex].id);
        } else if (currentFilteredItems.length === 1) {
            selectInventoryItem(currentFilteredItems[0].id);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        closeItemDropdown();
        restoreSelectedItemDisplay();
    }
}

function updateHighlightedRow() {
    document.querySelectorAll('.searchable-dropdown-list .item-result-row').forEach((row, idx) => {
        if (idx === currentHighlightedIndex) {
            row.classList.add('highlighted');
            row.scrollIntoView({ block: 'nearest' });
        } else {
            row.classList.remove('highlighted');
        }
    });
}

// Click outside listener to dismiss dropdown
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('itemSearchWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        const list = document.getElementById('itemDropdownList');
        if (list && list.style.display === 'block') {
            closeItemDropdown();
            restoreSelectedItemDisplay();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
