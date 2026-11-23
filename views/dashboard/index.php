<?php
/*
 * Main dashboard page. Displays inventory summaries, low-stock alerts, recent
 * movements, and overview tables for the user's warehouse.
 */

$pageTitle   = 'Admin Dashboard — InventoryTeam';
$activePage  = 'dashboard';
$activeGroup = '';

require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../layouts/sidebar.php';
require_once __DIR__ . '/../layouts/navbar.php';

$warehouseCode = is_array($assignedWarehouse ?? null) ? (string)($assignedWarehouse['warehouse_code'] ?? '') : '';
$warehouseName = is_array($assignedWarehouse ?? null) ? (string)($assignedWarehouse['warehouse_name'] ?? '') : '';

$stmtInventory = $pdo->prepare("
    SELECT 
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        i.code AS item_code,
        i.name AS item_name,
        COALESCE(i.item_type, i.type) AS item_type,
        i.unit,
        COALESCE(s.qty_on_hand, 0) AS quantity,
        COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level) AS default_reorder_level
    FROM items i
    JOIN warehouses w ON w.warehouse_id = :wid1
    LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = :wid2
    WHERE (LOWER(i.status) = 'active')
    ORDER BY i.type ASC, i.name ASC
");
$stmtInventory->execute([':wid1' => $currentWarehouseId, ':wid2' => $currentWarehouseId]);
$inventoryRows = $stmtInventory->fetchAll(PDO::FETCH_ASSOC);

$totalRawMaterials         = 0;
$totalFinishedGoods        = 0;
$lowStockCount             = 0;
$lowStockRawMaterials      = 0;
$lowStockFinishedGoods     = 0;
$outOfStockCount           = 0;
$outOfStockRawMaterials    = 0;
$outOfStockFinishedGoods   = 0;

foreach ($inventoryRows as $row) {
    $normType = strtolower(str_replace(' ', '_', (string)($row['item_type'] ?? '')));
    if ($normType === 'raw_material') {
        $totalRawMaterials++;
    } elseif ($normType === 'finished_good') {
        $totalFinishedGoods++;
    }
    $qty = (float)($row['quantity'] ?? 0);
    $reorder = (float)($row['default_reorder_level'] ?? 0);

    if ($qty <= 0) {
        $outOfStockCount++;
        if ($normType === 'raw_material') {
            $outOfStockRawMaterials++;
        } elseif ($normType === 'finished_good') {
            $outOfStockFinishedGoods++;
        }
    } elseif ($reorder > 0 && $qty <= $reorder) {
        $lowStockCount++;
        if ($normType === 'raw_material') {
            $lowStockRawMaterials++;
        } elseif ($normType === 'finished_good') {
            $lowStockFinishedGoods++;
        }
    }
}

$formatCategoryBreakdown = static function (int $total, int $raw, int $fg): string {
    $parts = [];
    if ($raw > 0) {
        $parts[] = $raw . ' ' . ($raw === 1 ? 'Raw Material' : 'Raw Materials');
    }
    if ($fg > 0) {
        $parts[] = $fg . ' ' . ($fg === 1 ? 'Finished Good' : 'Finished Goods');
    }
    $other = $total - $raw - $fg;
    if ($other > 0) {
        $parts[] = $other . ' ' . ($other === 1 ? 'Other Item' : 'Other Items');
    }
    return implode(' • ', $parts);
};

$lowStockBreakdownText   = $formatCategoryBreakdown($lowStockCount, $lowStockRawMaterials, $lowStockFinishedGoods);
$outOfStockBreakdownText = $formatCategoryBreakdown($outOfStockCount, $outOfStockRawMaterials, $outOfStockFinishedGoods);

