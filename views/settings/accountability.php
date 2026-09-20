<?php
/**
 * View: Full Page Accountability Audit Log
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Whole-page audit ledger showing who performed inventory-related actions,
 * what they did, when they did it, and which warehouse was affected.
 * Kept strictly inside the Settings section.
 */

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../helpers/AccountabilityService.php';

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    header('Location: ' . $auth->getLoginRedirectUrl());
    exit;
}

$currentUser = $auth->getCurrentUser();
$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');

$currentWarehouseId = $isSuperAdmin ? (int)($_GET['warehouse_id'] ?? 0) : (int)($currentUser['warehouse_id'] ?? 0);

// Real CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    AccountabilityService::exportCsv($currentWarehouseId, $_GET);
}

// Fetch live KPIs and warehouses
$kpis = AccountabilityService::getKpis($currentWarehouseId);

$pdo = Database::getConnection();
$warehousesStmt = $pdo->query("SELECT warehouse_id, warehouse_name, warehouse_code FROM warehouses ORDER BY warehouse_name ASC");
$allWarehouses = $warehousesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch logs with initial warehouse scope
$logs = AccountabilityService::getLogs($currentWarehouseId, [
    'team'       => $_GET['team'] ?? '',
    'action'     => $_GET['action'] ?? '',
    'search'     => $_GET['search'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date'   => $_GET['end_date'] ?? '',
]);

$pageTitle   = 'Accountability Audit Log — Settings — StockPilot';
$activePage  = 'settings';
$activeGroup = 'settings';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$currentWarehouseCode = $assignedWarehouse['warehouse_code'] ?? 'WH-MAIN';
$currentWarehouseName = $assignedWarehouse['warehouse_name'] ?? 'Main Warehouse';
$currentBranchLabel = htmlspecialchars($currentWarehouseCode . ' · ' . $currentWarehouseName);
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title" style="display: flex; align-items: center; gap: 10px;">
            <span>Accountability Audit Log</span>
        </h1>
        <p class="page-subtitle">Granular operational audit trail capturing all user and team activities across inventory touchpoints</p>
    </div>
    <div class="header-actions">
        <!-- Quick Action: Contact Support -->
        <button type="button" class="btn btn-secondary" onclick="openSettingsModal('contact_support')" style="display: inline-flex; align-items: center; gap: 6px; color: #7C3AED; border-color: rgba(124, 58, 237, 0.3);">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                <line x1="8" y1="10" x2="16" y2="10"/>
                <line x1="8" y1="14" x2="13" y2="14"/>
            </svg>
            <span>Contact Support</span>
        </button>
        <!-- Quick Action: Settings Modal Menu -->
        <button type="button" class="btn btn-secondary" onclick="openSettingsModal('main')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            <span>Settings Menu</span>
        </button>
    </div>
</div>

<!-- Accountability KPI Metrics Grid -->
<div class="stats-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 24px;">
    <!-- Metric 1: Total Events -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Audit Trail Events</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: var(--panel-ink);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                    <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                    <path d="m9 14 2 2 4-4"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($kpis['total_events']) ?></div>
        <div class="stat-meta">Cross-department operations logged</div>
    </div>

    <!-- Metric 2: Procurement -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Procurement Operations</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="background: #FEF3C7; color: #92400E;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: #92400E;"><?= number_format($kpis['procurement_ops']) ?></div>
        <div class="stat-meta">Inbound stock requests dispatched</div>
    </div>

    <!-- Metric 3: Production -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Production Floor</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="background: #F3E8FF; color: #6B21A8;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 16 12 12 8 16"/>
                    <line x1="12" y1="12" x2="12" y2="21"/>
                    <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: #6B21A8;"><?= number_format($kpis['production_ops']) ?></div>
        <div class="stat-meta">Raw issues &amp; finished goods stock ins</div>
    </div>

    <!-- Metric 4: Sales & Inventory -->
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Sales &amp; Controllers</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="background: #E0F2FE; color: #0369A1;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="m4.93 4.93 4.24 4.24"/>
                    <path d="m14.83 9.17 4.24-4.24"/>
                    <path d="m14.83 14.83 4.24 4.24"/>
                    <path d="m9.17 14.83-4.24 4.24"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="color: #0369A1;"><?= number_format($kpis['sales_ops'] + $kpis['inventory_ops']) ?></div>
        <div class="stat-meta">Dispatches, counts &amp; facility transfers</div>
    </div>
</div>

<!-- Main Accountability Card -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Activity Audit Ledger</h2>
            <p class="card-desc">Showing timestamped inventory activities with full operational traceability</p>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="filter-group">
            <!-- Search Box -->
            <div class="search-wrap" style="width: 280px;">
                <span class="search-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="fullLogSearch" class="search-box" placeholder="Filter user, item, team, or warehouse..." oninput="filterFullLogTable()">
            </div>

            <!-- Team Filter -->
            <select id="fullLogTeamFilter" class="select-filter" onchange="filterFullLogTable()">
                <option value="all">All Teams</option>
                <option value="Procurement">Procurement</option>
                <option value="Production">Production</option>
                <option value="Sales">Sales</option>
                <option value="Inventory">Inventory</option>
                <option value="Administration">Administration</option>
            </select>

            <!-- Action Type Filter -->
            <select id="fullLogActionFilter" class="select-filter" onchange="filterFullLogTable()">
                <option value="all">All Actions</option>
                <option value="STOCK_IN">Stock In</option>
                <option value="STOCK_OUT">Stock Out</option>
                <option value="TRANSFER">Inter-Warehouse Transfer</option>
                <option value="ADJUSTMENT">Stock Adjustment</option>
                <option value="BAD_PRODUCT">Damaged / Bad Stock</option>
                <option value="ITEM_CREATED">Item Master Created</option>
            </select>

            <!-- Warehouse Filter -->
            <select id="fullLogWarehouseFilter" class="select-filter" onchange="filterFullLogTable()">
                <option value="all">All Warehouses</option>
                <?php foreach ($allWarehouses as $wh): ?>
                    <option value="<?= htmlspecialchars($wh['warehouse_name']) ?>">
                        <?= htmlspecialchars($wh['warehouse_code'] . ' · ' . $wh['warehouse_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Reset Button -->
            <button type="button" class="btn btn-secondary btn-sm" onclick="resetFullLogFilters()" title="Clear all filters">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
                <span>Reset</span>
            </button>
        </div>
    </div>

    <!-- Whole Page Accountability Table -->
    <div class="table-responsive">
        <table id="fullLogTable">
            <thead>
                <tr>
                    <th style="min-width: 170px;">Date &amp; Time</th>
                    <th style="min-width: 180px;">User</th>
                    <th style="min-width: 125px;">Team</th>
                    <th style="min-width: 210px;">Action</th>
                    <th style="min-width: 220px;">Raw Material / Item</th>
                    <th style="min-width: 110px; text-align: right;">Quantity</th>
                    <th style="min-width: 170px;">Warehouse</th>
                    <th style="min-width: 140px;">Reference PO / Batch</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--gray);">
                            <div style="font-size: 15px; font-weight: 600; margin-bottom: 4px; color: var(--panel-ink);">No Accountability Records Found</div>
                            <div style="font-size: 13px;">No events match your current filter or warehouse criteria.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $createdAt = strtotime($log['created_at']);
                        $dateFormatted = date('M d, Y', $createdAt);
                        $timeFormatted = date('h:i A', $createdAt);
                        $initials = AccountabilityService::getUserInitials($log['user_name']);
                        $team = htmlspecialchars($log['team']);
                        $actionBadge = AccountabilityService::formatActionBadge($log['action_type'], $log['channel']);
                        $teamBadge = AccountabilityService::formatTeamBadge($log['team']);
                        $whName = htmlspecialchars($log['warehouse_name']);
                        $whCode = htmlspecialchars($log['warehouse_code']);
                        $destName = !empty($log['dest_name']) ? htmlspecialchars($log['dest_name']) : '';
                    ?>
                    <tr data-team="<?= $team ?>" data-action="<?= htmlspecialchars($log['action_type']) ?>" data-warehouse="<?= $whName ?>">
                        <td style="white-space: nowrap;">
                            <strong style="color: var(--panel-ink);"><?= $dateFormatted ?></strong><br>
                            <small style="color: var(--gray); font-family: monospace; font-size: 12px;"><?= $timeFormatted ?></small>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: #E2E8F0; color: #1E293B; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <?= htmlspecialchars($initials) ?>
                                </div>
                                <div>
                                    <div style="font-weight: 600; color: var(--panel-ink);"><?= htmlspecialchars($log['user_name']) ?></div>
                                    <div style="font-size: 11.5px; color: var(--gray);"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['user_role'] ?? 'user'))) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?= $teamBadge ?>
                        </td>
                        <td>
                            <?= $actionBadge ?>
                        </td>
                        <td>
                            <?php if (!empty($log['item_name'])): ?>
                                <strong style="color: var(--panel-ink); font-size: 13.5px;"><?= htmlspecialchars($log['item_name']) ?></strong>
                                <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                    <?php if (!empty($log['item_code'])): ?>
                                        <span style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($log['item_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--gray); font-size: 13px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if ((float)$log['quantity'] != 0): ?>
                                <span style="font-weight: 700; font-size: 15px; color: var(--panel-ink);"><?= (float)$log['quantity'] > 0 ? '+' : '' ?><?= rtrim(rtrim(number_format((float)$log['quantity'], 4), '0'), '.') ?></span>
                                <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;"><?= htmlspecialchars($log['unit'] ?? '') ?></span>
                            <?php else: ?>
                                <span style="color: var(--gray); font-size: 13px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-wh wh-main"><?= $whName ?></span>
                            <?php if (!empty($destName)): ?>
                                <div style="font-size: 11px; color: var(--gray); margin-top: 2px;">➔ <?= $destName ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($log['reference_number'])): ?>
                                <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;"><?= htmlspecialchars($log['reference_number']) ?></span>
                            <?php else: ?>
                                <span style="color: var(--gray); font-size: 12px;">—</span>
                            <?php endif; ?>
                            <?php if (!empty($log['notes'])): ?>
                                <div style="font-size: 11px; color: var(--gray); margin-top: 2px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($log['notes']) ?>">
                                    <?= htmlspecialchars($log['notes']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Table Pagination / Summary Footer -->
    <div style="padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 12px; background: #F8FAFC;">
        <span id="fullLogTableCount" style="font-size: 12.5px; color: var(--gray); font-weight: 500;">
            Showing <strong id="visibleRowCount"><?= count($logs) ?></strong> of <strong><?= count($logs) ?></strong> total accountability events
        </span>
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="?export=csv<?= !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span>Export CSV</span>
            </a>
            <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect width="12" height="8" x="6" y="14"/>
                </svg>
                <span>Print Ledger</span>
            </button>
        </div>
    </div>
</div>

<script>
function filterFullLogTable() {
    const query = (document.getElementById('fullLogSearch')?.value || '').toLowerCase().trim();
    const team = (document.getElementById('fullLogTeamFilter')?.value || 'all');
    const action = (document.getElementById('fullLogActionFilter')?.value || 'all');
    const warehouse = (document.getElementById('fullLogWarehouseFilter')?.value || 'all');
    const rows = document.querySelectorAll('#fullLogTable tbody tr');
    let visible = 0;

    rows.forEach(row => {
        // Skip empty placeholder row if present
        if (row.cells.length <= 1) return;

        const rowTeam = (row.getAttribute('data-team') || '').toLowerCase();
        const rowAction = (row.getAttribute('data-action') || '').toLowerCase();
        const rowWarehouse = (row.getAttribute('data-warehouse') || '').toLowerCase();
        const text = row.innerText.toLowerCase();

        const matchTeam = (team === 'all' || rowTeam === team.toLowerCase());
        const matchAction = (action === 'all' || rowAction.includes(action.toLowerCase()));
        const matchWarehouse = (warehouse === 'all' || rowWarehouse.includes(warehouse.toLowerCase()));
        const matchQuery = !query || text.includes(query);

        if (matchTeam && matchAction && matchWarehouse && matchQuery) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    const countEl = document.getElementById('visibleRowCount');
    if (countEl) {
        countEl.textContent = visible;
    }
}

function resetFullLogFilters() {
    const search = document.getElementById('fullLogSearch');
    const team = document.getElementById('fullLogTeamFilter');
    const action = document.getElementById('fullLogActionFilter');
    const warehouse = document.getElementById('fullLogWarehouseFilter');

    if (search) search.value = '';
    if (team) team.value = 'all';
    if (action) action.value = 'all';
    if (warehouse) warehouse.value = 'all';

    filterFullLogTable();
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
