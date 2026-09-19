<?php
/**
 * View: System Settings & Governance Portal
 * StockPilot — Liquor Business Inventory Management System
 */

require_once __DIR__ . '/../../controllers/AuthController.php';

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    header('Location: ' . $auth->getLoginRedirectUrl());
    exit;
}

$currentUser = $auth->getCurrentUser();
$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');

$pageTitle   = 'System Settings & Accountability — StockPilot';
$activePage  = 'settings';
$activeGroup = 'settings';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$currentWarehouseCode = $assignedWarehouse['warehouse_code'] ?? 'WH-MAIN';
$currentWarehouseName = $assignedWarehouse['warehouse_name'] ?? 'Main Warehouse';
$currentBranchLabel = htmlspecialchars($currentWarehouseCode . ' · ' . $currentWarehouseName);
$userRoleDisplay = htmlspecialchars(ucfirst(str_replace('_', ' ', $currentUser['role'] ?? 'admin')));
$userNameDisplay = htmlspecialchars($currentUser['name'] ?? 'Administrator');
$userEmailDisplay = htmlspecialchars($currentUser['email'] ?? 'admin@inventory.local');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Settings &amp; Accountability</h1>
        <p class="page-subtitle">Personal account configuration, cross-team inventory accountability audit log, and administrative support</p>
    </div>
    <div class="header-actions">
        <a href="<?= BASE_URL ?>views/settings/accountability.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                <path d="m9 14 2 2 4-4"/>
            </svg>
            <span>Whole Page Accountability Log</span>
        </a>
        <button type="button" class="btn btn-primary" onclick="openSettingsModal('contact_support')" style="background: #7C3AED; border-color: #7C3AED;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                <line x1="8" y1="10" x2="16" y2="10"/>
                <line x1="8" y1="14" x2="13" y2="14"/>
            </svg>
            <span>Contact Support</span>
        </button>
    </div>
</div>

<!-- KPI Stats Grid -->
<div class="stats-grid stats-grid-3 mb-6">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Operating Facility</span>
            <div class="stat-icon-wrap" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                    <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                    <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="font-size: 20px;"><?= htmlspecialchars($currentWarehouseCode) ?></div>
        <div class="stat-meta"><?= htmlspecialchars($currentWarehouseName) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Audit Trail Status</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #1F7A6C;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="font-size: 20px; color: #15803D;">Active</div>
        <div class="stat-meta">Cross-team accountability logs enabled</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Support Channel</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #7C3AED;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" style="font-size: 20px; color: #7C3AED;">Super Admin</div>
        <div class="stat-meta">Direct administrative desk ready</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
    
    <!-- Left Column: Settings Quick Links Card -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <div>
                <h2 class="card-title">Settings Navigation</h2>
                <p class="card-desc">Configure security, notifications, and export warehouse audit sheets</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openSettingsModal('main')">
                <span>View Full Menu</span>
            </button>
        </div>

        <div class="settings-group" style="box-shadow: none;">
            <div class="settings-row" onclick="openSettingsModal('account')" role="button" tabindex="0">
                <div class="settings-row-left">
                    <div class="settings-squircle teal">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </div>
                    <div>
                        <div class="settings-row-title">My Account Profile</div>
                        <div class="settings-row-subtitle">Assigned facility &amp; credentials</div>
                    </div>
                </div>
                <div class="settings-row-right">
                    <span class="settings-row-value"><?= $userRoleDisplay ?></span>
                    <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </div>
            </div>
            <div class="settings-divider"></div>
            <div class="settings-row" onclick="openSettingsModal('password')" role="button" tabindex="0">
                <div class="settings-row-left">
                    <div class="settings-squircle bronze">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="7.5" cy="15.5" r="5.5"/>
                            <path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>
                        </svg>
                    </div>
                    <div>
                        <div class="settings-row-title">Change Password</div>
                        <div class="settings-row-subtitle">Update password &amp; OTP reset</div>
                    </div>
                </div>
                <div class="settings-row-right">
                    <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </div>
            </div>
            <div class="settings-divider"></div>
            <div class="settings-row" onclick="openSettingsModal('backup')" role="button" tabindex="0">
                <div class="settings-row-left">
                    <div class="settings-squircle green">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </div>
                    <div>
                        <div class="settings-row-title">Backup &amp; Export</div>
                        <div class="settings-row-subtitle">Warehouse CSV exports &amp; audit ledgers</div>
                    </div>
                </div>
                <div class="settings-row-right">
                    <span class="settings-row-value"><?= htmlspecialchars($currentWarehouseCode) ?></span>
                    <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Contact Support Form Section -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="settings-squircle purple" style="width: 28px; height: 28px; border-radius: 7px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="card-title">Contact Support</h2>
                    <p class="card-desc">Visually compose inquiries to the Super Admin</p>
                </div>
            </div>
            <span class="settings-badge" style="background: #7C3AED;">UI Only</span>
        </div>

        <div id="pageSupportFeedback" style="display: none; background: var(--success-light); border: 1px solid var(--success-border); border-radius: 10px; padding: 10px 14px; align-items: center; gap: 8px; margin-bottom: 14px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--success);">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span style="font-size: 12.5px; color: #14532D; font-weight: 500;">
                Message simulated to <strong>Super Admin</strong>. (UI Demo Mode)
            </span>
        </div>

        <form onsubmit="event.preventDefault(); document.getElementById('pageSupportFeedback').style.display = 'flex'; this.reset();" style="display: flex; flex-direction: column; gap: 12px;">
            <div>
                <label style="font-size: 12px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 4px;">Recipient</label>
                <div class="support-recipient-capsule" style="padding: 6px 12px;">
                    <span style="font-weight: 600; color: var(--panel-ink);">Super Admin</span>
                    <span style="font-size: 11.5px; color: var(--gray);">&lt;superadmin@centhub.local&gt;</span>
                </div>
            </div>
            <div>
                <label style="font-size: 12px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 4px;">Subject</label>
                <input type="text" class="search-box" style="width: 100%; height: 36px; padding: 0 12px; border-radius: 8px;" placeholder="e.g., Raw material requisition discrepancy" required>
            </div>
            <div>
                <label style="font-size: 12px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 4px;">Message</label>
                <textarea rows="3" class="search-box" style="width: 100%; height: auto; min-height: 80px; padding: 8px 12px; border-radius: 8px; resize: vertical;" placeholder="Type message for Super Admin..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px;">
                <button type="reset" class="btn btn-secondary btn-sm" style="height: 34px;">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" style="height: 34px; background: #7C3AED; border-color: #7C3AED;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    <span>Send Message</span>
                </button>
            </div>
        </form>
    </div>

