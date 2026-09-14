<?php
/**
 * View: Users & Account Administration
 * StockPilot — Liquor Business Inventory Management System
 */

$pageTitle   = 'Users & Accounts — StockPilot';
$activePage  = 'users';
$activeGroup = 'users';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Assigned branch for current session
$currentBranch = $assignedWarehouse 
    ? ($assignedWarehouse['warehouse_code'] . ' (' . $assignedWarehouse['warehouse_name'] . ')')
    : 'WH-MAIN (Main Warehouse - Laguna)';

// Query users from database with assigned warehouse
$sql = "
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.role,
        u.status,
        u.warehouse_id,
        w.warehouse_code,
        w.warehouse_name,
        u.created_at,
        u.updated_at
    FROM users u
    LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($roleFilter !== '') {
    $sql .= " AND u.role = ?";
    $params[] = $roleFilter;
}

if ($statusFilter !== '') {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY u.created_at DESC, u.user_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalUsers = count($users);
$activeCount = 0;
$adminCount = 0;
foreach ($users as $u) {
    if (($u['status'] ?? '') === 'active') $activeCount++;
    if (in_array($u['role'] ?? '', ['admin', 'super_admin'])) $adminCount++;
}

$totalWarehouses = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active'")->fetchColumn();

// Map functional department/team based on email or role for liquor ERP display
function getTeamAssignment($email, $role) {
    $emailLower = strtolower($email);
    if (str_contains($emailLower, 'procure')) return 'Procurement Team';
    if (str_contains($emailLower, 'prod') || str_contains($emailLower, 'brew') || str_contains($emailLower, 'distill')) return 'Production & Distillation';
    if (str_contains($emailLower, 'sale') || str_contains($emailLower, 'order')) return 'Commercial Sales';
    if (str_contains($emailLower, 'super') || $role === 'super_admin') return 'Executive Administration';
    return 'Warehouse Inventory Operations';
}

function getFacilityAssignment($user) {
    if (is_array($user) && !empty($user['warehouse_code'])) {
        return $user['warehouse_code'] . ' (' . ($user['warehouse_name'] ?? 'Facility') . ')';
    }
    $emailLower = is_array($user) ? strtolower($user['email'] ?? '') : strtolower((string)$user);
    if (str_contains($emailLower, 'procure') || str_contains($emailLower, 'raw')) return 'WH-MAIN (Main Warehouse - Laguna)';
    if (str_contains($emailLower, 'sales') || str_contains($emailLower, 'bond')) return 'WH-BOND (Bonded Warehouse - Manila)';
    if (str_contains($emailLower, 'prod') || str_contains($emailLower, 'bott') || str_contains($emailLower, 'fg')) return 'WH-BOTT (Bottling Area - Bulacan)';
    return 'WH-MAIN (Main Warehouse - Laguna)';
}
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Users & Account Management</h1>
        <p class="page-subtitle">Directory of authorized distillery operators, inventory controllers, and cross-team service accounts</p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect width="12" height="8" x="6" y="14"/>
            </svg>
            <span>Print User Roster</span>
        </button>
        <a href="<?= BASE_URL ?>views/auth/register.php" class="btn btn-primary" target="_blank">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>Register Operator</span>
        </a>
    </div>
</div>

<!-- My Profile Spotlight Card -->
<div class="card mb-6" style="border-left: 4px solid #1F7A6C;">
    <div class="card-body">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="user-avatar" style="width: 52px; height: 52px; font-size: 20px; background: #14213D; color: #FFFFFF;">
                    <?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 style="font-size: 18px; font-weight: 700; color: #14213D; margin: 0;">
                            <?= htmlspecialchars($currentUser['name'] ?? 'Administrator') ?>
                        </h2>
                        <span class="badge badge-teal">Current Session</span>
                    </div>
                    <div class="text-xs text-muted flex items-center gap-3 mt-1">
                        <span>Email: <strong><?= htmlspecialchars($currentUser['email'] ?? 'admin@inventory.local') ?></strong></span>
                        <span>•</span>
                        <span>Role: <strong class="capitalize"><?= htmlspecialchars(str_replace('_', ' ', $currentUser['role'] ?? 'admin')) ?></strong></span>
                        <span>•</span>
                        <span>Assigned Scope: <strong><?= htmlspecialchars($currentBranch) ?></strong></span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge badge-navy" style="padding: 8px 12px; font-size: 12px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-1">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    Authenticated via Secure Session Guard
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Summary Metrics -->
<div class="kpi-grid mb-6">
    <div class="kpi-card">
        <div class="kpi-icon navy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </div>
        <div class="kpi-meta">
            <span class="kpi-label">Registered Accounts</span>
            <div class="kpi-value"><?= number_format($totalUsers) ?></div>
            <span class="kpi-subtext">System operators & administrators</span>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon teal">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>
        <div class="kpi-meta">
            <span class="kpi-label">Active Status</span>
            <div class="kpi-value" style="color: #1F7A6C;"><?= number_format($activeCount) ?></div>
            <span class="kpi-subtext">Currently authorized logins</span>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon bronze">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <div class="kpi-meta">
            <span class="kpi-label">Administrative Roles</span>
            <div class="kpi-value" style="color: #C69255;"><?= number_format($adminCount) ?></div>
            <span class="kpi-subtext">Admin & Super Admin privileges</span>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon navy">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="18" x="3" y="3" rx="2"/>
                <path d="M7 7h10"/>
                <path d="M7 12h10"/>
                <path d="M7 17h10"/>
            </svg>
        </div>
        <div class="kpi-meta">
            <span class="kpi-label">Active Facilities</span>
            <div class="kpi-value"><?= $totalWarehouses ?></div>
            <span class="kpi-subtext">Laguna, Manila &amp; Bulacan facilities</span>
        </div>
    </div>
</div>

<!-- Filters Card -->
<div class="card">
    <form method="GET" action="index.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <!-- Search -->
        <div class="search-wrap" style="flex: 1; min-width: 220px;">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" name="search" class="search-box" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>" id="userSearchInput" onkeyup="filterTable('userSearchInput', 'usersTable')">
        </div>

        <!-- Role Filter -->
        <select name="role" class="select-filter">
            <option value="">All Roles</option>
            <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="operator" <?= $roleFilter === 'operator' ? 'selected' : '' ?>>Operator</option>
        </select>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="height: 38px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                </svg>
                <span>Filter</span>
            </button>
            <a href="index.php" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" title="Reset Filters">
                <span>Reset</span>
            </a>
        </div>
    </form>
</div>

<!-- Users Table Card -->
<div class="card">
    <div class="card-header">
        <div class="flex items-center gap-3">
            <h2 class="card-title">Operator & Service Account Directory</h2>
            <span class="badge badge-teal"><?= $totalUsers ?> Accounts</span>
        </div>
        <div class="text-xs text-muted">
            Synchronized with team_inventory access control
        </div>
    </div>
    <div class="table-responsive">
        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th>User / Operator</th>
                    <th>Security Role</th>
                    <th>Functional Team</th>
                    <th>Assigned Facility</th>
                    <th>Account Status</th>
                    <th>Registration Date</th>
                    <th class="text-right">Audit Access</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-10 text-muted">
                            No user accounts match your search filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): 
                        $team = getTeamAssignment($u['email'], $u['role'] ?? '');
                        $facility = getFacilityAssignment($u);
                        $isCurrent = ((int)$u['user_id'] === (int)($currentUser['id'] ?? 0));
                    ?>
                        <tr style="<?= $isCurrent ? 'background-color: rgba(31, 122, 108, 0.04);' : '' ?>">
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="user-avatar" style="width: 34px; height: 34px; font-size: 13px;">
                                        <?= strtoupper(substr($u['name'] ?: 'U', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-medium text-navy flex items-center gap-2">
                                            <?= htmlspecialchars($u['name']) ?>
                                            <?php if ($isCurrent): ?>
                                                <span class="badge badge-teal text-xs" style="font-size: 10px; padding: 1px 6px;">You</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-mono text-xs text-muted"><?= htmlspecialchars($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (($u['role'] ?? '') === 'super_admin'): ?>
                                    <span class="badge badge-navy">Super Admin</span>
                                <?php elseif (($u['role'] ?? '') === 'admin'): ?>
                                    <span class="badge badge-teal">Administrator</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?= htmlspecialchars(ucfirst($u['role'] ?? 'Operator')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-bronze text-xs">
                                    <?= htmlspecialchars($team) ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-xs font-medium text-navy">
                                    <?= htmlspecialchars($facility) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (($u['status'] ?? '') === 'active'): ?>
                                    <span class="badge badge-teal">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= htmlspecialchars(ucfirst($u['status'] ?? 'Inactive')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="font-mono text-xs whitespace-nowrap text-muted">
                                <?= !empty($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="showUserDetails(<?= htmlspecialchars(json_encode([
                                    'id' => $u['user_id'],
                                    'name' => $u['name'],
                                    'email' => $u['email'],
                                    'role' => $u['role'],
                                    'status' => $u['status'],
                                    'team' => $team,
                                    'facility' => $facility,
                                    'created_at' => $u['created_at'] ? date('M d, Y H:i:s', strtotime($u['created_at'])) : '—'
                                ])) ?>)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    <span>Profile</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Drawer for User Account Details -->
<div id="userModal" class="modal" role="dialog" aria-hidden="true" style="display: none; position: fixed; inset: 0; background: rgba(20, 33, 61, 0.5); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(3px);">
    <div class="card" style="width: 100%; max-width: 520px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); animation: modalFadeIn 0.2s ease;">
        <div class="card-header flex justify-between items-center">
            <h3 class="card-title" id="modalUserName">User Account Profile</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeUserModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 16px; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #E2E8F0;">
                <div id="modalUserAvatar" class="user-avatar" style="width: 56px; height: 56px; font-size: 22px; background: #14213D; color: #FFFFFF;">
                    U
                </div>
                <div>
                    <h4 id="modalNameText" style="font-size: 16px; font-weight: 700; color: #14213D; margin: 0 0 4px 0;">User Name</h4>
                    <div id="modalEmailText" class="font-mono text-xs text-muted">user@inventory.local</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div>
                    <label class="form-label" style="font-size: 11px;">System Role</label>
                    <div id="modalRoleText" class="font-semibold text-navy text-sm">—</div>
                </div>
                <div>
                    <label class="form-label" style="font-size: 11px;">Account Status</label>
                    <div id="modalStatusText" class="font-semibold text-teal text-sm">—</div>
                </div>
                <div>
                    <label class="form-label" style="font-size: 11px;">Functional Team</label>
                    <div id="modalTeamText" class="text-sm font-medium text-navy">—</div>
                </div>
                <div>
                    <label class="form-label" style="font-size: 11px;">Assigned Warehouse</label>
                    <div id="modalFacilityText" class="text-sm font-medium text-navy">—</div>
                </div>
                <div style="grid-column: span 2;">
                    <label class="form-label" style="font-size: 11px;">Created Timestamp</label>
                    <div id="modalCreatedText" class="font-mono text-xs text-muted">—</div>
                </div>
            </div>
        </div>
        <div class="card-footer flex justify-end gap-2" style="background: #F8FAFC; border-top: 1px solid #E2E8F0; padding: 12px 20px;">
            <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Close</button>
        </div>
    </div>
</div>

<script>
function showUserDetails(userData) {
    document.getElementById('modalUserName').innerText = userData.name + ' — Details';
    document.getElementById('modalNameText').innerText = userData.name;
    document.getElementById('modalEmailText').innerText = userData.email;
    document.getElementById('modalUserAvatar').innerText = userData.name.charAt(0).toUpperCase();
    document.getElementById('modalRoleText').innerText = userData.role.toUpperCase();
    document.getElementById('modalStatusText').innerText = userData.status.toUpperCase();
    document.getElementById('modalTeamText').innerText = userData.team;
    document.getElementById('modalFacilityText').innerText = userData.facility;
    document.getElementById('modalCreatedText').innerText = userData.created_at;

    const modal = document.getElementById('userModal');
    modal.style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
}

window.addEventListener('click', function(e) {
    const modal = document.getElementById('userModal');
    if (e.target === modal) {
        closeUserModal();
    }
});
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
