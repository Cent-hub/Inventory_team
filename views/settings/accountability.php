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

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    header('Location: ' . $auth->getLoginRedirectUrl());
    exit;
}

$currentUser = $auth->getCurrentUser();
$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');

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
        <div class="stat-value">7</div>
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
        <div class="stat-value" style="color: #92400E;">2</div>
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
        <div class="stat-value" style="color: #6B21A8;">2</div>
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
        <div class="stat-value" style="color: #0369A1;">3</div>
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
            </select>

            <!-- Action Type Filter -->
            <select id="fullLogActionFilter" class="select-filter" onchange="filterFullLogTable()">
                <option value="all">All Actions</option>
                <option value="Stock In Request">Stock In Request</option>
                <option value="Material Issued">Material Issued (Took Raw)</option>
                <option value="Stock In Finished Goods">Stock In Finished Goods</option>
                <option value="Stock Out">Stock Out (Sales Dispatch)</option>
                <option value="Cycle Count">Cycle Count Adjustment</option>
                <option value="Transfer">Inter-Warehouse Transfer</option>
            </select>

            <!-- Warehouse Filter -->
            <select id="fullLogWarehouseFilter" class="select-filter" onchange="filterFullLogTable()">
                <option value="all">All Warehouses</option>
                <option value="Main Warehouse">Main Warehouse</option>
                <option value="Bonded Distillery">Bonded Distillery</option>
                <option value="Bottling &amp; Packaging">Bottling &amp; Packaging</option>
                <option value="Laguna Central Hub">Laguna Central Hub</option>
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
                <!-- Row 1: Procurement (Potatoes) -->
                <tr data-team="Procurement" data-action="Stock In Request" data-warehouse="Main Warehouse">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">09:15 AM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #FEF3C7; color: #92400E; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                JD
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Juan Dela Cruz</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Purchasing Officer</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team procurement">Procurement</span>
                    </td>
                    <td>
                        <span class="badge-action inbound">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <polyline points="19 12 12 19 5 12"/>
                            </svg>
                            Stock In Request
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">Potatoes</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-raw" style="font-size: 10px; padding: 1px 6px;">Raw Material</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">RM-POT-01</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: var(--panel-ink);">50</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">kg</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Main Warehouse</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">PO-2026-0919-01</span>
                    </td>
                </tr>

                <!-- Row 2: Procurement (Salt) -->
                <tr data-team="Procurement" data-action="Stock In Request" data-warehouse="Main Warehouse">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">10:32 AM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #FEF3C7; color: #92400E; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                MS
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Maria Santos</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Procurement Specialist</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team procurement">Procurement</span>
                    </td>
                    <td>
                        <span class="badge-action inbound">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <polyline points="19 12 12 19 5 12"/>
                            </svg>
                            Stock In Request
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">Salt</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-raw" style="font-size: 10px; padding: 1px 6px;">Raw Material</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">RM-SLT-04</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: var(--panel-ink);">20</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">kg</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Main Warehouse</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">PO-2026-0919-02</span>
                    </td>
                </tr>

                <!-- Row 3: Production (Took Raw Materials) -->
                <tr data-team="Production" data-action="Material Issued" data-warehouse="Bonded Distillery">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">11:45 AM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #F3E8FF; color: #6B21A8; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                RR
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Ricardo Ramos</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Master Distiller</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team production">Production</span>
                    </td>
                    <td>
                        <span class="badge-action issue">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="16 16 12 12 8 16"/>
                                <line x1="12" y1="12" x2="12" y2="21"/>
                                <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                            </svg>
                            Material Issued (Took Raw)
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">Premium Malted Barley</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-raw" style="font-size: 10px; padding: 1px 6px;">Raw Material</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">RM-BRL-02</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: #92400E;">120</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">kg</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-bond">Bonded Distillery</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">BATCH-DIS-881</span>
                    </td>
                </tr>

                <!-- Row 4: Production (Stocked in Finished Goods) -->
                <tr data-team="Production" data-action="Stock In Finished Goods" data-warehouse="Bottling &amp; Packaging">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">01:20 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #F3E8FF; color: #6B21A8; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                EG
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Elena Gomez</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Packaging Supervisor</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team production">Production</span>
                    </td>
                    <td>
                        <span class="badge-action inbound">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Stock In Finished Goods
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">Barrel Reserve Rum 750ml</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-fg" style="font-size: 10px; padding: 1px 6px;">Finished Good</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">FG-RUM-01</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: #15803D;">350</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">bottles</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-bott">Bottling &amp; Packaging</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">BATCH-BOT-104</span>
                    </td>
                </tr>

                <!-- Row 5: Sales (Finished Goods Stock Out) -->
                <tr data-team="Sales" data-action="Stock Out" data-warehouse="Main Warehouse">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">02:40 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #E0F2FE; color: #0369A1; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                CM
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Carlo Mendoza</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Sales Logistics Officer</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team sales">Sales</span>
                    </td>
                    <td>
                        <span class="badge-action outbound">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="19" x2="12" y2="5"/>
                                <polyline points="5 12 12 5 19 12"/>
                            </svg>
                            Stock Out (Sales Dispatch)
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">Single Malt Whisky 700ml</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-fg" style="font-size: 10px; padding: 1px 6px;">Finished Good</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">FG-WHK-02</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: #991B1B;">60</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">cases</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Main Warehouse</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">SO-DISP-542</span>
                    </td>
                </tr>

                <!-- Row 6: Inventory (Stock Adjustment) -->
                <tr data-team="Inventory" data-action="Cycle Count" data-warehouse="Bonded Distillery">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">03:15 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #E6F4F1; color: #165B50; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                VS
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Vincent Santos</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Inventory Auditor</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team inventory">Inventory</span>
                    </td>
                    <td>
                        <span class="badge-action adjustment">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                            Cycle Count Adjustment
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">Neutral Cane Spirit</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-raw" style="font-size: 10px; padding: 1px 6px;">Raw Material</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">RM-NCS-03</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: #15803D;">+15</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">L</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-bond">Bonded Distillery</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">AUDIT-CNT-09</span>
                    </td>
                </tr>

                <!-- Row 7: Inventory (Inter-Warehouse Transfer) -->
                <tr data-team="Inventory" data-action="Transfer" data-warehouse="Laguna Central Hub">
                    <td style="white-space: nowrap;">
                        <strong style="color: var(--panel-ink);">Sept 19, 2026</strong><br>
                        <small style="color: var(--gray); font-family: monospace; font-size: 12px;">04:05 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #E6F4F1; color: #165B50; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                TR
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--panel-ink);">Teresa Reyes</div>
                                <div style="font-size: 11.5px; color: var(--gray);">Warehouse Supervisor</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team inventory">Inventory</span>
                    </td>
                    <td>
                        <span class="badge-action transfer">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="17 1 21 5 17 9"/>
                                <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                                <polyline points="7 23 3 19 7 15"/>
                                <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                            </svg>
                            Inter-Warehouse Transfer
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--panel-ink); font-size: 13.5px;">French Oak Chips</strong>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                            <span class="badge-type type-raw" style="font-size: 10px; padding: 1px 6px;">Raw Material</span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--gray);">RM-FOC-09</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 15px; color: #1D4ED8;">40</span>
                        <span style="color: var(--gray); font-weight: 500; font-size: 12.5px;">kg</span>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Laguna Central Hub</span>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-size: 11.5px; color: var(--panel-ink); font-weight: 600;">XFER-WH-41</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Table Pagination / Summary Footer -->
    <div style="padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 12px; background: #F8FAFC;">
        <span id="fullLogTableCount" style="font-size: 12.5px; color: var(--gray); font-weight: 500;">
            Showing <strong>7</strong> of <strong>7</strong> total accountability events (UI Demonstration Dataset)
        </span>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="alert('Export simulated: CSV format contains 7 cross-team records.');">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span>Export CSV</span>
            </button>
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
        const rowTeam = row.getAttribute('data-team') || '';
        const rowAction = row.getAttribute('data-action') || '';
        const rowWarehouse = row.getAttribute('data-warehouse') || '';
        const text = row.innerText.toLowerCase();

        const matchTeam = (team === 'all' || rowTeam.toLowerCase() === team.toLowerCase());
        const matchAction = (action === 'all' || rowAction.toLowerCase().includes(action.toLowerCase()));
        const matchWarehouse = (warehouse === 'all' || rowWarehouse.toLowerCase().includes(warehouse.toLowerCase()));
        const matchQuery = !query || text.includes(query);

        if (matchTeam && matchAction && matchWarehouse && matchQuery) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    const countEl = document.getElementById('fullLogTableCount');
    if (countEl) {
        countEl.innerHTML = `Showing <strong>${visible}</strong> of <strong>${rows.length}</strong> total accountability events (UI Demonstration Dataset)`;
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