</div>

<!-- Main Accountability Section Card -->
<div class="card">
    <div class="card-header">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="settings-squircle navy" style="width: 32px; height: 32px; border-radius: 8px;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                    <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                    <path d="m9 14 2 2 4-4"/>
                </svg>
            </div>
            <div>
                <h2 class="card-title">Inventory Accountability Audit Log</h2>
                <p class="card-desc">Tracks who performed actions, what was handled, when it occurred, and which warehouse was affected</p>
            </div>
            <a href="<?= BASE_URL ?>views/settings/accountability.php" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <span>Whole Page View</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
            </a>
        </div>

        <div class="filter-group">
            <div class="search-wrap">
                <span class="search-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                </span>
                <input type="text" id="pageAccountabilitySearch" class="search-box" placeholder="Filter user, team, material, or warehouse..." oninput="filterPageAccountability()">
            </div>
            <select id="pageTeamFilter" class="select-filter" onchange="filterPageAccountability()">
                <option value="all">All Teams</option>
                <option value="Procurement">Procurement</option>
                <option value="Production">Production</option>
                <option value="Sales">Sales</option>
                <option value="Inventory">Inventory</option>
            </select>
        </div>
    </div>

    <!-- Accountability Table -->
    <div class="table-responsive">
        <table id="pageAccountabilityTable">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>User</th>
                    <th>Team</th>
                    <th>Action</th>
                    <th>Raw Material / Item</th>
                    <th style="text-align: right;">Quantity</th>
                    <th>Warehouse</th>
                </tr>
            </thead>
            <tbody>
                <!-- Row 1: Procurement (Potatoes) -->
                <tr data-team="Procurement">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">09:15 AM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #FEF3C7; color: #92400E; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                JD
                            </div>
                            <strong>Juan Dela Cruz</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team procurement">Procurement</span>
                    </td>
                    <td>
                        <span class="badge-action inbound">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>
                            </svg>
                            Stock In Request
                        </span>
                    </td>
                    <td>
                        <strong>Potatoes</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Raw Material &middot; RM-POT-01</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px;">50</span>
                        <small style="color: var(--gray);">kg</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Main Warehouse</span>
                    </td>
                </tr>

                <!-- Row 2: Procurement (Salt) -->
                <tr data-team="Procurement">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">10:32 AM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #FEF3C7; color: #92400E; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                MS
                            </div>
                            <strong>Maria Santos</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team procurement">Procurement</span>
                    </td>
                    <td>
                        <span class="badge-action inbound">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>
                            </svg>
                            Stock In Request
                        </span>
                    </td>
                    <td>
                        <strong>Salt</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Raw Material &middot; RM-SLT-04</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px;">20</span>
                        <small style="color: var(--gray);">kg</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Main Warehouse</span>
                    </td>
                </tr>

                <!-- Row 3: Production (Took raw materials) -->
                <tr data-team="Production">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">11:45 AM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #F3E8FF; color: #6B21A8; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                RR
                            </div>
                            <strong>Ricardo Ramos</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team production">Production</span>
                    </td>
                    <td>
                        <span class="badge-action issue">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/>
                            </svg>
                            Material Issued (Took Raw)
                        </span>
                    </td>
                    <td>
                        <strong>Premium Malted Barley</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Raw Material &middot; RM-BRL-02</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px; color: #92400E;">120</span>
                        <small style="color: var(--gray);">kg</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-bond">Bonded Distillery</span>
                    </td>
                </tr>

                <!-- Row 4: Production (Stocked in finished goods) -->
                <tr data-team="Production">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">01:20 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #F3E8FF; color: #6B21A8; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                EG
                            </div>
                            <strong>Elena Gomez</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team production">Production</span>
                    </td>
                    <td>
                        <span class="badge-action inbound">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/>
                            </svg>
                            Stock In Finished Goods
                        </span>
                    </td>
                    <td>
                        <strong>Barrel Reserve Rum 750ml</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Finished Good &middot; FG-RUM-01</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px; color: #15803D;">350</span>
                        <small style="color: var(--gray);">bottles</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-bott">Bottling &amp; Packaging</span>
                    </td>
                </tr>

                <!-- Row 5: Sales (Finished Goods Stock Out) -->
                <tr data-team="Sales">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">02:40 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #E0F2FE; color: #0369A1; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                CM
                            </div>
                            <strong>Carlo Mendoza</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team sales">Sales</span>
                    </td>
                    <td>
                        <span class="badge-action outbound">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>
                            </svg>
                            Stock Out (Sales Dispatch)
                        </span>
                    </td>
                    <td>
                        <strong>Single Malt Whisky 700ml</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Finished Good &middot; FG-WHK-02</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px; color: #991B1B;">60</span>
                        <small style="color: var(--gray);">cases</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Main Warehouse</span>
                    </td>
                </tr>

                <!-- Row 6: Inventory (Stock Adjustment) -->
                <tr data-team="Inventory">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">03:15 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #E6F4F1; color: #165B50; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                VS
                            </div>
                            <strong>Vincent Santos</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team inventory">Inventory</span>
                    </td>
                    <td>
                        <span class="badge-action adjustment">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            Cycle Count Adjustment
                        </span>
                    </td>
                    <td>
                        <strong>Neutral Cane Spirit</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Raw Material &middot; RM-NCS-03</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px; color: #15803D;">+15</span>
                        <small style="color: var(--gray);">L</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-bond">Bonded Distillery</span>
                    </td>
                </tr>

                <!-- Row 7: Inventory (Inter-Warehouse Transfer) -->
                <tr data-team="Inventory">
                    <td style="white-space: nowrap;">
                        <strong>Sept 19, 2026</strong><br>
                        <small style="color: var(--gray);">04:05 PM</small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #E6F4F1; color: #165B50; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                TR
                            </div>
                            <strong>Teresa Reyes</strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-team inventory">Inventory</span>
                    </td>
                    <td>
                        <span class="badge-action transfer">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                            </svg>
                            Inter-Warehouse Transfer
                        </span>
                    </td>
                    <td>
                        <strong>French Oak Chips</strong>
                        <div style="font-size: 11.5px; color: var(--gray);">Raw Material &middot; RM-FOC-09</div>
                    </td>
                    <td style="text-align: right;">
                        <span style="font-weight: 700; font-size: 14px; color: #1D4ED8;">40</span>
                        <small style="color: var(--gray);">kg</small>
                    </td>
                    <td>
                        <span class="badge-wh wh-main">Laguna Central Hub</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterPageAccountability() {
    const query = (document.getElementById('pageAccountabilitySearch')?.value || '').toLowerCase().trim();
    const team = (document.getElementById('pageTeamFilter')?.value || 'all');
    const rows = document.querySelectorAll('#pageAccountabilityTable tbody tr');

    rows.forEach(row => {
        const rowTeam = row.getAttribute('data-team') || '';
        const rowText = row.innerText.toLowerCase();

        const matchesTeam = (team === 'all' || rowTeam.toLowerCase() === team.toLowerCase());
        const matchesQuery = !query || rowText.includes(query);

        row.style.display = (matchesTeam && matchesQuery) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
