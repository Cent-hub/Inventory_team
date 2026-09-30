<?php
/**
 * View: Master Item Catalog & SKU Management
 * InventoryTeam — Liquor Business Inventory Management System
 */

$pageTitle   = 'Item Catalog & SKUs — InventoryTeam';
$activePage  = 'items';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$successMessage = null;
$errorMessage   = null;
$canManageItems = empty($currentUser['role']) || in_array(strtolower(trim((string)$currentUser['role'])), ['super_admin', 'admin'], true);

// Handle New Item Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_item') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } elseif (!$canManageItems) {
        $errorMessage = "Access denied: Only Administrators can create catalog items.";
    } else {
        $itemCode        = strtoupper(trim($_POST['item_code'] ?? ''));
        $itemName        = trim($_POST['item_name'] ?? '');
        $itemType        = trim($_POST['item_type'] ?? '');
        $categoryId      = (int)($_POST['category_id'] ?? 0);
        $unit            = strtolower(trim($_POST['unit'] ?? 'pcs'));
        $rawReorderLevel = trim((string)($_POST['default_reorder_level'] ?? '0'));
        $description     = trim($_POST['description'] ?? '');

        if (empty($itemCode)) {
            $errorMessage = "Item Code (SKU) is required.";
        } elseif (mb_strlen($itemCode) > 50 || !preg_match('/^[A-Z0-9\-_]+$/', $itemCode)) {
            $errorMessage = "Item Code (SKU) must be 1–50 characters and contain only letters, numbers, hyphens (-), or underscores (_).";
        } elseif (empty($itemName)) {
            $errorMessage = "Item Name is required.";
        } elseif (mb_strlen($itemName) > 150) {
            $errorMessage = "Item Name cannot exceed 150 characters.";
        } elseif (!in_array($itemType, ['raw_material', 'finished_good'], true)) {
            $errorMessage = "Item classification must be either 'Raw Material' or 'Finished Good'.";
        } elseif ($categoryId <= 0) {
            $errorMessage = "Please select a valid category.";
        } elseif (empty($unit) || mb_strlen($unit) > 20) {
            $errorMessage = "Unit of measurement is required and cannot exceed 20 characters.";
        } elseif ($rawReorderLevel !== '' && !is_numeric($rawReorderLevel)) {
            $errorMessage = "Default reorder level must be a valid number.";
        } else {
            $reorderLevel = $rawReorderLevel === '' ? 0.0 : (float)$rawReorderLevel;
            if ($reorderLevel < 0 || $reorderLevel > 99999999999.999) {
                $errorMessage = "Default reorder level must be between 0 and 99,999,999,999.999.";
            } elseif (abs($reorderLevel - round($reorderLevel, 3)) > 0.000001) {
                $errorMessage = "Default reorder level cannot have more than 3 decimal places.";
            } else {
                try {
                    // Verify active category exists
                    $stmtCat = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE category_id = ? AND status = 'active'");
                    $stmtCat->execute([$categoryId]);
                    if ((int)$stmtCat->fetchColumn() === 0) {
                        $errorMessage = "Selected category does not exist or is inactive.";
                    } else {
                        // Check uniqueness of item code
                        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM items WHERE item_code = ?");
                        $stmtChk->execute([$itemCode]);
                        if ((int)$stmtChk->fetchColumn() > 0) {
                            $errorMessage = "An item with SKU code '{$itemCode}' already exists.";
                        } else {
                            $userId = (int)($currentUser['id'] ?? 1);
                            $stmtIns = $pdo->prepare("
                                INSERT INTO items (
                                    item_code, item_name, description, item_type,
                                    category_id, unit, default_reorder_level, status, created_by
                                ) VALUES (
                                    ?, ?, ?, ?,
                                    ?, ?, ?, 'active', ?
                                )
                            ");
                            $stmtIns->execute([
                                $itemCode,
                                $itemName,
                                $description ?: null,
                                $itemType,
                                $categoryId,
                                $unit,
                                round($reorderLevel, 3),
                                $userId
                            ]);
                            $newId = (int)$pdo->lastInsertId();

                            // Initialize 0-stock inventory snapshot rows across all active warehouses
                            $stmtSeedInv = $pdo->prepare("
                                INSERT IGNORE INTO inventory (item_id, warehouse_id, quantity, reorder_level)
                                SELECT ?, warehouse_id, 0.000, ?
                                FROM warehouses
                                WHERE status = 'active'
                            ");
                            $stmtSeedInv->execute([$newId, round($reorderLevel, 3)]);

                            require_once __DIR__ . '/../../helpers/AccountabilityService.php';
                            AccountabilityService::log([
                                'user_id'          => $userId,
                                'team'             => 'Inventory',
                                'action_type'      => 'ITEM_CREATED',
                                'channel'          => 'UI',
                                'item_id'          => $newId,
                                'warehouse_id'     => $currentWarehouseId ?: 1,
                                'reference_number' => $itemCode,
                                'notes'            => "Master item created: {$itemName} ({$itemType})"
                            ]);

                            $successMessage = "Master item '{$itemName}' ({$itemCode}) created successfully!";
                        }
                    }
                } catch (Exception $e) {
                    $errorMessage = "Failed to create item: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Existing Item Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item') {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } elseif (!$canManageItems) {
        $errorMessage = "Access denied: Only Administrators can update catalog items.";
    } else {
        $itemId          = (int)($_POST['item_id'] ?? 0);
        $itemName        = trim($_POST['item_name'] ?? '');
        $categoryId      = (int)($_POST['category_id'] ?? 0);
        $unit            = strtolower(trim($_POST['unit'] ?? 'pcs'));
        $rawReorderLevel = trim((string)($_POST['default_reorder_level'] ?? '0'));
        $description     = trim($_POST['description'] ?? '');
        $itemStatus      = trim($_POST['status'] ?? 'active');

        if ($itemId <= 0) {
            $errorMessage = "Invalid item ID selected for update.";
        } elseif (empty($itemName) || mb_strlen($itemName) > 150) {
            $errorMessage = "Item Name is required and cannot exceed 150 characters.";
        } elseif ($categoryId <= 0) {
            $errorMessage = "Please select a valid category.";
        } elseif (empty($unit) || mb_strlen($unit) > 20) {
            $errorMessage = "Unit of measurement is required and cannot exceed 20 characters.";
        } elseif (!in_array($itemStatus, ['active', 'inactive'], true)) {
            $errorMessage = "Item status must be either 'active' or 'inactive'.";
        } elseif ($rawReorderLevel !== '' && !is_numeric($rawReorderLevel)) {
            $errorMessage = "Default reorder level must be a valid number.";
        } else {
            $reorderLevel = $rawReorderLevel === '' ? 0.0 : (float)$rawReorderLevel;
            if ($reorderLevel < 0 || $reorderLevel > 99999999999.999) {
                $errorMessage = "Default reorder level must be between 0 and 99,999,999,999.999.";
            } elseif (abs($reorderLevel - round($reorderLevel, 3)) > 0.000001) {
                $errorMessage = "Default reorder level cannot have more than 3 decimal places.";
            } else {
                try {
                    // Verify active category exists
                    $stmtCat = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE category_id = ? AND status = 'active'");
                    $stmtCat->execute([$categoryId]);
                    if ((int)$stmtCat->fetchColumn() === 0) {
                        $errorMessage = "Selected category does not exist or is inactive.";
                    } else {
                        $stmtExist = $pdo->prepare("SELECT item_code, item_type FROM items WHERE item_id = ?");
                        $stmtExist->execute([$itemId]);
                        $existingItem = $stmtExist->fetch(PDO::FETCH_ASSOC);

                        if (!$existingItem) {
                            $errorMessage = "Selected catalog item does not exist.";
                        } else {
                            $stmtUpd = $pdo->prepare("
                                UPDATE items
                                SET item_name = ?,
                                    description = ?,
                                    category_id = ?,
                                    unit = ?,
                                    default_reorder_level = ?,
                                    status = ?
                                WHERE item_id = ?
                            ");
                            $stmtUpd->execute([
                                $itemName,
                                $description ?: null,
                                $categoryId,
                                $unit,
                                round($reorderLevel, 3),
                                $itemStatus,
                                $itemId
                            ]);

                            // Keep warehouse inventory reorder levels synchronized with master catalog
                            $stmtSyncInv = $pdo->prepare("
                                UPDATE inventory
                                SET reorder_level = ?
                                WHERE item_id = ?
                            ");
                            $stmtSyncInv->execute([round($reorderLevel, 3), $itemId]);

                            // Ensure all active warehouses have an inventory row for this item
                            $stmtSeedMissing = $pdo->prepare("
                                INSERT IGNORE INTO inventory (item_id, warehouse_id, quantity, reorder_level)
                                SELECT ?, warehouse_id, 0.000, ?
                                FROM warehouses
                                WHERE status = 'active'
                            ");
                            $stmtSeedMissing->execute([$itemId, round($reorderLevel, 3)]);

                            $userId = (int)($currentUser['id'] ?? 1);
                            require_once __DIR__ . '/../../helpers/AccountabilityService.php';
                            AccountabilityService::log([
                                'user_id'          => $userId,
                                'team'             => 'Inventory',
                                'action_type'      => 'ITEM_UPDATED',
                                'channel'          => 'UI',
                                'item_id'          => $itemId,
                                'warehouse_id'     => $currentWarehouseId ?: 1,
                                'reference_number' => $existingItem['item_code'],
                                'notes'            => "Master item updated: {$itemName} (Status: {$itemStatus}, Reorder: " . round($reorderLevel, 3) . " {$unit})"
                            ]);

                            $successMessage = "Master item '{$itemName}' ({$existingItem['item_code']}) updated successfully!";
                        }
                    }
                } catch (Exception $e) {
                    $errorMessage = "Failed to update item: " . $e->getMessage();
                }
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
        i.category_id,
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

// KPI metrics (count active SKUs for Raw Materials & Finished Goods so totals match Inventory pages)
$totalSKUs    = count($itemsList);
$activeSKUs   = count(array_filter($itemsList, fn($x) => ($x['status'] ?? 'active') === 'active'));
$inactiveSKUs = $totalSKUs - $activeSKUs;
$rawSKUs      = count(array_filter($itemsList, fn($x) => $x['item_type'] === 'raw_material' && ($x['status'] ?? 'active') === 'active'));
$finishedSKUs = count(array_filter($itemsList, fn($x) => $x['item_type'] === 'finished_good' && ($x['status'] ?? 'active') === 'active'));
$catCount     = count($categories);
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Item Catalog</h1>
        <p class="page-subtitle">Centralized catalog and reorder threshold configuration</p>
    </div>
    <div class="header-actions">
        <?php if ($canManageItems): ?>
            <button type="button" class="btn btn-primary" onclick="openNewItemModal()">+ Add New Item</button>
        <?php endif; ?>
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
            <span class="stat-label">Total Registered SKUs</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #1D4ED8; background: #EFF6FF;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            </div>
        </div>
        <div class="stat-value"><?= $totalSKUs ?></div>
        <div class="stat-meta"><?= $inactiveSKUs > 0 ? "{$activeSKUs} active · {$inactiveSKUs} inactive" : 'Across all categories' ?></div>
    </div>

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Raw Materials</span>
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
            <span class="stat-label">Active Categories</span>
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
                <input type="text" id="itemSearch" class="search-box" aria-label="Filter catalog items" placeholder="Filter code, name, unit..." oninput="filterItemsTable()">
            </div>
            <select id="typeFilter" class="select-filter" aria-label="Filter by item classification" onchange="filterItemsTable()">
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
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Classification</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th>Default Reorder Level</th>
                    <th>Status</th>
                    <?php if ($canManageItems): ?>
                        <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($itemsList)): ?>
                    <tr>
                        <td colspan="<?= $canManageItems ? 9 : 8 ?>" style="text-align: center; color: var(--gray); padding: 36px;">No catalog items found. Click "+ Add New Item" above to create your first SKU.</td>
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
                                    <span class="badge-type type-raw">
                                        Raw Material
                                    </span>
                                <?php else: ?>
                                    <span class="badge-type type-fg">
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
                                <?= formatQty($row['default_reorder_level']) ?> <?= htmlspecialchars($row['unit']) ?>
                            </td>
                            <td>
                                <?php if (($row['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge status-completed">Active</span>
                                <?php else: ?>
                                    <span class="badge status-cancelled">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManageItems): ?>
                                <td>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openEditItemModal(<?= htmlspecialchars(json_encode([
                                        'item_id'               => (int)$row['item_id'],
                                        'item_code'             => $row['item_code'],
                                        'item_name'             => $row['item_name'],
                                        'item_type'             => $row['item_type'],
                                        'category_id'           => (int)$row['category_id'],
                                        'unit'                  => $row['unit'],
                                        'default_reorder_level' => (float)$row['default_reorder_level'],
                                        'description'           => (string)($row['description'] ?? ''),
                                        'status'                => $row['status'] ?? 'active'
                                    ])) ?>)">Edit</button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Item -->
<div id="newItemModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="newItemModalTitle" onclick="if(event.target === this && !document.getElementById('modalItemName').value.trim()) closeNewItemModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_item">
            <div class="modal-header">
                <div>
                    <h3 id="newItemModalTitle" class="card-title">Add New Catalog Item</h3>
                    <p class="card-desc">Define a new raw material ingredient or finished bottle SKU</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeNewItemModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Classification -->
                <div>
                    <label for="modalItemType" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Classification <span style="color: #DC2626;">*</span>
                    </label>
                    <select name="item_type" id="modalItemType" class="select-filter" style="width: 100%;" required onchange="autoSuggestCodePrefix(this.value)">
                        <option value="raw_material">Raw Material (Procurement Inbound / Production Request)</option>
                        <option value="finished_good">Finished Good (Production Receipt / Sales Delivery)</option>
                    </select>
                </div>

                <!-- Item Code & Name -->
                <div class="form-grid-2" style="grid-template-columns: 1fr 1.5fr; gap: 12px;">
                    <div>
                        <label for="modalItemCode" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Item Code <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="item_code" id="modalItemCode" class="search-box" style="width: 100%; padding-left: 12px; text-transform: uppercase;" placeholder="RM-EXAMPLE" required>
                    </div>
                    <div>
                        <label for="modalItemName" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Item Name <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="item_name" id="modalItemName" class="search-box" style="width: 100%; padding-left: 12px;" placeholder="e.g. Oak-Aged Whiskey Base" required>
                    </div>
                </div>

                <!-- Category & Unit -->
                <div class="form-grid-2" style="grid-template-columns: 1.5fr 1fr; gap: 12px;">
                    <div>
                        <label for="modalCategory" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Category <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="category_id" id="modalCategory" class="select-filter" style="width: 100%;" required>
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
                        <select name="unit" id="modalUnit" class="select-filter" style="width: 100%;" required>
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
                    <input type="number" step="0.01" min="0" name="default_reorder_level" id="modalReorder" class="search-box" style="width: 100%; padding-left: 12px;" value="50" required>
                    <small style="color: var(--gray); font-size: 11px;">Triggers replenishment alert when warehouse quantity drops below this level.</small>
                </div>

                <!-- Description -->
                <div>
                    <label for="modalDesc" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Description / Specifications
                    </label>
                    <textarea name="description" id="modalDesc" class="search-box" style="width: 100%; height: 50px; padding: 8px 12px;" placeholder="Optional details (ABV, packaging specs, notes)..."></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewItemModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save SKU</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Existing Item -->
<div id="editItemModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="editItemModalTitle" onclick="if(event.target === this) closeEditItemModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" id="editItemId" value="">
            <div class="modal-header">
                <div>
                    <h3 id="editItemModalTitle" class="card-title">Edit Catalog Item</h3>
                    <p class="card-desc">Update item details, reorder threshold, or active status</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeEditItemModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div class="form-grid-2" style="grid-template-columns: 1fr 1.5fr; gap: 12px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Item Code (SKU)
                        </label>
                        <input type="text" id="editItemCode" class="search-box" style="width: 100%; padding-left: 12px; background: #F1F5F9; color: var(--gray);" readonly>
                    </div>
                    <div>
                        <label for="editItemName" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Item Name <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="item_name" id="editItemName" class="search-box" style="width: 100%; padding-left: 12px;" required>
                    </div>
                </div>

                <div class="form-grid-2" style="grid-template-columns: 1.5fr 1fr; gap: 12px;">
                    <div>
                        <label for="editCategory" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Category <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="category_id" id="editCategory" class="select-filter" style="width: 100%;" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?> (<?= htmlspecialchars($cat['category_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="editUnit" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Unit <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="unit" id="editUnit" class="select-filter" style="width: 100%;" required>
                            <option value="pcs">pcs (Pieces / Bottles)</option>
                            <option value="liter">liter (Bulk Liquids)</option>
                            <option value="kg">kg (Weight / Botanicals)</option>
                            <option value="box">box (Cases / Cartons)</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2" style="grid-template-columns: 1.3fr 1fr; gap: 12px;">
                    <div>
                        <label for="editReorder" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Default Reorder Threshold <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="number" step="0.01" min="0" name="default_reorder_level" id="editReorder" class="search-box" style="width: 100%; padding-left: 12px;" required>
                    </div>
                    <div>
                        <label for="editStatus" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Catalog Status <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="status" id="editStatus" class="select-filter" style="width: 100%;" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="editDesc" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Description / Specifications
                    </label>
                    <textarea name="description" id="editDesc" class="search-box" style="width: 100%; height: 50px; padding: 8px 12px;"></textarea>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditItemModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
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
function openEditItemModal(item) {
    document.getElementById('editItemId').value = item.item_id;
    document.getElementById('editItemCode').value = item.item_code;
    document.getElementById('editItemName').value = item.item_name;
    document.getElementById('editCategory').value = item.category_id;
    document.getElementById('editUnit').value = item.unit;
    document.getElementById('editReorder').value = item.default_reorder_level;
    document.getElementById('editStatus').value = item.status || 'active';
    document.getElementById('editDesc').value = item.description || '';
    document.getElementById('editItemModal').classList.add('open');
}
function closeEditItemModal() {
    document.getElementById('editItemModal').classList.remove('open');
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
    if (!table) return;
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let row of rows) {
        if (row.cells.length <= 1) continue;
        const rowType = row.getAttribute('data-type') || '';
        const text = row.textContent.toLowerCase();
        const matchesQuery = !query || text.includes(query);
        const matchesType  = !typeFilter || rowType === typeFilter;
        const match = matchesQuery && matchesType;

        row.dataset.filteredOut = match ? 'false' : 'true';
    }
    if (table.paginationUpdate) table.paginationUpdate(true);
}
</script>

<?php
require_once __DIR__ . '/../layouts/footer.php';
?>
