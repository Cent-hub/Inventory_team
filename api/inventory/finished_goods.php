<?php
/**
 * API Endpoint: Get Available Finished Goods
 * Primary Consumer: Team 4 (Sales System - Finished Goods Availability & Inventory Checking)
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

// Authenticate caller (Sales or Admin)
$authUser = requireApiAuth(['sales', 'admin']);

// Rate Limit: 60 req / min
checkRateLimit('finished_goods_get', 60, 60, $authUser['api_token']);

// Parse query parameters
$warehouseId = isset($_GET['warehouse_id']) && is_numeric($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
$itemCode = isset($_GET['item_code']) && trim($_GET['item_code']) !== '' ? trim($_GET['item_code']) : null;
$itemId = isset($_GET['item_id']) && is_numeric($_GET['item_id']) ? (int)$_GET['item_id'] : null;
$inStockOnly = filter_var($_GET['in_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    $service = new StockService();
    $items = $service->getFinishedGoods($warehouseId, $itemCode, $itemId, $inStockOnly);

    jsonResponse([
        'success' => true,
        'count'   => count($items),
        'filters' => [
            'warehouse_id'  => $warehouseId,
            'item_code'     => $itemCode,
            'item_id'       => $itemId,
            'in_stock_only' => $inStockOnly
        ],
        'data'    => $items
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
