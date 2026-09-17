<?php
/**
 * Layout: Fixed Left Sidebar with Accordion Navigation
 * StockPilot — Liquor Business Inventory Management System
 */

$activePage  = $activePage ?? 'dashboard';
$activeGroup = $activeGroup ?? '';
$currentUser = $currentUser ?? [];

$isInventoryActive = in_array($activePage, [
    'raw_materials', 'finished_goods', 'items', 'stock_in', 'stock_out', 
    'stock_transfer', 'stock_adjustment', 'stock_card', 'movement'
], true);

$isReportsActive = ($activePage === 'reports' || $activeGroup === 'reports');
?>
<aside id="appSidebar" class="sidebar" aria-label="Main Navigation">
    <!-- Brand Header -->
    <div class="sidebar-brand">
        <div class="logo-mark" aria-hidden="true">
            <!-- Lucide Package Icon -->
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m7.5 4.27 9 5.15"/>
                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                <path d="m3.3 7 8.7 5 8.7-5"/>
                <path d="M12 22V12"/>
            </svg>
        </div>
        <div class="brand-text-wrap">
            <span class="brand-name">Stock<span>Pilot</span></span>
            <span class="brand-sub">Liquor Inventory</span>
        </div>
    </div>

    <!-- Navigation Scroll Area -->
    <nav class="sidebar-nav">
        <!-- Main Section -->
        <span class="nav-section-label">Core Operations</span>

        <!-- Dashboard -->
        <div class="nav-item">
            <a href="<?= BASE_URL ?>views/dashboard/index.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <!-- Lucide LayoutDashboard Icon -->
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="7" height="9" x="3" y="3" rx="1"/>
                    <rect width="7" height="5" x="14" y="3" rx="1"/>
                    <rect width="7" height="9" x="14" y="12" rx="1"/>
                    <rect width="7" height="5" x="3" y="16" rx="1"/>
                </svg>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- Inventory Dropdown Group -->
        <div class="nav-group <?= $isInventoryActive ? 'open has-active' : '' ?>" id="group-inventory">
            <button type="button" class="nav-group-header" onclick="toggleNavGroup('group-inventory')" aria-expanded="<?= $isInventoryActive ? 'true' : 'false' ?>">
                <div class="nav-group-left">
                    <!-- Lucide Boxes Icon -->
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l6 3.43a2 2 0 0 0 2.06 0l6-3.43a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71l-6-3.43a2 2 0 0 0-2.06 0l-6 3.43Z"/>
                        <path d="m12 11.5 6.63-3.79"/>
                        <path d="m12 11.5-6.63-3.79"/>
                        <path d="M12 11.5v7"/>
                        <path d="M7 6.07 12 3.2l5 2.87"/>
                    </svg>
                    <span>Inventory</span>
                </div>
                <!-- Chevron Icon -->
                <svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
            <div class="nav-sub-list">
                <!-- Items Catalog -->
                <a href="<?= BASE_URL ?>views/items/index.php" class="nav-sub-link <?= $activePage === 'items' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Item Master</span>
                </a>
                <!-- Raw Materials -->
                <a href="<?= BASE_URL ?>views/inventory/raw_materials.php" class="nav-sub-link <?= $activePage === 'raw_materials' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Raw Materials</span>
                </a>
                <!-- Finished Goods -->
                <a href="<?= BASE_URL ?>views/inventory/finished_goods.php" class="nav-sub-link <?= $activePage === 'finished_goods' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Finished Goods</span>
                </a>
                <!-- Inbound / Stock In -->
                <a href="<?= BASE_URL ?>views/stock_in/index.php" class="nav-sub-link <?= $activePage === 'stock_in' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Inbound / Stock In</span>
                </a>
                <!-- Outbound / Stock Out -->
                <a href="<?= BASE_URL ?>views/stock_out/index.php" class="nav-sub-link <?= $activePage === 'stock_out' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Outbound / Stock Out</span>
                </a>
                <!-- Stock Transfer -->
                <a href="<?= BASE_URL ?>views/stock_transfer/index.php" class="nav-sub-link <?= $activePage === 'stock_transfer' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Stock Transfer</span>
                </a>
                <!-- Stock Adjustment -->
                <a href="<?= BASE_URL ?>views/stock_adjustment/index.php" class="nav-sub-link <?= $activePage === 'stock_adjustment' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Stock Adjustment</span>
                </a>
                <!-- Stock Card -->
                <a href="<?= BASE_URL ?>views/inventory/stock_card.php" class="nav-sub-link <?= $activePage === 'stock_card' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Stock Card</span>
                </a>
                <!-- Stock Movement -->
                <a href="<?= BASE_URL ?>views/inventory/movement.php" class="nav-sub-link <?= $activePage === 'movement' ? 'active' : '' ?>">
                    <span class="sub-bullet"></span>
                    <span>Stock Movement</span>
                </a>
            </div>
        </div>

        <!-- Reports -->
        <div class="nav-item">
            <a href="<?= BASE_URL ?>views/reports/index.php" class="nav-link <?= $isReportsActive ? 'active' : '' ?>">
                <!-- Lucide BarChart3 Icon -->
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18"/>
                    <path d="M18 17V9"/>
                    <path d="M13 17V5"/>
                    <path d="M8 17v-3"/>
                </svg>
                <span>Reports</span>
            </a>
        </div>

        <!-- Management Section -->
        <span class="nav-section-label">Management</span>

        <!-- Users / Account -->
        <div class="nav-item">
            <a href="<?= BASE_URL ?>views/users/index.php" class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>">
                <!-- Lucide Users Icon -->
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <span>Users / Account</span>
            </a>
        </div>

    </nav>

    <!-- Sidebar User Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar" aria-hidden="true">
                <?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?>
            </div>
            <div class="user-meta">
                <span class="user-name" title="<?= htmlspecialchars($currentUser['name'] ?? 'Administrator') ?>">
                    <?= htmlspecialchars($currentUser['name'] ?? 'Administrator') ?>
                </span>
                <span class="user-role"><?= htmlspecialchars(str_replace('_', ' ', $currentUser['role'] ?? 'admin')) ?></span>
            </div>
        </div>
        <a href="<?= BASE_URL ?>api/auth/logout.php" class="btn-sidebar-logout" title="Sign out" aria-label="Sign out">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </a>
    </div>
</aside>
