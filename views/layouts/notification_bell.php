<?php
/**
 * Layout Component: Header Notification Bell & Dropdown
 * InventoryTeam — Liquor Business Inventory Management System
 */

if (!function_exists('getNotificationTimeAgo')) {
    function getNotificationTimeAgo($datetime) {
        if (empty($datetime)) {
            return 'Just now';
        }
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$ts) {
            return 'Just now';
        }

        $diff = time() - $ts;
        if ($diff < 0 || $diff < 60) {
            return 'Just now';
        }
        if ($diff < 120) {
            return '1 min ago';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return $m . ' mins ago';
        }
        if ($diff < 7200) {
            return '1 hr ago';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return $h . ' hrs ago';
        }
        if ($diff < 172800) {
            return '1 day ago';
        }
        if ($diff < 604800) {
            $d = (int)floor($diff / 86400);
            return $d . ' days ago';
        }
        if ($diff < 2592000) {
            $w = (int)floor($diff / 604800);
            return $w . ($w === 1 ? ' week ago' : ' weeks ago');
        }
        $mo = (int)floor($diff / 2592000);
        return $mo . ($mo === 1 ? ' month ago' : ' months ago');
    }
}

if (!function_exists('getNotificationCategory')) {
    function getNotificationCategory($datetime, $isRead = false) {
        if (empty($datetime)) {
            return 'New';
        }
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$ts) {
            return 'New';
        }

        $diff = time() - $ts;
        $todayMidnight = strtotime('today midnight');

        // New: Received in the last 4 hours OR unread from today
        if ($diff <= 4 * 3600 || (empty($isRead) && $ts >= $todayMidnight)) {
            return 'New';
        }

        // Today: Received today (read, older than 4 hours)
        if ($ts >= $todayMidnight) {
            return 'Today';
        }

        // Earlier: Received before today (yesterday, 1 day ago, 2 days ago, etc.)
        return 'Earlier';
    }
}

