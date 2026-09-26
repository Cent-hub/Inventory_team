<?php
/**
 * View: Inventory Inbound & Outbound (Unified Movement Hub)
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Unifies two core inventory transaction flows into a single interface:
 * 1. Inbound (Stock In receipts from Procurement & Production)
 * 2. Outbound (Stock Out dispatches to Sales & Production)
 */

$pageTitle   = 'Inventory Inbound & Outbound — StockPilot';
$activePage  = 'inbound_outbound';
$activeGroup = 'inventory';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../helpers/StockService.php';

$auth = $auth ?? new AuthController();
$pdo = $pdo ?? Database::getConnection();
$currentUser = $currentUser ?? ($auth->getCurrentUser() ?? []);
$currentWarehouseId = $currentWarehouseId ?? (int)($_SESSION['warehouse_id'] ?? ($currentUser['warehouse_id'] ?? 1));

/** @var array{warehouse_id: int, warehouse_code: string, warehouse_name: string, location: string} $assignedWarehouse */
$assignedWarehouse = is_array($assignedWarehouse ?? null) ? $assignedWarehouse : [
    'warehouse_id'   => $currentWarehouseId ?? 1,
    'warehouse_code' => 'WH-MAIN',
    'warehouse_name' => 'Main Warehouse',
    'location'       => 'Default Location'
];

$successMessage = null;
$errorMessage   = null;

// Determine Initial Active Tab
$activeTab = 'inbound';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['inbound', 'outbound'], true)) {
    $activeTab = $_GET['tab'];
} elseif (isset($_POST['active_tab']) && in_array($_POST['active_tab'], ['inbound', 'outbound'], true)) {
    $activeTab = $_POST['active_tab'];
}

