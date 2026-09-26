<?php
/**
 * API Endpoint: Create Stock Adjustment
 * Method: POST
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/rate_limiter.php';
require_once __DIR__ . '/../../helpers/StockService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'error'   => 'Method Not Allowed. Use POST.'
    ], 405);
}

$authUser = requireApiAuth();
$userId = (int)$authUser['user_id'];

checkRateLimit('stock_adjustment_create', 30, 60, $authUser['api_token']);

$payload = getRequestJson();

$warehouseId = (int)($payload['warehouse_id'] ?? 0);
$adjustmentDate = trim($payload['adjustment_date'] ?? date('Y-m-d'));
$reason = trim($payload['reason'] ?? '');
$items = $payload['items'] ?? [];

// Single item support at root level
if (empty($items) && isset($payload['item_id'])) {
    $items = [[
        'item_id'           => $payload['item_id'],
        'adjusted_quantity' => $payload['adjusted_quantity'] ?? $payload['quantity'] ?? null
    ]];
}

if ($warehouseId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: warehouse_id is required.'
    ], 400);
}

if (empty($reason)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: reason is required.'
    ], 400);
}

if (mb_strlen($reason) > 255) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: reason cannot exceed 255 characters.'
    ], 400);
}

if (empty($items) || !is_array($items)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: items array is required.'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->recordStockAdjustment(
        $warehouseId,
        $adjustmentDate,
        $reason,
        $items,
        $userId,
        $authUser
    );

    jsonResponse([
        'success' => true,
        'message' => 'Stock adjustment created successfully with status pending.',
        'data'    => $result
    ], 201);
} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);
} catch (DomainException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 422);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error occurred.'
    ], 500);
}