// If $notificationList or $unreadNotificationsCount isn't already computed, query active alerts
if (!isset($notificationList) || !is_array($notificationList)) {
    $notificationList = [];
    $unreadNotificationsCount = 0;

    if (isset($pdo) && !empty($currentWarehouseId)) {
        try {
            // Read stored read notification IDs from cookie
            $readNotificationIds = [];
            if (!empty($_COOKIE['inventory_read_notifications'])) {
                $rawCookie = $_COOKIE['inventory_read_notifications'];
                $decoded = json_decode($rawCookie, true);
                if (!is_array($decoded)) {
                    $decoded = json_decode(stripslashes($rawCookie), true);
                }
                if (is_array($decoded)) {
                    $readNotificationIds = $decoded;
                }
            }

            // 1. Fetch active low stock items
            $stmtNotif = $pdo->prepare("
                SELECT 
                    i.item_id,
                    i.code AS item_code,
                    i.name AS item_name,
                    i.type AS item_type,
                    i.unit,
                    COALESCE(s.qty_on_hand, 0) AS quantity,
                    COALESCE(s.reorder_level, i.reorder_level, 0) AS reorder_level,
                    UNIX_TIMESTAMP(COALESCE(s.updated_at, i.updated_at, i.created_at)) AS alert_epoch
                FROM items i
                LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = :wid
                WHERE i.status = 'active'
                  AND COALESCE(s.reorder_level, i.reorder_level, 0) > 0
                  AND COALESCE(s.qty_on_hand, 0) <= COALESCE(s.reorder_level, i.reorder_level, 0)
                ORDER BY (COALESCE(s.reorder_level, i.reorder_level, 0) - COALESCE(s.qty_on_hand, 0)) DESC
                LIMIT 8
            ");
            $stmtNotif->execute([':wid' => $currentWarehouseId]);
            $lowStockRows = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lowStockRows as $ls) {
                $deficit = (float)$ls['reorder_level'] - (float)$ls['quantity'];
                $isZero = ((float)$ls['quantity'] <= 0);
                $notifId = 'low_stock_' . (int)$ls['item_id'] . ($isZero ? '_zero' : '');
                $isRead = in_array($notifId, $readNotificationIds, true);
                $alertTimestamp = !empty($ls['alert_epoch']) ? (int)$ls['alert_epoch'] : time();

                $notificationList[] = [
                    'id'        => $notifId,
                    'is_read'   => $isRead,
                    'type'      => 'low_stock',
                    'title'     => ($isZero ? 'Out of Stock: ' : 'Low Stock: ') . htmlspecialchars($ls['item_code']),
                    'message'   => htmlspecialchars($ls['item_name']) . ' (Qty: ' . (function_exists('formatQty') ? formatQty($ls['quantity']) : $ls['quantity']) . ' ' . htmlspecialchars($ls['unit']) . ' / Reorder: ' . (function_exists('formatQty') ? formatQty($ls['reorder_level']) : $ls['reorder_level']) . ')',
                    'link'      => BASE_URL . 'views/inbound_outbound/index.php?tab=stock_in&item_id=' . (int)$ls['item_id'],
                    'timestamp' => $alertTimestamp,
                    'time'      => getNotificationTimeAgo($alertTimestamp),
                    'category'  => getNotificationCategory($alertTimestamp, $isRead),
                    'severity'  => $isZero ? 'critical' : 'warning',
                ];
            }

            // 2. Pending Adjustments count
            $stmtPendingAdj = $pdo->query("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(requested_at)) AS latest_epoch FROM stock_adjustments WHERE status = 'pending'");
            $adjRow = $stmtPendingAdj->fetch(PDO::FETCH_ASSOC);
            $pendingAdjCount = (int)($adjRow['total_count'] ?? 0);
            if ($pendingAdjCount > 0) {
                $notifId = 'pending_adj_' . $pendingAdjCount;
                $isRead = in_array($notifId, $readNotificationIds, true);
                $adjTimestamp = !empty($adjRow['latest_epoch']) ? (int)$adjRow['latest_epoch'] : time();

                $notificationList[] = [
                    'id'        => $notifId,
                    'is_read'   => $isRead,
                    'type'      => 'pending_adj',
                    'title'     => 'Pending Stock Adjustments',
                    'message'   => $pendingAdjCount . ' adjustment transaction(s) awaiting approval',
                    'link'      => BASE_URL . 'views/stock_operations/index.php?tab=stock_adjustment',
                    'timestamp' => $adjTimestamp,
                    'time'      => getNotificationTimeAgo($adjTimestamp),
                    'category'  => getNotificationCategory($adjTimestamp, $isRead),
                    'severity'  => 'info',
                ];
            }

            // 3. Pending Inbound Transfers
            $stmtPendingTrans = $pdo->prepare("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(requested_at)) AS latest_epoch FROM stock_transfers WHERE destination_warehouse_id = :wid AND status IN ('pending', 'in_transit')");
            $stmtPendingTrans->execute([':wid' => $currentWarehouseId]);
            $transRow = $stmtPendingTrans->fetch(PDO::FETCH_ASSOC);
            $pendingTransCount = (int)($transRow['total_count'] ?? 0);
            if ($pendingTransCount > 0) {
                $notifId = 'pending_trans_' . (int)$currentWarehouseId . '_' . $pendingTransCount;
                $isRead = in_array($notifId, $readNotificationIds, true);
                $transTimestamp = !empty($transRow['latest_epoch']) ? (int)$transRow['latest_epoch'] : time();

                $notificationList[] = [
                    'id'        => $notifId,
                    'is_read'   => $isRead,
                    'type'      => 'pending_transfer',
                    'title'     => 'Inbound Transfers Pending',
                    'message'   => $pendingTransCount . ' transfer shipment(s) in transit to this facility',
                    'link'      => BASE_URL . 'views/stock_operations/index.php?tab=stock_transfer',
                    'timestamp' => $transTimestamp,
                    'time'      => getNotificationTimeAgo($transTimestamp),
                    'category'  => getNotificationCategory($transTimestamp, $isRead),
                    'severity'  => 'info',
                ];
            }

            // 4. Super Admin Stock Actions (approvals, rejections, direct adjustments, write-offs)
            $stmtAdminActs = $pdo->prepare("
                SELECT 
                    al.log_id,
                    al.user_id,
                    COALESCE(u.name, 'System Super Admin') AS admin_name,
                    al.action,
                    al.item_id,
                    al.quantity,
                    al.warehouse_id,
                    al.details,
                    UNIX_TIMESTAMP(al.created_at) AS alert_epoch,
                    al.created_at
                FROM accountability_logs al
                LEFT JOIN users u ON u.user_id = al.user_id
                WHERE (u.role = 'super_admin' OR al.details LIKE '%\"user_role\":\"super_admin\"%')
                  AND al.action NOT IN ('SUPPORT_INQUIRY', 'ITEM_CREATED')
                  AND (al.warehouse_id IS NULL OR al.warehouse_id = :wid)
                ORDER BY al.log_id DESC
                LIMIT 4
            ");
            $stmtAdminActs->execute([':wid' => $currentWarehouseId]);
            $adminActRows = $stmtAdminActs->fetchAll(PDO::FETCH_ASSOC);

            $actionTitles = [
                'ADJUSTMENT_APPROVED'  => 'Super Admin: Adjustment Approved',
                'ADJUSTMENT_REJECTED'  => 'Super Admin: Adjustment Rejected',
                'ADJUSTMENT_REQUESTED' => 'Super Admin: Stock Adjustment',
                'STOCK_ADJUSTMENT'     => 'Super Admin: Stock Adjustment',
                'TRANSFER_INITIATED'   => 'Super Admin: Transfer Dispatched',
                'TRANSFER_RECEIVED'    => 'Super Admin: Transfer Received',
                'STOCK_IN'             => 'Super Admin: Stock In Received',
                'STOCK_OUT'            => 'Super Admin: Stock Out Dispatched',
                'BAD_PRODUCT'          => 'Super Admin: Defect Write-off',
                'STOCK_IN_CANCEL'      => 'Super Admin: Stock In Cancelled',
                'STOCK_OUT_CANCEL'     => 'Super Admin: Stock Out Cancelled',
                'BAD_PRODUCT_CANCEL'   => 'Super Admin: Defect Cancelled',
            ];

            foreach ($adminActRows as $al) {
                $notifId = 'sa_act_' . (int)$al['log_id'];
                $isRead = in_array($notifId, $readNotificationIds, true);
                $actTimestamp = !empty($al['alert_epoch']) ? (int)$al['alert_epoch'] : strtotime($al['created_at']);
                $detailsData = json_decode($al['details'] ?? '', true) ?: [];

                $itemCode = htmlspecialchars($detailsData['item_code'] ?? '');
                $itemName = htmlspecialchars($detailsData['item_name'] ?? '');
                $qty = isset($al['quantity']) && (float)$al['quantity'] != 0 
                    ? ' (Qty: ' . (function_exists('formatQty') ? formatQty($al['quantity']) : $al['quantity']) . ' ' . htmlspecialchars($detailsData['unit'] ?? '') . ')' 
                    : '';
                $notes = htmlspecialchars($detailsData['notes'] ?? '');

                $msg = ($itemCode ? "{$itemCode} {$itemName}{$qty}" : '');
                if (!empty($notes)) {
                    $msg = $msg ? "{$msg} — {$notes}" : $notes;
                }
                if (empty($msg)) {
                    $msg = "Operational inventory action recorded by {$al['admin_name']}";
                }

                $title = $actionTitles[$al['action']] ?? ('Super Admin: ' . ucwords(strtolower(str_replace('_', ' ', $al['action']))));
                $refNo = $detailsData['reference_number'] ?? '';
                $link = BASE_URL . 'views/settings/accountability.php' . ($refNo ? '?search=' . urlencode($refNo) : '');

                $severity = in_array($al['action'], ['ADJUSTMENT_REJECTED', 'BAD_PRODUCT', 'STOCK_IN_CANCEL', 'STOCK_OUT_CANCEL'], true) 
                    ? 'critical' 
                    : (in_array($al['action'], ['ADJUSTMENT_APPROVED', 'ADJUSTMENT_REQUESTED'], true) ? 'warning' : 'shield');

                $notificationList[] = [
                    'id'        => $notifId,
                    'is_read'   => $isRead,
                    'type'      => 'super_admin_stock',
                    'title'     => $title,
                    'message'   => $msg,
                    'link'      => $link,
                    'timestamp' => $actTimestamp,
                    'time'      => getNotificationTimeAgo($actTimestamp),
                    'category'  => getNotificationCategory($actTimestamp, $isRead),
                    'severity'  => $severity,
                ];
            }

            // 5. Contact Support Inquiries & Super Admin Messages
            $stmtSupport = $pdo->query("
                SELECT 
                    al.log_id,
                    al.user_id,
                    COALESCE(u.name, 'Support User') AS sender_name,
                    COALESCE(u.role, 'admin') AS sender_role,
                    al.action,
                    al.warehouse_id,
                    al.details,
                    UNIX_TIMESTAMP(al.created_at) AS alert_epoch,
                    al.created_at
                FROM accountability_logs al
                LEFT JOIN users u ON u.user_id = al.user_id
                WHERE al.action IN ('SUPPORT_INQUIRY', 'ADMIN_MESSAGE')
                   OR al.details LIKE '%\"action_type\":\"SUPPORT_INQUIRY\"%'
                ORDER BY al.log_id DESC
                LIMIT 4
            ");
            $supportRows = $stmtSupport->fetchAll(PDO::FETCH_ASSOC);

            foreach ($supportRows as $sup) {
                $notifId = 'sup_msg_' . (int)$sup['log_id'];
                $isRead = in_array($notifId, $readNotificationIds, true);
                $supTimestamp = !empty($sup['alert_epoch']) ? (int)$sup['alert_epoch'] : strtotime($sup['created_at']);
                $detailsData = json_decode($sup['details'] ?? '', true) ?: [];

                $notes = $detailsData['notes'] ?? '';
                $refNo = $detailsData['reference_number'] ?? ('SUP-' . $sup['log_id']);
                $isFromSuperAdmin = ($sup['sender_role'] === 'super_admin' || ($detailsData['user_role'] ?? '') === 'super_admin');

                $title = $isFromSuperAdmin ? 'Super Admin Message' : 'Contact Support Inquiry';
                $message = $notes;

                if (preg_match('/^\[(.*?)\]\s*(.*?)(?:\s*—\s*(.*))?$/s', $notes, $matches)) {
                    $catTag = trim($matches[1]);
                    $subject = trim($matches[2]);
                    $body = trim($matches[3] ?? '');

                    if ($isFromSuperAdmin) {
                        $title = 'Super Admin Message: ' . htmlspecialchars($subject);
                        $message = !empty($body) ? htmlspecialchars($body) : htmlspecialchars($subject);
                    } else {
                        $sender = htmlspecialchars($detailsData['user_name'] ?? $sup['sender_name']);
                        $title = 'Support Inquiry: ' . htmlspecialchars($subject);
                        $message = "From {$sender}" . (!empty($body) ? " — " . htmlspecialchars($body) : "");
                    }
                }

                $link = BASE_URL . 'views/settings/accountability.php?search=' . urlencode($refNo);

                $notificationList[] = [
                    'id'        => $notifId,
                    'is_read'   => $isRead,
                    'type'      => 'support_message',
                    'title'     => $title,
                    'message'   => $message,
                    'link'      => $link,
                    'timestamp' => $supTimestamp,
                    'time'      => getNotificationTimeAgo($supTimestamp),
                    'category'  => getNotificationCategory($supTimestamp, $isRead),
                    'severity'  => 'message',
                ];
            }

            // Calculate unread count
            $unreadNotificationsCount = 0;
            foreach ($notificationList as $notif) {
                if (empty($notif['is_read'])) {
                    $unreadNotificationsCount++;
                }
            }
        } catch (Throwable $e) {
            $notificationList = [];
            $unreadNotificationsCount = 0;
        }
    }
} else {
    // If $notificationList is provided externally, ensure IDs, cookie check, and unread count are computed
    $readNotificationIds = [];
    if (!empty($_COOKIE['inventory_read_notifications'])) {
        $rawCookie = $_COOKIE['inventory_read_notifications'];
        $decoded = json_decode($rawCookie, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(stripslashes($rawCookie), true);
        }
        if (is_array($decoded)) {
            $readNotificationIds = $decoded;
        }
    }

    $unreadNotificationsCount = 0;
    foreach ($notificationList as &$notif) {
        if (!isset($notif['id'])) {
            $notif['id'] = md5($notif['title'] ?? uniqid());
        }
        if (!isset($notif['is_read'])) {
            $notif['is_read'] = in_array($notif['id'], $readNotificationIds, true);
        }
        if (!isset($notif['timestamp'])) {
            $notif['timestamp'] = time();
        }
        if (!isset($notif['time'])) {
            $notif['time'] = getNotificationTimeAgo($notif['timestamp']);
        }
        if (!isset($notif['category'])) {
            $notif['category'] = getNotificationCategory($notif['timestamp'], $notif['is_read']);
        }
        if (empty($notif['is_read'])) {
            $unreadNotificationsCount++;
        }
    }
    unset($notif);
}

// Group notifications into New, Today, Earlier sections
$groupedNotifications = [
    'New'     => [],
    'Today'   => [],
    'Earlier' => [],
];

foreach ($notificationList as $notif) {
    $cat = $notif['category'] ?? 'New';
    if (!isset($groupedNotifications[$cat])) {
        $groupedNotifications[$cat] = [];
    }
    $groupedNotifications[$cat][] = $notif;
}

// Sort each section with newest alerts first
foreach ($groupedNotifications as &$sectionItems) {
    usort($sectionItems, function($a, $b) {
        return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });
}
unset($sectionItems);

// Calculate filter tab badge totals
$tabStockCount = 0;
$tabMessageCount = 0;
foreach ($notificationList as $notifItem) {
    $t = $notifItem['type'] ?? '';
    if ($t === 'support_message') {
        $tabMessageCount++;
    } else {
        $tabStockCount++;
    }
}
?>
<div class="notification-bell-wrapper" id="headerNotificationWrapper">
    <button type="button" 
            class="header-notification-btn" 
            id="notificationBellBtn" 
            onclick="toggleNotificationDropdown(event)" 
            aria-label="<?= ($unreadNotificationsCount > 0) ? 'Notifications (' . $unreadNotificationsCount . ' unread)' : 'Notifications' ?>" 
            title="Notifications" 
            data-tooltip="Notifications"
            aria-haspopup="dialog" 
            aria-expanded="false">
        <!-- Lucide Bell Icon -->
        <svg class="notification-bell-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
        </svg>
        <span class="notification-badge" id="notificationBadge" style="<?= ($unreadNotificationsCount <= 0) ? 'display: none;' : '' ?>" aria-hidden="true">
            <?= $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount ?>
        </span>
    </button>

    <!-- Notification Dropdown Popover -->
    <div class="notification-dropdown" id="notificationDropdown" role="dialog" aria-label="Notifications Panel">
        <div class="notif-header">
            <div class="notif-header-title">
                <span>Notifications</span>
                <span class="notif-count-tag" id="notifHeaderCount" style="<?= ($unreadNotificationsCount <= 0) ? 'display: none;' : '' ?>">
                    <?= $unreadNotificationsCount ?> New
                </span>
            </div>
            <button type="button" 
                    class="btn-mark-all-read" 
                    id="btnMarkAllRead" 
                    style="<?= ($unreadNotificationsCount <= 0) ? 'display: none;' : '' ?>" 
                    onclick="markAllNotificationsRead(event)">
                Mark all read
            </button>
        </div>

        <!-- Segmented Notification Filter Controls Group -->
        <div class="notif-tabs" role="tablist" aria-label="Notification Filters">
            <button type="button" 
                    class="notif-tab active" 
                    data-filter="all" 
                    role="tab" 
                    aria-selected="true" 
                    aria-controls="notifListContainer"
                    onclick="filterNotifications('all', this)">
                <span class="notif-tab-label">All</span>
                <span class="notif-tab-badge" id="tabCountAll"><?= count($notificationList) ?></span>
            </button>
            <button type="button" 
                    class="notif-tab" 
                    data-filter="unread" 
                    role="tab" 
                    aria-selected="false" 
                    aria-controls="notifListContainer"
                    onclick="filterNotifications('unread', this)">
                <span class="notif-tab-label">Unread</span>
                <span class="notif-tab-badge" id="tabCountUnread"><?= $unreadNotificationsCount ?></span>
            </button>
            <button type="button" 
                    class="notif-tab" 
                    data-filter="stock" 
                    role="tab" 
                    aria-selected="false" 
                    aria-controls="notifListContainer"
                    onclick="filterNotifications('stock', this)">
                <span class="notif-tab-label">Stock</span>
                <span class="notif-tab-badge" id="tabCountStock"><?= $tabStockCount ?></span>
            </button>
            <button type="button" 
                    class="notif-tab" 
                    data-filter="messages" 
                    role="tab" 
                    aria-selected="false" 
                    aria-controls="notifListContainer"
                    onclick="filterNotifications('messages', this)">
                <span class="notif-tab-label">Messages</span>
                <span class="notif-tab-badge" id="tabCountMessages"><?= $tabMessageCount ?></span>
            </button>
        </div>

        <div class="notif-list" id="notifListContainer">
            <?php if (!empty($notificationList)): ?>
                <?php foreach ($groupedNotifications as $category => $items): ?>
                    <?php if (!empty($items)): ?>
                        <div class="notif-section" data-section="<?= htmlspecialchars($category) ?>">
                            <div class="notif-section-header">
                                <span class="notif-section-title"><?= htmlspecialchars($category) ?></span>
                                <span class="notif-section-count"><?= count($items) ?></span>
                            </div>
                            <?php foreach ($items as $notif): ?>
                                <div class="notif-item <?= !empty($notif['is_read']) ? 'read' : 'unread' ?>" data-notif-id="<?= htmlspecialchars($notif['id']) ?>" data-type="<?= htmlspecialchars($notif['type']) ?>">
                                    <a href="<?= $notif['link'] ?>" class="notif-link" onclick="handleNotificationClick(event, this.closest('.notif-item'))">
                                        <div class="notif-icon-circle <?= $notif['severity'] ?>" aria-hidden="true">
                                            <?php if ($notif['severity'] === 'critical'): ?>
                                                <!-- AlertCircle Icon -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                                </svg>
                                            <?php elseif ($notif['severity'] === 'warning'): ?>
                                                <!-- AlertTriangle Icon -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                                </svg>
                                            <?php elseif ($notif['severity'] === 'shield'): ?>
                                                <!-- ShieldCheck Icon (Super Admin Stock Action) -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                                    <path d="m9 12 2 2 4-4"/>
                                                </svg>
                                            <?php elseif ($notif['severity'] === 'message'): ?>
                                                <!-- MessageSquare Icon (Contact Support / Admin Message) -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                                </svg>
                                            <?php else: ?>
                                                <!-- Layers Icon -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.9a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/>
                                                    <path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/>
                                                    <path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-content-title">
                                                <span class="notif-title-text"><?= $notif['title'] ?></span>
                                                <span class="notif-badge-sub <?= $notif['severity'] ?>">
                                                    <?php
                                                    if ($notif['severity'] === 'shield') {
                                                        echo 'Super Admin';
                                                    } elseif ($notif['severity'] === 'message') {
                                                        echo 'Support';
                                                    } else {
                                                        echo ucfirst($notif['severity']);
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="notif-content-desc"><?= $notif['message'] ?></div>
                                            <div class="notif-content-meta">
                                                <svg class="notif-time-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <polyline points="12 6 12 12 16 14"/>
                                                </svg>
                                                <span class="notif-content-time"><?= $notif['time'] ?></span>
                                            </div>
                                        </div>
                                    </a>
                                    <button type="button" 
                                            class="notif-mark-btn <?= !empty($notif['is_read']) ? 'is-read' : '' ?>" 
                                            title="<?= !empty($notif['is_read']) ? 'Mark as unread' : 'Mark as read' ?>" 
                                            aria-label="<?= !empty($notif['is_read']) ? 'Mark as unread' : 'Mark as read' ?>"
                                            onclick="toggleSingleNotificationRead(event, this.closest('.notif-item'))">
                                        <span class="notif-unread-dot" aria-hidden="true"></span>
                                        <svg class="notif-read-check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notif-empty">
                    <div class="notif-empty-icon" aria-hidden="true">
                        <!-- CheckCircle Icon -->
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                    <div class="notif-empty-title">All caught up!</div>
                    <div class="notif-empty-desc">All inventory items are currently above reorder thresholds.</div>
                </div>
            <?php endif; ?>

            <!-- Filter Empty State -->
            <div class="notif-filter-empty" id="notifFilterEmpty" style="display: none;">
                <div class="notif-empty-icon" style="background: #F1F5F9; color: #64748B;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                </div>
                <div class="notif-empty-title">No notifications</div>
                <div class="notif-empty-desc">No items match the selected filter.</div>
            </div>
        </div>

        <div class="notif-footer">
            <span style="color: #64748B;">Liquor Inventory Alerts</span>
            <a href="<?= BASE_URL ?>views/inbound_outbound/index.php">Manage Stock &rarr;</a>
        </div>
    </div>
</div>

<!-- Real-Time Toast Alert Container -->
<div class="notif-toast-container" id="notifToastContainer" aria-live="polite" aria-atomic="true"></div>
