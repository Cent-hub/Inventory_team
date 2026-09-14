<?php
/**
 * API Endpoint: Create Stock In
 * Primary Consumer: Team 1 (Procurement) & Team 3 (Production FG Receipt)
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

// Authenticate caller: Admin, Procurement (User 2, 5), or Production (User 3)
$authUser = requireApiAuth(['procurement', 'production']);
$userId = (int)$authUser['user_id'];

// Enforce rate limiting per API token (30 req / 60s)
checkRateLimit('stock_in_create', 30, 60, $authUser['api_token']);

$payload = getRequestJson();

$warehouseId = (int)($payload['warehouse_id'] ?? 0);
$sourceType = strtoupper(trim($payload['source_type'] ?? 'PURCHASE_ORDER'));
$sourceReferenceNo = trim($payload['source_reference_no'] ?? '');
$items = $payload['items'] ?? [];
$remarks = $payload['remarks'] ?? null;

// Validation
if ($warehouseId <= 0) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: warehouse_id is required.'
    ], 400);
}

$validSourceTypes = ['PURCHASE_ORDER', 'PRODUCTION_RETURN', 'MANUAL'];
if (!in_array($sourceType, $validSourceTypes, true)) {
    jsonResponse([
        'success' => false,
        'error'   => "Validation Error: source_type must be one of: " . implode(', ', $validSourceTypes)
    ], 400);
}

if (empty($sourceReferenceNo)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: source_reference_no (e.g. PO Number or Work Order #) is required.'
    ], 400);
}

if (!is_array($items) || empty($items)) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error: items array is required and must contain at least one {item_id, quantity}.'
    ], 400);
}

try {
    $service = new StockService();
    $result = $service->recordStockIn(
        $warehouseId,
        $sourceType,
        $sourceReferenceNo,
        $items,
        $userId,
        $remarks
    );

    jsonResponse([
        'success' => true,
        'message' => 'Stock IN recorded successfully. Inventory updated.',
        'data'    => $result
    ], 201);
} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Validation Error',
        'detail'  => $e->getMessage()
    ], 422);
} catch (PDOException $e) {
    handleDbException($e);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'error'   => 'Internal Server Error',
        'detail'  => $e->getMessage()
    ], 500);
}
