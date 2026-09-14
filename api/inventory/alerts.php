<?php
/**
 * API Endpoint: Get Active Low Stock Alerts & Reorder Requirements
 * Primary Consumer: Team 1 (Procurement - Purchasing Replenishment)
 * Method: GET
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/StockService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use GET.'
    ], 405);
}

// Authenticate caller (All authenticated teams can view alerts, especially Procurement)
$authUser = requireApiAuth();

// Rate Limit: 60 req / min
checkRateLimit('inventory_alerts', 60, 60, $authUser['api_token']);

$warehouseId = isset($_GET['warehouse_id']) && is_numeric($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
$itemType = isset($_GET['item_type']) ? trim($_GET['item_type']) : null;

try {
    $service = new StockService();
    $alerts = $service->getLowStockAlerts($warehouseId, $itemType);

    jsonResponse([
        'success'      => true,
        'alerts_count' => count($alerts),
        'message'      => count($alerts) > 0 ? 'Active low-stock items requiring procurement replenishment.' : 'All inventory levels are above reorder thresholds.',
        'data'         => $alerts
    ], 200);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal Server Error',
        'detail'  => $e->getMessage()
    ], 500);
}
