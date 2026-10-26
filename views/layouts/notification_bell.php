<?php
/**
 * Layout Component: Header Notification Bell & Dropdown
 * InventoryTeam — Liquor Business Inventory Management System
 * Refactored under F-22 & F-35 via NotificationService
 */

require_once __DIR__ . '/../../helpers/NotificationService.php';

if (!function_exists('getNotificationTimeAgo')) {
    function getNotificationTimeAgo($datetime) {
        return NotificationService::getTimeAgo($datetime);
    }
}

if (!function_exists('getNotificationCategory')) {
    function getNotificationCategory($datetime, $isRead = false) {
        return NotificationService::getCategory($datetime, $isRead);
    }
}

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

if (!isset($notificationList) || !is_array($notificationList)) {
    try {
        $feed = NotificationService::getFeed(
            $pdo,
            (int)($currentWarehouseId ?? 1),
            $currentUser ?? [],
            $readNotificationIds,
            defined('BASE_URL') ? BASE_URL : ''
        );
        $notificationList = $feed['notifications'];
        $unreadNotificationsCount = $feed['unread_count'];
    } catch (Throwable $e) {
        $notificationList = [];
        $unreadNotificationsCount = 0;
    }
} else {
    $unreadNotificationsCount = count(array_filter($notificationList, fn($n) => empty($n['is_read'])));
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
<?php if (!empty($isAuthorizedForMultiWarehouse)): 
    $headerWarehouses = [];
    try {
        $headerWarehouses = $pdo->query("SELECT warehouse_id, code AS warehouse_code, name AS warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $headerWarehouses = [];
    }
    if (count($headerWarehouses) > 1):
?>
    <!-- F-37: Super Admin Desktop Facility Switcher inside .page-header -->
    <div class="header-desktop-wh-switcher" style="display: inline-flex; align-items: center; margin-right: 8px;">
        <form method="GET" style="margin: 0; display: inline-flex; align-items: center;">
            <?php foreach ($_GET as $gk => $gv): if ($gk === 'warehouse_id' || is_array($gv)) continue; ?>
                <input type="hidden" name="<?= htmlspecialchars((string)$gk) ?>" value="<?= htmlspecialchars((string)$gv) ?>">
            <?php endforeach; ?>
            <div style="position: relative; display: inline-flex; align-items: center;">
                <span style="position: absolute; left: 10px; color: var(--gold, #E0A96D); pointer-events: none; font-size: 11px; font-weight: 700;">WH:</span>
                <select name="warehouse_id" aria-label="Switch active facility" class="select-filter" style="height: 34px; font-size: 12px; padding: 0 12px 0 36px; border-radius: 20px; font-weight: 600; background: rgba(255, 255, 255, 0.12); color: #FFFFFF; border: 1px solid rgba(255, 255, 255, 0.25); cursor: pointer;" onchange="this.form.submit()">
                    <?php foreach ($headerWarehouses as $hw): ?>
                        <option value="<?= (int)$hw['warehouse_id'] ?>" style="background: #14213D; color: #FFFFFF;" <?= ((int)$hw['warehouse_id'] === (int)($currentWarehouseId ?? 1)) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($hw['warehouse_code'] . ' · ' . $hw['warehouse_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
<?php endif; endif; ?>
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
                        <div class="notif-section" data-section="<?= htmlspecialchars((string)$category, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="notif-section-header">
                                <span class="notif-section-title"><?= htmlspecialchars((string)$category, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="notif-section-count"><?= count($items) ?></span>
                            </div>
                            <?php foreach ($items as $notif):
                                $allowedSeverities = ['critical', 'warning', 'shield', 'message', 'info'];
                                $rawSev = (string)($notif['severity'] ?? 'info');
                                $safeSev = in_array($rawSev, $allowedSeverities, true) ? $rawSev : 'info';
                                $rawLink = trim((string)($notif['link'] ?? '#'));
                                if ($rawLink === '' || (!str_starts_with($rawLink, '/') && !str_starts_with($rawLink, 'http://') && !str_starts_with($rawLink, 'https://') && !str_starts_with($rawLink, '#') && !str_starts_with($rawLink, 'views/'))) {
                                    $rawLink = '#';
                                }
                                $safeLink = htmlspecialchars($rawLink, ENT_QUOTES, 'UTF-8');
                                $safeTitle = htmlspecialchars((string)($notif['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $safeMessage = htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $safeTime = htmlspecialchars((string)($notif['time'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $safeId = htmlspecialchars((string)($notif['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $safeType = htmlspecialchars((string)($notif['type'] ?? ''), ENT_QUOTES, 'UTF-8');
                            ?>
                                <div class="notif-item <?= !empty($notif['is_read']) ? 'read' : 'unread' ?>" data-notif-id="<?= $safeId ?>" data-type="<?= $safeType ?>">
                                    <a href="<?= $safeLink ?>" class="notif-link" onclick="handleNotificationClick(event, this.closest('.notif-item'))">
                                        <div class="notif-icon-circle <?= $safeSev ?>" aria-hidden="true">
                                            <?php if ($safeSev === 'critical'): ?>
                                                <!-- AlertCircle Icon -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                                </svg>
                                            <?php elseif ($safeSev === 'warning'): ?>
                                                <!-- AlertTriangle Icon -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                                </svg>
                                            <?php elseif ($safeSev === 'shield'): ?>
                                                <!-- ShieldCheck Icon (Super Admin Stock Action) -->
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                                    <path d="m9 12 2 2 4-4"/>
                                                </svg>
                                            <?php elseif ($safeSev === 'message'): ?>
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
                                                <span class="notif-title-text"><?= $safeTitle ?></span>
                                                <span class="notif-badge-sub <?= $safeSev ?>">
                                                    <?php
                                                    if ($safeSev === 'shield') {
                                                        echo 'Super Admin';
                                                    } elseif ($safeSev === 'message') {
                                                        echo 'Support';
                                                    } else {
                                                        echo htmlspecialchars(ucfirst($safeSev), ENT_QUOTES, 'UTF-8');
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="notif-content-desc"><?= $safeMessage ?></div>
                                            <div class="notif-content-meta">
                                                <svg class="notif-time-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <polyline points="12 6 12 12 16 14"/>
                                                </svg>
                                                <span class="notif-content-time"><?= $safeTime ?></span>
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

