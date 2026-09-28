<?php
/**
 * Layout: Top Navigation Bar
 * StockPilot — Liquor Business Inventory Management System
 */
$navbarWarehouses = [];
if (!empty($isAuthorizedForMultiWarehouse) && isset($pdo)) {
    try {
        $nwStmt = $pdo->query("SELECT warehouse_id, warehouse_code, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC");
        if ($nwStmt) {
            $navbarWarehouses = $nwStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $navbarWarehouses = [];
    }
}
?>
<div class="main-content">
    <header class="navbar">
        <div class="navbar-left">
            <button type="button" class="btn-mobile-toggle" onclick="toggleSidebar()" aria-label="Toggle navigation">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <span style="font-family: var(--font-display); font-weight: 800; font-size: 16px; color: var(--panel-ink); letter-spacing: -0.3px;">
                Stock<span style="color: var(--accent);">Pilot</span>
            </span>
        </div>
        <div class="navbar-right">
            <?php if (!empty($isSuperAdmin) && count($navbarWarehouses) > 1): ?>
                <form method="GET" style="margin: 0; display: inline-flex; align-items: center;">
                    <?php foreach ($_GET as $k => $v): if ($k === 'warehouse_id' || is_array($v)) continue; ?>
                        <input type="hidden" name="<?= htmlspecialchars((string)$k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                    <?php endforeach; ?>
                    <select name="warehouse_id" aria-label="Switch active warehouse" class="select-filter" style="height: 32px; font-size: 12px; padding: 0 10px; border-radius: 20px; font-weight: 600;" onchange="this.form.submit()">
                        <?php foreach ($navbarWarehouses as $nw): ?>
                            <option value="<?= (int)$nw['warehouse_id'] ?>" <?= ((int)$nw['warehouse_id'] === (int)($currentWarehouseId ?? 1)) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nw['warehouse_code'] . ' · ' . $nw['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php elseif (!empty($assignedWarehouse['warehouse_code'])): ?>
                <div class="wh-badge">
                    <span class="status-dot"></span>
                    <span><?= htmlspecialchars($assignedWarehouse['warehouse_code']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <main class="content-body">
