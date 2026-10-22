<?php
/**
 * API: Header Notification Real-Time Polling
 * GET /api/inventory/notifications_poll.php?last_known_id=...
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/AuthController.php';

$auth = new AuthController();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$warehouseId = !empty($_GET['warehouse_id']) 
    ? (int)$_GET['warehouse_id'] 
    : (!empty($_SESSION['warehouse_id']) ? (int)$_SESSION['warehouse_id'] : ($currentUser['warehouse_id'] ?? 1));

if ($warehouseId <= 0) {
    $warehouseId = 1;
}

$lastKnownId = trim((string)($_GET['last_known_id'] ?? ''));

// Read stored read IDs from cookie or GET param
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
if (!empty($_GET['read_ids'])) {
    $clientIds = json_decode((string)$_GET['read_ids'], true);
    if (is_array($clientIds)) {
        $readNotificationIds = array_unique(array_merge($readNotificationIds, $clientIds));
    }
}

if (!function_exists('getPollTimeAgo')) {
    function getPollTimeAgo($datetime) {
        if (empty($datetime)) return 'Just now';
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$ts) return 'Just now';
        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 120) return '1 min ago';
        if ($diff < 3600) return (int)floor($diff / 60) . ' mins ago';
        if ($diff < 7200) return '1 hr ago';
        if ($diff < 86400) return (int)floor($diff / 3600) . ' hrs ago';
        if ($diff < 172800) return '1 day ago';
        return (int)floor($diff / 86400) . ' days ago';
    }
}

if (!function_exists('getPollCategory')) {
    function getPollCategory($datetime, $isRead = false) {
        if (empty($datetime)) return 'New';
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$ts) return 'New';
        $diff = time() - $ts;
        $todayMidnight = strtotime('today midnight');
        if ($diff <= 4 * 3600 || (empty($isRead) && $ts >= $todayMidnight)) return 'New';
        if ($ts >= $todayMidnight) return 'Today';
        return 'Earlier';
    }
}

$pdo = Database::getConnection();
$notificationList = [];

// Compute clean BASE_URL
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
$baseUrl = $projectRoot . '/';

try {
    // 1. Low stock items & exact warehouse count
    $stmtExactLow = $pdo->prepare("
        SELECT COUNT(*) AS total_low
        FROM items i
        LEFT JOIN stock s ON s.item_id = i.item_id AND s.warehouse_id = :wid
        WHERE i.status = 'active'
          AND COALESCE(s.reorder_level, i.reorder_level, 0) > 0
          AND COALESCE(s.qty_on_hand, 0) <= COALESCE(s.reorder_level, i.reorder_level, 0)
    ");
    $stmtExactLow->execute([':wid' => $warehouseId]);
    $exactLowStockCount = (int)$stmtExactLow->fetchColumn();

    $stmtNotif = $pdo->prepare("
        SELECT 
            i.item_id,
            i.code AS item_code,
            i.name AS item_name,
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
    $stmtNotif->execute([':wid' => $warehouseId]);
    while ($ls = $stmtNotif->fetch(PDO::FETCH_ASSOC)) {
        $isZero = ((float)$ls['quantity'] <= 0);
        $notifId = 'low_stock_' . (int)$ls['item_id'] . ($isZero ? '_zero' : '');
        $isRead = in_array($notifId, $readNotificationIds, true);
        $ts = !empty($ls['alert_epoch']) ? (int)$ls['alert_epoch'] : time();

        $notificationList[] = [
            'id'        => $notifId,
            'is_read'   => $isRead,
            'type'      => 'low_stock',
            'title'     => ($isZero ? 'Out of Stock: ' : 'Low Stock: ') . htmlspecialchars($ls['item_code']),
            'message'   => htmlspecialchars($ls['item_name']) . ' (Qty: ' . $ls['quantity'] . ' ' . htmlspecialchars($ls['unit']) . ')',
            'link'      => $baseUrl . 'views/inbound_outbound/index.php?tab=stock_in&item_id=' . (int)$ls['item_id'],
            'timestamp' => $ts,
            'time'      => getPollTimeAgo($ts),
            'category'  => getPollCategory($ts, $isRead),
            'severity'  => $isZero ? 'critical' : 'warning',
        ];
    }

    // 2. Pending Adjustments
    $stmtPendingAdj = $pdo->query("SELECT COUNT(*) AS total_count, UNIX_TIMESTAMP(MAX(requested_at)) AS latest_epoch FROM stock_adjustments WHERE status = 'pending'");
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
            'time'      => getPollTimeAgo($ts),
            'category'  => getPollCategory($ts, $isRead),
            'severity'  => 'info',
        ];
    }

    // 3. Pending Transfers
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
            'time'      => getPollTimeAgo($ts),
            'category'  => getPollCategory($ts, $isRead),
            'severity'  => 'info',
        ];
    }

    // 4. Super Admin Stock Actions
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
    while ($al = $stmtAdminActs->fetch(PDO::FETCH_ASSOC)) {
        $notifId = 'sa_act_' . (int)$al['log_id'];
        $isRead = in_array($notifId, $readNotificationIds, true);
        $ts = !empty($al['alert_epoch']) ? (int)$al['alert_epoch'] : strtotime($al['created_at']);
        $detailsData = json_decode($al['details'] ?? '', true) ?: [];

        $itemCode = htmlspecialchars($detailsData['item_code'] ?? '');
        $itemName = htmlspecialchars($detailsData['item_name'] ?? '');
        $qty = isset($al['quantity']) && (float)$al['quantity'] != 0 
            ? ' (Qty: ' . $al['quantity'] . ' ' . htmlspecialchars($detailsData['unit'] ?? '') . ')' 
            : '';
        $notes = htmlspecialchars($detailsData['notes'] ?? '');

        $msg = ($itemCode ? "{$itemCode} {$itemName}{$qty}" : '');
        if (!empty($notes)) {
            $msg = $msg ? "{$msg} — {$notes}" : $notes;
        }
        if (empty($msg)) {
            $msg = "Operational inventory action recorded by {$al['admin_name']}";
        }

        $refNo = $detailsData['reference_number'] ?? '';
        $link = $baseUrl . 'views/settings/accountability.php' . ($refNo ? '?search=' . urlencode($refNo) : '');
        $severity = in_array($al['action'], ['ADJUSTMENT_REJECTED', 'BAD_PRODUCT', 'STOCK_IN_CANCEL', 'STOCK_OUT_CANCEL'], true) 
            ? 'critical' 
            : (in_array($al['action'], ['ADJUSTMENT_APPROVED', 'ADJUSTMENT_REQUESTED'], true) ? 'warning' : 'shield');

        $notificationList[] = [
            'id'        => $notifId,
            'is_read'   => $isRead,
            'type'      => 'super_admin_stock',
            'title'     => 'Super Admin: ' . ucwords(strtolower(str_replace('_', ' ', $al['action']))),
            'message'   => $msg,
            'link'      => $link,
            'timestamp' => $ts,
            'time'      => getPollTimeAgo($ts),
            'category'  => getPollCategory($ts, $isRead),
            'severity'  => $severity,
        ];
    }

    // 5. Support Inquiries & Messages
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

        $link = $baseUrl . 'views/settings/accountability.php?search=' . urlencode($refNo);

        $notificationList[] = [
            'id'        => $notifId,
            'is_read'   => $isRead,
            'type'      => 'support_message',
            'title'     => $title,
            'message'   => $message,
            'link'      => $link,
            'timestamp' => $ts,
            'time'      => getPollTimeAgo($ts),
            'category'  => getPollCategory($ts, $isRead),
            'severity'  => 'message',
        ];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// Sort newest first
usort($notificationList, function($a, $b) {
    return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
});

$unreadCount = 0;
$unreadStockCount = 0;
$unreadMessageCount = 0;
$totalStockCount = 0;
$totalMessageCount = 0;
$newAlert = null;

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

if ($latestItem && empty($latestItem['is_read']) && $lastKnownId !== '' && $latestId !== $lastKnownId) {
    $hasNewAlert = true;
    $newAlert = $latestItem;
}

echo json_encode([
    'success'               => true,
    'unread_count'          => $unreadCount,
    'total_count'           => count($notificationList),
    'unread_stock_count'    => $unreadStockCount,
    'unread_message_count'  => $unreadMessageCount,
    'total_stock_count'     => $totalStockCount,
    'total_message_count'   => $totalMessageCount,
    'latest_id'             => $latestId,
    'has_new_alert'         => $hasNewAlert,
    'new_alert'             => $newAlert,
    'kpi'                   => [
        'low_stock_count'      => $exactLowStockCount ?? 0,
        'pending_adj_count'    => $pendingAdjCount ?? 0,
        'pending_trans_count'  => $pendingTransCount ?? 0,
    ],
    'notifications'         => $notificationList,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
