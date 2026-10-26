<?php
/**
 * API: Header Notification Real-Time Polling (F-22, F-35)
 * GET /api/inventory/notifications_poll.php?last_known_id=...
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../helpers/NotificationService.php';

$auth = new AuthController();
// F-23: pass false so background polling does not reset idle activity timestamp
if (!$auth->isAuthenticated(false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$currentUser = $auth->getCurrentUser();
if (($currentUser['role'] ?? '') === 'super_admin') {
    $warehouseId = !empty($_GET['warehouse_id'])
        ? (int)$_GET['warehouse_id']
        : (!empty($_SESSION['warehouse_id']) ? (int)$_SESSION['warehouse_id'] : ((int)($currentUser['warehouse_id'] ?? 1)));
} else {
    $warehouseId = (int)($currentUser['warehouse_id'] ?? 1);
}

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

$pdo = Database::getConnection();

// Compute clean BASE_URL
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$projectRoot = preg_replace('#/(auth|views|api|dashboard).*$#', '', $scriptDir);
$projectRoot = ($projectRoot === '/' || $projectRoot === '\\') ? '' : rtrim($projectRoot, '/\\');
$baseUrl = $projectRoot . '/';

try {
    // F-35: Centralized feed generation
    $feed = NotificationService::getFeed(
        $pdo,
        $warehouseId,
        $currentUser,
        $readNotificationIds,
        $baseUrl,
        $lastKnownId
    );

    echo json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
