<?php
/**
 * API Endpoint: Get Real-Time Inventory Balance
 * Primary Consumers: Team 4 (Sales - Availability Check), Team 1, Team 3
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

// Authenticate caller (Any authenticated ERP service account)
$authUser = requireApiAuth();

// Rate Limit: 60 req / min
checkRateLimit('inventory_get', 60, 60, $authUser['api_token']);

$itemId = isset($_GET['item_id']) && is_numeric($_GET['item_id']) ? (int)$_GET['item_id'] : null;
$itemCode = isset($_GET['item_code']) ? trim($_GET['item_code']) : null;
$warehouseId = isset($_GET['warehouse_id']) && is_numeric($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
$itemType = isset($_GET['item_type']) ? trim($_GET['item_type']) : null;

try {
    $service = new StockService();
    $balances = $service->getInventory($itemId, $itemCode, $warehouseId, $itemType);

    jsonResponse([
        'success' => true,
        'count'   => count($balances),
        'data'    => $balances
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
