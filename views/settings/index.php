<?php
/**
 * View: System Settings & Governance Portal
 * StockPilot — Liquor Business Inventory Management System
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
$currentWarehouseId = $isSuperAdmin ? 0 : (int)($currentUser['warehouse_id'] ?? 0);
$recentAccountabilityLogs = AccountabilityService::getLogs($currentWarehouseId, ['limit' => 20]);

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
                <?php if (empty($recentAccountabilityLogs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: var(--gray);">
                            No recent accountability records for this facility.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentAccountabilityLogs as $log): 
                        $createdAt = strtotime($log['created_at']);
                        $dateFormatted = date('M d, Y', $createdAt);
                        $timeFormatted = date('h:i A', $createdAt);
                        $initials = AccountabilityService::getUserInitials($log['user_name']);
                        $team = htmlspecialchars($log['team']);
                        $actionBadge = AccountabilityService::formatActionBadge($log['action_type'], $log['channel']);
                        $teamBadge = AccountabilityService::formatTeamBadge($log['team']);
                        $whName = htmlspecialchars($log['warehouse_name']);
                        $destName = !empty($log['dest_name']) ? htmlspecialchars($log['dest_name']) : '';
                    ?>
                    <tr data-team="<?= $team ?>">
                        <td style="white-space: nowrap;">
                            <strong><?= $dateFormatted ?></strong><br>
                            <small style="color: var(--gray);"><?= $timeFormatted ?></small>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #E2E8F0; color: #1E293B; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <?= htmlspecialchars($initials) ?>
                                </div>
                                <div>
                                    <strong><?= htmlspecialchars($log['user_name']) ?></strong>
                                    <div style="font-size: 11px; color: var(--gray);"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['user_role'] ?? 'user'))) ?></div>
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
                                <strong><?= htmlspecialchars($log['item_name']) ?></strong>
                                <div style="font-size: 11.5px; color: var(--gray);"><?= htmlspecialchars($log['item_code'] ?? '') ?></div>
                            <?php else: ?>
                                <span style="color: var(--gray); font-size: 13px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if ((float)$log['quantity'] != 0): ?>
                                <span style="font-weight: 700; font-size: 14px;"><?= (float)$log['quantity'] > 0 ? '+' : '' ?><?= rtrim(rtrim(number_format((float)$log['quantity'], 4), '0'), '.') ?></span>
                                <small style="color: var(--gray);"><?= htmlspecialchars($log['unit'] ?? '') ?></small>
                            <?php else: ?>
                                <span style="color: var(--gray); font-size: 13px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-wh wh-main"><?= $whName ?></span>
                            <?php if (!empty($destName)): ?>
                                <div style="font-size: 10.5px; color: var(--gray); margin-top: 1px;">➔ <?= $destName ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
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