$stmtRecent = $pdo->prepare("
    SELECT 
        sm.movement_id,
        sm.movement_type,
        COALESCE(sm.remarks, CONCAT(sm.movement_type, ' #', sm.movement_id)) AS reference_number,
        CASE WHEN COALESCE(sm.quantity_delta, 0) > 0 THEN sm.quantity_delta ELSE 0 END AS quantity_in,
        CASE WHEN COALESCE(sm.quantity_delta, 0) < 0 THEN ABS(sm.quantity_delta) ELSE 0 END AS quantity_out,
        COALESCE(sm.balance_after, 0) AS balance_after,
        sm.created_at,
        i.code AS item_code,
        i.name AS item_name,
        COALESCE(i.item_type, i.type) AS item_type,
        i.unit,
        w.code AS warehouse_code,
        w.name AS warehouse_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.item_id
    JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    WHERE sm.warehouse_id = :wid
      AND sm.movement_type != ''
      AND sm.movement_type NOT IN ('IN', 'OUT')
      AND (sm.status IS NULL OR sm.status = 'Posted')
      AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
    ORDER BY sm.movement_id DESC
    LIMIT 100
");
$stmtRecent->execute([':wid' => $currentWarehouseId]);
$recentActivities = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

$chartDays = [];
for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-$i days"));
    $chartDays[$dateKey] = [
        'date'     => $dateKey,
        'label'    => date('D, M j, Y', strtotime($dateKey)),
        'day_name' => date('D', strtotime($dateKey)),
        'day_num'  => date('j', strtotime($dateKey)),
        'month'    => date('M', strtotime($dateKey)),
        'is_today' => ($i === 0),
        'inbound'  => 0.0,
        'outbound' => 0.0
    ];
}

