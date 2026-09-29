<?php
/**
 * View: Users & Account Administration
 * InventoryTeam — Liquor Business Inventory Management System
 */

require_once __DIR__ . '/../../controllers/AuthController.php';

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    header('Location: ' . $auth->getLoginRedirectUrl());
    exit;
}

$currentUser = $auth->getCurrentUser();
$isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');

$pageTitle   = $isSuperAdmin ? 'Users & Accounts — InventoryTeam' : 'My Account — InventoryTeam';
$activePage  = $isSuperAdmin ? 'users' : 'my_account';
$activeGroup = $isSuperAdmin ? 'users' : 'settings';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$successMessage = null;
$errorMessage   = null;

// Handle User Creation & Updates (Super Admin only)
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken()) {
        $errorMessage = "Security validation failed: Invalid or expired CSRF token. Please refresh the page and try again.";
    } else {
        $action = $_POST['action'];

        if ($action === 'create_user') {
            $uName       = trim($_POST['name'] ?? '');
            $uEmail      = strtolower(trim($_POST['email'] ?? ''));
            $uPassword   = $_POST['password'] ?? '';
            $uRole       = trim($_POST['role'] ?? 'admin');
            $uTeam       = trim($_POST['team'] ?? 'Inventory');
            $uWarehouse  = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
            $uStatus     = trim($_POST['status'] ?? 'active');

            if (empty($uName) || mb_strlen($uName) > 100) {
                $errorMessage = "Full Name is required and cannot exceed 100 characters.";
            } elseif (empty($uEmail) || !filter_var($uEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($uEmail) > 150) {
                $errorMessage = "Please enter a valid email address (max 150 characters).";
            } elseif (strlen($uPassword) < 6) {
                $errorMessage = "Password must be at least 6 characters long.";
            } elseif (!in_array($uRole, ['super_admin', 'admin'], true)) {
                $errorMessage = "Security role must be either 'super_admin' or 'admin'.";
            } elseif (!in_array($uStatus, ['active', 'inactive'], true)) {
                $errorMessage = "Account status must be either 'active' or 'inactive'.";
            } else {
                try {
                    $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    $stmtChk->execute([$uEmail]);
                    if ((int)$stmtChk->fetchColumn() > 0) {
                        $errorMessage = "An account with email '{$uEmail}' already exists.";
                    } else {
                        $passwordHash = password_hash($uPassword, PASSWORD_BCRYPT);
                        $apiToken     = bin2hex(random_bytes(32));

                        $stmtIns = $pdo->prepare("
                            INSERT INTO users (name, email, password, role, team, warehouse_id, api_token, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtIns->execute([
                            $uName,
                            $uEmail,
                            $passwordHash,
                            $uRole,
                            $uTeam ?: 'Inventory',
                            $uWarehouse,
                            $apiToken,
                            $uStatus
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();

                        require_once __DIR__ . '/../../helpers/AccountabilityService.php';
                        AccountabilityService::log([
                            'user_id'          => (int)($currentUser['id'] ?? 1),
                            'team'             => 'Inventory',
                            'action_type'      => 'USER_CREATED',
                            'channel'          => 'UI',
                            'warehouse_id'     => $uWarehouse ?: ($currentWarehouseId ?: 1),
                            'reference_number' => "USR-{$newUserId}",
                            'notes'            => "Created user account: {$uName} ({$uEmail}) — Role: {$uRole}, Status: {$uStatus}"
                        ]);

                        $successMessage = "User account '{$uName}' ({$uEmail}) created successfully!";
                    }
                } catch (Exception $e) {
                    $errorMessage = "Failed to create user account: " . $e->getMessage();
                }
            }
        } elseif ($action === 'update_user') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $uName        = trim($_POST['name'] ?? '');
            $uEmail       = strtolower(trim($_POST['email'] ?? ''));
            $uRole        = trim($_POST['role'] ?? 'admin');
            $uTeam        = trim($_POST['team'] ?? 'Inventory');
            $uWarehouse   = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
            $uStatus      = trim($_POST['status'] ?? 'active');
            $newPassword  = $_POST['new_password'] ?? '';

            if ($targetUserId <= 0) {
                $errorMessage = "Invalid user account selected for update.";
            } elseif (empty($uName) || mb_strlen($uName) > 100) {
                $errorMessage = "Full Name is required and cannot exceed 100 characters.";
            } elseif (empty($uEmail) || !filter_var($uEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($uEmail) > 150) {
                $errorMessage = "Please enter a valid email address (max 150 characters).";
            } elseif (!in_array($uRole, ['super_admin', 'admin'], true)) {
                $errorMessage = "Security role must be either 'super_admin' or 'admin'.";
            } elseif (!in_array($uStatus, ['active', 'inactive'], true)) {
                $errorMessage = "Account status must be either 'active' or 'inactive'.";
            } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
                $errorMessage = "New password must be at least 6 characters long.";
            } elseif ($targetUserId === (int)($currentUser['id'] ?? 0) && ($uStatus !== 'active' || $uRole !== 'super_admin')) {
                $errorMessage = "You cannot deactivate or demote your own currently active Super Admin session.";
            } else {
                try {
                    $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
                    $stmtChk->execute([$uEmail, $targetUserId]);
                    if ((int)$stmtChk->fetchColumn() > 0) {
                        $errorMessage = "Another account is already using email '{$uEmail}'.";
                    } else {
                        if ($newPassword !== '') {
                            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
                            $stmtUpd = $pdo->prepare("
                                UPDATE users
                                SET name = ?, email = ?, password = ?, role = ?, team = ?, warehouse_id = ?, status = ?
                                WHERE user_id = ?
                            ");
                            $stmtUpd->execute([$uName, $uEmail, $passwordHash, $uRole, $uTeam ?: 'Inventory', $uWarehouse, $uStatus, $targetUserId]);
                        } else {
                            $stmtUpd = $pdo->prepare("
                                UPDATE users
                                SET name = ?, email = ?, role = ?, team = ?, warehouse_id = ?, status = ?
                                WHERE user_id = ?
                            ");
                            $stmtUpd->execute([$uName, $uEmail, $uRole, $uTeam ?: 'Inventory', $uWarehouse, $uStatus, $targetUserId]);
                        }

                        require_once __DIR__ . '/../../helpers/AccountabilityService.php';
                        AccountabilityService::log([
                            'user_id'          => (int)($currentUser['id'] ?? 1),
                            'team'             => 'Inventory',
                            'action_type'      => 'USER_UPDATED',
                            'channel'          => 'UI',
                            'warehouse_id'     => $uWarehouse ?: ($currentWarehouseId ?: 1),
                            'reference_number' => "USR-{$targetUserId}",
                            'notes'            => "Updated user account: {$uName} ({$uEmail}) — Role: {$uRole}, Status: {$uStatus}" . ($newPassword !== '' ? " [Password Reset]" : "")
                        ]);

                        $successMessage = "User account '{$uName}' updated successfully!";
                    }
                } catch (Exception $e) {
                    $errorMessage = "Failed to update user account: " . $e->getMessage();
                }
            }
        }
    }
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Assigned branch for current session
$currentBranch = $assignedWarehouse 
    ? ($assignedWarehouse['warehouse_code'] . ' (' . $assignedWarehouse['warehouse_name'] . ')')
    : 'WH-MAIN (Main Warehouse - Laguna)';

$users = [];
$warehousesList = [];
$totalUsers = 0;
$activeCount = 0;
$adminCount = 0;
$totalWarehouses = 0;

if ($isSuperAdmin) {
    $warehousesList = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Query users from database with assigned warehouse (Super Admin only)
    $sql = "
        SELECT 
            u.user_id,
            u.name,
            u.email,
            u.role,
            u.team,
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
    foreach ($users as $u) {
        if (($u['status'] ?? '') === 'active') $activeCount++;
        if (in_array($u['role'] ?? '', ['admin', 'super_admin'])) $adminCount++;
    }

    $totalWarehouses = count($warehousesList);
}

// Map functional department/team based on email or role for liquor ERP display
function getTeamAssignment($email, $role, $teamColumn = '') {
    if (!empty($teamColumn) && strtolower($teamColumn) !== 'inventory') {
        return $teamColumn . ' Team';
    }
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
    if (is_array($user) && ($user['role'] ?? '') === 'super_admin') {
        return 'All Facilities (Global Scope)';
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
        <h1 class="page-title"><?= $isSuperAdmin ? 'Users & Account Management' : 'My Account' ?></h1>
        <p class="page-subtitle"><?= $isSuperAdmin ? 'Directory of authorized distillery administrators, inventory controllers, and cross-team service accounts' : 'Personal account profile, assigned warehouse facility, and security settings' ?></p>
    </div>
    <div class="header-actions" style="display: flex; gap: 10px;">
        <?php if ($isSuperAdmin): ?>
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 6 2 18 2 18 9"/>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect width="12" height="8" x="6" y="14"/>
            </svg>
            <span>Print User Roster</span>
        </button>
        <button type="button" class="btn btn-primary" onclick="openCreateUserModal()">+ Add New User</button>
        <?php endif; ?>
    </div>
</div>

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

<!-- My Profile Spotlight Card -->
<div class="card mb-6" id="my-account" style="border-left: 4px solid #1F7A6C;">
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
            <div class="flex items-center gap-2" id="security">
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

<?php if (!$isSuperAdmin): ?>
<div class="card mb-6">
    <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
        <div>
            <h3 style="font-size: 16px; font-weight: 700; color: var(--panel-ink); margin: 0 0 4px 0;">Account &amp; Facility Settings</h3>
            <p style="font-size: 13px; color: var(--gray); margin: 0;">Manage your password, notifications, security preferences, CSV exports, and support inquiries from the unified Settings panel.</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="openSettingsModal('main')">Open Settings</button>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof openSettingsModal === 'function') {
        openSettingsModal('main');
    }
});
</script>
<?php else: ?>
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
    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <!-- Search -->
        <div class="search-wrap" style="flex: 1; min-width: 220px;">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" name="search" class="search-box" aria-label="Search user accounts by name or email" placeholder="Search by name, email, or facility..." value="<?= htmlspecialchars($search) ?>" id="userSearchInput" oninput="filterUsersDirectoryTable()">
        </div>

        <!-- Role Filter -->
        <select name="role" id="userRoleFilter" class="select-filter" aria-label="Filter users by security role" onchange="filterUsersDirectoryTable()">
            <option value="">All Roles</option>
            <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn btn-secondary" style="height: 38px; display: inline-flex; align-items: center;" onclick="resetUsersDirectoryFilters()" title="Reset Filters">
                <span>Reset</span>
            </button>
        </div>
    </div>
</div>

<!-- Users Table Card -->
<div class="card">
    <div class="card-header">
        <div class="flex items-center gap-3">
            <h2 class="card-title">Administrator &amp; Service Account Directory</h2>
            <span class="badge badge-teal"><?= $totalUsers ?> Accounts</span>
        </div>
        <div class="text-xs text-muted">
            Synchronized with team_inventory access control
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sticky-actions" id="usersTable">
            <thead>
                <tr>
                    <th>User Account</th>
                    <th>Security Role</th>
                    <th>Functional Team</th>
                    <th>Assigned Facility</th>
                    <th>Account Status</th>
                    <th>Registration Date</th>
                    <th class="text-right">Actions</th>
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
                        $team = getTeamAssignment($u['email'], $u['role'] ?? '', $u['team'] ?? '');
                        $facility = getFacilityAssignment($u);
                        $isCurrent = ((int)$u['user_id'] === (int)($currentUser['id'] ?? 0));
                    ?>
                        <tr data-role="<?= htmlspecialchars($u['role'] ?? '') ?>" style="<?= $isCurrent ? 'background-color: rgba(31, 122, 108, 0.04);' : '' ?>">
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
                                <?php else: ?>
                                    <span class="badge badge-teal">Administrator</span>
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
                                <div style="display: inline-flex; gap: 6px; justify-content: flex-end;">
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
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openEditUserModal(<?= htmlspecialchars(json_encode([
                                        'user_id'      => (int)$u['user_id'],
                                        'name'         => $u['name'],
                                        'email'        => $u['email'],
                                        'role'         => $u['role'] ?? 'admin',
                                        'team'         => $u['team'] ?? 'Inventory',
                                        'warehouse_id' => $u['warehouse_id'] ? (int)$u['warehouse_id'] : '',
                                        'status'       => $u['status'] ?? 'active',
                                        'is_self'      => $isCurrent
                                    ])) ?>)">
                                        <span>Edit</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Drawer for User Account Details -->
<div id="userModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalUserName" aria-hidden="true" onclick="if(event.target === this) closeUserModal()">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="card-title" id="modalUserName">User Account Profile</h3>
            <button type="button" class="modal-close" aria-label="Close modal" onclick="closeUserModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div style="display: flex; gap: 16px; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #E2E8F0;">
                <div id="modalUserAvatar" class="user-avatar" style="width: 56px; height: 56px; font-size: 22px; background: #14213D; color: #FFFFFF;">
                    U
                </div>
                <div>
                    <h4 id="modalNameText" style="font-size: 16px; font-weight: 700; color: #14213D; margin: 0 0 4px 0;">User Name</h4>
                    <div id="modalEmailText" class="font-mono text-xs text-muted">user@inventory.local</div>
                </div>
            </div>

            <div class="form-grid-2">
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
                <div style="grid-column: 1 / -1;">
                    <label class="form-label" style="font-size: 11px;">Created Timestamp</label>
                    <div id="modalCreatedText" class="font-mono text-xs text-muted">—</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Create New User Account -->
<div id="createUserModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="createUserModalTitle" onclick="if(event.target === this) closeCreateUserModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="modal-header">
                <div>
                    <h3 id="createUserModalTitle" class="card-title">Add New User Account</h3>
                    <p class="card-desc">Register a new warehouse administrator or cross-team account</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeCreateUserModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div class="form-grid-2" style="grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="createUserName" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Full Name <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="name" id="createUserName" class="search-box" style="width: 100%; padding-left: 12px;" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                    <div>
                        <label for="createUserEmail" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Email Address <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="email" name="email" id="createUserEmail" class="search-box" style="width: 100%; padding-left: 12px;" placeholder="user@inventory.local" required>
                    </div>
                </div>

                <div class="form-grid-2" style="grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="createUserPassword" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Initial Password <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="password" name="password" id="createUserPassword" class="search-box" style="width: 100%; padding-left: 12px;" minlength="6" placeholder="Min. 6 characters" required>
                    </div>
                    <div>
                        <label for="createUserRole" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Security Role <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="role" id="createUserRole" class="select-filter" style="width: 100%;" required>
                            <option value="admin">Administrator (Warehouse Scope)</option>
                            <option value="super_admin">Super Admin (Global Scope)</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2" style="grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="createUserTeam" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Functional Team <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="team" id="createUserTeam" class="select-filter" style="width: 100%;" required>
                            <option value="Inventory">Inventory Operations</option>
                            <option value="Procurement">Procurement Team</option>
                            <option value="Production">Production &amp; Distillation</option>
                            <option value="Sales">Commercial Sales</option>
                        </select>
                    </div>
                    <div>
                        <label for="createUserStatus" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Account Status <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="status" id="createUserStatus" class="select-filter" style="width: 100%;" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="createUserWarehouse" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Assigned Warehouse Facility
                    </label>
                    <select name="warehouse_id" id="createUserWarehouse" class="select-filter" style="width: 100%;">
                        <option value="">— Global / Unassigned (Super Admin) —</option>
                        <?php foreach ($warehousesList as $wh): ?>
                            <option value="<?= (int)$wh['warehouse_id'] ?>">
                                <?= htmlspecialchars($wh['warehouse_code']) ?> — <?= htmlspecialchars($wh['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCreateUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Existing User Account -->
<div id="editUserModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="editUserModalTitle" onclick="if(event.target === this) closeEditUserModal()">
    <div class="modal-card" style="max-width: 520px;">
        <form method="POST" action="index.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="editUserId" value="">
            <div class="modal-header">
                <div>
                    <h3 id="editUserModalTitle" class="card-title">Edit User Account</h3>
                    <p class="card-desc">Modify role, facility assignment, status, or reset password</p>
                </div>
                <button type="button" class="modal-close" aria-label="Close modal" onclick="closeEditUserModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div class="form-grid-2" style="grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="editUserName" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Full Name <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="text" name="name" id="editUserName" class="search-box" style="width: 100%; padding-left: 12px;" required>
                    </div>
                    <div>
                        <label for="editUserEmail" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Email Address <span style="color: #DC2626;">*</span>
                        </label>
                        <input type="email" name="email" id="editUserEmail" class="search-box" style="width: 100%; padding-left: 12px;" required>
                    </div>
                </div>

                <div class="form-grid-2" style="grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="editUserRole" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Security Role <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="role" id="editUserRole" class="select-filter" style="width: 100%;" required>
                            <option value="admin">Administrator (Warehouse Scope)</option>
                            <option value="super_admin">Super Admin (Global Scope)</option>
                        </select>
                    </div>
                    <div>
                        <label for="editUserStatus" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Account Status <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="status" id="editUserStatus" class="select-filter" style="width: 100%;" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive (Revoke Login)</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2" style="grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="editUserTeam" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Functional Team <span style="color: #DC2626;">*</span>
                        </label>
                        <select name="team" id="editUserTeam" class="select-filter" style="width: 100%;" required>
                            <option value="Inventory">Inventory Operations</option>
                            <option value="Procurement">Procurement Team</option>
                            <option value="Production">Production &amp; Distillation</option>
                            <option value="Sales">Commercial Sales</option>
                        </select>
                    </div>
                    <div>
                        <label for="editUserWarehouse" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                            Assigned Facility
                        </label>
                        <select name="warehouse_id" id="editUserWarehouse" class="select-filter" style="width: 100%;">
                            <option value="">— Global / Unassigned —</option>
                            <?php foreach ($warehousesList as $wh): ?>
                                <option value="<?= (int)$wh['warehouse_id'] ?>">
                                    <?= htmlspecialchars($wh['warehouse_code']) ?> — <?= htmlspecialchars($wh['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="editUserPassword" style="font-size: 13px; font-weight: 600; color: var(--panel-ink); margin-bottom: 6px; display: block;">
                        Reset Password <span style="color: var(--gray); font-weight: 400;">(Leave blank to keep current password)</span>
                    </label>
                    <input type="password" name="new_password" id="editUserPassword" class="search-box" style="width: 100%; padding-left: 12px;" minlength="6" placeholder="Optional new password (min. 6 characters)">
                </div>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateUserModal() {
    const modal = document.getElementById('createUserModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}
function closeCreateUserModal() {
    const modal = document.getElementById('createUserModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}
function openEditUserModal(u) {
    document.getElementById('editUserId').value = u.user_id;
    document.getElementById('editUserName').value = u.name || '';
    document.getElementById('editUserEmail').value = u.email || '';
    document.getElementById('editUserRole').value = u.role || 'admin';
    document.getElementById('editUserStatus').value = u.status || 'active';
    document.getElementById('editUserTeam').value = u.team || 'Inventory';
    document.getElementById('editUserWarehouse').value = u.warehouse_id || '';
    document.getElementById('editUserPassword').value = '';
    const modal = document.getElementById('editUserModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
}
function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function filterUsersDirectoryTable() {
    const query = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();
    const role = (document.getElementById('userRoleFilter')?.value || '').toLowerCase().trim();
    const table = document.getElementById('usersTable');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.cells.length <= 1) return;
        const rowRole = (row.getAttribute('data-role') || '').toLowerCase();
        const text = row.textContent.toLowerCase();

        const matchRole = !role || rowRole === role;
        const matchQuery = !query || text.includes(query);
        const match = matchRole && matchQuery;

        row.dataset.filteredOut = match ? 'false' : 'true';
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

function resetUsersDirectoryFilters() {
    const searchInput = document.getElementById('userSearchInput');
    const roleFilter = document.getElementById('userRoleFilter');
    if (searchInput) searchInput.value = '';
    if (roleFilter) roleFilter.value = '';
    filterUsersDirectoryTable();
}

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
    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
}
</script>
<?php endif; // $isSuperAdmin ?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