// =============================================================================
// POST ACTION DISPATCHER
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $stockService = new StockService();
        $userId       = (int)($currentUser['id'] ?? 1);

        try {
            switch ($action) {
                // -------------------------------------------------------------
                // 1. Stock In (Inbound Receipt)
                // -------------------------------------------------------------
                case 'create_stock_in':
                    $activeTab = 'inbound';
                    $sourceType        = trim($_POST['source_type'] ?? 'PURCHASE_ORDER');
                    $sourceReferenceNo = trim($_POST['source_reference_no'] ?? '');
                    $itemId            = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity       = $_POST['quantity'] ?? null;
                    $remarks           = trim($_POST['remarks'] ?? '');
                    $targetWhId        = (int)($currentWarehouseId ?: 1);

                    if (!in_array($sourceType, StockService::VALID_STOCK_IN_SOURCES, true)) {
                        throw new InvalidArgumentException("Invalid source_type '{$sourceType}'.");
                    } elseif (empty($sourceReferenceNo)) {
                        throw new InvalidArgumentException("Reference Number (PO # or Work Order #) is required.");
                    } elseif (mb_strlen($sourceReferenceNo) > 100) {
                        throw new InvalidArgumentException("Reference Number cannot exceed 100 characters.");
                    } elseif ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select an item to receive.");
                    }
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity received');

                    $res = $stockService->recordStockIn(
                        $targetWhId,
                        $sourceType,
                        $sourceReferenceNo,
                        [['item_id' => $itemId, 'quantity' => $quantity]],
                        $userId,
                        $remarks ?: null
                    );
                    $successMessage = "Inbound stock received successfully! Transaction reference: " . htmlspecialchars($res['transaction_number']);
                    break;

                // -------------------------------------------------------------
                // 2. Stock Out (Outbound Dispatch)
                // -------------------------------------------------------------
                case 'create_stock_out':
                    $activeTab = 'outbound';
                    $sourceType        = trim($_POST['source_type'] ?? 'SALES_DELIVERY');
                    $sourceReferenceNo = trim($_POST['source_reference_no'] ?? '');
                    $itemId            = (int)($_POST['item_id'] ?? 0);
                    $rawQuantity       = $_POST['quantity'] ?? null;
                    $remarks           = trim($_POST['remarks'] ?? '');
                    $sourceWhId        = (int)($currentWarehouseId ?: 1);

                    if (!in_array($sourceType, StockService::VALID_STOCK_OUT_SOURCES, true)) {
                        throw new InvalidArgumentException("Invalid source_type '{$sourceType}'.");
                    } elseif (empty($sourceReferenceNo)) {
                        throw new InvalidArgumentException("Reference Number (Sales Order # or Material Request #) is required.");
                    } elseif (mb_strlen($sourceReferenceNo) > 100) {
                        throw new InvalidArgumentException("Reference Number cannot exceed 100 characters.");
                    } elseif ($itemId <= 0) {
                        throw new InvalidArgumentException("Please select an item to dispatch.");
                    }
                    $quantity = StockService::validatePositiveQuantity($rawQuantity, null, 'quantity dispatched');

                    $res = $stockService->recordStockOut(
                        $sourceWhId,
                        $sourceType,
                        $sourceReferenceNo,
                        [['item_id' => $itemId, 'quantity' => $quantity]],
                        $userId,
                        $remarks ?: null
                    );
                    $successMessage = "Outbound stock dispatched successfully! Transaction reference: " . htmlspecialchars($res['transaction_number']);
                    break;

                default:
                    break;
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

// =============================================================================
// DATA QUERIES: SECTION 1 (INBOUND / STOCK IN)
// =============================================================================

// Fetch all active items for receiving dropdown
$allItems = $pdo->query("
    SELECT item_id, item_code, item_name, item_type, unit 
    FROM items 
    WHERE status = 'active' 
    ORDER BY item_type ASC, item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Stock In transactions strictly for assigned warehouse
$stmtIn = $pdo->prepare("
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
$stmtIn->execute([':wid' => $currentWarehouseId]);
$stockIns = $stmtIn->fetchAll(PDO::FETCH_ASSOC);

// Fetch line items strictly for this warehouse's stock ins
$stmtInLines = $pdo->prepare("
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
$stmtInLines->execute([':wid' => $currentWarehouseId]);
$linesByStockIn = [];
while ($row = $stmtInLines->fetch(PDO::FETCH_ASSOC)) {
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

// =============================================================================
// DATA QUERIES: SECTION 2 (OUTBOUND / STOCK OUT)
// =============================================================================

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
$stmtOut = $pdo->prepare("
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
$stmtOut->execute([':wid' => $currentWarehouseId]);
$stockOuts = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

// Fetch line items strictly for this warehouse's stock outs
$stmtOutLines = $pdo->prepare("
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
$stmtOutLines->execute([':wid' => $currentWarehouseId]);
$linesByStockOut = [];
while ($row = $stmtOutLines->fetch(PDO::FETCH_ASSOC)) {
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

<style>
/* Operations Tab Navigation Bar */
.stock-ops-nav-wrapper {
    background: #FFFFFF;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px 18px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}
.stock-ops-nav-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--gray);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.stock-ops-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.stock-tab-btn {
    appearance: none;
    background: #F8FAFC;
    border: 1.5px solid #E2E8F0;
    color: #475569;
    padding: 10px 20px;
    border-radius: 9px;
    font-family: var(--font-body);
    font-weight: 600;
    font-size: 13.5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    transition: all 0.16s ease;
    user-select: none;
}
.stock-tab-btn:hover {
    background: #F1F5F9;
    color: var(--panel-ink);
    border-color: #CBD5E1;
    transform: translateY(-1px);
}
.stock-tab-btn.active {
    background: var(--panel-ink);
    color: #FFFFFF;
    border-color: var(--panel-ink);
    box-shadow: 0 4px 14px rgba(20, 33, 61, 0.20);
    font-weight: 700;
    transform: none;
}
.stock-tab-btn .tab-badge-count {
    background: #E2E8F0;
    color: #475569;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    transition: all 0.16s ease;
}
.stock-tab-btn.active .tab-badge-count {
    background: rgba(255, 255, 255, 0.22);
    color: #FFFFFF;
}

/* Tab Panes */
.stock-op-pane {
    animation: fadeIn 0.18s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(3px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Source Badges (Inbound) */
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

/* Destination Badges (Outbound) */
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

<!-- Page Header -->
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1 class="page-title">Inventory Inbound &amp; Outbound</h1>
        <p class="page-subtitle">Unified transaction ledger for receipts and dispatches &middot; <strong><?= htmlspecialchars($assignedWarehouse['warehouse_name'] ?? 'Main Warehouse') ?> (<?= htmlspecialchars($assignedWarehouse['warehouse_code'] ?? 'WH-MAIN') ?>)</strong></p>
    </div>
</div>

<!-- ========================================================================= -->
<!-- NAVIGATION SWITCHER: INBOUND | OUTBOUND                                   -->
<!-- ========================================================================= -->
<div class="stock-ops-nav-wrapper">
    <div class="stock-ops-nav-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m16 3 4 4-4 4"/>
            <path d="M20 7H4"/>
            <path d="m8 21-4-4 4-4"/>
            <path d="M4 17h16"/>
        </svg>
        <span>Inventory Inbound &amp; Outbound</span>
    </div>
    <div class="stock-ops-tabs">
        <button type="button" id="tab-btn-inbound" class="stock-tab-btn <?= $activeTab === 'inbound' ? 'active' : '' ?>" onclick="switchStockTab('inbound')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="17" y1="7" x2="7" y2="17"/>
                <polyline points="17 17 7 17 7 7"/>
            </svg>
            <span>Inbound</span>
            <span class="tab-badge-count"><?= $totalStockInTxns ?></span>
        </button>

        <button type="button" id="tab-btn-outbound" class="stock-tab-btn <?= $activeTab === 'outbound' ? 'active' : '' ?>" onclick="switchStockTab('outbound')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="7" y1="17" x2="17" y2="7"/>
                <polyline points="7 7 17 7 17 17"/>
            </svg>
            <span>Outbound</span>
            <span class="tab-badge-count"><?= $totalStockOutTxns ?></span>
        </button>
    </div>
</div>

<!-- Flash Alerts -->
<?php if ($successMessage): ?>
    <div style="background: var(--success-light); border: 1px solid var(--success-border); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?= htmlspecialchars($successMessage) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: var(--success); font-size: 16px;">&times;</button>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div style="background: var(--error-light); border: 1px solid var(--error-border); color: var(--error); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-weight: 500;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($errorMessage) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: var(--error); font-size: 16px;">&times;</button>
    </div>
<?php endif; ?>

<!-- ######################################################################### -->
<!-- PANE 1: INBOUND SECTION                                                   -->
<!-- ######################################################################### -->
<div id="pane-inbound" class="stock-op-pane" style="display: <?= $activeTab === 'inbound' ? 'block' : 'none' ?>;">
    <!-- Inbound KPI Cards -->
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
                                    +<?= formatQty((float)$row['total_quantity']) ?>
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
</div>

<!-- Line Item Details Modal (Inbound) -->
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


<!-- ######################################################################### -->
<!-- PANE 2: OUTBOUND SECTION                                                  -->
<!-- ######################################################################### -->
<div id="pane-outbound" class="stock-op-pane" style="display: <?= $activeTab === 'outbound' ? 'block' : 'none' ?>;">
    <!-- Outbound KPI Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Total Outbound</span>
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
                <span class="stat-label">Sales (Finished Goods)</span>
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
                <span class="stat-label">Production (Raw Materials)</span>
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

    <!-- Outbound Main Table Card -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Outbound / Stock Out Dispatches</h2>
                <p class="card-desc">Real-time log of stock releases requested through Production and Sales API integrations</p>
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
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="16" height="13" x="1" y="5" rx="2"/><polygon points="17 8 20 8 23 11 23 18 17 18 17 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                            Sales
                                        </span>
                                    <?php elseif ($row['source_type'] === 'MATERIAL_REQUEST'): ?>
                                        <span class="badge-dest-production">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 2 7 12 12 22 7 12 2"/>    <polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
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
</div>

<!-- Line Item Details Modal (Outbound) -->
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
// =============================================================================
// TAB SWITCHING CONTROLLER
// =============================================================================
function switchStockTab(tabName) {
    const validTabs = ['inbound', 'outbound'];
    if (!validTabs.includes(tabName)) tabName = 'inbound';

    // Update Tab Buttons
    validTabs.forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        if (btn) {
            if (t === tabName) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
    });

    // Update Tab Panes
    validTabs.forEach(t => {
        const pane = document.getElementById('pane-' + t);
        if (pane) {
            pane.style.display = (t === tabName) ? 'block' : 'none';
        }
    });

    // Sync URL without reloading
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({ tab: tabName }, '', url.toString());

    // Trigger pagination re-calculation for the newly visible table
    const activeTable = (tabName === 'inbound') 
        ? document.getElementById('stockInTable') 
        : document.getElementById('stockOutTable');
    if (activeTable && typeof activeTable.paginationUpdate === 'function') {
        activeTable.paginationUpdate(false);
    }
}

// Support browser back/forward buttons
window.addEventListener('popstate', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || 'inbound';
    switchStockTab(tab);
});

// =============================================================================
// SECTION 1: INBOUND SCRIPTS
// =============================================================================
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
                <td style="font-weight: 700; color: #15803D;">+${Number(parseFloat(l.quantity).toFixed(2))} <small style="color: var(--gray);">${escapeHtml(l.unit)}</small></td>
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

// =============================================================================
// SECTION 2: OUTBOUND SCRIPTS
// =============================================================================
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
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