$stmtChart = $pdo->prepare("
    SELECT 
        DATE(sm.created_at) AS txn_date,
        COALESCE(SUM(CASE 
            WHEN sm.movement_type IN ('STOCK_IN', 'STOCK_TRANSFER_IN', 'CUSTOMER_RETURN') THEN sm.quantity 
            ELSE 0 
        END), 0) AS total_in,
        COALESCE(SUM(CASE 
            WHEN sm.movement_type IN ('STOCK_OUT', 'STOCK_TRANSFER_OUT') THEN sm.quantity 
            ELSE 0 
        END), 0) AS total_out
    FROM stock_movements sm
    WHERE sm.warehouse_id = :wid
      AND (sm.status IS NULL OR sm.status = 'Posted')
      AND sm.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(sm.created_at)
");
$stmtChart->execute([':wid' => $currentWarehouseId]);
$chartDbRows = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

foreach ($chartDbRows as $row) {
    $d = $row['txn_date'];
    if (isset($chartDays[$d])) {
        $chartDays[$d]['inbound'] = (float)$row['total_in'];
        $chartDays[$d]['outbound'] = (float)$row['total_out'];
    }
}

$totalWeekIn = array_sum(array_column($chartDays, 'inbound'));
$totalWeekOut = array_sum(array_column($chartDays, 'outbound'));

$canMonitorAllLogins = $isSuperAdmin
    || strtolower(trim((string)($currentUser['team'] ?? ''))) === 'hr'
    || strtolower(trim((string)($currentUser['department'] ?? ''))) === 'hr';
$selfUserId = (int)($currentUser['id'] ?? ($currentUser['user_id'] ?? 0));

$staffSql = "
    SELECT 
        al.log_id,
        al.user_id,
        COALESCE(u.name, u.username, 'Warehouse Staff') AS user_name,
        COALESCE(u.email, '') AS user_email,
        COALESCE(u.role, 'admin') AS user_role,
        'Inventory' AS user_team,
        al.action AS action_type,
        al.created_at,
        al.details,
        al.warehouse_id
    FROM accountability_logs al
    INNER JOIN users u ON u.user_id = al.user_id
    WHERE al.warehouse_id = :wid
      AND LOWER(COALESCE(u.team, '')) = 'inventory'
      AND (u.department IS NULL OR LOWER(u.department) IN ('inventory', 'admin'))
      AND LOWER(COALESCE(al.team, 'inventory')) IN ('inventory', 'administration')
      AND al.action IN ('USER_LOGIN', 'STOCK_IN', 'STOCK_OUT', 'TRANSFER_INITIATED', 'TRANSFER_RECEIVED', 'ADJUSTMENT_REQUESTED', 'BAD_PRODUCT', 'USER_CREATED')
";
$staffParams = [':wid' => $currentWarehouseId];
if (!$canMonitorAllLogins) {
    $staffSql .= " AND al.user_id = :self_uid";
    $staffParams[':self_uid'] = $selfUserId;
}
$staffSql .= " ORDER BY al.created_at DESC, al.log_id DESC LIMIT 5";
$stmtWhStaff = $pdo->prepare($staffSql);
$stmtWhStaff->execute($staffParams);
$recentWarehouseStaff = $stmtWhStaff->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Liquor Inventory Dashboard</h1>
        <p class="page-subtitle">Overview, stock levels, and recent warehouse activity in <strong><?= htmlspecialchars($warehouseCode . ' (' . $warehouseName . ')') ?></strong></p>
    </div>
    <div class="header-actions">
        <?php require __DIR__ . '/../layouts/notification_bell.php'; ?>
    </div>
</div>

<div class="stats-grid stats-grid-4">

    <div class="stat-card stat-gold">
        <div class="stat-header">
            <span class="stat-label">Total Raw Materials</span>
            <div class="stat-icon-wrap" aria-hidden="true">

                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"/>
                    <polyline points="2 17 12 22 22 17"/>
                    <polyline points="2 12 12 17 22 12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalRawMaterials) ?></div>
        
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Finished Goods</span>
            <div class="stat-icon-wrap" aria-hidden="true">

                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m7.5 4.27 9 5.15"/>
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                    <path d="m3.3 7 8.7 5 8.7-5"/>
                    <path d="M12 22V12"/>
                </svg>
            </div>
        </div>
        <div class="stat-value"><?= number_format($totalFinishedGoods) ?></div>

    </div>

    <div class="stat-card <?= $lowStockCount > 0 ? 'stat-alert' : '' ?>" id="dashboardCardLowStock">
        <div class="stat-header">
            <span class="stat-label">Low Stock Items</span>
            <div class="stat-icon-wrap" aria-hidden="true">

                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" id="dashboardValLowStock"><?= $lowStockCount ?></div>
        <div class="stat-meta" id="dashboardMetaLowStock">
            <?php if ($lowStockCount === 0): ?>
                All items at healthy levels
            <?php else: ?>
                <?= htmlspecialchars($lowStockBreakdownText) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-card <?= $outOfStockCount > 0 ? 'stat-alert' : '' ?>" id="dashboardCardOutOfStock">
        <div class="stat-header">
            <span class="stat-label">Out of Stock Items</span>
            <div class="stat-icon-wrap" aria-hidden="true" style="color: #B91C1C; background: #FEE2E2;">

                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
        </div>
        <div class="stat-value" id="dashboardValOutOfStock"><?= $outOfStockCount ?></div>
        <div class="stat-meta" id="dashboardMetaOutOfStock">
            <?php if ($outOfStockCount === 0): ?>
                No out of stock items
            <?php else: ?>
                <?= htmlspecialchars($outOfStockBreakdownText) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dashboard-analytics-grid">

    <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
            <div>
                <h2 class="card-title" style="margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    7-Day Stock Movement
                </h2>
                <p class="card-desc" style="margin: 3px 0 0 0;">
                    7-Day Movement (<strong><?= date('M j', strtotime('-6 days')) ?> &ndash; <?= date('M j, Y') ?></strong>) &bull; <strong><?= htmlspecialchars($warehouseCode) ?> (<?= htmlspecialchars($warehouseName) ?>)</strong>
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #10B981;"></span>
                    <span style="color: var(--gray);">7-Day Stock In:</span>
                    <strong style="color: #059669;">+<?= number_format($totalWeekIn) ?></strong>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #3B82F6;"></span>
                    <span style="color: var(--gray);">7-Day Stock Out:</span>
                    <strong style="color: #2563EB;">-<?= number_format($totalWeekOut) ?></strong>
                </div>
            </div>
        </div>

        <div style="width: 100%; overflow: hidden;">
            <?php
            $svgWidth = 560;
            $svgHeight = 210;
            $padL = 40;
            $padR = 15;
            $padT = 16;
            $padB = 44;
            $plotW = $svgWidth - $padL - $padR;
            $plotH = $svgHeight - $padT - $padB;

            $maxVal = 0;
            foreach ($chartDays as $d) {
                if ($d['inbound'] > $maxVal) $maxVal = $d['inbound'];
                if ($d['outbound'] > $maxVal) $maxVal = $d['outbound'];
            }
            $yMax = max(10, ceil($maxVal * 1.15));
            $numDays = count($chartDays); 
            $slotW = $plotW / $numDays;
            $barW = 14;
            ?>
            <svg viewBox="0 0 <?= $svgWidth ?> <?= $svgHeight ?>" style="width: 100%; height: auto; max-height: 230px; display: block;" role="img" aria-label="Realtime 7-day inventory movement trend chart">

                <?php for ($step = 0; $step <= 3; $step++): 
                    $ratio = $step / 3;
                    $yVal = round($yMax * (1 - $ratio));
                    $yPos = $padT + ($plotH * $ratio);
                ?>
                    <line x1="<?= $padL ?>" y1="<?= $yPos ?>" x2="<?= $svgWidth - $padR ?>" y2="<?= $yPos ?>" stroke="#E2E8F0" stroke-width="1" stroke-dasharray="<?= $step === 3 ? '0' : '3,3' ?>" />
                    <text x="<?= $padL - 6 ?>" y="<?= $yPos + 3 ?>" text-anchor="end" font-size="10" font-family="sans-serif" fill="#94A3B8"><?= $yVal ?></text>
                <?php endfor; ?>

                <?php 
                $dayIndex = 0;
                foreach ($chartDays as $d): 
                    $slotCenter = $padL + ($dayIndex * $slotW) + ($slotW / 2);
                    $inH = $yMax > 0 ? ($d['inbound'] / $yMax) * $plotH : 0;
                    $outH = $yMax > 0 ? ($d['outbound'] / $yMax) * $plotH : 0;
                    $inX = $slotCenter - $barW - 2;
                    $outX = $slotCenter + 2;
                    $inY = $padT + $plotH - $inH;
                    $outY = $padT + $plotH - $outH;
                    $isToday = !empty($d['is_today']);
                ?>

                    <?php if ($isToday): ?>
                        <rect x="<?= $slotCenter - ($slotW / 2) + 2 ?>" y="<?= $padT ?>" width="<?= $slotW - 4 ?>" height="<?= $plotH ?>" rx="4" fill="rgba(31, 122, 108, 0.05)" />
                    <?php endif; ?>

                    <?php if ($d['inbound'] > 0): ?>

                        <rect x="<?= $inX ?>" y="<?= $inY ?>" width="<?= $barW ?>" height="<?= max(2, $inH) ?>" rx="3" fill="#10B981" style="transition: fill-opacity 0.2s; cursor: pointer;">
                            <title><?= htmlspecialchars($d['label']) ?> (<?= $isToday ? 'Today' : $d['day_name'] ?>) — Inbound: +<?= formatQty($d['inbound']) ?> units</title>
                        </rect>
                    <?php else: ?>

                        <rect x="<?= $inX ?>" y="<?= $padT + $plotH - 2 ?>" width="<?= $barW ?>" height="2" rx="1" fill="#E2E8F0" opacity="0.8">
                            <title><?= htmlspecialchars($d['label']) ?> (<?= $isToday ? 'Today' : $d['day_name'] ?>) — Inbound: 0 units</title>
                        </rect>
                    <?php endif; ?>

                    <?php if ($d['outbound'] > 0): ?>

                        <rect x="<?= $outX ?>" y="<?= $outY ?>" width="<?= $barW ?>" height="<?= max(2, $outH) ?>" rx="3" fill="#3B82F6" style="transition: fill-opacity 0.2s; cursor: pointer;">
                            <title><?= htmlspecialchars($d['label']) ?> (<?= $isToday ? 'Today' : $d['day_name'] ?>) — Outbound: -<?= formatQty($d['outbound']) ?> units</title>
                        </rect>
                    <?php else: ?>

                        <rect x="<?= $outX ?>" y="<?= $padT + $plotH - 2 ?>" width="<?= $barW ?>" height="2" rx="1" fill="#E2E8F0" opacity="0.8">
                            <title><?= htmlspecialchars($d['label']) ?> (<?= $isToday ? 'Today' : $d['day_name'] ?>) — Outbound: 0 units</title>
                        </rect>
                    <?php endif; ?>

                    <text x="<?= $slotCenter ?>" y="<?= $padT + $plotH + 16 ?>" text-anchor="middle" font-size="10.5" font-family="sans-serif" font-weight="<?= $isToday ? '700' : '600' ?>" fill="<?= $isToday ? '#1F7A6C' : '#475569' ?>">
                        <?= htmlspecialchars($d['month'] . ' ' . $d['day_num']) ?>
                    </text>
                    <text x="<?= $slotCenter ?>" y="<?= $padT + $plotH + 28 ?>" text-anchor="middle" font-size="9.5" font-family="sans-serif" font-weight="<?= $isToday ? '700' : '500' ?>" fill="<?= $isToday ? '#1F7A6C' : '#94A3B8' ?>">
                        <?= $isToday ? 'Today' : htmlspecialchars($d['day_name']) ?>
                    </text>
                <?php 
                    $dayIndex++;
                endforeach; 
                ?>
            </svg>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 12px;">
            <div>
                <h2 class="card-title" style="margin: 0; font-size: 15px; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Recent Staff Activity
                </h2>
                <p class="card-desc" style="margin: 2px 0 0 0; font-size: 11px;">
                    Warehouse: <strong><?= htmlspecialchars($warehouseCode) ?></strong>
                </p>
            </div>
            <span class="badge" style="background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; font-size: 10.5px; padding: 2px 7px; font-weight: 600;">
                <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #10B981; margin-right: 4px;"></span> Active
            </span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
            <?php if (empty($recentWarehouseStaff)): ?>
                <div style="text-align: center; color: var(--gray); font-size: 12px; padding: 28px 0;">
                    No recent staff activity recorded for this warehouse.
                </div>
            <?php else: ?>
                <?php foreach ($recentWarehouseStaff as $st): ?>
                    <?php
                        $nameParts = explode(' ', trim($st['user_name'] ?: 'WS'));
                        $initials = count($nameParts) >= 2 
                            ? (strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1)))
                            : strtoupper(substr($nameParts[0], 0, 2));
                        $timeStr = !empty($st['created_at']) ? date('M d, H:i', strtotime($st['created_at'])) : 'Recent';
                        $actionLabel = match($st['action_type']) {
                            'USER_LOGIN'          => 'Signed in to warehouse',
                            'STOCK_IN'            => 'Received stock in',
                            'STOCK_OUT'           => 'Sent stock out',
                            'TRANSFER_RECEIVED'   => 'Received transfer',
                            'TRANSFER_INITIATED'  => 'Initiated transfer',
                            'ADJUSTMENT_REQUESTED'=> 'Requested adjustment',
                            'BAD_PRODUCT'         => 'Reported damaged stock',
                            default               => 'Assigned warehouse staff'
                        };
                    ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 10px; border-radius: 8px; background: #F8FAFC; border: 1px solid #EDF2F7;">
                        <div style="display: flex; align-items: center; gap: 9px; min-width: 0;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #E2E8F0; color: #1E293B; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <?= htmlspecialchars($initials) ?>
                            </div>
                            <div style="min-width: 0;">
                                <div style="font-size: 12px; font-weight: 600; color: var(--panel-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= htmlspecialchars($st['user_name']) ?>
                                </div>
                                <div style="font-size: 10.5px; color: var(--gray); margin-top: 1px;">
                                    <?= htmlspecialchars($actionLabel) ?>
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right; flex-shrink: 0;">
                            <span class="badge" style="font-size: 9.5px; padding: 1px 5px; background: #EEF2F6; color: #475569;">
                                <?= htmlspecialchars($st['user_team'] ?: 'Inventory') ?>
                            </span>
                            <div style="font-size: 10px; color: var(--gray); margin-top: 2px; font-family: monospace;">
                                <?= htmlspecialchars($timeStr) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Recent Inventory Activity</h2>
        </div>
    </div>
    <div class="table-responsive">
        <table id="recentActivityTable">
            <thead>
                <tr>
                    <th>Product / Item</th>
                    <th>Item Type</th>
                    <th class="text-center">Movement</th>
                    <th class="text-center">Quantity</th>
                    <th>Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentActivities)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--gray); padding: 32px;">No inventory movements recorded in the last 3 days.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentActivities as $act): ?>
                        <?php 
                            $isIncoming = (float)$act['quantity_in'] > 0;
                            $isTransfer = strpos($act['movement_type'], 'TRANSFER') !== false;
                            $isAdj = strpos($act['movement_type'], 'ADJUSTMENT') !== false;
                            $isBad = strpos($act['movement_type'], 'BAD') !== false;
                            $isCancel = strpos($act['movement_type'], 'CANCEL') !== false;
                            $pillClass = $isCancel ? 'status-cancelled' : ($isBad ? 'mov-out' : ($isTransfer ? 'mov-transfer' : ($isAdj ? 'mov-adj' : ($isIncoming ? 'mov-in' : 'mov-out'))));
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($act['item_name']) ?></strong>
                                <div style="font-family: monospace; font-size: 11px; color: var(--gray);"><?= htmlspecialchars($act['item_code']) ?></div>
                            </td>
                            <td>
                                <span class="badge-type <?= $act['item_type'] === 'finished_good' ? 'type-fg' : 'type-raw' ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $act['item_type'])) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $pillClass ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $act['movement_type'])) ?>
                                </span>
                            </td>
                            <td class="text-center" style="font-weight: 700; font-size: 13.5px;">
                                <?php if ($isIncoming): ?>
                                    <span style="color: #15803D;"><?= formatQty(abs((float)$act['quantity_in'])) ?></span>
                                <?php elseif ((float)$act['quantity_out'] > 0): ?>
                                    <span style="color: #B91C1C;"><?= formatQty(abs((float)$act['quantity_out'])) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--gray);">0.00</span>
                                <?php endif; ?>
                                <small style="color: var(--gray); font-weight: normal;"><?= htmlspecialchars($act['unit']) ?></small>
                            </td>
                            <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                <?= date('M d, Y H:i', strtotime($act['created_at'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Current Warehouse Stock</h2>
        </div>
        <div class="search-wrap">
            <span class="search-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </span>
            <input type="text" id="dashSearchInput" class="search-box" aria-label="Search warehouse inventory" placeholder="Search item code or name..." oninput="filterTable('dashSearchInput', 'dashStockTable')">
        </div>
    </div>
    <div class="table-responsive">
        <table id="dashStockTable">
            <thead>
                <tr>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th class="text-center">Type</th>
                    <th class="text-center">Quantity on Hand</th>
                    <th class="text-center">Reorder Level</th>
                    <th class="text-center">Stock Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventoryRows)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray); padding: 32px;">No inventory records in database.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventoryRows as $row): ?>
                        <?php
                            $qtyOnHand = (float)$row['quantity'];
                            $reorderThreshold = (float)$row['default_reorder_level'];
                            $isOutOfStock = $qtyOnHand <= 0;
                            $isLowStock = !$isOutOfStock && $qtyOnHand <= $reorderThreshold;
                        ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: 600; color: var(--panel-ink);">
                                <?= htmlspecialchars($row['item_code']) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge-type <?= in_array(strtolower(str_replace(' ', '_', (string)$row['item_type'])), ['finished_good', 'finished good']) ? 'type-fg' : 'type-raw' ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $row['item_type'])) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span style="font-weight: 700; font-size: 14.5px;"><?= formatQty($row['quantity']) ?></span>
                                <small style="color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                            </td>
                            <td class="text-center">
                                <?= formatQty($row['default_reorder_level']) ?> <small style="color: var(--gray);"><?= htmlspecialchars($row['unit']) ?></small>
                            </td>
                            <td class="text-center">
                                <?php if ($isOutOfStock): ?>
                                    <span class="badge status-alert">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        Out of Stock
                                    </span>
                                <?php elseif ($isLowStock): ?>
                                    <span class="badge status-pending">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                        Low Stock
                                    </span>
                                <?php else: ?>
                                    <span class="badge status-optimal">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        In Stock
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
