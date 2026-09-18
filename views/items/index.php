<?php
/**
 * View: Master Item Catalog & SKU Management
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Item Catalog & SKUs — StockPilot';
$activePage  = 'items';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$successMessage = null;
$errorMessage   = null;

// Handle New Item Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_item') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $itemCode     = strtoupper(trim($_POST['item_code'] ?? ''));
        $itemName     = trim($_POST['item_name'] ?? '');
    $itemType     = trim($_POST['item_type'] ?? '');
    $categoryId   = (int)($_POST['category_id'] ?? 0);
    $unit         = strtolower(trim($_POST['unit'] ?? 'pcs'));
    $reorderLevel = (float)($_POST['default_reorder_level'] ?? 0);
    $description  = trim($_POST['description'] ?? '');

    if (empty($itemCode)) {
        $errorMessage = "Item Code (SKU) is required.";
    } elseif (empty($itemName)) {
        $errorMessage = "Item Name is required.";
    } elseif (!in_array($itemType, ['raw_material', 'finished_good'], true)) {
        $errorMessage = "Item classification must be either 'Raw Material' or 'Finished Good'.";
    } elseif ($categoryId <= 0) {
        $errorMessage = "Please select a valid category.";
    } elseif (empty($unit)) {
        $errorMessage = "Unit of measurement is required.";
    } elseif ($reorderLevel < 0) {
        $errorMessage = "Default reorder level cannot be negative.";
    } else {
        try {
            // Check uniqueness of item code
            $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM items WHERE item_code = ?");
            $stmtChk->execute([$itemCode]);
            if ((int)$stmtChk->fetchColumn() > 0) {
                $errorMessage = "An item with SKU code '{$itemCode}' already exists.";
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO items (
                        item_code, item_name, description, item_type,
                        category_id, unit, default_reorder_level, status
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?, 'active'
                    )
                ");
                $stmtIns->execute([
                    $itemCode,
                    $itemName,
                    $description ?: null,
                    $itemType,
                    $categoryId,
                    $unit,
                    $reorderLevel
                ]);
                $successMessage = "Master item '{$itemName}' ({$itemCode}) created successfully!";
            }
        } catch (Exception $e) {
            $errorMessage = "Failed to create item: " . $e->getMessage();
        }
    }
    }
}

// Fetch all categories for dropdown
$categories = $pdo->query("SELECT category_id, category_code, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch items list
$stmtItems = $pdo->query("
    SELECT 
        i.item_id,
        i.item_code,
        i.item_name,
        i.description,
        i.item_type,
        i.unit,
        i.default_reorder_level,
        i.status,
        i.created_at,
        c.category_name,
        c.category_code
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.category_id
    ORDER BY i.item_type ASC, i.item_name ASC
");
$itemsList = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// KPI metrics
$totalSKUs    = count($itemsList);
$rawSKUs      = count(array_filter($itemsList, fn($x) => $x['item_type'] === 'raw_material'));
$finishedSKUs = count(array_filter($itemsList, fn($x) => $x['item_type'] === 'finished_good'));
$catCount     = count($categories);
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Item Catalog</h1>
        <p class="page-subtitle">Master product definitions for raw distilling ingredients and packaged finished goods</p>
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

<!-- KPI Metrics Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Registered </span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalSKUs ?></div>
        <div class="stat-meta">Across all categories</div>
    </div>

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Raw Material</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            </div>
        </div>
        <div class="stat-value"><?= $rawSKUs ?></div>
        <div class="stat-meta">Inbounded only via Procurement</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Finished Goods</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #15803D; background: #DCFCE7;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>
            </div>
        </div>
        <div class="stat-value"><?= $finishedSKUs ?></div>
        <div class="stat-meta">Produced &amp; dispatched to Sales</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Active Taxonomies</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #6D28D9; background: #F5F3FF;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>
            </div>
        </div>
        <div class="stat-value"><?= $catCount ?></div>
        <div class="stat-meta">System categories</div>
    </div>
</div>

<!-- Main Items Table Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Catalog Inventory Index</h2>
            <p class="card-desc">All system materials and products. Each item has a strict single classification.</p>
        </div>
        <div class="filter-group">
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <input type="text" id="itemSearch" class="search-box" placeholder="Filter code, name, unit..." onkeyup="filterItemsTable()">
            </div>
            <select id="typeFilter" class="select-filter" onchange="filterItemsTable()">
                <option value="">All Classifications</option>
                <option value="raw_material">Raw Materials</option>
                <option value="finished_good">Finished Goods</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table id="itemsTable">
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Item Code (SKU)</th>
                    <th>Item Name</th>
                    <th>Classification</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th>Default Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($itemsList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--gray); padding: 36px;">No catalog items found. Click "+ Add New Item" above to create your first SKU.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($itemsList as $row): ?>
                        <tr data-type="<?= htmlspecialchars($row['item_type']) ?>">
                            <td style="font-family: monospace; color: var(--gray); font-weight: 600;">
                                #<?= (int)$row['item_id'] ?>
                            </td>
                            <td style="font-family: monospace; font-weight: 700; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['item_code']) ?>
                            </td>
                            <td style="font-weight: 600;">
                                <?= htmlspecialchars($row['item_name']) ?>
                                <?php if (!empty($row['description'])): ?>
                                    <div style="font-size: 11.5px; color: var(--gray); font-weight: normal;"><?= htmlspecialchars($row['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['item_type'] === 'raw_material'): ?>
                                    <span class="badge" style="background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;">
                                        Raw Material
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0;">
                                        Finished Good
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($row['category_name'] ?: 'General') ?>
                            </td>
                            <td style="font-weight: 600; text-transform: uppercase; font-size: 12px; color: #475569;">
                                <?= htmlspecialchars($row['unit']) ?>
                            </td>
                            <td style="font-family: monospace; font-weight: 600;">
                                <?= number_format((float)$row['default_reorder_level'], 1) ?> <?= htmlspecialchars($row['unit']) ?>
                            </td>
                            <td>
                                <span class="badge status-completed">Active</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Item -->
<div id="newItemModal" class="modal-backdrop" onclick="if(event.target === this) closeNewItemModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_item">
            <div class="modal-header">
                <div>
                    <h3 class="card-title">Add New Catalog Item</h3>
                    <p class="card-desc">Define a new raw material ingredient or finished bottle SKU</p>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 32px; width: 32px; padding: 0;" onclick="closeNewItemModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Classification -->
                <div>
                    <label for="modalItemType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Classification <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_type" id="modalItemType" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required onchange="autoSuggestCodePrefix(this.value)">
                        <option value="raw_material">Raw Material (Procurement Inbound / Production Request)</option>
                        <option value="finished_good">Finished Good (Production Receipt / Sales Delivery)</option>
                    </select>
                </div>

                <!-- Item Code & Name -->
                <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 12px;">
                    <div>
                        <label for="modalItemCode" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Item Code (SKU) <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="item_code" id="modalItemCode" class="search-box" style="width: 100%; height: 40px; border-radius: 8px; text-transform: uppercase;" placeholder="RM-EXAMPLE" required>
                    </div>
                    <div>
                        <label for="modalItemName" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Item Name <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="item_name" id="modalItemName" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" placeholder="e.g. Oak-Aged Whiskey Base" required>
                    </div>
                </div>

                <!-- Category & Unit -->
                <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 12px;">
                    <div>
                        <label for="modalCategory" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Category <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="category_id" id="modalCategory" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?> (<?= htmlspecialchars($cat['category_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="modalUnit" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Unit <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="unit" id="modalUnit" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;" required>
                            <option value="pcs">pcs (Pieces / Bottles)</option>
                            <option value="liter">liter (Bulk Liquids)</option>
                            <option value="kg">kg (Weight / Botanicals)</option>
                            <option value="box">box (Cases / Cartons)</option>
                        </select>
                    </div>
                </div>

                <!-- Reorder Level -->
                <div>
                    <label for="modalReorder" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Default Low Stock Reorder Threshold
                    </label>
                    <input type="number" step="0.01" min="0" name="default_reorder_level" id="modalReorder" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" value="50.00" required>
                    <small style="color: var(--gray); font-size: 11px;">Triggers replenishment alert when warehouse quantity drops below this level.</small>
                </div>

                <!-- Description -->
                <div>
                    <label for="modalDesc" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Description / Specifications
                    </label>
                    <textarea name="description" id="modalDesc" class="search-box" style="width: 100%; border-radius: 8px; height: 50px; padding: 8px 12px;" placeholder="Optional details (ABV, packaging specs, notes)..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewItemModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save SKU</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewItemModal() {
    document.getElementById('newItemModal').classList.add('open');
}
function closeNewItemModal() {
    document.getElementById('newItemModal').classList.remove('open');
}
function autoSuggestCodePrefix(type) {
    const codeInput = document.getElementById('modalItemCode');
    if (!codeInput.value || codeInput.value.startsWith('RM-') || codeInput.value.startsWith('FG-')) {
        codeInput.value = (type === 'raw_material') ? 'RM-' : 'FG-';
    }
}
function filterItemsTable() {
    const query = document.getElementById('itemSearch').value.toLowerCase().trim();
    const typeFilter = document.getElementById('typeFilter').value;
    const table = document.getElementById('itemsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        const rowType = row.getAttribute('data-type') || '';
        const text = row.textContent.toLowerCase();
        const matchesQuery = !query || text.includes(query);
        const matchesType  = !typeFilter || rowType === typeFilter;

        row.style.display = (matchesQuery && matchesType) ? '' : 'none';
    }
}
</script>

<?php
require_once __DIR__ . '/../layouts/footer.php';
?>
