<?php
/**
 * NotificationService.php
 * Unified notification feed service for Inventory ERP (F-22, F-35).
 * Shared by views/layouts/notification_bell.php and api/inventory/notifications_poll.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StockService.php';

class NotificationService {
    /**
     * Compute human-readable relative time string.
     */
    public static function getTimeAgo($datetime): string {
        if (empty($datetime)) {
            return 'Just now';
        }
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
        if (!$ts) {
            return 'Just now';
        }

        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 120) return '1 min ago';
        if ($diff < 3600) return (int)floor($diff / 60) . ' mins ago';
        if ($diff < 7200) return '1 hr ago';
        if ($diff < 86400) return (int)floor($diff / 3600) . ' hrs ago';
        if ($diff < 172800) return '1 day ago';
        if ($diff < 604800) return (int)floor($diff / 86400) . ' days ago';
        if ($diff < 2592000) {
            $w = (int)floor($diff / 604800);
            return $w . ($w === 1 ? ' week ago' : ' weeks ago');
        }
        $mo = (int)floor($diff / 2592000);
        return $mo . ($mo === 1 ? ' month ago' : ' months ago');
    }

    /**
     * Categorize notification into New, Today, or Earlier.
     */
    public static function getCategory($datetime, bool $isRead = false): string {
        if (empty($datetime)) {
            return 'New';
        }
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
        if (!$ts) {
            return 'New';
        }

        $diff = time() - $ts;
        $todayMidnight = strtotime('today midnight');

        if ($diff <= 4 * 3600 || (!$isRead && $ts >= $todayMidnight)) {
            return 'New';
        }
        if ($ts >= $todayMidnight) {
            return 'Today';
        }
        return 'Earlier';
    }

    /**
     * Generate the complete notification feed for a user and warehouse facility.
     *
     * @param PDO $pdo Active database connection
     * @param int $warehouseId Target warehouse ID
     * @param array $currentUser Authenticated user record
     * @param array $readNotificationIds Array of notification IDs previously marked as read
     * @param string $baseUrl Base URL for hyperlinks
     * @param string $lastKnownId Client's last known notification ID for new alert detection
     * @return array Unified feed payload
     */
    public static function getFeed(
        PDO $pdo,
        int $warehouseId,
        array $currentUser,
        array $readNotificationIds = [],
        string $baseUrl = '',
        string $lastKnownId = ''
    ): array {
        $notificationList = [];
        $isSuperAdmin = (($currentUser['role'] ?? '') === 'super_admin');
        $currentUid = (int)($currentUser['id'] ?? ($currentUser['user_id'] ?? 0));

        if ($warehouseId <= 0) {
            $warehouseId = 1;
        }

        // 1. Low Stock Alerts (F-22: strictly NULLIF(s.reorder_level, 0) and reorder > 0)
        $stmtExactLow = $pdo->prepare("
            SELECT COUNT(*) AS total_low
            FROM items i
            LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = :wid
            WHERE i.status = 'active'
              AND COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0) > 0
              AND COALESCE(s.qty_on_hand, 0) <= COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0)
        ");
        $stmtExactLow->execute([':wid' => $warehouseId]);
        $exactLowStockCount = (int)$stmtExactLow->fetchColumn();

        $stmtNotif = $pdo->prepare("
            SELECT 
                i.item_id,
                i.code AS item_code,
                i.name AS item_name,
                i.type AS item_type,
                i.unit,
                COALESCE(s.qty_on_hand, 0) AS quantity,
                COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0) AS reorder_level,
                UNIX_TIMESTAMP(COALESCE(s.updated_at, i.updated_at, i.created_at)) AS alert_epoch
            FROM items i
            LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = :wid
            WHERE i.status = 'active'
              AND COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0) > 0
              AND COALESCE(s.qty_on_hand, 0) <= COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0)
            ORDER BY (COALESCE(NULLIF(s.reorder_level, 0), i.reorder_level, 0) - COALESCE(s.qty_on_hand, 0)) DESC
            LIMIT 8
        ");
        $stmtNotif->execute([':wid' => $warehouseId]);
        while ($ls = $stmtNotif->fetch(PDO::FETCH_ASSOC)) {
            $isZero = ((float)$ls['quantity'] <= 0);
            $notifId = 'low_stock_' . (int)$ls['item_id'] . ($isZero ? '_zero' : '');
            $isRead = in_array($notifId, $readNotificationIds, true);
            $ts = !empty($ls['alert_epoch']) ? (int)$ls['alert_epoch'] : time();

            $formattedQty = function_exists('formatQty') ? formatQty((float)$ls['quantity']) : (string)$ls['quantity'];
            $formattedReorder = function_exists('formatQty') ? formatQty((float)$ls['reorder_level']) : (string)$ls['reorder_level'];

            $notificationList[] = [
                'id'        => $notifId,
                'is_read'   => $isRead,
                'type'      => 'low_stock',
                'title'     => ($isZero ? 'Out of Stock: ' : 'Low Stock: ') . (string)$ls['item_code'],
                'message'   => (string)$ls['item_name'] . ' (Qty: ' . $formattedQty . ' ' . (string)$ls['unit'] . ' / Reorder: ' . $formattedReorder . ')',
                'link'      => $baseUrl . 'views/inbound_outbound/index.php?tab=stock_in&item_id=' . (int)$ls['item_id'],
                'timestamp' => $ts,
                'time'      => self::getTimeAgo($ts),
                'category'  => self::getCategory($ts, $isRead),
                'severity'  => $isZero ? 'critical' : 'warning',
            ];
        }

        // 2. Pending Stock Adjustments (F-11: scoped to warehouse for non-super_admin)
        if ($isSuperAdmin) {
            $stmtPendingAdj = $pdo->query("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(requested_at)) AS latest_epoch FROM stock_adjustments WHERE status = 'pending'");
        } else {
            $stmtPendingAdj = $pdo->prepare("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(requested_at)) AS latest_epoch FROM stock_adjustments WHERE status = 'pending' AND warehouse_id = :wid");
            $stmtPendingAdj->execute([':wid' => $warehouseId]);
        }
        $adjRow = $stmtPendingAdj->fetch(PDO::FETCH_ASSOC);
        $pendingAdjCount = (int)($adjRow['total_count'] ?? 0);
        if ($pendingAdjCount > 0) {
            $notifId = 'pending_adj_' . $pendingAdjCount;
            $isRead = in_array($notifId, $readNotificationIds, true);
            $ts = !empty($adjRow['latest_epoch']) ? (int)$adjRow['latest_epoch'] : time();

            $notificationList[] = [
                'id'        => $notifId,
                'is_read'   => $isRead,
                'type'      => 'pending_adj',
                'title'     => 'Pending Stock Adjustments',
                'message'   => $pendingAdjCount . ' adjustment transaction(s) awaiting approval',
                'link'      => $baseUrl . 'views/stock_operations/index.php?tab=stock_adjustment',
                'timestamp' => $ts,
                'time'      => self::getTimeAgo($ts),
                'category'  => self::getCategory($ts, $isRead),
                'severity'  => 'info',
            ];
        }

        // 3. Pending Inbound Transfers
        $stmtPendingTrans = $pdo->prepare("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(requested_at)) AS latest_epoch FROM stock_transfers WHERE destination_warehouse_id = :wid AND status IN ('pending', 'in_transit')");
        $stmtPendingTrans->execute([':wid' => $warehouseId]);
        $transRow = $stmtPendingTrans->fetch(PDO::FETCH_ASSOC);
        $pendingTransCount = (int)($transRow['total_count'] ?? 0);
        if ($pendingTransCount > 0) {
            $notifId = 'pending_trans_' . (int)$warehouseId . '_' . $pendingTransCount;
            $isRead = in_array($notifId, $readNotificationIds, true);
            $ts = !empty($transRow['latest_epoch']) ? (int)$transRow['latest_epoch'] : time();

            $notificationList[] = [
                'id'        => $notifId,
                'is_read'   => $isRead,
                'type'      => 'pending_transfer',
                'title'     => 'Inbound Transfers Pending',
                'message'   => $pendingTransCount . ' transfer shipment(s) in transit to this facility',
                'link'      => $baseUrl . 'views/stock_operations/index.php?tab=stock_transfer',
                'timestamp' => $ts,
                'time'      => self::getTimeAgo($ts),
                'category'  => self::getCategory($ts, $isRead),
                'severity'  => 'info',
            ];
        }

        // 3b. Pending Catalog Items awaiting Super Admin review (BR-01)
        $pendingItemCount = 0;
        if ($isSuperAdmin) {
            $stmtPendingItems = $pdo->query("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(created_at)) AS latest_epoch FROM items WHERE status = 'pending'");
            $pendingItemRow = $stmtPendingItems->fetch(PDO::FETCH_ASSOC);
            $pendingItemCount = (int)($pendingItemRow['total_count'] ?? 0);
            if ($pendingItemCount > 0) {
                $notifId = 'pending_catalog_items_' . $pendingItemCount;
                $isRead = in_array($notifId, $readNotificationIds, true);
                $ts = !empty($pendingItemRow['latest_epoch']) ? (int)$pendingItemRow['latest_epoch'] : time();

                $notificationList[] = [
                    'id'        => $notifId,
                    'is_read'   => $isRead,
                    'type'      => 'pending_item',
                    'title'     => 'Catalog Items Pending Approval',
                    'message'   => $pendingItemCount . ' new catalog item(s) awaiting Super Admin review and activation',
                    'link'      => $baseUrl . 'views/items/index.php?status=pending',
                    'timestamp' => $ts,
                    'time'      => self::getTimeAgo($ts),
                    'category'  => self::getCategory($ts, $isRead),
                    'severity'  => 'warning',
                ];
            }
        }

        // 4. Super Admin Stock Actions (audited inventory actions)
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
        $stmtAdminActs->execute([':wid' => $warehouseId]);
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

        while ($al = $stmtAdminActs->fetch(PDO::FETCH_ASSOC)) {
            $notifId = 'sa_act_' . (int)$al['log_id'];
            $isRead = in_array($notifId, $readNotificationIds, true);
            $ts = !empty($al['alert_epoch']) ? (int)$al['alert_epoch'] : strtotime($al['created_at']);
            $detailsData = json_decode($al['details'] ?? '', true) ?: [];

            $itemCode = (string)($detailsData['item_code'] ?? '');
            $itemName = (string)($detailsData['item_name'] ?? '');
            $qtyFormatted = (isset($al['quantity']) && (float)$al['quantity'] != 0)
                ? ' (Qty: ' . (function_exists('formatQty') ? formatQty((float)$al['quantity']) : $al['quantity']) . ' ' . (string)($detailsData['unit'] ?? '') . ')'
                : '';
            $notes = (string)($detailsData['notes'] ?? '');

            $msg = ($itemCode ? "{$itemCode} {$itemName}{$qtyFormatted}" : '');
            if (!empty($notes)) {
                $msg = $msg ? "{$msg} — {$notes}" : $notes;
            }
            if (empty($msg)) {
                $msg = "Operational inventory action recorded by " . (string)$al['admin_name'];
            }

            $title = $actionTitles[$al['action']] ?? ('Super Admin: ' . ucwords(strtolower(str_replace('_', ' ', $al['action']))));
            $refNo = $detailsData['reference_number'] ?? '';
            $link = $baseUrl . 'views/settings/accountability.php' . ($refNo ? '?search=' . urlencode($refNo) : '');

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
                'timestamp' => $ts,
                'time'      => self::getTimeAgo($ts),
                'category'  => self::getCategory($ts, $isRead),
                'severity'  => $severity,
            ];
        }

        // 5. Support Inquiries & Messages (F-11: restricted to super_admin or owning user)
        if ($isSuperAdmin) {
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
        } else {
            $stmtSupport = $pdo->prepare("
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
                WHERE ((al.action = 'SUPPORT_INQUIRY' OR al.details LIKE '%\"action_type\":\"SUPPORT_INQUIRY\"%') AND al.user_id = :uid)
                   OR (al.action = 'ADMIN_MESSAGE' AND (al.warehouse_id IS NULL OR al.warehouse_id = :wid))
                ORDER BY al.log_id DESC
                LIMIT 4
            ");
            $stmtSupport->execute([':uid' => $currentUid, ':wid' => $warehouseId]);
        }

        while ($sup = $stmtSupport->fetch(PDO::FETCH_ASSOC)) {
            $notifId = 'sup_msg_' . (int)$sup['log_id'];
            $isRead = in_array($notifId, $readNotificationIds, true);
            $ts = !empty($sup['alert_epoch']) ? (int)$sup['alert_epoch'] : strtotime($sup['created_at']);
            $detailsData = json_decode($sup['details'] ?? '', true) ?: [];

            $notes = $detailsData['notes'] ?? '';
            $refNo = $detailsData['reference_number'] ?? ('SUP-' . $sup['log_id']);
            $isFromSuperAdmin = ($sup['sender_role'] === 'super_admin' || ($detailsData['user_role'] ?? '') === 'super_admin');

            $title = $isFromSuperAdmin ? 'Super Admin Message' : 'Contact Support Inquiry';
            $message = $notes;

            if (preg_match('/^\[(.*?)\]\s*(.*?)(?:\s*—\s*(.*))?$/s', $notes, $matches)) {
                $subject = trim($matches[2]);
                $body = trim($matches[3] ?? '');

                if ($isFromSuperAdmin) {
                    $title = 'Super Admin Message: ' . $subject;
                    $message = !empty($body) ? $body : $subject;
                } else {
                    $sender = (string)($detailsData['user_name'] ?? $sup['sender_name']);
                    $title = 'Support Inquiry: ' . $subject;
                    $message = "From {$sender}" . (!empty($body) ? " — " . $body : "");
                }
            }

            $link = $baseUrl . 'views/settings/accountability.php?search=' . urlencode($refNo);

            $notificationList[] = [
                'id'        => $notifId,
                'is_read'   => $isRead,
                'type'      => 'support_message',
                'title'     => $title,
                'message'   => $message,
                'link'      => $link,
                'timestamp' => $ts,
                'time'      => self::getTimeAgo($ts),
                'category'  => self::getCategory($ts, $isRead),
                'severity'  => 'message',
            ];
        }

        // Sort feed newest first
        usort($notificationList, function($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        $unreadCount = 0;
        $unreadStockCount = 0;
        $unreadMessageCount = 0;
        $totalStockCount = 0;
        $totalMessageCount = 0;

        foreach ($notificationList as $n) {
            if (empty($n['is_read'])) {
                $unreadCount++;
                if ($n['type'] === 'support_message') {
                    $unreadMessageCount++;
                } else {
                    $unreadStockCount++;
                }
            }
            if ($n['type'] === 'support_message') {
                $totalMessageCount++;
            } else {
                $totalStockCount++;
            }
        }

        $latestItem = $notificationList[0] ?? null;
        $latestId = $latestItem ? $latestItem['id'] : '';
        $hasNewAlert = false;
        $newAlert = null;

        if ($latestItem && empty($latestItem['is_read']) && $lastKnownId !== '' && $latestId !== $lastKnownId) {
            $hasNewAlert = true;
            $newAlert = $latestItem;
        }

        return [
            'success'              => true,
            'unread_count'         => $unreadCount,
            'total_count'          => count($notificationList),
            'unread_stock_count'   => $unreadStockCount,
            'unread_message_count' => $unreadMessageCount,
            'total_stock_count'    => $totalStockCount,
            'total_message_count'  => $totalMessageCount,
            'latest_id'            => $latestId,
            'has_new_alert'        => $hasNewAlert,
            'new_alert'            => $newAlert,
            'kpi'                  => [
                'low_stock_count'     => $exactLowStockCount,
                'pending_adj_count'   => $pendingAdjCount,
                'pending_trans_count' => $pendingTransCount,
                'pending_item_count'  => $pendingItemCount,
            ],
            'notifications'        => $notificationList,
        ];
    }
}
